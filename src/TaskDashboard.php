<?php

namespace GlpiPlugin\Tasksmanager;

use CommonGLPI;
use Ticket;
use Session;
use Plugin;
use Html;

/**
 * TaskDashboard – adds a "Workflow" tab to Ticket forms.
 */
class TaskDashboard extends CommonGLPI
{
    public static $rightname = 'ticket';

    public static function getTypeName($nb = 0): string
    {
        return __('Workflow', 'tasksmanager');
    }

    public static function getIcon(): string
    {
        return 'ti ti-git-branch';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): array
    {
        if (!($item instanceof Ticket) || !Session::haveRight('ticket', READ)) {
            return [];
        }
        return [1 => self::createTabEntry(__('Workflow', 'tasksmanager'), 0, $item::class, self::getIcon())];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof Ticket)) {
            return false;
        }
        self::showWorkflowPanel($item);
        return true;
    }

    public static function showWorkflowPanel(Ticket $ticket): void
    {
        global $DB;

        $tickets_id = $ticket->getID();
        $can_edit   = Session::haveRight('ticket', UPDATE);
        $ajax_url   = Plugin::getWebDir('tasksmanager') . '/ajax/workflow.php';

        // Is there an active workflow on this ticket?
        $tw_iter = $DB->request([
            'SELECT'    => ['tw.id', 'tw.current_step', 'tw.workflows_id', 'wf.name AS wf_name'],
            'FROM'      => 'glpi_plugin_tasksmanager_ticket_workflows AS tw',
            'LEFT JOIN' => [
                'glpi_plugin_tasksmanager_workflows AS wf' => ['ON' => ['tw' => 'workflows_id', 'wf' => 'id']],
            ],
            'WHERE' => ['tw.tickets_id' => $tickets_id, 'tw.status' => 'active'],
            'LIMIT' => 1,
        ]);

        echo '<div class="p-3 tasksmanager-workflow-panel">';

        if (count($tw_iter) > 0) {
            $tw = $tw_iter->current();

            // Total steps and current position
            // iterator_to_array on a GLPI DBmysqlIterator preserves wfs.id as
            // the array key. We need positional 0..N-1 indices for "step N/total",
            // so re-key with array_values().
            $all_steps = array_values(iterator_to_array($DB->request([
                'SELECT'    => ['wfs.id', 'wfs.step_order', 'tt.name AS tpl_name'],
                'FROM'      => 'glpi_plugin_tasksmanager_workflow_steps AS wfs',
                'LEFT JOIN' => [
                    'glpi_tasktemplates AS tt' => ['ON' => ['wfs' => 'tasktemplates_id', 'tt' => 'id']],
                ],
                'WHERE' => ['wfs.workflows_id' => $tw['workflows_id']],
                'ORDER' => ['wfs.step_order ASC'],
            ])));
            $total    = count($all_steps);
            $position = 0;
            foreach ($all_steps as $i => $s) {
                if ((int)$s['step_order'] <= (int)$tw['current_step']) {
                    $position = $i + 1;
                }
            }
            $pct = $total > 0 ? round($position / $total * 100) : 0;

            // Current step template name
            $cur_iter = $DB->request([
                'SELECT'    => ['tt.name AS tpl_name'],
                'FROM'      => 'glpi_plugin_tasksmanager_workflow_steps AS wfs',
                'LEFT JOIN' => ['glpi_tasktemplates AS tt' => ['ON' => ['wfs' => 'tasktemplates_id', 'tt' => 'id']]],
                'WHERE'     => ['wfs.workflows_id' => $tw['workflows_id'], 'wfs.step_order' => $tw['current_step']],
                'LIMIT'     => 1,
            ]);
            $cur_tpl = count($cur_iter) > 0 ? ($cur_iter->current()['tpl_name'] ?? '—') : '—';

            echo '<div class="card">';
            echo '<div class="card-body">';
            echo '<div class="d-flex justify-content-between align-items-start flex-wrap gap-3">';
            echo '<div>';
            echo '<h6 class="mb-1"><i class="ti ti-git-branch me-1"></i>' . htmlspecialchars($tw['wf_name']) . '</h6>';
            echo '<div class="text-muted small mb-2">';
            echo __('Current step', 'tasksmanager') . ': <strong>' . htmlspecialchars($cur_tpl) . '</strong>';
            echo ' &nbsp;(' . $position . '&nbsp;/&nbsp;' . $total . ')';
            echo '</div>';
            echo '<div class="progress" style="height:8px;min-width:200px">';
            echo '<div class="progress-bar" style="width:' . $pct . '%"></div>';
            echo '</div>';
            echo '</div>';

            $can_admin = Session::haveRight('plugin_tasksmanager_workflows', UPDATE);

            if ($can_edit || $can_admin) {
                echo '<div class="btn-group">';
                if ($can_admin) {
                    echo '<button class="btn btn-sm btn-outline-secondary" id="tm-btn-restart-step"';
                    echo ' data-tw-id="' . (int)$tw['id'] . '"';
                    echo ' title="' . __('Re-create the current step\'s task', 'tasksmanager') . '">';
                    echo '<i class="ti ti-refresh me-1"></i>' . __('Restart step', 'tasksmanager');
                    echo '</button>';

                    echo '<button class="btn btn-sm btn-outline-warning" id="tm-btn-skip-step"';
                    echo ' data-tw-id="' . (int)$tw['id'] . '"';
                    echo ' title="' . __('Force-advance to the next step without completing the current task', 'tasksmanager') . '">';
                    echo '<i class="ti ti-player-track-next me-1"></i>' . __('Skip step', 'tasksmanager');
                    echo '</button>';
                }
                if ($can_edit) {
                    echo '<button class="btn btn-sm btn-outline-danger" id="tm-btn-remove-wf"';
                    echo ' data-tickets-id="' . $tickets_id . '">';
                    echo '<i class="ti ti-x me-1"></i>' . __('Remove workflow', 'tasksmanager');
                    echo '</button>';
                }
                echo '</div>';
            }

            echo '</div></div></div>';

            // Which steps were actually instantiated? A workflow step has a
            // taskstate row only when applyStep ran for it. Steps with
            // step_order < current_step but no taskstate row were *jumped
            // over* by a routing rule (or `default_goto`) — those should
            // render as "Skipped", not "Done".
            $started_step_orders = [];
            $ts_iter = $DB->request([
                'SELECT' => ['workflow_step_order'],
                'FROM'   => 'glpi_plugin_tasksmanager_taskstates',
                'WHERE'  => [
                    'ticket_workflows_id' => (int)$tw['id'],
                    'workflow_step_order' => ['>', 0],
                ],
            ]);
            foreach ($ts_iter as $row) {
                $started_step_orders[(int)$row['workflow_step_order']] = true;
            }

            // Step list overview
            echo '<table class="table table-sm mt-3">';
            echo '<thead class="table-light"><tr>';
            echo '<th>#</th><th>' . __('Task template', 'tasksmanager') . '</th><th>' . __('Status') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($all_steps as $i => $s) {
                $num     = $i + 1;
                $order   = (int)$s['step_order'];
                $cur     = (int)$tw['current_step'];
                $active  = ($order === $cur);
                $past    = ($order <  $cur);
                $started = !empty($started_step_orders[$order]);

                echo '<tr' . ($active ? ' class="table-primary"' : '') . '>';
                echo '<td>' . $num . '</td>';
                echo '<td>' . htmlspecialchars($s['tpl_name'] ?? '—') . '</td>';
                echo '<td>';
                if ($active) {
                    echo '<span class="badge bg-primary text-white">' . __('In progress') . '</span>';
                } elseif ($past && $started) {
                    echo '<span class="badge bg-success text-white">' . __('Done') . '</span>';
                } elseif ($past && !$started) {
                    // Jumped by a routing rule / default_goto — never had a task
                    echo '<span class="badge bg-warning text-dark"><i class="ti ti-player-track-next me-1"></i>'
                        . __('Skipped', 'tasksmanager') . '</span>';
                } else {
                    echo '<span class="badge bg-secondary text-white">' . __('Pending') . '</span>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';

            // ── Audit log (history) ───────────────────────────────────────
            self::renderHistory($tickets_id, 12);

        } else {
            // No active workflow — show selector. If a workflow was previously
            // completed on this ticket, surface that as a confirmation banner
            // so the user knows the prior run finished cleanly.
            $completed_iter = $DB->request([
                'SELECT'    => ['wf.name AS wf_name', 'tw.date_mod'],
                'FROM'      => 'glpi_plugin_tasksmanager_ticket_workflows AS tw',
                'LEFT JOIN' => [
                    'glpi_plugin_tasksmanager_workflows AS wf' => ['ON' => ['tw' => 'workflows_id', 'wf' => 'id']],
                ],
                'WHERE' => ['tw.tickets_id' => $tickets_id, 'tw.status' => 'completed'],
                'ORDER' => ['tw.date_mod DESC'],
                'LIMIT' => 1,
            ]);

            // Check for a deferred (pending-approval) workflow. The
            // ITEM_ADD hook stores one of these when the ticket needs
            // approval; ticket_update will consume it on ACCEPT.
            $pending_iter = $DB->request([
                'SELECT'    => ['pw.workflows_id', 'pw.date_creation', 'wf.name AS wf_name'],
                'FROM'      => 'glpi_plugin_tasksmanager_pending_workflows AS pw',
                'LEFT JOIN' => [
                    'glpi_plugin_tasksmanager_workflows AS wf' => ['ON' => ['pw' => 'workflows_id', 'wf' => 'id']],
                ],
                'WHERE'     => ['pw.tickets_id' => $tickets_id],
                'LIMIT'     => 1,
            ]);

            if (count($completed_iter) > 0) {
                $done = $completed_iter->current();
                echo '<div class="alert alert-success d-flex align-items-center">';
                echo '<i class="ti ti-circle-check me-2"></i>';
                echo '<div>';
                echo sprintf(
                    __('Workflow %s completed.', 'tasksmanager'),
                    '<strong>' . htmlspecialchars($done['wf_name'] ?? '') . '</strong>'
                );
                if (!empty($done['date_mod'])) {
                    echo ' <span class="text-muted small ms-2">'
                        . Html::convDateTime($done['date_mod']) . '</span>';
                }
                echo '</div></div>';

                // Keep the audit trail visible even after the workflow finishes.
                self::renderHistory($tickets_id, 12);
            } elseif (count($pending_iter) > 0) {
                $pending = $pending_iter->current();
                echo '<div class="alert alert-warning d-flex align-items-start">';
                echo '<i class="ti ti-clock me-2 mt-1"></i>';
                echo '<div class="flex-grow-1">';
                echo '<strong>' . sprintf(
                    __('Workflow %s — waiting for approval', 'tasksmanager'),
                    htmlspecialchars($pending['wf_name'] ?? __('(unknown)', 'tasksmanager'))
                ) . '</strong>';
                echo '<div class="text-muted small mt-1">';
                echo __('The workflow will start automatically when the ticket\'s approval is granted.', 'tasksmanager');
                if (!empty($pending['date_creation'])) {
                    echo ' <span class="ms-2">'
                        . __('Queued', 'tasksmanager') . ': '
                        . Html::convDateTime($pending['date_creation']) . '</span>';
                }
                echo '</div></div>';
                echo '</div>';

                // Show audit log here too — workflow_pending / workflow_deferred events
                // are the diagnostic gold for "why isn't anything happening?"
                self::renderHistory($tickets_id, 12);
            } else {
                echo '<p class="text-muted">' . __('No active workflow on this ticket.', 'tasksmanager') . '</p>';
                // No pending and nothing completed — but our hook may still have
                // logged events (e.g. workflow_applied_immediate with decision=apply
                // that then failed silently). Show recent history if any exists.
                self::renderHistory($tickets_id, 12);
            }

            if ($can_edit) {
                $workflows = Workflow::getDropdownOptions();

                if (empty($workflows)) {
                    echo '<div class="alert alert-info">';
                    echo __('No workflows defined yet.', 'tasksmanager') . ' ';
                    if (Session::haveRight('plugin_tasksmanager_workflows', UPDATE)) {
                        echo '<a href="' . Plugin::getWebDir('tasksmanager') . '/front/workflow.list.php">';
                        echo __('Create a workflow', 'tasksmanager') . '</a>.';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="d-flex gap-2 align-items-center flex-wrap">';
                    echo '<select id="tm-wf-select" class="form-select form-select-sm" style="max-width:300px">';
                    echo '<option value="">' . __('-- Select a workflow --', 'tasksmanager') . '</option>';
                    foreach ($workflows as $wid => $wname) {
                        echo '<option value="' . $wid . '">' . htmlspecialchars($wname) . '</option>';
                    }
                    echo '</select>';
                    echo '<button class="btn btn-primary btn-sm" id="tm-btn-apply-wf"';
                    echo ' data-tickets-id="' . $tickets_id . '">';
                    echo '<i class="ti ti-player-play me-1"></i>' . __('Apply', 'tasksmanager');
                    echo '</button>';
                    echo '</div>';
                }
            }
        }

        echo '</div>';

        // Inline script
        echo '<script>';
        echo '(function(){';
        echo 'const ajaxUrl=' . json_encode($ajax_url) . ';';
        echo 'function csrfToken(){';
        echo '  const m=document.querySelector("meta[property=\'glpi:csrf_token\']");';
        echo '  return m?m.getAttribute("content"):"";';
        echo '}';
        // Endpoint contract: { ok: bool, error?: string, data?: object }
        echo 'function post(data){';
        echo '  const fd=new FormData();';
        echo '  for(const[k,v] of Object.entries(data)) fd.append(k,v);';
        echo '  return fetch(ajaxUrl,{method:"POST",body:fd,credentials:"same-origin",';
        echo '    headers:{"X-Requested-With":"XMLHttpRequest","X-Glpi-Csrf-Token":csrfToken()}})';
        echo '    .then(r=>r.json().catch(()=>({ok:false,error:"HTTP "+r.status})))';
        echo '    .catch(err=>{console.error("Workflow AJAX:",err);return{ok:false,error:String(err)};});';
        echo '}';

        // Apply button
        echo 'const btnApply=document.getElementById("tm-btn-apply-wf");';
        echo 'if(btnApply){btnApply.addEventListener("click",function(){';
        echo '  const sel=document.getElementById("tm-wf-select");';
        echo '  if(!sel||!sel.value){alert(' . json_encode(__('Please select a workflow.', 'tasksmanager')) . ');return;}';
        echo '  post({action:"apply_to_ticket",tickets_id:btnApply.dataset.ticketsId,workflows_id:sel.value})';
        echo '    .then(d=>{if(d.ok){location.reload();}else{alert(d.error||"Error");}});';
        echo '});}';

        // Remove button
        echo 'const btnRemove=document.getElementById("tm-btn-remove-wf");';
        echo 'if(btnRemove){btnRemove.addEventListener("click",function(){';
        echo '  if(!confirm(' . json_encode(__('Remove the active workflow from this ticket?', 'tasksmanager')) . ')) return;';
        echo '  post({action:"remove_from_ticket",tickets_id:btnRemove.dataset.ticketsId})';
        echo '    .then(d=>{if(d.ok){location.reload();}else{alert(d.error||"Error");}});';
        echo '});}';

        // Skip step button
        echo 'const btnSkip=document.getElementById("tm-btn-skip-step");';
        echo 'if(btnSkip){btnSkip.addEventListener("click",function(){';
        echo '  if(!confirm(' . json_encode(__('Force-advance past the current step? The task will be marked Done.', 'tasksmanager')) . ')) return;';
        echo '  post({action:"skip_current_step",ticket_workflows_id:btnSkip.dataset.twId})';
        echo '    .then(d=>{if(d.ok){location.reload();}else{alert(d.error||"Error");}});';
        echo '});}';

        // Restart step button
        echo 'const btnRestart=document.getElementById("tm-btn-restart-step");';
        echo 'if(btnRestart){btnRestart.addEventListener("click",function(){';
        echo '  if(!confirm(' . json_encode(__('Restart the current step? A fresh task will be created and the existing one marked Done.', 'tasksmanager')) . ')) return;';
        echo '  post({action:"restart_current_step",ticket_workflows_id:btnRestart.dataset.twId})';
        echo '    .then(d=>{if(d.ok){location.reload();}else{alert(d.error||"Error");}});';
        echo '});}';

        echo '})();';
        echo '</script>';
    }

    /**
     * Render the workflow audit log for a ticket as a collapsible "History"
     * card. Silently no-ops if the events table is missing (pre-1.3.14
     * installs that haven't run the upgrade yet) or there are no events.
     */
    private static function renderHistory(int $tickets_id, int $limit = 12): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_tasksmanager_workflow_events')) {
            return;
        }

        $iter = $DB->request([
            'SELECT'    => [
                'ev.id', 'ev.event_type', 'ev.step_order', 'ev.details',
                'ev.users_id', 'ev.date_creation',
                'wf.name AS wf_name',
            ],
            'FROM'      => 'glpi_plugin_tasksmanager_workflow_events AS ev',
            'LEFT JOIN' => [
                'glpi_plugin_tasksmanager_workflows AS wf' => ['ON' => ['ev' => 'workflows_id', 'wf' => 'id']],
            ],
            'WHERE'     => ['ev.tickets_id' => $tickets_id],
            'ORDER'     => ['ev.date_creation DESC', 'ev.id DESC'],
            'LIMIT'     => $limit,
        ]);

        if (count($iter) === 0) {
            return;
        }

        $labels = [
            'workflow_applied'           => ['ti-player-play',        __('Workflow started',         'tasksmanager')],
            'workflow_applied_immediate' => ['ti-player-play',        __('Workflow started (no approval needed)', 'tasksmanager')],
            'workflow_pending'           => ['ti-clock',              __('Workflow deferred (approval pending)',  'tasksmanager')],
            'workflow_pending_recheck'   => ['ti-clock',              __('Workflow deferred at shutdown (re-check)', 'tasksmanager')],
            'workflow_applied_recheck'   => ['ti-player-play',        __('Workflow started at shutdown (re-check)',  'tasksmanager')],
            'step_started'               => ['ti-arrow-right',        __('Step started',             'tasksmanager')],
            'step_routed'                => ['ti-route',              __('Step routed',              'tasksmanager')],
            'step_skipped'               => ['ti-player-track-next',  __('Step skipped',             'tasksmanager')],
            'step_restarted'             => ['ti-refresh',            __('Step restarted',           'tasksmanager')],
            'workflow_completed'         => ['ti-circle-check',       __('Workflow completed',       'tasksmanager')],
            'workflow_removed'           => ['ti-x',                  __('Workflow removed',         'tasksmanager')],
        ];

        echo '<div class="card mt-3">';
        echo '<div class="card-header py-2">';
        echo '<h6 class="mb-0"><i class="ti ti-history me-1"></i>'
            . __('History', 'tasksmanager') . '</h6>';
        echo '</div>';
        echo '<div class="card-body p-0">';
        echo '<table class="table table-sm mb-0">';
        echo '<thead class="table-light"><tr>';
        echo '<th style="width:160px">' . __('When') . '</th>';
        echo '<th>' . __('Event', 'tasksmanager') . '</th>';
        echo '<th>' . __('User') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($iter as $ev) {
            $type        = $ev['event_type'] ?? '';
            [$icon, $lbl] = $labels[$type] ?? ['ti-circle-dot', $type];
            $when        = $ev['date_creation'] ? Html::convDateTime($ev['date_creation']) : '—';

            $user_name = '—';
            if (!empty($ev['users_id'])) {
                $u = new \User();
                if ($u->getFromDB((int)$ev['users_id'])) {
                    $user_name = $u->getFriendlyName();
                }
            }

            $extra = '';
            if (!empty($ev['step_order'])) {
                $extra .= ' <span class="text-muted small">'
                    . sprintf(__('step %d', 'tasksmanager'), (int)$ev['step_order'])
                    . '</span>';
            }
            if (!empty($ev['wf_name'])) {
                $extra .= ' <span class="text-muted small">— '
                    . htmlspecialchars($ev['wf_name']) . '</span>';
            }

            // For workflow_pending / workflow_applied_immediate, show the
            // three approval-gate signals plus the actual input keys we saw
            // — invaluable for diagnosing cases where Forms slipped through.
            if (in_array($type, ['workflow_pending', 'workflow_applied_immediate'], true)
                && !empty($ev['details'])
            ) {
                $det = json_decode($ev['details'], true);
                if (is_array($det)) {
                    $extra .= ' <span class="text-muted small">— '
                        . sprintf(
                            __('global_validation=%d, waiting=%d, input_has_validation=%s', 'tasksmanager'),
                            (int)($det['global_validation'] ?? 0),
                            (int)($det['pending_validations'] ?? 0),
                            !empty($det['input_has_validation']) ? 'yes' : 'no'
                        )
                        . '</span>';

                    if (!empty($det['validation_keys_seen']) && is_array($det['validation_keys_seen'])) {
                        $extra .= '<div class="text-muted small">'
                            . __('Validation keys seen:', 'tasksmanager') . ' '
                            . htmlspecialchars(implode(', ', $det['validation_keys_seen']))
                            . '</div>';
                    }
                    if (!empty($det['input_keys']) && is_array($det['input_keys'])) {
                        $keys_str = implode(', ', array_map('strval', $det['input_keys']));
                        $extra .= '<div class="text-muted small" style="word-break:break-all">'
                            . __('Input keys:', 'tasksmanager') . ' '
                            . htmlspecialchars(mb_substr($keys_str, 0, 500))
                            . (mb_strlen($keys_str) > 500 ? '…' : '')
                            . '</div>';
                    }
                }
            }

            // For step_routed events, surface the routing decision inline so
            // the user can see why a particular step ran without having to
            // inspect the raw JSON details column.
            if ($type === 'step_routed' && !empty($ev['details'])) {
                $det = json_decode($ev['details'], true);
                if (is_array($det)) {
                    $decision_map = [
                        'rule_match'   => __('rule matched',         'tasksmanager'),
                        'default_goto' => __('default → step',       'tasksmanager'),
                        'default_end'  => __('default → end',        'tasksmanager'),
                        'linear'       => __('linear next',          'tasksmanager'),
                        'workflow_end' => __('no next step (end)',   'tasksmanager'),
                    ];
                    $dec   = (string)($det['decision'] ?? '');
                    $rules = (int)($det['rules_count'] ?? 0);
                    $extra .= ' <span class="text-muted small">— '
                        . sprintf(__('rules: %d', 'tasksmanager'), $rules)
                        . ', ' . ($decision_map[$dec] ?? $dec)
                        . '</span>';

                    // Show why each rule was skipped, if any rules existed
                    if (!empty($det['evaluations']) && is_array($det['evaluations'])) {
                        $bits = [];
                        foreach ($det['evaluations'] as $e) {
                            if (!is_array($e)) continue;
                            $f = (string)($e['field'] ?? '');
                            $o = (string)($e['op']    ?? '');
                            $v = (string)($e['value'] ?? '');
                            $a = $e['actual'] ?? null;
                            $r = $e['skip_reason'] ?? null;
                            $m = !empty($e['matched']) ? __('match', 'tasksmanager')
                                                       : ($r ?: __('skipped', 'tasksmanager'));
                            $actual_preview = ($a === null)
                                ? __('(unresolved)', 'tasksmanager')
                                : '“' . mb_substr((string)$a, 0, 60) . (mb_strlen((string)$a) > 60 ? '…' : '') . '”';
                            $bits[] = htmlspecialchars(
                                "{$f} {$o} \"{$v}\" vs {$actual_preview} → {$m}"
                            );
                        }
                        if (!empty($bits)) {
                            $extra .= '<div class="text-muted small" style="white-space:normal">'
                                . implode('<br>', $bits) . '</div>';
                        }
                    }
                }
            }

            echo '<tr>';
            echo '<td class="text-muted small">' . $when . '</td>';
            echo '<td><i class="ti ' . htmlspecialchars($icon) . ' me-1"></i>'
                . htmlspecialchars($lbl) . $extra . '</td>';
            echo '<td>' . htmlspecialchars($user_name) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}
