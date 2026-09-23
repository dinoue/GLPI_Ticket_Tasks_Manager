<?php

namespace GlpiPlugin\Tasksmanager;

use CommonDBTM;
use GlpiPlugin\Tasksmanager\Automation\AriaConnector;
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
     * Save the Aria Automation settings from the config form.
     *
     * Returns an error message, or null on success. An empty token field
     * keeps the stored token. A new token or URL drops the cached access
     * token so the next cron run logs in with the new credentials.
     */
    public static function saveAriaSettings(array $input): ?string
    {
        $url = rtrim(trim((string)($input['aria_url'] ?? '')), '/');
        if ($url !== '' && !AriaConnector::isValidUrl($url)) {
            return __('Aria URL must be a valid https:// URL.', 'tasksmanager');
        }

        $drop_cache = $url !== (string)self::getConfigValue(AriaConnector::CFG_URL, '');
        self::setConfigValue(AriaConnector::CFG_URL, $url);
        self::setConfigValue(
            AriaConnector::CFG_VERIFY_SSL,
            ($input['aria_verify_ssl'] ?? '1') === '0' ? '0' : '1'
        );

        $token = trim((string)($input['aria_refresh_token'] ?? ''));
        if ($token !== '') {
            self::setConfigValue(AriaConnector::CFG_REFRESH_TOKEN, (string)(new \GLPIKey())->encrypt($token));
            $drop_cache = true;
        } elseif (!empty($input['aria_refresh_token_clear'])) {
            self::setConfigValue(AriaConnector::CFG_REFRESH_TOKEN, '');
            $drop_cache = true;
        }

        if ($drop_cache) {
            self::setConfigValue(AriaConnector::CFG_ACCESS_TOKEN, '');
            self::setConfigValue(AriaConnector::CFG_ACCESS_EXPIRES, '0');
        }
        return null;
    }

    /**
     * Display the configuration form
     */
    public static function showConfigForm(): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $default_priority      = self::getConfigValue('default_priority', '3');
        $enable_notifications  = self::getConfigValue('enable_notifications', '1');

        echo '<form method="post" action="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">';
        echo '<script>document.querySelector(".glpi-csrf-token").value=document.querySelector("meta[property=\'glpi:csrf_token\']")?.getAttribute("content")??"";'
           . '</script>';

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

        // ── Aria Automation (automation steps) ────────────────────────────
        // The refresh token is write-only: we show whether one is stored,
        // never its value (not even encrypted).
        $aria_url       = (string)self::getConfigValue(AriaConnector::CFG_URL, '');
        $has_token      = (string)self::getConfigValue(AriaConnector::CFG_REFRESH_TOKEN, '') !== '';
        $aria_verify    = (string)self::getConfigValue(AriaConnector::CFG_VERIFY_SSL, '1');

        echo '<h4 class="mt-4">' . __('Aria Automation', 'tasksmanager') . '</h4>';
        echo '<div class="mb-3">';
        echo '<label class="form-label" for="tm-aria-url">' . __('Aria URL', 'tasksmanager') . '</label>';
        echo '<input type="url" id="tm-aria-url" name="aria_url" class="form-control"'
            . ' placeholder="https://aria.example.com"'
            . ' value="' . htmlspecialchars($aria_url, ENT_QUOTES) . '">';
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="tm-aria-token">' . __('Refresh token', 'tasksmanager') . '</label>';
        echo '<input type="password" id="tm-aria-token" name="aria_refresh_token" class="form-control"'
            . ' autocomplete="new-password" value=""'
            . ' placeholder="' . htmlspecialchars(
                $has_token
                    ? __('A token is stored — leave empty to keep it', 'tasksmanager')
                    : __('Not set', 'tasksmanager'),
                ENT_QUOTES
            ) . '">';
        if ($has_token) {
            echo '<div class="form-check mt-1">';
            echo '<input type="checkbox" class="form-check-input" id="tm-aria-token-clear" name="aria_refresh_token_clear" value="1">';
            echo '<label class="form-check-label" for="tm-aria-token-clear">'
                . __('Remove the stored token', 'tasksmanager') . '</label>';
            echo '</div>';
        }
        echo '<div class="text-muted small">'
            . __('Stored encrypted with the GLPI key. Used by automation steps from the "automationjobs" automatic action.', 'tasksmanager')
            . '</div>';
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="tm-aria-verify">' . __('Verify TLS certificate', 'tasksmanager') . '</label>';
        echo '<select id="tm-aria-verify" name="aria_verify_ssl" class="form-select">';
        echo '<option value="1"' . ($aria_verify !== '0' ? ' selected' : '') . '>' . __('Yes') . '</option>';
        echo '<option value="0"' . ($aria_verify === '0' ? ' selected' : '') . '>' . __('No') . '</option>';
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
