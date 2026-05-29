<?php

namespace GlpiPlugin\Tasksmanager;

use CronTask;

/**
 * Sla — per-step SLA + escalation engine for Tasks Manager workflows.
 *
 * GLPI's native SLA is ticket-wide only. This adds a deadline to each
 * individual workflow STEP: "this step may stay current for at most N
 * seconds; warn at X% of that, and on breach do <action>."
 *
 * The check runs from a GLPI cron task (registered in the install hook):
 *   GlpiPlugin\Tasksmanager\Sla::cronWorkflowSla(CronTask $task)
 *
 * State / dedup: we never add SLA-tracking columns to ticket_workflows.
 * Instead we reuse the audit log (glpi_plugin_tasksmanager_workflow_events)
 * as the idempotency store — once a `step_sla_warning` / `step_sla_breached`
 * event exists for the current step instance, we don't fire again. To
 * survive a step Restart (same step_order, fresh task), dedup only counts
 * events dated at-or-after the *current* task's start time.
 */
class Sla
{
    /** Breach actions. */
    public const ACTION_NOTIFY      = 'notify';
    public const ACTION_REASSIGN    = 'reassign';
    public const ACTION_SKIP        = 'skip';
    public const ACTION_PRIORITY_UP = 'priority_up';

    public const ACTIONS = [
        self::ACTION_NOTIFY,
        self::ACTION_REASSIGN,
        self::ACTION_SKIP,
        self::ACTION_PRIORITY_UP,
    ];

    /** Cron frequency in seconds (5 minutes). */
    public const CRON_FREQUENCY = 300;

    /**
     * Cron description shown on Setup → Automatic actions.
     */
    public static function cronInfo(string $name): array
    {
        switch ($name) {
            case 'WorkflowSla':
                return ['description' => __('Tasks Manager: per-step SLA check & escalation', 'tasksmanager')];
        }
        return [];
    }

