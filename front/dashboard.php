<?php

/**
 * Tasks Manager - Dashboard overview
 */

use GlpiPlugin\Tasksmanager\TaskDashboard;
use GlpiPlugin\Tasksmanager\TaskState;

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    Html::displayNotFoundError();
}

Session::checkRight('ticket', READ);

Html::header(
    TaskDashboard::getTypeName(),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    TaskDashboard::class
);

// Show a global overview of all task states across tickets
echo '<div class="tasksmanager-global-dashboard">';
echo '<h2>' . __('Tasks Manager - Global Overview', 'tasksmanager') . '</h2>';

// Use GLPI Search engine for the listing
Search::show(TaskState::class);

echo '</div>';

Html::footer();
