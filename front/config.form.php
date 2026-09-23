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

// "Save and test connection" buttons submit the same form, so they save too.
if (isset($_POST['update']) || isset($_POST['test_aria']) || isset($_POST['test_vsphere'])) {
    // CSRF already validated by GLPI 11 CheckCsrfListener

    Config::setConfigValue('default_priority', $_POST['default_priority'] ?? '3');
    Config::setConfigValue('enable_notifications', $_POST['enable_notifications'] ?? '1');

    $error = Config::saveAriaSettings($_POST) ?? Config::saveVsphereSettings($_POST);
    if ($error !== null) {
        Session::addMessageAfterRedirect($error, true, ERROR);
        Html::redirect($_SERVER['REQUEST_URI']);
    }

    $test = Config::runConnectionTest($_POST);
    if ($test !== null) {
        Session::addMessageAfterRedirect(htmlspecialchars($test[1]), true, $test[0] ? INFO : ERROR);
        Html::redirect($_SERVER['REQUEST_URI']);
    }

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

Config::showConfigForm();

Html::footer();
