<?php

namespace GlpiPlugin\Tasksmanager;

use CommonDBTM;
use Session;
use Html;

/**
 * Config - Plugin configuration management
 */
class Config extends CommonDBTM
{
    public static $rightname = 'config';

    /**
     * @param int $nb
     * @return string
     */
    public static function getTypeName($nb = 0): string
    {
        return __('Tasks Manager Configuration', 'tasksmanager');
    }

    /**
     * Get a config value by key
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function getConfigValue(string $key, mixed $default = null): mixed
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_configs',
            'WHERE' => ['key' => $key],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return $row['value'];
        }

        return $default;
    }

    /**
     * Set a config value
     *
     * @param string $key
     * @param string $value
     * @return bool
     */
    public static function setConfigValue(string $key, string $value): bool
    {
        global $DB;

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_configs',
            'WHERE' => ['key' => $key],
            'LIMIT' => 1,
        ]);

        if (count($existing)) {
            return $DB->update(
                'glpi_plugin_tasksmanager_configs',
                ['value' => $value],
                ['key'   => $key]
            );
        }

        return (bool) $DB->insert('glpi_plugin_tasksmanager_configs', [
            'key'   => $key,
            'value' => $value,
        ]);
    }

    /**
     * Display the configuration form
     */
    public static function showConfigForm(string $csrf_token = ''): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $default_priority      = self::getConfigValue('default_priority', '3');
        $enable_notifications  = self::getConfigValue('enable_notifications', '1');

        echo '<form method="post" action="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlspecialchars($csrf_token) . '">';

        echo '<div class="card">';
        echo '<div class="card-header"><h3>' . __('Tasks Manager Settings', 'tasksmanager') . '</h3></div>';
        echo '<div class="card-body">';

        echo '<div class="mb-3">';
        echo '<label class="form-label">' . __('Default task priority', 'tasksmanager') . '</label>';
        echo '<select name="default_priority" class="form-select">';
        foreach (TaskState::getPriorityChoices() as $val => $label) {
            $selected = ((int)$default_priority === $val) ? ' selected' : '';
            echo '<option value="' . $val . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label">' . __('Enable notifications', 'tasksmanager') . '</label>';
        echo '<select name="enable_notifications" class="form-select">';
        echo '<option value="1"' . ($enable_notifications === '1' ? ' selected' : '') . '>'
            . __('Yes') . '</option>';
        echo '<option value="0"' . ($enable_notifications === '0' ? ' selected' : '') . '>'
            . __('No') . '</option>';
        echo '</select>';
        echo '</div>';

        echo '</div>';
        echo '<div class="card-footer text-end">';
        echo '<button type="submit" name="update" class="btn btn-primary">'
            . __('Save') . '</button>';
        echo '</div>';
        echo '</div>';

        Html::closeForm();
    }
}
