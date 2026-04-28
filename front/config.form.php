<?php

/**
 * Tasks Manager - Configuration form
 */

use GlpiPlugin\Tasksmanager\Config;

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

// Generate CSRF token BEFORE Html::header() so it is persisted to session
// before GLPI 11 flushes the session during header rendering.
$csrf_token = Session::getNewCSRFToken();

if (isset($_POST['update'])) {
    Session::checkCSRF($_POST);

    Config::setConfigValue('default_priority', $_POST['default_priority'] ?? '3');
    Config::setConfigValue('enable_notifications', $_POST['enable_notifications'] ?? '1');

    Session::addMessageAfterRedirect(
        __('Configuration updated successfully', 'tasksmanager'),
        true,
        INFO
    );
    Html::redirect($_SERVER['REQUEST_URI']);
}

Html::header(
    __('Tasks Manager Configuration', 'tasksmanager'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

Config::showConfigForm($csrf_token);

Html::footer();
