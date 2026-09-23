<?php

namespace GlpiPlugin\Tasksmanager\Automation;

use GlpiPlugin\Tasksmanager\Config;

/**
 * VsphereConnector — deploys a VM straight from a vCenter content-library
 * template (vSphere Automation REST API, /api, vSphere 8).
 *
 * Inputs (resolved from the form, or set on the review page):
 *   template            content-library item id (vm-template)
 *   vm_name             VM name (checked unique in vCenter before deploy)
 *   cluster, folder     placement (folder optional)
 *   datastore           VM home + disks
 *   network             port group for the template's first NIC (optional)
 *   cpu, cores_per_socket, memory_gb   (optional — template defaults)
 *   disks               extra disks, list of sizes in GB
 *   customization_spec  vCenter customization spec name (optional)
 *   ip, prefix, gateway static IPv4 for the first NIC (optional)
 *
 * The work runs as resumable phases, one external_id each:
 *   task:<id>             deploy (vmw-task) — VLAN/CPU/RAM set in the deploy
 *   vm:<vm>:disk:<n>      add extra disk n
 *   vm:<vm>:custom        apply the customization spec (name, IP)
 *   vm:<vm>:power         power on → succeeded
 * The VM stays powered off until the last phase, so customization is
 * applied before first boot.
 */
class VsphereConnector implements ConnectorInterface
{
    public const NAME = 'vsphere';

    /** Config keys (glpi_plugin_tasksmanager_configs). */
    public const CFG_URL             = 'vsphere_url';
    public const CFG_USERNAME        = 'vsphere_username';
    public const CFG_PASSWORD        = 'vsphere_password';        // GLPIKey-encrypted
    public const CFG_LIBRARY         = 'vsphere_library_id';      // optional: limit templates
    public const CFG_VERIFY_SSL      = 'vsphere_verify_ssl';
    public const CFG_SESSION         = 'vsphere_session';         // GLPIKey-encrypted cache
    public const CFG_SESSION_EXPIRES = 'vsphere_session_expires';

    private const TIMEOUT         = 30;
    private const CONNECT_TIMEOUT = 10;
    /** vCenter drops idle sessions after 30 min; stay well under. */
    private const SESSION_TTL     = 1200;
    private const MAX_TEMPLATES   = 200;

    private ?string $session = null;

    public function getName(): string
    {
        return self::NAME;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ConnectorInterface
    // ─────────────────────────────────────────────────────────────────────

    public function submit(array $inputs, array $cfg): string
    {
        foreach (['template', 'vm_name', 'cluster', 'datastore'] as $required) {
            if (trim((string)($inputs[$required] ?? '')) === '') {
                throw new ConnectorException('Missing ' . $required, true);
            }
        }
        $template = (string)$inputs['template'];
        $name     = (string)$inputs['vm_name'];
        foreach (['template', 'cluster', 'datastore', 'folder', 'network'] as $id_key) {
            if (!empty($inputs[$id_key]) && !self::isSafeId((string)$inputs[$id_key])) {
                throw new ConnectorException('Invalid ' . $id_key . ' id', true);
            }
        }

        if ($this->vmNameExists($name)) {
            throw new ConnectorException(sprintf('A VM named "%s" already exists in vCenter', $name), true);
        }

        $spec = [
            'name'            => $name,
            'placement'       => array_filter([
                'cluster' => (string)$inputs['cluster'],
                'folder'  => (string)($inputs['folder'] ?? ''),
            ]),
            'vm_home_storage' => ['datastore' => (string)$inputs['datastore']],
            'disk_storage'    => ['datastore' => (string)$inputs['datastore']],
            'powered_on'      => false,
        ];

        $hw = [];
        if (!empty($inputs['network'])) {
            $nic_key = $this->firstNicKey($template);
            if ($nic_key === null) {
                throw new ConnectorException('The template has no network adapter to attach the VLAN to', true);
            }
            $hw['nics'] = [$nic_key => ['network' => (string)$inputs['network']]];
        }
        if (!empty($inputs['cpu'])) {
            $hw['cpu_update'] = [
                'num_cpus'             => (int)$inputs['cpu'],
                'num_cores_per_socket' => max(1, (int)($inputs['cores_per_socket'] ?? 1)),
            ];
        }
        if (!empty($inputs['memory_gb'])) {
            $hw['memory_update'] = ['memory' => (int)round((float)$inputs['memory_gb'] * 1024)];
        }
        if ($hw) {
            $spec['hardware_customization'] = $hw;
        }

        // vmw-task=true: return a task id instead of blocking until the
        // clone finishes (minutes, far past our 30 s timeout).
        $resp = $this->request(
            'POST',
            '/api/vcenter/vm-template/library-items/' . rawurlencode($template) . '?action=deploy&vmw-task=true',
            ['json' => $spec],
            true
        );
        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            throw new ConnectorException(
                sprintf('vCenter rejected the deploy (HTTP %d): %s', $resp['code'], self::errorMessage($resp['body'])),
                self::isPermanentStatus($resp['code'])
            );
        }

        $id = is_string($resp['body']) ? trim($resp['body'], "\" \n") : '';
        if ($id === '') {
            throw new ConnectorException(
                sprintf('vCenter accepted the deploy but returned no task id — check for VM "%s" in vCenter', $name),
                true
            );
        }
        // A synchronous answer is the VM id itself.
        return str_starts_with($id, 'vm-') ? 'vm:' . $id . ':disk:0' : 'task:' . $id;
    }

