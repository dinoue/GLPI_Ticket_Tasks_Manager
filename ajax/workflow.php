<?php

/**
 * Tasks Manager - Workflow AJAX endpoint
 *
 * Response shape (per GLPI-Shared/rules/glpi-plugin-api.md):
 *   { ok: bool, error?: string, data?: object }
 *
 * HTTP status codes:
 *   200 — success
 *   400 — missing / invalid input
 *   403 — not allowed
 *   404 — referenced item not found
 *   500 — unexpected server error
 *
 * Actions (POST):
 *   add_step                  – add a task-template step to a workflow
 *   remove_step               – remove a step
 *   reorder_steps             – save new step order (array of step IDs)
 *   update_template_comment   – update the linked task template's comment
 *   apply_to_ticket           – assign a workflow to a ticket and create its first task
 *   remove_from_ticket        – cancel the active workflow on a ticket
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../hook.php');

use GlpiPlugin\Tasksmanager\Workflow;

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Plugin not active']);
    exit;
}

/** Helper: respond and exit. */
function tm_respond(bool $ok, int $http_code = 200, ?string $error = null, array $data = []): void
{
    http_response_code($http_code);
    $out = ['ok' => $ok];
    if ($error !== null) {
        $out['error'] = $error;
    }
    if (!empty($data)) {
        $out['data'] = $data;
    }
    echo json_encode($out);
    exit;
}

$action = $_POST['action'] ?? '';

global $DB;

switch ($action) {

    // ── Add a template step ───────────────────────────────────────────────
    case 'add_step':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $workflows_id     = (int)($_POST['workflows_id']     ?? 0);
        $tasktemplates_id = (int)($_POST['tasktemplates_id'] ?? 0);

        if (!$workflows_id || !$tasktemplates_id) {
            tm_respond(false, 400, 'Missing parameters');
        }

        $last = $DB->request([
            'SELECT' => ['step_order'],
            'FROM'   => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE'  => ['workflows_id' => $workflows_id],
            'ORDER'  => ['step_order DESC'],
            'LIMIT'  => 1,
        ]);
        $step_order = (count($last) > 0 ? (int)$last->current()['step_order'] : 0) + 10;

        $DB->insert('glpi_plugin_tasksmanager_workflow_steps', [
            'workflows_id'     => $workflows_id,
            'tasktemplates_id' => $tasktemplates_id,
            'step_order'       => $step_order,
            'date_creation'    => date('Y-m-d H:i:s'),
        ]);

        tm_respond(true, 200, null, ['step_id' => (int)$DB->insertId()]);

    // ── Update the linked task template's `comment` field ─────────────────
    // (Bound to the description textarea on the workflow editor — changes
    // here apply to every workflow that references the same template.)
    case 'update_template_comment':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $tasktemplates_id = (int)($_POST['tasktemplates_id'] ?? 0);
        $comment          = (string)($_POST['comment'] ?? '');
        if (!$tasktemplates_id) {
            tm_respond(false, 400, 'Missing tasktemplates_id');
        }

        $tpl = new TaskTemplate();
        if (!$tpl->getFromDB($tasktemplates_id)) {
            tm_respond(false, 404, 'Template not found');
        }
        $tpl->update([
            'id'      => $tasktemplates_id,
            'comment' => $comment,
        ]);
        tm_respond(true);

    // ── Remove a step ─────────────────────────────────────────────────────
    case 'remove_step':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $step_id = (int)($_POST['step_id'] ?? 0);
        if (!$step_id) {
            tm_respond(false, 400, 'Missing step_id');
        }
        $DB->delete('glpi_plugin_tasksmanager_workflow_steps', ['id' => $step_id]);
        tm_respond(true);

    // ── Save new order ────────────────────────────────────────────────────
    case 'reorder_steps':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $workflows_id = (int)($_POST['workflows_id'] ?? 0);
        $order        = json_decode($_POST['order'] ?? '[]', true);

        if (!$workflows_id || !is_array($order)) {
            tm_respond(false, 400, 'Invalid parameters');
        }
        foreach ($order as $position => $step_id) {
            $DB->update(
                'glpi_plugin_tasksmanager_workflow_steps',
                ['step_order' => ($position + 1) * 10],
                ['id' => (int)$step_id, 'workflows_id' => $workflows_id]
            );
        }
        tm_respond(true);

    // ── Apply workflow to a ticket ─────────────────────────────────────────
    case 'apply_to_ticket':
        Session::checkRight('ticket', UPDATE);

        $tickets_id   = (int)($_POST['tickets_id']   ?? 0);
        $workflows_id = (int)($_POST['workflows_id'] ?? 0);

        if (!$tickets_id || !$workflows_id) {
            tm_respond(false, 400, 'Missing parameters');
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
            tm_respond(false, 403, 'Access denied');
        }

        if (!Workflow::applyToTicket($tickets_id, $workflows_id)) {
            tm_respond(false, 500, __(
                'Could not apply workflow. The ticket may already have an active workflow, the workflow may have no steps, or the first task template is invalid.',
                'tasksmanager'
            ));
        }

        tm_respond(true);

    // ── Admin: skip the current step ──────────────────────────────────────
    case 'skip_current_step':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $ticket_workflows_id = (int)($_POST['ticket_workflows_id'] ?? 0);
        if (!$ticket_workflows_id) {
            tm_respond(false, 400, 'Missing ticket_workflows_id');
        }
        if (!Workflow::skipCurrentStep($ticket_workflows_id)) {
            tm_respond(false, 500, 'Skip failed (workflow may not be active)');
        }
        tm_respond(true);

    // ── Admin: restart the current step ───────────────────────────────────
    case 'restart_current_step':
        Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

        $ticket_workflows_id = (int)($_POST['ticket_workflows_id'] ?? 0);
        if (!$ticket_workflows_id) {
            tm_respond(false, 400, 'Missing ticket_workflows_id');
        }
        if (!Workflow::restartCurrentStep($ticket_workflows_id)) {
            tm_respond(false, 500, 'Restart failed (workflow may not be active)');
        }
        tm_respond(true);

    // ── Remove active workflow from ticket ────────────────────────────────
    case 'remove_from_ticket':
        Session::checkRight('ticket', UPDATE);

        $tickets_id = (int)($_POST['tickets_id'] ?? 0);
        if (!$tickets_id) {
            tm_respond(false, 400, 'Missing tickets_id');
        }

        // Capture context before cancelling so we can log it
        $active = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE' => ['tickets_id' => $tickets_id, 'status' => 'active'],
            'LIMIT' => 1,
        ]);
        $tw_id_for_log = 0;
        $wf_id_for_log = 0;
        $step_for_log  = null;
        if (count($active) > 0) {
            $row = $active->current();
            $tw_id_for_log = (int)$row['id'];
            $wf_id_for_log = (int)$row['workflows_id'];
            $step_for_log  = (int)$row['current_step'];
        }

        $DB->update(
            'glpi_plugin_tasksmanager_ticket_workflows',
            ['status' => 'cancelled'],
            ['tickets_id' => $tickets_id, 'status' => 'active']
        );

        if ($tw_id_for_log > 0) {
            Workflow::logEvent(
                'workflow_removed',
                $tickets_id,
                $wf_id_for_log,
                $tw_id_for_log,
                $step_for_log
            );
        }
        tm_respond(true);

    default:
        tm_respond(false, 400, 'Unknown action');
}
