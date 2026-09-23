<?php

namespace GlpiPlugin\Tasksmanager\Automation;

use GlpiPlugin\Tasksmanager\Profile;
use GlpiPlugin\Tasksmanager\Workflow;

/**
 * Provision — the review step for vSphere automation jobs.
 *
 * A step with "require_review": true queues its job as `draft`. A
 * technician opens front/provision.form.php from the ticket's Workflow
 * tab: the fields are prefilled from the form answers (Mapping), and the
 * dropdowns list real vCenter objects. Deploy re-validates everything,
 * checks the VM name is free, stores the approved values in
 * request_payload ('reviewed' => true) and moves the job to `queued`.
 */
class Provision
{
    /** Fields shown on the review page, in order. */
    public const FIELDS = [
        'template', 'vm_name', 'cluster', 'folder', 'datastore', 'network',
        'cpu', 'cores_per_socket', 'memory_gb', 'disks',
        'customization_spec', 'ip', 'prefix', 'gateway',
    ];

    private const TABLE = 'glpi_plugin_tasksmanager_automation_jobs';

    /** The job row, or null if it doesn't exist. */
    public static function getJob(int $job_id): ?array
    {
        global $DB;

        $iter = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $job_id], 'LIMIT' => 1]);
        return count($iter) > 0 ? $iter->current() : null;
    }

    /** The job (any status) for one step instance, if any. */
    public static function getCurrentJob(int $ticket_workflows_id, int $step_order): ?array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return null;
        }
        $iter = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['ticket_workflows_id' => $ticket_workflows_id, 'step_order' => $step_order],
            'LIMIT' => 1,
        ]);
        return count($iter) > 0 ? $iter->current() : null;
    }

    /** Right to deploy + right to update this ticket (entity scope). */
    public static function canDeploy(array $job): bool
    {
        if (!\Session::haveRight(Profile::RIGHT_PROVISION, UPDATE)) {
            return false;
        }
        $ticket = new \Ticket();
        return $ticket->getFromDB((int)$job['tickets_id']) && $ticket->canUpdateItem();
    }

    /**
     * Initial values from the step's input mapping. Each field is resolved
     * on its own so one bad answer (unmapped OS) shows as an error next to
     * its field instead of blanking the page.
     *
     * @return array{0: array, 1: array<string,string>} [values, errors]
     */
    public static function prefill(array $job): array
    {
        global $DB;

        $values = array_fill_keys(self::FIELDS, '');
        $values['disks'] = [];
        $values['cores_per_socket'] = 1;
        $values['prefix'] = 24;
        $errors = [];

        $iter = $DB->request([
            'SELECT' => ['automation_config'],
            'FROM'   => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE'  => ['id' => (int)$job['workflow_steps_id']],
            'LIMIT'  => 1,
        ]);
        $cfg = count($iter) > 0 ? json_decode((string)$iter->current()['automation_config'], true) : null;
        foreach ((array)($cfg['inputs'] ?? []) as $key => $spec) {
            if (!in_array($key, self::FIELDS, true)) {
                continue;
            }
            try {
                $value = Mapping::resolve($spec, (int)$job['tickets_id'], (string)$key);
                if ($value !== null && $value !== '') {
                    $values[$key] = $value;
                }
            } catch (MappingException $e) {
                $errors[$key] = $e->getMessage();
            }
        }
        $values['disks'] = array_values(array_filter(array_map('intval', (array)$values['disks'])));
        return [$values, $errors];
    }

    /**
     * Validate the posted review form.
     *
     * @return array{0: array, 1: array<string,string>} [clean values, errors]
     */
    public static function validate(array $post): array
    {
        $v = [];
        $e = [];

        foreach (['template', 'cluster', 'datastore'] as $k) {
            $v[$k] = trim((string)($post[$k] ?? ''));
            if ($v[$k] === '' || !VsphereConnector::isSafeId($v[$k])) {
                $e[$k] = __('Required', 'tasksmanager');
            }
        }
        foreach (['folder', 'network'] as $k) {
            $v[$k] = trim((string)($post[$k] ?? ''));
            if ($v[$k] !== '' && !VsphereConnector::isSafeId($v[$k])) {
                $e[$k] = __('Invalid value', 'tasksmanager');
            }
        }

        // DNS label rules; Windows (NetBIOS) additionally caps at 15.
        $v['vm_name'] = trim((string)($post['vm_name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $v['vm_name'])) {
            $e['vm_name'] = __('Letters, digits and hyphens only; must not start or end with a hyphen.', 'tasksmanager');
        }

        $ints = [
            'cpu'              => [1, 128],
            'cores_per_socket' => [1, 128],
            'memory_gb'        => [1, 4096],
        ];
        foreach ($ints as $k => [$min, $max]) {
            $raw = trim((string)($post[$k] ?? ''));
            if ($raw === '') {
                $v[$k] = '';
                continue;
            }
            if (!ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) {
                $e[$k] = sprintf(__('Between %1$d and %2$d', 'tasksmanager'), $min, $max);
            }
            $v[$k] = (int)$raw;
        }
        if ($v['cpu'] !== '' && $v['cores_per_socket'] !== '' && $v['cpu'] % $v['cores_per_socket'] !== 0) {
            $e['cores_per_socket'] = __('CPU count must be a multiple of cores per socket', 'tasksmanager');
        }

        $v['disks'] = [];
        foreach ((array)($post['disks'] ?? []) as $size) {
            $size = trim((string)$size);
            if ($size === '') {
                continue;
            }
            if (!ctype_digit($size) || (int)$size < 1 || (int)$size > 65536) {
                $e['disks'] = __('Disk sizes are whole GB between 1 and 65536', 'tasksmanager');
                continue;
            }
            $v['disks'][] = (int)$size;
        }
        if (count($v['disks']) > 16) {
            $e['disks'] = __('At most 16 extra disks', 'tasksmanager');
        }

        $v['customization_spec'] = trim((string)($post['customization_spec'] ?? ''));
        if (mb_strlen($v['customization_spec']) > 255) {
            $e['customization_spec'] = __('Invalid value', 'tasksmanager');
        }

        $v['ip']      = trim((string)($post['ip'] ?? ''));
        $v['gateway'] = trim((string)($post['gateway'] ?? ''));
        $v['prefix']  = (int)($post['prefix'] ?? 24);
        if ($v['ip'] !== '') {
            if (filter_var($v['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $e['ip'] = __('Not a valid IPv4 address', 'tasksmanager');
            }
            if ($v['prefix'] < 1 || $v['prefix'] > 32) {
                $e['prefix'] = sprintf(__('Between %1$d and %2$d', 'tasksmanager'), 1, 32);
            }
            if ($v['gateway'] !== '' && filter_var($v['gateway'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $e['gateway'] = __('Not a valid IPv4 address', 'tasksmanager');
            }
            if ($v['customization_spec'] === '') {
                $e['customization_spec'] = __('A static IP needs a customization spec', 'tasksmanager');
            }
        }

        return [$v, $e];
    }

    /**
     * Approve a draft: final name check, store the values, queue the job.
     *
     * @return string|null error message, or null when queued
     */
    public static function approve(array $job, array $values, VsphereConnector $vcenter): ?string
    {
        global $DB;

        if ($job['status'] !== 'draft') {
            return __('This deployment has already been launched.', 'tasksmanager');
        }
        try {
            if ($vcenter->vmNameExists((string)$values['vm_name'])) {
                return sprintf(__('A VM named "%s" already exists in vCenter.', 'tasksmanager'), $values['vm_name']);
            }
        } catch (ConnectorException $e) {
            return $e->getMessage();
        }

        $inputs = array_filter($values, fn ($x) => $x !== '' && $x !== []);
        $DB->update(
            self::TABLE,
            [
                'status'          => 'queued',
                'request_payload' => json_encode([
                    'reviewed'    => true,
                    'reviewed_by' => (int)\Session::getLoginUserID(),
                    'reviewed_at' => date('Y-m-d H:i:s'),
                    'inputs'      => $inputs,
                    'config'      => [],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            ['id' => (int)$job['id'], 'status' => 'draft']
        );
        if ($DB->affectedRows() < 1) {
            return __('This deployment has already been launched.', 'tasksmanager');
        }

        Workflow::logEvent(
            'automation_reviewed',
            (int)$job['tickets_id'],
            0,
            (int)$job['ticket_workflows_id'],
            (int)$job['step_order'],
            ['automation_jobs_id' => (int)$job['id'], 'vm_name' => $values['vm_name']]
        );
        return null;
    }
}