    public function poll(string $id, array $cfg): Result
    {
        $inputs = (array)($cfg['resolved_inputs'] ?? []);

        if (preg_match('/^task:(.+)$/', $id, $m)) {
            return $this->pollDeployTask($m[1]);
        }
        if (!preg_match('/^vm:([A-Za-z0-9._-]+):(disk:(\d+)|custom|power)$/', $id, $m)) {
            return Result::failed('Unrecognised job phase: ' . $id);
        }
        $vm = $m[1];

        if (str_starts_with($m[2], 'disk:')) {
            return $this->addDisk($vm, (int)$m[3], $inputs);
        }
        if ($m[2] === 'custom') {
            $this->customize($vm, $inputs);
            return Result::pending('customized', [], 'vm:' . $vm . ':power');
        }
        return $this->powerOn($vm, $inputs);
    }

    private function pollDeployTask(string $task): Result
    {
        if (!self::isSafeId($task)) {
            return Result::failed('Invalid task id: ' . $task);
        }
        $resp = $this->request('GET', '/api/cis/tasks/' . rawurlencode($task));
        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            throw new ConnectorException(
                sprintf('vCenter task lookup failed (HTTP %d): %s', $resp['code'], self::errorMessage($resp['body'])),
                false
            );
        }
        $body   = is_array($resp['body']) ? $resp['body'] : [];
        $status = strtoupper((string)($body['status'] ?? ''));
        $raw    = ['task' => $task, 'status' => $status, 'progress' => $body['progress']['completed'] ?? null];

