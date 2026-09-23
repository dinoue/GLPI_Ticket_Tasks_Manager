<?php

namespace GlpiPlugin\Tasksmanager\Automation;

use CronTask;
use GlpiPlugin\Tasksmanager\Workflow;

/**
 * Runner — the automationjobs cron (registered in the install hook,
 * every 120 s). Drives glpi_plugin_tasksmanager_automation_jobs:
 *
 *   queued ──submit──▶ submitting ──▶ submitted ──poll──▶ succeeded
 *      │                    │               │
 *      └─ step no longer ── └─ crash ─────── └─────────────▶ failed
 *         current: cancelled   (stale)
 *
 * succeeded: post a follow-up (VM name, IP, deployment id), then set the
 *   step's task to Done. The existing ITEM_UPDATE hook advances the
 *   workflow from there, so routing, reassignment and notifications are
 *   the same as when a human ticks the box.
 * failed: reassign the ticket to automation_config.failure_groups_id and
 *   post a private follow-up with the error. The task stays To do: a
 *   human finishes the step. The step SLA covers jobs that hang.
 *
 * `attempts` counts consecutive transport errors (network, 5xx); it is
 * reset by any successful call. MAX_ATTEMPTS in a row fails the job.
 */
class Runner
{
    public const MAX_ATTEMPTS            = 5;
    public const BATCH_SIZE              = 20;
    public const DEFAULT_TIMEOUT_MINUTES = 240;
    /** A job left in `submitting` this long means the run died mid-call. */
    private const STALE_SUBMIT_SECONDS   = 600;

    private const TABLE = 'glpi_plugin_tasksmanager_automation_jobs';

    /** @var array<string, ConnectorInterface> one instance per run (token reuse) */
    private static array $connectors = [];

    public static function cronInfo(string $name): array
    {
        switch ($name) {
            case 'automationjobs':
                return ['description' => __('Tasks Manager: run automation steps (submit and poll)', 'tasksmanager')];
        }
        return [];
    }

