<?php

/**
 * Tasks Manager - Workflow AJAX endpoint
 *
 * Actions (POST):
 *   add_step          – add a task-template step to a workflow
 *   remove_step       – remove a step
 *   reorder_steps     – save new step order (array of step IDs)
 *   apply_to_ticket   – assign a workflow to a ticket and create its first task
 *   remove_from_ticket – cancel the active workflow on a ticket
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../hook.php');

use GlpiPlugin\Tasksmanager\Workflow;

header('Content-Type: application/json');

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    echo json_encode(['success' => false, 'message' => 'Plugin not active']);
    exit;
}

$action = $_POST['action'] ?? '';

global $DB;

switch ($action) {

    // ── Add a template step ───────────────────────────────────────────────
    case 'add_step':
        Session::checkRight('config', UPDATE);

        $workflows_id     = (int)($_POST['workflows_id']     ?? 0);
        $tasktemplates_id = (int)($_POST['tasktemplates_id'] ?? 0);

        if (!$workflows_id || !$tasktemplates_id) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']);
            exit;
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

        echo json_encode(['success' => true, 'step_id' => $DB->insertId()]);
        break;

    // ── Remove a step ─────────────────────────────────────────────────────
    case 'remove_step':
        Session::checkRight('config', UPDATE);

        $step_id = (int)($_POST['step_id'] ?? 0);
        if (!$step_id) {
            echo json_encode(['success' => false, 'message' => 'Missing step_id']);
            exit;
        }
        $DB->delete('glpi_plugin_tasksmanager_workflow_steps', ['id' => $step_id]);
        echo json_encode(['success' => true]);
        break;

    // ── Save new order ────────────────────────────────────────────────────
    case 'reorder_steps':
        Session::checkRight('config', UPDATE);

        $workflows_id = (int)($_POST['workflows_id'] ?? 0);
        $order        = json_decode($_POST['order'] ?? '[]', true);

        if (!$workflows_id || !is_array($order)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }
        foreach ($order as $position => $step_id) {
            $DB->update(
                'glpi_plugin_tasksmanager_workflow_steps',
                ['step_order' => ($position + 1) * 10],
                ['id' => (int)$step_id, 'workflows_id' => $workflows_id]
            );
        }
        echo json_encode(['success' => true]);
        break;

    // ── Apply workflow to a ticket ─────────────────────────────────────────
    case 'apply_to_ticket':
        Session::checkRight('ticket', UPDATE);

        $tickets_id   = (int)($_POST['tickets_id']   ?? 0);
        $workflows_id = (int)($_POST['workflows_id'] ?? 0);

        if (!$tickets_id || !$workflows_id) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']);
            exit;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }

        if (!Workflow::applyToTicket($tickets_id, $workflows_id)) {
            echo json_encode(['success' => false, 'message' => __('Could not apply workflow. The ticket may already have an active workflow, the workflow may have no steps, or the first task template is invalid.', 'tasksmanager')]);
            exit;
        }

        echo json_encode(['success' => true]);
        break;

    // ── Remove active workflow from ticket ────────────────────────────────
    case 'remove_from_ticket':
        Session::checkRight('ticket', UPDATE);

        $tickets_id = (int)($_POST['tickets_id'] ?? 0);
        if (!$tickets_id) {
            echo json_encode(['success' => false, 'message' => 'Missing tickets_id']);
            exit;
        }
        $DB->update(
            'glpi_plugin_tasksmanager_ticket_workflows',
            ['status' => 'cancelled'],
            ['tickets_id' => $tickets_id, 'status' => 'active']
        );
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        break;
}
