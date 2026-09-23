<?php

namespace GlpiPlugin\Tasksmanager\Automation;

use GlpiPlugin\Tasksmanager\Config;

/**
 * AriaConnector — VMware Aria Automation (vRA 8.x) Service Broker catalog
 * requests.
 *
 *   auth    POST /iaas/api/login                     {refreshToken} → {token}
 *   submit  POST /catalog/api/items/{id}/request     → [{deploymentId, …}]
 *   poll    GET  /deployment/api/deployments/{id}?expand=resources&expand=lastRequest
 *
 * The catalog request answers with a deployment id (no request id), so
 * polling reads the deployment: `lastRequest.status` carries the outcome
 * and the expanded resources carry the VM name / IP for the follow-up.
 *
 * The access token is cached (encrypted) in the plugin config table so a
 * 2-minute cron doesn't log in on every run; a 401 drops it and retries
 * once with a fresh one.
 */
class AriaConnector implements ConnectorInterface
{
    public const NAME = 'aria';

    /** Config keys (glpi_plugin_tasksmanager_configs). */
    public const CFG_URL            = 'aria_url';
    public const CFG_REFRESH_TOKEN  = 'aria_refresh_token';   // GLPIKey-encrypted
    public const CFG_VERIFY_SSL     = 'aria_verify_ssl';
    public const CFG_ACCESS_TOKEN   = 'aria_access_token';    // GLPIKey-encrypted cache
    public const CFG_ACCESS_EXPIRES = 'aria_access_token_expires';

    private const TIMEOUT         = 30;
    private const CONNECT_TIMEOUT = 10;
    /** Conservative cache lifetime; Aria access tokens outlive this. */
    private const TOKEN_TTL       = 1500;
    private const API_VERSION     = '2020-08-25';

    private ?string $accessToken = null;

    public function getName(): string
    {
        return self::NAME;
    }

    public function submit(array $inputs, array $cfg): string
    {
        $item    = trim((string)($cfg['catalog_item_id'] ?? ''));
        $project = trim((string)($cfg['project_id'] ?? ''));
        $name    = trim((string)($cfg['deployment_name'] ?? ''));
        if ($item === '' || $project === '' || $name === '') {
            throw new ConnectorException(
                'automation_config needs catalog_item_id, project_id and deployment_name',
                true
            );
        }
        if (!self::isSafeId($item)) {
            throw new ConnectorException('Invalid catalog_item_id', true);
        }

        $body = [
            'deploymentName' => $name,
            'projectId'      => $project,
            // Object, not list: an empty inputs map must serialise as {}.
            'inputs'         => (object)$inputs,
        ];
        if (!empty($cfg['reason'])) {
            $body['reason'] = (string)$cfg['reason'];
        }
        if (!empty($cfg['catalog_item_version'])) {
            $body['version'] = (string)$cfg['catalog_item_version'];
        }

        // A read timeout after the request went out leaves the outcome
        // unknown — Aria may have started the deployment. Fail the job
        // (permanent) rather than resubmit and risk a second VM; the
        // technician checks Aria for the deployment name.
        $resp = $this->request(
            'POST',
            '/catalog/api/items/' . rawurlencode($item) . '/request?apiVersion=' . self::API_VERSION,
            ['json' => $body],
            true
        );

        $code = $resp['code'];
        if ($code >= 200 && $code < 300) {
            $data = $resp['body'];
            $first = (is_array($data) && array_is_list($data)) ? ($data[0] ?? []) : $data;
            $deployment_id = is_array($first) ? (string)($first['deploymentId'] ?? '') : '';
            if ($deployment_id === '') {
                throw new ConnectorException(
                    sprintf('Aria accepted the request but returned no deploymentId — check deployment "%s" in Aria', $name),
                    true
                );
            }
            return $deployment_id;
        }

        throw new ConnectorException(
            sprintf('Aria rejected the catalog request (HTTP %d): %s', $code, self::errorMessage($resp['body'])),
            self::isPermanentStatus($code)
        );
    }

