<?php

/**
 * Tasks Manager - TaskState CRUD form
 */

use GlpiPlugin\Tasksmanager\TaskState;

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    Html::displayNotFoundError();
}

Session::checkRight('ticket', READ);

$taskstate = new TaskState();

if (isset($_POST['update'])) {
    // CSRF already validated by GLPI 11 CheckCsrfListener
    $taskstate->check($_POST['id'], UPDATE);
    $taskstate->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    // CSRF already validated by GLPI 11 CheckCsrfListener
    $taskstate->check($_POST['id'], PURGE);
    $taskstate->delete($_POST, 1);
    $taskstate->redirectToList();
} else {
    Html::header(
        TaskState::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'ticket'
    );

    if (isset($_GET['id'])) {
        $taskstate->display(['id' => $_GET['id']]);
    }

    Html::footer();
}