        if ($status === 'FAILED') {
            return Result::failed('Deploy failed: ' . self::errorMessage($body['error'] ?? $body), $raw);
        }
        if ($status === 'SUCCEEDED') {
            $vm = (string)($body['result'] ?? '');
            if (!self::isSafeId($vm)) {
                return Result::failed('Deploy finished but vCenter returned no VM id', $raw);
            }
            return Result::pending('deployed', $raw, 'vm:' . $vm . ':disk:0');
        }
        return Result::pending($status ?: 'RUNNING', $raw);
    }

    private function addDisk(string $vm, int $n, array $inputs): Result
    {
        $disks = array_values(array_filter(
            array_map('intval', (array)($inputs['disks'] ?? [])),
            fn ($gb) => $gb > 0
        ));
        if ($n >= count($disks)) {
            return Result::pending('disks added', [], 'vm:' . $vm . ':custom');
        }

        // Adding a disk is not idempotent: an unknown outcome is permanent
        // so a retry can't attach the same disk twice.
        $resp = $this->request(
            'POST',
            '/api/vcenter/vm/' . rawurlencode($vm) . '/hardware/disk',
            ['json' => ['type' => 'SCSI', 'new_vmdk' => ['capacity' => $disks[$n] * 1024 ** 3]]],
            true
        );
        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            throw new ConnectorException(
                sprintf('Adding disk %d (%d GB) to %s failed (HTTP %d): %s', $n + 1, $disks[$n], $vm, $resp['code'], self::errorMessage($resp['body'])),
                self::isPermanentStatus($resp['code'])
            );
        }
        return Result::pending('disk ' . ($n + 1) . ' added', [], 'vm:' . $vm . ':disk:' . ($n + 1));
    }

    /**
     * Apply the customization spec while the VM is still powered off.
     *   no IP  → apply the stored spec by name (set its computer name to
     *            "use the virtual machine name" in vCenter)
     *   IP set → fetch the spec, set computer name + first NIC IPv4, apply
     *            the modified copy (the stored spec is not changed)
     *
     * @throws ConnectorException
     */
    private function customize(string $vm, array $inputs): void
    {
        $spec_name = trim((string)($inputs['customization_spec'] ?? ''));
        if ($spec_name === '') {
            return;
        }
        $path = '/api/vcenter/vm/' . rawurlencode($vm) . '/guest/customization';
        $ip   = trim((string)($inputs['ip'] ?? ''));

        if ($ip === '') {
            $body = ['name' => $spec_name];
        } else {
            $resp = $this->request('GET', '/api/vcenter/guest/customization-specs/' . rawurlencode($spec_name));
            if ($resp['code'] < 200 || $resp['code'] >= 300 || !is_array($resp['body']['spec'] ?? null)) {
                throw new ConnectorException(
                    sprintf('Cannot read customization spec "%s" (HTTP %d): %s', $spec_name, $resp['code'], self::errorMessage($resp['body'])),
                    self::isPermanentStatus($resp['code'])
                );
            }
            $spec = $resp['body']['spec'];
            $name = ['type' => 'FIXED', 'fixed_name' => (string)$inputs['vm_name']];
            if (isset($spec['configuration_spec']['windows_config'])) {
                $spec['configuration_spec']['windows_config']['sysprep']['user_data']['computer_name'] = $name;
            } elseif (isset($spec['configuration_spec']['linux_config'])) {
                $spec['configuration_spec']['linux_config']['hostname'] = $name;
            }
            $spec['interfaces'][0]['adapter']['ipv4'] = [
                'type'       => 'STATIC',
                'ip_address' => $ip,
                'prefix'     => (int)($inputs['prefix'] ?? 24),
                'gateways'   => array_values(array_filter([(string)($inputs['gateway'] ?? '')])),
            ];
            $body = ['spec' => $spec];
        }

        $resp = $this->request('PUT', $path, ['json' => $body]);
        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            throw new ConnectorException(
                sprintf('Applying customization spec "%s" failed (HTTP %d): %s', $spec_name, $resp['code'], self::errorMessage($resp['body'])),
                self::isPermanentStatus($resp['code'])
            );
        }
    }

    private function powerOn(string $vm, array $inputs): Result
    {
        $path  = '/api/vcenter/vm/' . rawurlencode($vm) . '/power';
        $state = $this->request('GET', $path);
        if (strtoupper((string)($state['body']['state'] ?? '')) !== 'POWERED_ON') {
            $resp = $this->request('POST', $path . '?action=start');
            if ($resp['code'] < 200 || $resp['code'] >= 300) {
                throw new ConnectorException(
                    sprintf('Power-on of %s failed (HTTP %d): %s', $vm, $resp['code'], self::errorMessage($resp['body'])),
                    self::isPermanentStatus($resp['code'])
                );
            }
        }

        $disks = array_filter(array_map('intval', (array)($inputs['disks'] ?? [])));
        return Result::succeeded(array_filter([
            'VM name'       => (string)($inputs['vm_name'] ?? ''),
            'vCenter VM id' => $vm,
            'IP address'    => (string)($inputs['ip'] ?? ''),
            'CPU'           => (string)($inputs['cpu'] ?? ''),
            'Memory (GB)'   => (string)($inputs['memory_gb'] ?? ''),
            'Extra disks'   => $disks ? implode(', ', array_map(fn ($g) => $g . ' GB', $disks)) : '',
        ], fn ($v) => $v !== ''), '', ['vm' => $vm]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Inventory lookups (review page)
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<string,string> library item id => name, vm-templates only */
    public function listTemplates(): array
    {
        $libraries = [];
        $configured = trim((string)Config::getConfigValue(self::CFG_LIBRARY, ''));
        if ($configured !== '') {
            $libraries[] = $configured;
        } else {
            $libraries = (array)$this->getJson('/api/content/library');
        }

        $out = [];
        foreach ($libraries as $lib) {
            if (!self::isSafeId((string)$lib)) {
                continue;
            }
            foreach ((array)$this->getJson('/api/content/library/item?library_id=' . rawurlencode((string)$lib)) as $item_id) {
                if (count($out) >= self::MAX_TEMPLATES || !self::isSafeId((string)$item_id)) {
                    break;
                }
                $item = $this->getJson('/api/content/library/item/' . rawurlencode((string)$item_id));
                if (is_array($item) && ($item['type'] ?? '') === 'vm-template') {
                    $out[(string)$item_id] = (string)($item['name'] ?? $item_id);
                }
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** @return array<string,string> */
    public function listClusters(): array
    {
        return self::pairs($this->getJson('/api/vcenter/cluster'), 'cluster');
    }

    /** @return array<string,string> */
    public function listFolders(): array
    {
        return self::pairs($this->getJson('/api/vcenter/folder?type=VIRTUAL_MACHINE'), 'folder');
    }

    /** @return array<string,string> id => "name (free / capacity)" */
    public function listDatastores(): array
    {
        $out = [];
        foreach ((array)$this->getJson('/api/vcenter/datastore') as $ds) {
            if (!is_array($ds) || empty($ds['datastore'])) {
                continue;
            }
            $free = isset($ds['free_space']) ? round($ds['free_space'] / 1024 ** 3) : null;
            $cap  = isset($ds['capacity']) ? round($ds['capacity'] / 1024 ** 3) : null;
            $out[(string)$ds['datastore']] = (string)($ds['name'] ?? $ds['datastore'])
                . ($free !== null && $cap !== null ? sprintf(' (%d / %d GB free)', $free, $cap) : '');
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** @return array<string,string> */
    public function listNetworks(): array
    {
        return self::pairs($this->getJson('/api/vcenter/network'), 'network');
    }

    /** @return array<string,string> name => "name (OS)" */
    public function listCustomizationSpecs(): array
    {
        $out = [];
        foreach ((array)$this->getJson('/api/vcenter/guest/customization-specs') as $s) {
            if (is_array($s) && !empty($s['name'])) {
                $out[(string)$s['name']] = (string)$s['name'] . (!empty($s['os_type']) ? ' (' . $s['os_type'] . ')' : '');
            }
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    public function vmNameExists(string $name): bool
    {
        $list = $this->getJson('/api/vcenter/vm?names=' . rawurlencode($name));
        return is_array($list) && count($list) > 0;
    }

    private function firstNicKey(string $template): ?string
    {
        $tpl  = $this->getJson('/api/vcenter/vm-template/library-items/' . rawurlencode($template));
        $nics = is_array($tpl) ? (array)($tpl['nics'] ?? []) : [];
        if (!$nics) {
            return null;
        }
        $keys = array_keys($nics);
        sort($keys, SORT_NATURAL);
        return (string)$keys[0];
    }

    /**
     * @throws ConnectorException
     */
    private function getJson(string $path): mixed
    {
        $resp = $this->request('GET', $path);
        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            throw new ConnectorException(
                sprintf('vCenter GET %s failed (HTTP %d): %s', strtok($path, '?'), $resp['code'], self::errorMessage($resp['body'])),
                self::isPermanentStatus($resp['code'])
            );
        }
        return $resp['body'];
    }

    private static function pairs(mixed $list, string $id_key): array
    {
        $out = [];
        foreach ((array)$list as $row) {
            if (is_array($row) && !empty($row[$id_key])) {
                $out[(string)$row[$id_key]] = (string)($row['name'] ?? $row[$id_key]);
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  HTTP + session
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Authenticated request with one re-login on 401.
     *
     * @return array{code:int, body:mixed}
     * @throws ConnectorException
     */
    private function request(string $method, string $path, array $options = [], bool $unsafe_to_retry = false): array
    {
        $resp = $this->send($method, $path, $options, $this->getSession(), $unsafe_to_retry);
        if ($resp['code'] === 401) {
            $this->dropSession();
            $resp = $this->send($method, $path, $options, $this->getSession(true), $unsafe_to_retry);
        }
        return $resp;
    }

    /**
     * @return array{code:int, body:mixed}
     * @throws ConnectorException
     */
    private function send(string $method, string $path, array $options, ?string $session, bool $unsafe_to_retry = false): array
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
        if ($session !== null) {
            $options['headers']['vmware-api-session-id'] = $session;
        }

        try {
            $response = self::client()->request($method, self::baseUrl() . $path, $options);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            throw new ConnectorException('Cannot reach vCenter: ' . $e->getMessage(), false, $e);
        } catch (\Throwable $e) {
            throw new ConnectorException(
                ($unsafe_to_retry ? 'vCenter request outcome unknown (check vCenter before retrying): ' : 'vCenter request failed: ')
                    . $e->getMessage(),
                $unsafe_to_retry,
                $e
            );
        }

        $raw  = (string)$response->getBody();
        $body = $raw === '' ? null : json_decode($raw, true);
        return ['code' => $response->getStatusCode(), 'body' => $body ?? $raw];
    }

    /**
     * @throws ConnectorException
     */
    private function getSession(bool $force = false): string
    {
        if (!$force) {
            if ($this->session !== null) {
                return $this->session;
            }
            $expires = (int)Config::getConfigValue(self::CFG_SESSION_EXPIRES, '0');
            $cached  = (string)Config::getConfigValue(self::CFG_SESSION, '');
            if ($cached !== '' && $expires > time()) {
                $plain = (string)(new \GLPIKey())->decrypt($cached);
                if ($plain !== '') {
                    return $this->session = $plain;
                }
            }
        }

        $user    = trim((string)Config::getConfigValue(self::CFG_USERNAME, ''));
        $enc_pwd = (string)Config::getConfigValue(self::CFG_PASSWORD, '');
        $pwd     = $enc_pwd !== '' ? (string)(new \GLPIKey())->decrypt($enc_pwd) : '';
        if ($user === '' || $pwd === '') {
            throw new ConnectorException('vCenter username / password are not configured', true);
        }

        $resp = $this->send('POST', '/api/session', ['auth' => [$user, $pwd]], null);
        $session = is_string($resp['body']) ? trim($resp['body'], "\" \n") : '';
        if ($resp['code'] < 200 || $resp['code'] >= 300 || $session === '') {
            throw new ConnectorException(
                sprintf('vCenter login failed (HTTP %d): %s', $resp['code'], self::errorMessage($resp['body'])),
                in_array($resp['code'], [400, 401, 403], true)
            );
        }

        Config::setConfigValue(self::CFG_SESSION, (string)(new \GLPIKey())->encrypt($session));
        Config::setConfigValue(self::CFG_SESSION_EXPIRES, (string)(time() + self::SESSION_TTL));
        return $this->session = $session;
    }

    private function dropSession(): void
    {
        $this->session = null;
        Config::setConfigValue(self::CFG_SESSION, '');
        Config::setConfigValue(self::CFG_SESSION_EXPIRES, '0');
    }

    /**
     * @throws ConnectorException
     */
    private static function baseUrl(): string
    {
        $url = rtrim(trim((string)Config::getConfigValue(self::CFG_URL, '')), '/');
        if (!AriaConnector::isValidUrl($url)) {
            throw new ConnectorException('vCenter URL is not configured (https://… expected)', true);
        }
        return $url;
    }

    private static function client(): \GuzzleHttp\Client
    {
        $options = [
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'http_errors'     => false,
            'verify'          => Config::getConfigValue(self::CFG_VERIFY_SSL, '1') !== '0',
        ];
        if (method_exists(\Toolbox::class, 'getGuzzleClient')) {
            return \Toolbox::getGuzzleClient($options);
        }
        return new \GuzzleHttp\Client($options);
    }

    /** vCenter ids: vm-123, domain-c8, group-v4, dvportgroup-9, UUIDs. */
    public static function isSafeId(string $id): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $id);
    }

    private static function isPermanentStatus(int $code): bool
    {
        return $code >= 400 && $code < 500 && !in_array($code, [401, 408, 429], true);
    }

    private static function errorMessage(mixed $body): string
    {
        if (is_array($body)) {
            $msgs = [];
            foreach ((array)($body['messages'] ?? []) as $m) {
                if (is_array($m) && !empty($m['default_message'])) {
                    $msgs[] = (string)$m['default_message'];
                }
            }
            if ($msgs) {
                return implode(' ', $msgs);
            }
            if (!empty($body['error_type'])) {
                return (string)$body['error_type'];
            }
            return mb_substr((string)json_encode($body), 0, 500);
        }
        return mb_substr(trim(strip_tags((string)$body)), 0, 500);
    }
}
