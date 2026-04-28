<?php

namespace GlpiPlugin\Tasksmanager;

use CommonDBTM;
use CommonGLPI;
use Search;
use Session;

/**
 * TaskState - Extended state tracking for ticket tasks
 */
class TaskState extends CommonDBTM
{
    // Table name is derived automatically: glpi_plugin_tasksmanager_taskstates
    public static $rightname = 'ticket';

    /**
     * @param int $nb
     * @return string
     */
    public static function getTypeName($nb = 0): string
    {
        return _n('Task State', 'Task States', $nb, 'tasksmanager');
    }

    /**
     * Get valid plugin_status values
     *
     * @return array
     */
    public static function getStatusChoices(): array
    {
        return [
            'pending'     => __('Pending', 'tasksmanager'),
            'in_progress' => __('In Progress', 'tasksmanager'),
            'blocked'     => __('Blocked', 'tasksmanager'),
            'review'      => __('In Review', 'tasksmanager'),
            'done'        => __('Done', 'tasksmanager'),
        ];
    }

    /**
     * Get priority labels
     *
     * @return array
     */
    public static function getPriorityChoices(): array
    {
        return [
            1 => __('Very low'),
            2 => __('Low'),
            3 => __('Medium'),
            4 => __('High'),
            5 => __('Very high'),
            6 => __('Critical'),
        ];
    }

    /**
     * Define search options for Search engine integration
     *
     * @return array
     */
    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => '2',
            'table'         => self::getTable(),
            'field'         => 'plugin_status',
            'name'          => __('Status', 'tasksmanager'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals'],
        ];

        $tab[] = [
            'id'            => '3',
            'table'         => self::getTable(),
            'field'         => 'priority',
            'name'          => __('Priority'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals'],
        ];

        $tab[] = [
            'id'            => '4',
            'table'         => self::getTable(),
            'field'         => 'due_date',
            'name'          => __('Due date', 'tasksmanager'),
            'datatype'      => 'datetime',
        ];

        $tab[] = [
            'id'            => '5',
            'table'         => self::getTable(),
            'field'         => 'progress',
            'name'          => __('Progress', 'tasksmanager'),
            'datatype'      => 'integer',
            'min'           => 0,
            'max'           => 100,
            'unit'          => '%',
        ];

        $tab[] = [
            'id'            => '6',
            'table'         => 'glpi_users',
            'field'         => 'name',
            'name'          => __('Assigned user', 'tasksmanager'),
            'datatype'      => 'dropdown',
            'right'         => 'all',
            'linkfield'     => 'assigned_users_id',
        ];

        $tab[] = [
            'id'            => '7',
            'table'         => 'glpi_groups',
            'field'         => 'completename',
            'name'          => __('Assigned group', 'tasksmanager'),
            'datatype'      => 'dropdown',
            'linkfield'     => 'assigned_groups_id',
        ];

        $tab[] = [
            'id'            => '8',
            'table'         => self::getTable(),
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }

    /**
     * Prepare input for adding a new item
     *
     * @param array $input
     * @return array|false
     */
    public function prepareInputForAdd($input)
    {
        if (!isset($input['plugin_status'])) {
            $input['plugin_status'] = 'pending';
        }

        if (!isset($input['priority'])) {
            $input['priority'] = 3;
        }

        // Validate progress range
        if (isset($input['progress'])) {
            $input['progress'] = max(0, min(100, (int)$input['progress']));
        }

        return $input;
    }

    /**
     * Prepare input for updating an item
     *
     * @param array $input
     * @return array|false
     */
    public function prepareInputForUpdate($input)
    {
        // Validate progress range
        if (isset($input['progress'])) {
            $input['progress'] = max(0, min(100, (int)$input['progress']));
        }

        // Auto-set progress to 100 when status is done
        if (isset($input['plugin_status']) && $input['plugin_status'] === 'done') {
            $input['progress'] = 100;
        }

        return $input;
    }

    /**
     * Get task states for a given ticket
     *
     * @param int $tickets_id
     * @return array
     */
    public static function getForTicket(int $tickets_id): array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['tickets_id' => $tickets_id],
            'ORDER' => ['priority DESC', 'due_date ASC'],
        ]);

        $results = [];
        foreach ($iterator as $row) {
            $results[] = $row;
        }
        return $results;
    }
}
