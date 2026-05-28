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
                'SELECT'    => [
                    'wf.name AS wf_name',
                    'wf.solutiontemplates_id',
                    'tw.date_mod',
                    'st.name AS st_name',
                ],
                'FROM'      => 'glpi_plugin_tasksmanager_ticket_workflows AS tw',
                'LEFT JOIN' => [
                    'glpi_plugin_tasksmanager_workflows AS wf' =>
                        ['ON' => ['tw' => 'workflows_id', 'wf' => 'id']],
                    // Best-effort join to the SolutionTemplate name. The table
                    // always exists in GLPI 11, so no tableExists guard needed.
                    'glpi_solutiontemplates AS st' =>
                        ['ON' => ['wf' => 'solutiontemplates_id', 'st' => 'id']],
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
                $suggested_st_id   = (int)($done['solutiontemplates_id'] ?? 0);
                $suggested_st_name = (string)($done['st_name'] ?? '');

                echo '<div class="alert alert-success">';
                echo '<div class="d-flex align-items-center">';
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

                // Suggested solution template — render only if the workflow
                // has one configured AND the row still exists. Pure
                // suggestion: clicking the button selects the template in
                // GLPI's standard solution dropdown (so all native warnings
                // / approval safeguards stay in the flow); we do NOT
                // auto-create the ITILSolution.
                if ($suggested_st_id > 0 && $suggested_st_name !== '') {
                    echo '<hr class="my-2">';
                    echo '<div class="d-flex align-items-center flex-wrap gap-2">';
                    echo '<i class="ti ti-file-text me-1"></i>';
                    echo '<span>'
                        . sprintf(
                            __('Suggested solution template: %s', 'tasksmanager'),
                            '<strong>' . htmlspecialchars($suggested_st_name) . '</strong>'
                        )
                        . '</span>';
                    echo '<button type="button" class="btn btn-sm btn-outline-success ms-auto"';
                    echo ' id="tm-btn-use-solution-template"';
                    echo ' data-solutiontemplates-id="' . $suggested_st_id . '"';
                    echo ' data-solutiontemplates-name="' . htmlspecialchars($suggested_st_name, ENT_QUOTES) . '"';
                    echo ' title="'
                        . htmlspecialchars(__('Open GLPI\'s solution form with this template pre-selected. You still need to review the content and click save — GLPI\'s normal warnings (waiting for approval, etc.) still apply.', 'tasksmanager'))
                        . '">';
                    echo '<i class="ti ti-arrow-down-right me-1"></i>'
                        . __('Use this template', 'tasksmanager');
                    echo '</button>';
                    echo '</div>';
                }
                echo '</div>';

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

        // "Use this solution template" button on the completion banner.
        //
        // The actual open + Select2 pre-fill flow lives in
        // public/js/workflow-refresh.js as `window.tmOpenSolutionWithTemplate`
        // — same helper consumes the post-reload sessionStorage stash that
        // gets written when the server emits X-TM-Auto-Solution-Id. We just
        // hand it our button's data here. Falls back to a corner toast if
        // the helper can't find GLPI's solution block (older GLPI, layout
        // change, missing permission, etc.).
        $toast_label   = json_encode(__('Pick this solution template:', 'tasksmanager'));
        $toast_subtext = json_encode(__('Click GLPI\'s solution-add button below and choose this name from the template dropdown.', 'tasksmanager'));
        echo 'const btnSolTpl=document.getElementById("tm-btn-use-solution-template");';
        echo 'if(btnSolTpl){btnSolTpl.addEventListener("click",function(){';
        echo '  const tplId = parseInt(btnSolTpl.dataset.solutiontemplatesId, 10) || 0;';
        echo '  const name  = btnSolTpl.dataset.solutiontemplatesName || "";';
        echo '  const ok = (typeof window.tmOpenSolutionWithTemplate === "function")';
        echo '    && window.tmOpenSolutionWithTemplate(tplId, name);';
        echo '  if (ok) return;';
        // ── Fallback: scroll + toast guidance ────────────────────────────
        echo '  const esc = s => s.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");';
        echo '  window.scrollTo({top:document.body.scrollHeight,behavior:"smooth"});';
        echo '  const toast = document.createElement("div");';
        echo '  toast.className = "alert alert-success shadow-lg position-fixed";';
        echo '  toast.style.cssText = "bottom:24px;right:24px;z-index:10000;max-width:380px;cursor:pointer";';
        echo '  toast.innerHTML ='
            . ' "<strong><i class=\"ti ti-file-text me-1\"></i>" + ' . $toast_label . ' + "</strong><br>"'
            . ' + "<span style=\"font-size:1.1em\">" + esc(name) + "</span>"'
            . ' + "<div class=\"text-muted small mt-1\">" + ' . $toast_subtext . ' + "</div>";';
        echo '  toast.addEventListener("click", () => toast.remove());';
        echo '  document.body.appendChild(toast);';
        echo '  setTimeout(() => { if (toast.parentNode) toast.remove(); }, 6000);';
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

        // Pull events oldest-first so begin/delay are easy to compute, then
        // reverse for display. We bring back the most recent $limit events
        // via a subquery so begin/delay reflect the *visible* window.
        $iter_desc = $DB->request([
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

        if (count($iter_desc) === 0) {
            return;
        }

        // Materialise + flip to ASC so we can walk forwards for begin/delay.
        $events_asc = array_reverse(iterator_to_array($iter_desc));

        // Reference time = ticket creation, matching timelineticket's
        // `begin` semantics. Falls back to the first event time if the
        // ticket row is unreadable.
        $ticket = new \Ticket();
        $ticket_ts = null;
        if ($ticket->getFromDB($tickets_id) && !empty($ticket->fields['date'])) {
            $ticket_ts = strtotime($ticket->fields['date']);
        }
        if (!$ticket_ts && !empty($events_asc[0]['date_creation'])) {
            $ticket_ts = strtotime($events_asc[0]['date_creation']);
        }

        // Walk ASC, attach begin (seconds from ticket creation) and delay
        // (seconds since previous event).
        $previous_ts = null;
        foreach ($events_asc as &$ev) {
            $ts = $ev['date_creation'] ? strtotime($ev['date_creation']) : null;
            $ev['begin'] = ($ts && $ticket_ts)   ? max(0, $ts - $ticket_ts)   : 0;
            $ev['delay'] = ($ts && $previous_ts) ? max(0, $ts - $previous_ts) : 0;
            $previous_ts = $ts;
        }
        unset($ev);

        // Detect timelineticket — if installed, mention compatibility in the
        // card footer so users know our log shares the same begin/delay
        // semantics as their AssignGroup / AssignUser / AssignState views.
        $tlt_installed = $DB->tableExists('glpi_plugin_timelineticket_assigngroups')
                      || $DB->tableExists('glpi_plugin_timelineticket_assignusers')
                      || $DB->tableExists('glpi_plugin_timelineticket_assignstates');

        // Display order is newest-first.
        $iter = array_reverse($events_asc);

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
        echo '<div class="card-header py-2 d-flex justify-content-between align-items-center">';
        echo '<h6 class="mb-0"><i class="ti ti-history me-1"></i>'
            . __('History', 'tasksmanager') . '</h6>';
        if ($tlt_installed) {
            echo '<span class="badge bg-info-lt" title="'
                . htmlspecialchars(__('Begin / Delay columns use the same semantics as the TimelineTicket plugin\'s debug reports.', 'tasksmanager'))
                . '"><i class="ti ti-timeline me-1"></i>'
                . __('TimelineTicket compatible', 'tasksmanager') . '</span>';
        }
        echo '</div>';
        echo '<div class="card-body p-0">';
        echo '<table class="table table-sm mb-0">';
        echo '<thead class="table-light"><tr>';
        echo '<th style="width:160px">' . __('When') . '</th>';
        echo '<th>' . __('Event', 'tasksmanager') . '</th>';
        echo '<th>' . __('User') . '</th>';
        echo '<th class="text-end" style="width:160px" title="'
            . htmlspecialchars(__('Time since the ticket was created.', 'tasksmanager'))
            . '">' . __('Begin', 'tasksmanager') . '</th>';
        echo '<th class="text-end" style="width:160px" title="'
            . htmlspecialchars(__('Time since the previous workflow event.', 'tasksmanager'))
            . '">' . __('Delay', 'tasksmanager') . '</th>';
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
            // Begin / Delay — same semantics as timelineticket's debug reports.
            // Html::timestampToString turns seconds → "X days Y hours Z minutes …"
            $begin = (int)($ev['begin'] ?? 0);
            $delay = (int)($ev['delay'] ?? 0);
            // Match timelineticket's display granularity: "X days Y hours Z minutes W seconds"
            echo '<td class="text-end text-muted small font-monospace">'
                . ($begin > 0 ? htmlspecialchars(Html::timestampToString($begin, true)) : '—')
                . '</td>';
            echo '<td class="text-end text-muted small font-monospace">'
                . ($delay > 0 ? htmlspecialchars(Html::timestampToString($delay, true)) : '—')
                . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}