    public function poll(string $id, array $cfg): Result
    {
        if (!self::isSafeId($id)) {
            return Result::failed('Invalid deployment id: ' . $id);
        }

        $resp = $this->request(
            'GET',
            '/deployment/api/deployments/' . rawurlencode($id)
                . '?expand=resources&expand=lastRequest&apiVersion=' . self::API_VERSION
        );

        $code = $resp['code'];
        if ($code < 200 || $code >= 300) {
            // 404 right after submit can be propagation delay: transient,
            // bounded by the Runner's attempt cap like any other error.
            throw new ConnectorException(
                sprintf('Aria deployment lookup failed (HTTP %d): %s', $code, self::errorMessage($resp['body'])),
                $code !== 404 && self::isPermanentStatus($code)
            );
        }

        $dep = is_array($resp['body']) ? $resp['body'] : [];
        $req_status = strtoupper((string)($dep['lastRequest']['status'] ?? ''));
        $dep_status = strtoupper((string)($dep['status'] ?? ''));
        $raw = [
            'status'      => $dep_status,
            'lastRequest' => array_intersect_key(
                (array)($dep['lastRequest'] ?? []),
                array_flip(['id', 'name', 'status', 'details', 'completedAt'])
            ),
        ];

        if (in_array($req_status, ['FAILED', 'ABORTED'], true) || str_ends_with($dep_status, '_FAILED')) {
            $details = trim((string)($dep['lastRequest']['details'] ?? ''));
            return Result::failed($details !== '' ? $details : ('Deployment status ' . ($dep_status ?: $req_status)), $raw);
        }

        if ($req_status === 'SUCCESSFUL' || $dep_status === 'CREATE_SUCCESSFUL') {
            return Result::succeeded(self::extractDetails($id, $dep), '', $raw);
        }

        return Result::pending($req_status ?: $dep_status, $raw);
    }