    /**
     * Cron entry point. Evaluates every active workflow whose current step
     * has an SLA configured, firing warnings / breach actions as needed.
     *
     * @return int 1 if any warning/breach fired this run, 0 otherwise.
     */
    public static function cronWorkflowSla(CronTask $task): int
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_tasksmanager_ticket_workflows')
            || !$DB->fieldExists('glpi_plugin_tasksmanager_workflow_steps', 'sla_duration')
        ) {
            return 0;
        }

        // Active workflows. For each, we look up its current step separately
        // (a JOIN on `wfs.step_order = tw.current_step` needs a raw column
        // comparison that the query builder expresses awkwardly; a small
        // per-workflow lookup is clearer and the active-workflow set is
        // small in practice).
        $tw_iter = $DB->request([
            'SELECT' => [
                'id AS tw_id',
                'tickets_id',
                'workflows_id',
                'current_step',
            ],
            'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE' => ['status' => 'active'],
        ]);

        $fired = 0;
        foreach ($tw_iter as $tw) {
            $step = $DB->request([
                'SELECT' => [
                    'id AS step_id',
                    'sla_duration',
                    'sla_warning_pct',
                    'sla_breach_action',
                    'sla_breach_groups_id',
                    'sla_breach_users_id',
                    'sla_use_calendar',
                    'olas_id',
                ],
                'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
                'WHERE' => [
                    'workflows_id' => (int)$tw['workflows_id'],
                    'step_order'   => (int)$tw['current_step'],
                    // SLA-enabled = custom duration OR a referenced OLA.
                    'OR' => [
                        ['sla_duration' => ['>', 0]],
                        ['olas_id'      => ['>', 0]],
                    ],
                ],
                'LIMIT' => 1,
            ]);
            if (count($step) === 0) {
                continue; // Current step has no SLA configured.
            }

            $row = $tw + $step->current();
            if (self::evaluateAndAct($row)) {
                $fired++;
                $task->addVolume(1);
            }
        }

        return $fired > 0 ? 1 : 0;
    }

    /**
     * Evaluate one active step instance and fire a warning or breach if the
     * thresholds are crossed and we haven't already acted.
     *
     * @return bool true if a warning or breach was fired this call.
     */
    private static function evaluateAndAct(array $row): bool
    {
        $tw_id        = (int)$row['tw_id'];
        $tickets_id   = (int)$row['tickets_id'];
        $workflows_id = (int)$row['workflows_id'];
        $step_order   = (int)$row['current_step'];
        $warning_pct  = $row['sla_warning_pct'] === null ? 0 : (int)$row['sla_warning_pct'];
        $action       = (string)$row['sla_breach_action'];
        $use_calendar = (int)$row['sla_use_calendar'] === 1;
        $olas_id      = (int)($row['olas_id'] ?? 0);

        // When did the current step start? = creation of its workflow task.
        $start_ts = self::getStepStart($tw_id, $step_order);
        if ($start_ts === null) {
            return false; // No task yet — nothing to measure.
        }

        // Budget + elapsed come from a referenced GLPI OLA when one is set
        // (hybrid mode), else from the step's custom duration/calendar.
        if ($olas_id > 0) {
            [$duration, $elapsed] = self::resolveFromOla($olas_id, $start_ts, $tickets_id);
        } else {
            $duration = (int)$row['sla_duration'];
            $elapsed  = self::computeElapsed($start_ts, $tickets_id, $use_calendar);
        }

        if ($duration <= 0) {
            return false;
        }

        // ── Breach ───────────────────────────────────────────────────────
        if ($elapsed >= $duration) {
            if (self::hasEventSince($tw_id, $step_order, 'step_sla_breached', $start_ts)) {
                return false; // Already actioned this instance.
            }

            $details = [
                'elapsed_seconds'  => $elapsed,
                'sla_duration'     => $duration,
                'breach_action'    => $action,
                'use_calendar'     => $use_calendar,
            ];

            self::executeBreachAction($action, $row, $details);

            Workflow::logEvent(
                'step_sla_breached',
                $tickets_id,
                $workflows_id,
                $tw_id,
                $step_order,
                $details
            );
            return true;
        }

        // ── Warning ──────────────────────────────────────────────────────
        if ($warning_pct > 0) {
            $threshold = (int)floor($duration * $warning_pct / 100);
            if ($elapsed >= $threshold) {
                if (self::hasEventSince($tw_id, $step_order, 'step_sla_warning', $start_ts)) {
                    return false;
                }

                self::addFollowup(
                    $tickets_id,
                    sprintf(
                        __('SLA warning: workflow step has used %1$d%% of its %2$s budget (%3$s elapsed).', 'tasksmanager'),
                        $warning_pct,
                        \Html::timestampToString($duration, true),
                        \Html::timestampToString($elapsed, true)
                    )
                );

                Workflow::logEvent(
                    'step_sla_warning',
                    $tickets_id,
                    $workflows_id,
                    $tw_id,
                    $step_order,
                    [
                        'elapsed_seconds' => $elapsed,
                        'sla_duration'    => $duration,
                        'warning_pct'     => $warning_pct,
                    ]
                );
                return true;
            }
        }

        return false;
    }

    /**
     * Hybrid mode: derive [budget_seconds, elapsed_seconds] from a GLPI OLA.
     *
     * The OLA's number_time/definition_time gives the budget; its parent
     * SLM's calendar (if any) gives working-hours elapsed via
     * LevelAgreement::getActiveTimeBetween. Returns [0, 0] if the OLA can't
     * be loaded, so the caller treats the step as having no SLA.
     */
    private static function resolveFromOla(int $olas_id, int $start_ts, int $tickets_id): array
    {
        $ola = new \OLA();
        if (!$ola->getFromDB($olas_id)) {
            return [0, 0];
        }

        $duration = (int)$ola->getTime();
        if ($duration <= 0) {
            return [0, 0];
        }

        // Resolve the calendar the OLA should clock against and write it
        // into $ola->fields['calendars_id'] so getActiveTimeBetween() picks
        // it up (it reads that field; 0 → raw wall-clock).
        //   - use_ticket_calendar = the ticket's entity calendar
        //   - otherwise           = the parent SLM's calendar
        $cal_id = 0;
        if (!empty($ola->fields['use_ticket_calendar'])) {
            $cal = self::resolveCalendar($tickets_id);
            $cal_id = ($cal !== null) ? (int)$cal->fields['id'] : 0;
        } else {
            $slm = new \SLM();
            if (!empty($ola->fields['slms_id']) && $slm->getFromDB((int)$ola->fields['slms_id'])) {
                $cal_id = (int)($slm->fields['calendars_id'] ?? 0);
            }
        }
        $ola->fields['calendars_id'] = $cal_id;

        $now = time();
        $elapsed = (int)$ola->getActiveTimeBetween(
            date('Y-m-d H:i:s', $start_ts),
            date('Y-m-d H:i:s', $now)
        );

        return [$duration, max(0, $elapsed)];
    }

    /**
     * Step start = date_creation of the taskstate row for the current
     * (ticket_workflows_id, step_order). Returns a unix timestamp or null.
     */
    private static function getStepStart(int $tw_id, int $step_order): ?int
    {
        global $DB;

        $iter = $DB->request([
            'SELECT' => ['date_creation'],
            'FROM'   => 'glpi_plugin_tasksmanager_taskstates',
            'WHERE'  => [
                'ticket_workflows_id' => $tw_id,
                'workflow_step_order' => $step_order,
            ],
            'ORDER'  => ['date_creation DESC'],
            'LIMIT'  => 1,
        ]);
        if (count($iter) === 0) {
            return null;
        }
        $dc = $iter->current()['date_creation'] ?? null;
        if (empty($dc)) {
            return null;
        }
        $ts = strtotime($dc);
        return $ts === false ? null : $ts;
    }

    /**
     * Seconds elapsed since the step started. Raw wall-clock by default;
     * if $use_calendar and the ticket's entity has a calendar configured,
     * counts only active (working-hours) time via Calendar::getActiveTimeBetween.
     */
    private static function computeElapsed(int $start_ts, int $tickets_id, bool $use_calendar): int
    {
        $now = time();
        if ($now <= $start_ts) {
            return 0;
        }

        if ($use_calendar) {
            $cal = self::resolveCalendar($tickets_id);
            if ($cal !== null) {
                $active = (int)$cal->getActiveTimeBetween(
                    date('Y-m-d H:i:s', $start_ts),
                    date('Y-m-d H:i:s', $now)
                );
                // getActiveTimeBetween returns 0 outside working hours; that's
                // intentional — the SLA clock pauses overnight / weekends.
                return max(0, $active);
            }
        }

        return $now - $start_ts;
    }

    /**
     * Resolve the working-hours calendar for a ticket's entity, or null.
     */
    private static function resolveCalendar(int $tickets_id): ?\Calendar
    {
        global $DB;

        $t = $DB->request([
            'SELECT' => ['entities_id'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => $tickets_id],
            'LIMIT'  => 1,
        ]);
        if (count($t) === 0) {
            return null;
        }
        $entities_id = (int)$t->current()['entities_id'];

        $cal_id = (int)\Entity::getUsedConfig('calendars_strategy', $entities_id, 'calendars_id', 0);
        if ($cal_id <= 0) {
            return null;
        }
        $cal = new \Calendar();
        if (!$cal->getFromDB($cal_id)) {
            return null;
        }
        return $cal;
    }

    /**
     * Has a given event type already been logged for this step instance at
     * or after the current task's start? Keying on start time means a step
     * Restart (which creates a fresh task with a later start) is treated as
     * a new instance and can warn/breach again.
     */
    private static function hasEventSince(int $tw_id, int $step_order, string $event_type, int $since_ts): bool
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_tasksmanager_workflow_events')) {
            return false;
        }

        $iter = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => 'glpi_plugin_tasksmanager_workflow_events',
            'WHERE' => [
                'ticket_workflows_id' => $tw_id,
                'step_order'          => $step_order,
                'event_type'          => $event_type,
                'date_creation'       => ['>=', date('Y-m-d H:i:s', $since_ts)],
            ],
        ]);
        return count($iter) > 0 && (int)$iter->current()['cnt'] > 0;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Breach actions
    // ─────────────────────────────────────────────────────────────────────

    private static function executeBreachAction(string $action, array $row, array $details): void
    {
        $tickets_id = (int)$row['tickets_id'];
        $tw_id      = (int)$row['tw_id'];

        switch ($action) {
            case self::ACTION_REASSIGN:
                $g = (int)$row['sla_breach_groups_id'];
                $u = (int)$row['sla_breach_users_id'];
                if ($g > 0 || $u > 0) {
                    Workflow::swapAssignActors($tickets_id, $u, $g);
                }
                self::addFollowup(
                    $tickets_id,
                    sprintf(
                        __('SLA breached on workflow step — ticket reassigned for escalation (%s).', 'tasksmanager'),
                        \Html::timestampToString((int)$details['sla_duration'], true)
                    )
                );
                break;

            case self::ACTION_SKIP:
                self::addFollowup(
                    $tickets_id,
                    __('SLA breached on workflow step — auto-skipping to the next step.', 'tasksmanager')
                );
                Workflow::skipCurrentStep($tw_id);
                break;

            case self::ACTION_PRIORITY_UP:
                self::bumpPriority($tickets_id);
                self::addFollowup(
                    $tickets_id,
                    __('SLA breached on workflow step — ticket priority raised.', 'tasksmanager')
                );
                break;

            case self::ACTION_NOTIFY:
            default:
                self::addFollowup(
                    $tickets_id,
                    sprintf(
                        __('SLA breached on workflow step — the %s budget was exceeded.', 'tasksmanager'),
                        \Html::timestampToString((int)$details['sla_duration'], true)
                    )
                );
                break;
        }
    }

    /**
     * Raise the ticket's priority by one level (capped at Very high = 5,
     * leaving Major=6 for human judgement). No-op if already ≥ 5.
     */
    private static function bumpPriority(int $tickets_id): void
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return;
        }
        $current = (int)($ticket->fields['priority'] ?? 0);
        $target  = min($current + 1, 5);
        if ($target > $current) {
            $ticket->update([
                'id'       => $tickets_id,
                'priority' => $target,
            ]);
        }
    }

    /**
     * Add a private followup to the ticket. GLPI sends its native
     * "new followup" notification to the ticket's actors, so the assigned
     * team is alerted without us having to build a NotificationTarget.
     * Best-effort — never throws into the cron loop.
     */
    private static function addFollowup(int $tickets_id, string $message): void
    {
        try {
            $fup = new \ITILFollowup();
            $fup->add([
                'itemtype'   => 'Ticket',
                'items_id'   => $tickets_id,
                'content'    => '<p><i class="ti ti-alarm"></i> ' . \Html::entities_deep($message) . '</p>',
                'is_private' => 1,
            ]);
        } catch (\Throwable $e) {
            // Swallow — a notification glitch must not abort the SLA sweep.
        }
    }
}
