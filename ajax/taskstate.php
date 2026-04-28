<?php

/**
 * Tasks Manager - AJAX handler for task state updates
 */

use GlpiPlugin\Tasksmanager\TaskState;

include('../../../inc/includes.php');

// Enforce authentication
Session::checkLoginUser();
Session::checkRight('ticket', UPDATE);

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'update_status':
            Session::checkCSRF($_POST);
            $taskstate = new TaskState();
            $id = (int)($_POST['id'] ?? 0);

            if (!$taskstate->getFromDB($id)) {
                throw new \RuntimeException('Task state not found');
            }

            $taskstate->check($id, UPDATE);
            $result = $taskstate->update([
                'id'            => $id,
                'plugin_status' => $_POST['plugin_status'] ?? $taskstate->fields['plugin_status'],
                'progress'      => $_POST['progress'] ?? $taskstate->fields['progress'],
            ]);

            echo json_encode([
                'success' => (bool)$result,
                'message' => $result ? __('Status updated', 'tasksmanager') : __('Update failed', 'tasksmanager'),
            ]);
            break;

        case 'get_states':
            $tickets_id = (int)($_GET['tickets_id'] ?? 0);
            if ($tickets_id <= 0) {
                throw new \RuntimeException('Invalid ticket ID');
            }
            $states = TaskState::getForTicket($tickets_id);
            echo json_encode(['success' => true, 'data' => $states]);
            break;

        default:
            throw new \RuntimeException('Unknown action');
    }
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