    public function testConnection(): string
    {
        $this->dropCachedToken();
        $this->getAccessToken(true);

        $resp = $this->request('GET', '/catalog/api/items?size=1&apiVersion=' . self::API_VERSION);
        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            throw new ConnectorException(
                sprintf('Logged in, but listing catalog items failed (HTTP %d): %s', $resp['code'], self::errorMessage($resp['body'])),
                true
            );
        }
        $total = is_array($resp['body']) ? (int)($resp['body']['totalElements'] ?? 0) : 0;
        return sprintf(__('Connected to Aria — %d catalog item(s) visible.', 'tasksmanager'), $total);
    }

    /**
     * Details for the success follow-up: deployment id/name plus the name
     * and IP of every machine resource.
     */
    private static function extractDetails(string $id, array $dep): array
    {
        $details = [
            'Deployment ID'   => $id,
            'Deployment name' => (string)($dep['name'] ?? ''),
        ];

        $names = [];
        $ips   = [];
        foreach ((array)($dep['resources'] ?? []) as $res) {
            if (!is_array($res) || !str_contains((string)($res['type'] ?? ''), 'Machine')) {
                continue;
            }
            $props = (array)($res['properties'] ?? []);
            $names[] = (string)($props['resourceName'] ?? $res['name'] ?? '');
            $ip = (string)($props['address'] ?? ($props['networks'][0]['address'] ?? ''));
            if ($ip !== '') {
                $ips[] = $ip;
            }
        }
        $names = array_filter($names);
        if ($names) {
            $details['VM name'] = implode(', ', $names);
        }
        if ($ips) {
            $details['IP address'] = implode(', ', $ips);
        }
        return array_filter($details, fn ($v) => $v !== '');
    }

    /**
     * Authenticated request with one retry on 401.
     *
     * @return array{code:int, body:mixed}
     * @throws ConnectorException
     */
    private function request(string $method, string $path, array $options = [], bool $unsafe_to_retry = false): array
    {
        $resp = $this->send($method, $path, $options, $this->getAccessToken(), $unsafe_to_retry);
        if ($resp['code'] === 401) {
            $this->dropCachedToken();
            $resp = $this->send($method, $path, $options, $this->getAccessToken(true), $unsafe_to_retry);
        }
        return $resp;
    }

    /**
     * @return array{code:int, body:mixed}
     * @throws ConnectorException
     */
    private function send(string $method, string $path, array $options, ?string $token, bool $unsafe_to_retry = false): array
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
        if ($token !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = self::client()->request($method, self::baseUrl() . $path, $options);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            // Never reached Aria — always safe to retry.
            throw new ConnectorException('Cannot reach Aria: ' . $e->getMessage(), false, $e);
        } catch (\Throwable $e) {
            throw new ConnectorException(
                ($unsafe_to_retry ? 'Aria request outcome unknown (check Aria before retrying): ' : 'Aria request failed: ')
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
    private function getAccessToken(bool $force_refresh = false): string
    {
        if (!$force_refresh) {
            if ($this->accessToken !== null) {
                return $this->accessToken;
            }
            $expires = (int)Config::getConfigValue(self::CFG_ACCESS_EXPIRES, '0');
            $cached  = (string)Config::getConfigValue(self::CFG_ACCESS_TOKEN, '');
            if ($cached !== '' && $expires > time()) {
                $plain = (string)(new \GLPIKey())->decrypt($cached);
                if ($plain !== '') {
                    return $this->accessToken = $plain;
                }
            }
        }

        $enc_refresh = (string)Config::getConfigValue(self::CFG_REFRESH_TOKEN, '');
        $refresh = $enc_refresh !== '' ? (string)(new \GLPIKey())->decrypt($enc_refresh) : '';
        if ($refresh === '') {
            throw new ConnectorException('Aria refresh token is not configured', true);
        }

        $resp = $this->send('POST', '/iaas/api/login', ['json' => ['refreshToken' => $refresh]], null);
        $token = is_array($resp['body']) ? (string)($resp['body']['token'] ?? '') : '';
        if ($resp['code'] < 200 || $resp['code'] >= 300 || $token === '') {
            // 400/401/403 here means the refresh token itself is bad —
            // retrying every 2 minutes won't fix it.
            throw new ConnectorException(
                sprintf('Aria login failed (HTTP %d): %s', $resp['code'], self::errorMessage($resp['body'])),
                in_array($resp['code'], [400, 401, 403], true)
            );
        }

        Config::setConfigValue(self::CFG_ACCESS_TOKEN, (string)(new \GLPIKey())->encrypt($token));
        Config::setConfigValue(self::CFG_ACCESS_EXPIRES, (string)(time() + self::TOKEN_TTL));
        return $this->accessToken = $token;
    }

    private function dropCachedToken(): void
    {
        $this->accessToken = null;
        Config::setConfigValue(self::CFG_ACCESS_TOKEN, '');
        Config::setConfigValue(self::CFG_ACCESS_EXPIRES, '0');
    }

    /**
     * @throws ConnectorException
     */
    private static function baseUrl(): string
    {
        $url = rtrim(trim((string)Config::getConfigValue(self::CFG_URL, '')), '/');
        if (!self::isValidUrl($url)) {
            throw new ConnectorException('Aria URL is not configured (https://… expected)', true);
        }
        return $url;
    }

    public static function isValidUrl(string $url): bool
    {
        return $url !== ''
            && str_starts_with(strtolower($url), 'https://')
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** GLPI's Guzzle client (honours the proxy settings), with our timeouts. */
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

    /** Aria ids are UUIDs; reject anything that could alter the path. */
    private static function isSafeId(string $id): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9._-]{1,128}$/', $id);
    }

    /** 4xx other than 401 / 408 / 429 won't succeed on retry. */
    private static function isPermanentStatus(int $code): bool
    {
        return $code >= 400 && $code < 500 && !in_array($code, [401, 408, 429], true);
    }

    private static function errorMessage(mixed $body): string
    {
        if (is_array($body)) {
            foreach (['message', 'serverMessage', 'error'] as $k) {
                if (!empty($body[$k]) && is_string($body[$k])) {
                    return $body[$k];
                }
            }
            return mb_substr((string)json_encode($body), 0, 500);
        }
        return mb_substr(trim(strip_tags((string)$body)), 0, 500);
    }
}
