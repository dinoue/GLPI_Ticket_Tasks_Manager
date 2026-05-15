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

        echo '<div class="p-3">';

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

            if ($can_edit) {
                echo '<button class="btn btn-sm btn-outline-danger" id="tm-btn-remove-wf"';
                echo ' data-tickets-id="' . $tickets_id . '">';
                echo '<i class="ti ti-x me-1"></i>' . __('Remove workflow', 'tasksmanager');
                echo '</button>';
            }

            echo '</div></div></div>';

            // Step list overview
            echo '<table class="table table-sm mt-3">';
            echo '<thead class="table-light"><tr>';
            echo '<th>#</th><th>' . __('Task template', 'tasksmanager') . '</th><th>' . __('Status') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($all_steps as $i => $s) {
                $num    = $i + 1;
                $active = ((int)$s['step_order'] === (int)$tw['current_step']);
                $done   = ((int)$s['step_order'] < (int)$tw['current_step']);
                echo '<tr' . ($active ? ' class="table-primary"' : '') . '>';
                echo '<td>' . $num . '</td>';
                echo '<td>' . htmlspecialchars($s['tpl_name'] ?? '—') . '</td>';
                echo '<td>';
                if ($done) {
                    echo '<span class="badge bg-success text-white">' . __('Done') . '</span>';
                } elseif ($active) {
                    echo '<span class="badge bg-primary text-white">' . __('In progress') . '</span>';
                } else {
                    echo '<span class="badge bg-secondary text-white">' . __('Pending') . '</span>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';

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
            } else {
                echo '<p class="text-muted">' . __('No active workflow on this ticket.', 'tasksmanager') . '</p>';
            }

            if ($can_edit) {
                $workflows = Workflow::getDropdownOptions();

                if (empty($workflows)) {
                    echo '<div class="alert alert-info">';
                    echo __('No workflows defined yet.', 'tasksmanager') . ' ';
                    if (Session::haveRight('config', UPDATE)) {
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
        echo 'function post(data){';
        echo '  const fd=new FormData();';
        echo '  for(const[k,v] of Object.entries(data)) fd.append(k,v);';
        echo '  return fetch(ajaxUrl,{method:"POST",body:fd,credentials:"same-origin",';
        echo '    headers:{"X-Requested-With":"XMLHttpRequest","X-Glpi-Csrf-Token":csrfToken()}})';
        echo '    .then(r=>{if(!r.ok)return Promise.reject("HTTP "+r.status);return r.json();})';
        echo '    .catch(err=>{console.error("Workflow AJAX:",err);return{success:false,message:String(err)};});';
        echo '}';

        // Apply button
        echo 'const btnApply=document.getElementById("tm-btn-apply-wf");';
        echo 'if(btnApply){btnApply.addEventListener("click",function(){';
        echo '  const sel=document.getElementById("tm-wf-select");';
        echo '  if(!sel||!sel.value){alert(' . json_encode(__('Please select a workflow.', 'tasksmanager')) . ');return;}';
        echo '  post({action:"apply_to_ticket",tickets_id:btnApply.dataset.ticketsId,workflows_id:sel.value})';
        echo '    .then(d=>{if(d.success){location.reload();}else{alert(d.message||"Error");}});';
        echo '});}';

        // Remove button
        echo 'const btnRemove=document.getElementById("tm-btn-remove-wf");';
        echo 'if(btnRemove){btnRemove.addEventListener("click",function(){';
        echo '  if(!confirm(' . json_encode(__('Remove the active workflow from this ticket?', 'tasksmanager')) . ')) return;';
        echo '  post({action:"remove_from_ticket",tickets_id:btnRemove.dataset.ticketsId})';
        echo '    .then(d=>{if(d.success){location.reload();}else{alert(d.message||"Error");}});';
        echo '});}';

        echo '})();';
        echo '</script>';
    }
}
