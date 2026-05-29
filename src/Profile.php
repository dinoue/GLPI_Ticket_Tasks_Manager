<?php

namespace GlpiPlugin\Tasksmanager;

use CommonDBTM;
use CommonGLPI;
use Html;
use Profile as GlpiProfile;
use ProfileRight;
use Session;

/**
 * Profile — adds a "Tasks Manager" tab to GLPI profiles so admins can
 * grant fine-grained rights to the plugin (manage workflows, etc.)
 * instead of relying on the catch-all `config` right.
 */
class Profile extends CommonDBTM
{
    /** Right name persisted in glpi_profilerights.name */
    public const RIGHT_WORKFLOWS = 'plugin_tasksmanager_workflows';

    public static function getTypeName($nb = 0): string
    {
        return __('Tasks Manager', 'tasksmanager');
    }

    /**
     * Tabler icon shown next to the tab label.
     * GLPI 11 picks this up automatically for CommonGLPI tabs.
     */
    public static function getIcon(): string
    {
        return 'ti ti-user-check';
    }

    /**
     * Definition of every plugin right and the granular permissions it exposes.
     * Used by displayTabContentForItem() to render the rights matrix.
     */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => Workflow::class,
                'label'    => __('Workflows', 'tasksmanager'),
                'field'    => self::RIGHT_WORKFLOWS,
                'rights'   => [
                    READ   => __('Read'),
                    UPDATE => __('Update'),
                    CREATE => __('Create'),
                    DELETE => __('Delete'),
                    PURGE  => __('Purge'),
                ],
            ],
        ];
    }

    /**
     * Tab label + icon shown on Profile forms.
     *
     * Returning the bare string `self::getTypeName()` makes GLPI 11
     * render the label without the icon — `getIcon()` alone isn't
     * picked up for plugin tabs. Use `CommonGLPI::createTabEntry()`
     * to bind the Tabler icon explicitly (same pattern as our
     * TaskDashboard Workflow tab on tickets).
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === GlpiProfile::class) {
            return self::createTabEntry(
                self::getTypeName(),
                0,
                $item::class,
                self::getIcon()
            );
        }
        return '';
    }

    /**
     * Render the rights matrix when the tab is clicked.
     */
    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ) {
        if ($item->getType() === GlpiProfile::class) {
            $instance = new self();
            $instance->displayRightsForm($item->getID());
        }
        return true;
    }

    /**
     * Render the rights matrix for a profile. (Named distinct from
     * CommonDBTM::showForm so we don't clash with its signature.)
     */
    public function displayRightsForm(int $profiles_id, bool $openform = true, bool $closeform = true): bool
    {
        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);
        if (!self::canView()) {
            return false;
        }

        $profile = new GlpiProfile();
        $profile->getFromDB($profiles_id);

        if ($openform) {
            echo '<form method="post" action="'
                . htmlspecialchars(GlpiProfile::getFormURL()) . '">';
        }

        $matrix_options = [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
        ];

        $profile->displayRightsChoiceMatrix(self::getAllRights(), $matrix_options);

        if ($canedit && $closeform) {
            echo '<div class="text-center mt-3">';
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(__('Save'), [
                'name'  => 'update',
                'class' => 'btn btn-primary',
            ]);
            echo '</div>';
            Html::closeForm();
        }

        return true;
    }

    /** @var string */
    public static $rightname = 'profile';

    /**
     * Install — declare the right and grant it to the super-admin profile (id 4)
     * so the admin who installs the plugin can actually use it out of the box.
     */
    public static function install(): void
    {
        global $DB;

        // ProfileRight::addProfileRights inserts a row per profile and will
        // throw a duplicate-key error if any rows already exist (e.g. on a
        // second upgrade). Only call it for profiles that don't already
        // have the right.
        $existing = [];
        if ($DB->tableExists('glpi_profilerights')) {
            foreach ($DB->request([
                'SELECT' => ['profiles_id'],
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => ['name' => self::RIGHT_WORKFLOWS],
            ]) as $row) {
                $existing[(int)$row['profiles_id']] = true;
            }
        }

        if (empty($existing)) {
            // No row yet anywhere — let GLPI insert defaults for every profile.
            ProfileRight::addProfileRights([self::RIGHT_WORKFLOWS]);
        } else {
            // Backfill any profiles that don't already have the row (e.g. new
            // profiles created after the first install).
            $all_profiles = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_profiles',
            ]);
            foreach ($all_profiles as $p) {
                $pid = (int)$p['id'];
                if (!isset($existing[$pid])) {
                    $DB->insert('glpi_profilerights', [
                        'profiles_id' => $pid,
                        'name'        => self::RIGHT_WORKFLOWS,
                        'rights'      => 0,
                    ]);
                }
            }
        }

        // Grant full rights to super-admin (default profile id 4) if present
        $DB->update(
            'glpi_profilerights',
            ['rights' => READ | UPDATE | CREATE | DELETE | PURGE],
            ['profiles_id' => 4, 'name' => self::RIGHT_WORKFLOWS]
        );
    }

    /**
     * Uninstall — drop the right from every profile.
     */
    public static function uninstall(): void
    {
        ProfileRight::deleteProfileRights([self::RIGHT_WORKFLOWS]);
    }
}
