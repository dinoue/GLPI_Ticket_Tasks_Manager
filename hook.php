<?php

/**
 * Tasks Manager - Install/Uninstall hooks
 *
 * @license   GPL-3.0-or-later
 */


/**
 * Install hook - create database tables and initial config
 *
 * @return bool
 */
function plugin_tasksmanager_install(): bool
{
    global $DB;

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_taskstates
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_taskstates')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_taskstates` (
            `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id`           INT UNSIGNED NOT NULL DEFAULT 0,
            `tickettasks_id`       INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_status`        VARCHAR(50)  NOT NULL DEFAULT 'pending',
            `priority`             TINYINT      NOT NULL DEFAULT 3,
            `due_date`             TIMESTAMP    NULL DEFAULT NULL,
            `assigned_users_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `assigned_groups_id`   INT UNSIGNED NOT NULL DEFAULT 0,
            `progress`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `notes`                TEXT         NULL,
            `ticket_workflows_id`  INT UNSIGNED NULL DEFAULT NULL,
            `workflow_step_order`  INT UNSIGNED NULL DEFAULT NULL,
            `date_creation`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tickets_id`          (`tickets_id`),
            KEY `tickettasks_id`      (`tickettasks_id`),
            KEY `plugin_status`       (`plugin_status`),
            KEY `assigned_users_id`   (`assigned_users_id`),
            KEY `assigned_groups_id`  (`assigned_groups_id`),
            KEY `due_date`            (`due_date`),
            KEY `ticket_workflows_id` (`ticket_workflows_id`),
            KEY `date_mod`            (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    } else {
        // Upgrade: add workflow tracking columns if missing
        if (!$DB->fieldExists('glpi_plugin_tasksmanager_taskstates', 'ticket_workflows_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_tasksmanager_taskstates`
                ADD `ticket_workflows_id` INT UNSIGNED NULL DEFAULT NULL AFTER `notes`,
                ADD `workflow_step_order` INT UNSIGNED NULL DEFAULT NULL AFTER `ticket_workflows_id`,
                ADD KEY `ticket_workflows_id` (`ticket_workflows_id`)");
        }
    }

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_configs
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_configs` (
            `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `key`   VARCHAR(255) NOT NULL,
            `value` TEXT         NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `key` (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");

        $DB->insert('glpi_plugin_tasksmanager_configs', ['key' => 'default_priority',      'value' => '3']);
        $DB->insert('glpi_plugin_tasksmanager_configs', ['key' => 'enable_notifications',  'value' => '1']);
        $DB->insert('glpi_plugin_tasksmanager_configs', [
            'key'   => 'statuses',
            'value' => json_encode(['pending', 'in_progress', 'blocked', 'review', 'done']),
        ]);
    }

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_workflows
    // Named workflow definitions
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_workflows')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_workflows` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`          VARCHAR(255) NOT NULL,
            `description`   TEXT         NULL,
            `is_active`     TINYINT      NOT NULL DEFAULT 1,
            `date_creation` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `name`      (`name`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    }

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_workflow_steps
    // Ordered task-template steps belonging to a workflow
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_workflow_steps')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_workflow_steps` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `workflows_id`     INT UNSIGNED NOT NULL DEFAULT 0,
            `tasktemplates_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `step_order`       INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `workflows_id`  (`workflows_id`),
            KEY `step_and_order` (`workflows_id`, `step_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    }

    // -------------------------------------------------------
    // Table: glpi_plugin_tasksmanager_ticket_workflows
    // Active workflow instance linked to a specific ticket
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_tasksmanager_ticket_workflows')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_tasksmanager_ticket_workflows` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `workflows_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `current_step`  INT UNSIGNED NOT NULL DEFAULT 0,
            `status`        VARCHAR(20)  NOT NULL DEFAULT 'active',
            `date_creation` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tickets_id`   (`tickets_id`),
            KEY `workflows_id` (`workflows_id`),
            KEY `status`       (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    }

    // Create plugin document directory
    $plugin_doc_dir = GLPI_PLUGIN_DOC_DIR . '/tasksmanager';
    if (!is_dir($plugin_doc_dir)) {
        mkdir($plugin_doc_dir, 0755, true);
    }

    return true;
}

/**
 * Uninstall hook - remove database tables and files
 *
 * @return bool
 */
function plugin_tasksmanager_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_tasksmanager_ticket_workflows',
        'glpi_plugin_tasksmanager_workflow_steps',
        'glpi_plugin_tasksmanager_workflows',
        'glpi_plugin_tasksmanager_taskstates',
        'glpi_plugin_tasksmanager_configs',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $plugin_doc_dir = GLPI_PLUGIN_DOC_DIR . '/tasksmanager';
    if (is_dir($plugin_doc_dir)) {
        Toolbox::deleteDir($plugin_doc_dir);
    }

    return true;
}

// -------------------------------------------------------
// Hook callbacks for ticket task events
// -------------------------------------------------------

/**
 * Callback when a Ticket is added — apply workflow from form destination if requested.
 * The workflow ID is injected into the ticket input by WorkflowField::applyConfiguratedValueToInputUsingAnswers().
 * This fires for both direct form submissions and post-approval ticket creation.
 */
function plugin_tasksmanager_ticket_add(Ticket $item): void
{
    $workflows_id = (int)($item->input['_plugin_tasksmanager_workflows_id'] ?? 0);
    if (!$workflows_id) {
        return;
    }
    \GlpiPlugin\Tasksmanager\Workflow::applyToTicket($item->getID(), $workflows_id);
}

/**
 * Callback when a TicketTask is added — create a basic taskstate record.
 */
function plugin_tasksmanager_item_add(TicketTask $item): void
{
    global $DB;

    // Skip if a taskstate was already created by the workflow engine
    $existing = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_taskstates',
        'WHERE' => ['tickettasks_id' => $item->getID()],
        'LIMIT' => 1,
    ]);
    if (count($existing) > 0) {
        return;
    }

    $DB->insert('glpi_plugin_tasksmanager_taskstates', [
        'tickets_id'     => $item->fields['tickets_id'],
        'tickettasks_id' => $item->getID(),
        'plugin_status'  => 'pending',
        'priority'       => 3,
        'date_creation'  => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Callback when a TicketTask is updated.
 * When a task is marked done (state=2) and it belongs to a workflow step,
 * this automatically creates the next step's task on the ticket and updates
 * the ticket's assigned technician/group to match the new task's template.
 */
function plugin_tasksmanager_item_update(TicketTask $item): void
{
    global $DB;

    // Act when the task reaches state=2 (Done), whether via checkbox or status dropdown
    $new_state = isset($item->input['state']) ? (int)$item->input['state'] : (int)$item->fields['state'];
    if ($new_state !== 2) {
        return;
    }

    // Sync our status tracking
    $DB->update(
        'glpi_plugin_tasksmanager_taskstates',
        ['plugin_status' => 'done', 'progress' => 100],
        ['tickettasks_id' => $item->getID()]
    );

    // Check whether this task belongs to an active workflow step
    $taskstate_iter = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_taskstates',
        'WHERE' => [
            'tickettasks_id'      => $item->getID(),
            'ticket_workflows_id' => ['>', 0],
        ],
        'LIMIT' => 1,
    ]);

    if (count($taskstate_iter) === 0) {
        return;
    }

    $taskstate           = $taskstate_iter->current();
    $ticket_workflows_id = (int)$taskstate['ticket_workflows_id'];
    $current_step_order  = (int)$taskstate['workflow_step_order'];

    // Load the ticket workflow record and verify it is still active
    $tw_iter = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
        'WHERE' => ['id' => $ticket_workflows_id, 'status' => 'active'],
        'LIMIT' => 1,
    ]);

    if (count($tw_iter) === 0) {
        return;
    }

    $ticket_workflow = $tw_iter->current();
    $workflows_id    = (int)$ticket_workflow['workflows_id'];
    $tickets_id      = (int)$ticket_workflow['tickets_id'];

    // Find the next step after the current one
    $next_iter = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
        'WHERE' => [
            'workflows_id' => $workflows_id,
            'step_order'   => ['>', $current_step_order],
        ],
        'ORDER' => ['step_order ASC'],
        'LIMIT' => 1,
    ]);

    if (count($next_iter) === 0) {
        // All steps done — mark workflow complete
        $DB->update(
            'glpi_plugin_tasksmanager_ticket_workflows',
            ['status' => 'completed'],
            ['id' => $ticket_workflows_id]
        );
        return;
    }

    $next_step = $next_iter->current();

    // Only advance current_step if the task was actually created
    if (plugin_tasksmanager_apply_workflow_step($tickets_id, $next_step, $ticket_workflows_id)) {
        $DB->update(
            'glpi_plugin_tasksmanager_ticket_workflows',
            ['current_step' => $next_step['step_order']],
            ['id' => $ticket_workflows_id]
        );
    }
}

/**
 * Thin wrapper so legacy callers and the AJAX endpoint can use the class method.
 */
function plugin_tasksmanager_apply_workflow_step(int $tickets_id, array $step, int $ticket_workflows_id): bool
{
    return \GlpiPlugin\Tasksmanager\Workflow::applyStep($tickets_id, $step, $ticket_workflows_id);
}