    /**
     * @return int 1 if any job moved this run, 0 otherwise.
     */
    public static function cronAutomationjobs(CronTask $task): int
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 0;
        }

        $done = self::failStaleSubmits();

        foreach (self::jobs('queued') as $job) {
            if (self::processQueued($job)) {
                $done++;
            }
        }
        foreach (self::jobs('submitted') as $job) {
            if (self::processSubmitted($job)) {
                $done++;
            }
        }

        $task->addVolume($done);
        return $done > 0 ? 1 : 0;
    }

    private static function jobs(string $status): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['status' => $status],
            'ORDER' => ['id ASC'],
            'LIMIT' => self::BATCH_SIZE,
        ]), false);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  queued → submitted
    // ─────────────────────────────────────────────────────────────────────

    private static function processQueued(array $job): bool
    {
        global $DB;

        $job_id = (int)$job['id'];

        // Skipped / restarted past / workflow removed while queued: don't
        // provision for a step that is no longer running.
        if (!self::isStillCurrent($job)) {
            self::setStatus($job_id, 'cancelled', ['date_completed' => date('Y-m-d H:i:s')]);
            self::log('automation_cancelled', $job, ['reason' => 'step_not_current']);
            return true;
        }

        try {
            [$connector, $cfg] = self::load($job);
            $inputs = Mapping::resolveInputs((array)($cfg['inputs'] ?? []), (int)$job['tickets_id']);
            $cfg    = Mapping::resolveConfig($cfg, (int)$job['tickets_id']);
            if (empty($cfg['deployment_name'])) {
                $cfg['deployment_name'] = sprintf('GLPI-%d-%d', (int)$job['tickets_id'], $job_id);
            }
        } catch (MappingException | ConnectorException $e) {
            self::fail($job, $e->getMessage());
            return true;
        }

        // Claim before the remote call. If this process dies after the
        // request left, the row stays `submitting` and failStaleSubmits()
        // hands it to a human instead of submitting it again.
        $DB->update(
            self::TABLE,
            [
                'status'          => 'submitting',
                'request_payload' => self::encode(['inputs' => $inputs, 'config' => $cfg]),
            ],
            ['id' => $job_id, 'status' => 'queued']
        );
        if ($DB->affectedRows() < 1) {
            return false; // Someone else took it.
        }

        try {
            $external_id = $connector->submit($inputs, $cfg);
        } catch (ConnectorException $e) {
            return self::handleError($job, $e, 'queued');
        }

        self::setStatus($job_id, 'submitted', [
            'external_id'    => mb_substr($external_id, 0, 255),
            'attempts'       => 0,
            'last_error'     => null,
            'date_submitted' => date('Y-m-d H:i:s'),
        ]);
        self::log('automation_submitted', $job, [
            'connector'   => $connector->getName(),
            'external_id' => $external_id,
        ]);
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  submitted → succeeded | failed
    // ─────────────────────────────────────────────────────────────────────

    private static function processSubmitted(array $job): bool
    {
        try {
            [$connector, $cfg] = self::load($job);
        } catch (ConnectorException $e) {
            self::fail($job, $e->getMessage());
            return true;
        }

        $timeout = (int)($cfg['timeout_minutes'] ?? self::DEFAULT_TIMEOUT_MINUTES);
        if ($timeout <= 0) {
            $timeout = self::DEFAULT_TIMEOUT_MINUTES;
        }
        $submitted_at = strtotime((string)$job['date_submitted']);
        if ($submitted_at !== false && time() - $submitted_at > $timeout * 60) {
            self::fail($job, sprintf('No result after %d minutes (external id %s)', $timeout, $job['external_id']));
            return true;
        }

        try {
            $result = $connector->poll((string)$job['external_id'], $cfg);
        } catch (ConnectorException $e) {
            return self::handleError($job, $e, 'submitted');
        }

        if ($result->isPending()) {
            self::setStatus((int)$job['id'], 'submitted', [
                'attempts'   => 0,
                'last_error' => null,
                'response'   => self::encode($result->raw),
            ]);
            return false;
        }

        if ($result->isSucceeded()) {
            self::succeed($job, $cfg, $result);
        } else {
            self::fail($job, $result->message, $result->raw);
        }
        return true;
    }

    private static function succeed(array $job, array $cfg, Result $result): void
    {
        self::setStatus((int)$job['id'], 'succeeded', [
            'attempts'       => 0,
            'last_error'     => null,
            'response'       => self::encode($result->raw),
            'date_completed' => date('Y-m-d H:i:s'),
        ]);

        // Follow-up first: ticking the task below advances the workflow,
        // and the next step's notifications should come after this one.
        $rows = '';
        foreach ($result->details as $label => $value) {
            $rows .= '<li><b>' . htmlspecialchars((string)$label) . '</b>: '
                . htmlspecialchars((string)$value) . '</li>';
        }
        self::addFollowup(
            (int)$job['tickets_id'],
            '<p><i class="ti ti-robot"></i> '
                . htmlspecialchars(__('Automation completed successfully.', 'tasksmanager')) . '</p>'
                . ($rows !== '' ? '<ul>' . $rows . '</ul>' : ''),
            !empty($cfg['success_followup_private'])
        );

        self::log('automation_succeeded', $job, ['details' => $result->details]);

        if (self::isStillCurrent($job)) {
            // Plain update, hook not suppressed: plugin_tasksmanager_item_update
            // sees state=2 and runs Workflow::advanceFrom().
            $task = new \TicketTask();
            $task->update(['id' => (int)$job['tickettasks_id'], 'state' => 2]);
        }
    }

    private static function fail(array $job, string $message, array $raw = []): void
    {
        $message = trim($message) !== '' ? trim($message) : __('Unknown error', 'tasksmanager');

        $update = [
            'last_error'     => mb_substr($message, 0, 2000),
            'date_completed' => date('Y-m-d H:i:s'),
        ];
        if ($raw) {
            $update['response'] = self::encode($raw);
        }
        self::setStatus((int)$job['id'], 'failed', $update);

        $current = self::isStillCurrent($job);

        // Reassign BEFORE posting: swapAssignActors is silent, so the
        // private follow-up is what notifies the fallback team.
        $cfg = self::decodeConfig($job);
        $group = (int)($cfg['failure_groups_id'] ?? 0);
        if ($current && $group > 0) {
            Workflow::swapAssignActors((int)$job['tickets_id'], 0, $group);
        }

        self::addFollowup(
            (int)$job['tickets_id'],
            '<p><i class="ti ti-alert-triangle"></i> '
                . htmlspecialchars(__('Automation failed — the step needs to be completed manually.', 'tasksmanager'))
                . '</p><pre>' . htmlspecialchars($message) . '</pre>',
            true
        );

        self::log('automation_failed', $job, [
            'error'             => mb_substr($message, 0, 500),
            'failure_groups_id' => $current ? $group : 0,
        ]);
    }

    /**
     * Transient errors go back to $retry_status until MAX_ATTEMPTS in a
     * row; permanent ones fail the job at once.
     */
    private static function handleError(array $job, ConnectorException $e, string $retry_status): bool
    {
        $attempts = (int)$job['attempts'] + 1;
        if ($e->permanent || $attempts >= self::MAX_ATTEMPTS) {
            $msg = $e->permanent
                ? $e->getMessage()
                : sprintf('%s (gave up after %d attempts)', $e->getMessage(), $attempts);
            self::fail($job, $msg);
            return true;
        }
        self::setStatus((int)$job['id'], $retry_status, [
            'attempts'   => $attempts,
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
        ]);
        return false;
    }

    private static function failStaleSubmits(): int
    {
        global $DB;

        $count = 0;
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'status'   => 'submitting',
                'date_mod' => ['<', date('Y-m-d H:i:s', time() - self::STALE_SUBMIT_SECONDS)],
            ],
        ]) as $job) {
            self::fail($job, 'Submission was interrupted; its outcome is unknown. Check the remote system before retrying.');
            $count++;
        }
        return $count;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Is the job's step still the active step of an active workflow, with
     * its task not yet done?
     */
    private static function isStillCurrent(array $job): bool
    {
        global $DB;

        $tw = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE' => ['id' => (int)$job['ticket_workflows_id'], 'status' => 'active'],
            'LIMIT' => 1,
        ]);
        if (count($tw) === 0 || (int)$tw->current()['current_step'] !== (int)$job['step_order']) {
            return false;
        }

        $task = new \TicketTask();
        return (int)$job['tickettasks_id'] > 0
            && $task->getFromDB((int)$job['tickettasks_id'])
            && (int)$task->fields['state'] !== 2;
    }

    /**
     * @return array{0: ConnectorInterface, 1: array}
     * @throws ConnectorException
     */
    private static function load(array $job): array
    {
        $cfg = self::decodeConfig($job);
        if ($cfg === null) {
            throw new ConnectorException('The step has no valid automation_config', true);
        }

        $name = (string)($cfg['connector'] ?? $job['connector'] ?? AriaConnector::NAME);
        if (!isset(self::$connectors[$name])) {
            self::$connectors[$name] = match ($name) {
                AriaConnector::NAME => new AriaConnector(),
                default             => throw new ConnectorException('Unknown connector: ' . $name, true),
            };
        }
        return [self::$connectors[$name], $cfg];
    }

    /** The step's automation_config, read live (null if missing / invalid). */
    private static function decodeConfig(array $job): ?array
    {
        global $DB;

        if ((int)$job['workflow_steps_id'] <= 0) {
            return null;
        }
        $iter = $DB->request([
            'SELECT' => ['automation_config'],
            'FROM'   => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE'  => ['id' => (int)$job['workflow_steps_id']],
            'LIMIT'  => 1,
        ]);
        if (count($iter) === 0) {
            return null;
        }
        $cfg = json_decode((string)$iter->current()['automation_config'], true);
        return is_array($cfg) ? $cfg : null;
    }

    private static function setStatus(int $job_id, string $status, array $fields = []): void
    {
        global $DB;

        $DB->update(self::TABLE, ['status' => $status] + $fields, ['id' => $job_id]);
    }

    private static function addFollowup(int $tickets_id, string $html, bool $private): void
    {
        try {
            $fup = new \ITILFollowup();
            $fup->add([
                'itemtype'   => 'Ticket',
                'items_id'   => $tickets_id,
                'content'    => $html,
                'is_private' => $private ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            // A follow-up glitch must not abort the cron loop.
        }
    }

    private static function log(string $event, array $job, array $details = []): void
    {
        global $DB;

        $tw = $DB->request([
            'SELECT' => ['workflows_id'],
            'FROM'   => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE'  => ['id' => (int)$job['ticket_workflows_id']],
            'LIMIT'  => 1,
        ]);
        Workflow::logEvent(
            $event,
            (int)$job['tickets_id'],
            count($tw) > 0 ? (int)$tw->current()['workflows_id'] : 0,
            (int)$job['ticket_workflows_id'],
            (int)$job['step_order'],
            ['automation_jobs_id' => (int)$job['id']] + $details
        );
    }

    private static function encode(array $data): string
    {
        return mb_substr((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 60000);
    }
}
