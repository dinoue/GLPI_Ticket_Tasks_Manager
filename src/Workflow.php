<?php

namespace GlpiPlugin\Tasksmanager;

use CommonDBTM;
use Plugin;
use Session;

/**
 * Workflow - a named, ordered sequence of GLPI task templates.
 *
 * When assigned to a ticket the first template's task is created immediately.
 * Each time a step's task is completed, the next template is instantiated
 * and the ticket assignment is updated to the new task's tech/group.
 */
class Workflow extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return _n('Workflow', 'Workflows', $nb, 'tasksmanager');
    }

    public static function getIcon(): string
    {
        return 'ti ti-git-branch';
    }

    public static function getMenuContent(): array
    {
        $menu = [
            'title' => self::getTypeName(2),
            'page'  => Plugin::getWebDir('tasksmanager', false) . '/front/workflow.list.php',
            'icon'  => self::getIcon(),
        ];

        if (Session::haveRight('config', UPDATE)) {
            $menu['links']['search'] = Plugin::getWebDir('tasksmanager', false) . '/front/workflow.list.php';
            $menu['links']['add']    = Plugin::getWebDir('tasksmanager', false) . '/front/workflow.form.php';
        }

        return $menu;
    }

    /**
     * Return all steps for this workflow ordered by step_order, with template names resolved.
     *
     * @return array
     */
    public function getSteps(): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                'wfs.id',
                'wfs.step_order',
                'wfs.tasktemplates_id',
                'tt.name AS template_name',
                'tt.users_id_tech',
                'tt.groups_id_tech',
            ],
            'FROM'      => 'glpi_plugin_tasksmanager_workflow_steps AS wfs',
            'LEFT JOIN' => [
                'glpi_tasktemplates AS tt' => [
                    'ON' => ['wfs' => 'tasktemplates_id', 'tt' => 'id'],
                ],
            ],
            'WHERE'     => ['wfs.workflows_id' => $this->getID()],
            'ORDER'     => ['wfs.step_order ASC'],
        ]);

        $steps = [];
        foreach ($iterator as $row) {
            $steps[] = $row;
        }
        return $steps;
    }

    /**
     * Return all active workflows as id => name pairs, for use in dropdowns.
     */
    public static function getDropdownOptions(): array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflows',
            'WHERE' => ['is_active' => 1],
            'ORDER' => ['name ASC'],
        ]);

        $options = [];
        foreach ($iterator as $row) {
            $options[(int)$row['id']] = $row['name'];
        }
        return $options;
    }

    /**
     * Apply a workflow to a ticket: create the ticket_workflow record and
     * instantiate the first step's task.
     *
     * Returns false if the ticket already has an active workflow, if the
     * workflow has no steps, or if the first task could not be created.
     */
    public static function applyToTicket(int $tickets_id, int $workflows_id): bool
    {
        global $DB;

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_ticket_workflows',
            'WHERE' => ['tickets_id' => $tickets_id, 'status' => 'active'],
        ]);
        if (count($existing) > 0) {
            return false;
        }

        $first_step_iter = $DB->request([
            'FROM'  => 'glpi_plugin_tasksmanager_workflow_steps',
            'WHERE' => ['workflows_id' => $workflows_id],
            'ORDER' => ['step_order ASC'],
            'LIMIT' => 1,
        ]);
        if (count($first_step_iter) === 0) {
            return false;
        }

        $first_step = $first_step_iter->current();

        $DB->insert('glpi_plugin_tasksmanager_ticket_workflows', [
            'tickets_id'    => $tickets_id,
            'workflows_id'  => $workflows_id,
            'current_step'  => (int)$first_step['step_order'],
            'status'        => 'active',
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $ticket_workflows_id = $DB->insertId();

        if (!self::applyStep($tickets_id, $first_step, $ticket_workflows_id)) {
            $DB->delete('glpi_plugin_tasksmanager_ticket_workflows', ['id' => $ticket_workflows_id]);
            return false;
        }

        return true;
    }

    /**
     * Create a TicketTask from a workflow step's template and record it in taskstates.
     */
    public static function applyStep(int $tickets_id, array $step, int $ticket_workflows_id): bool
    {
        global $DB;

        $template = new \TaskTemplate();
        if (!$template->getFromDB((int)$step['tasktemplates_id'])) {
            return false;
        }

        $content = $template->fields['content'] ?? '';
        if (empty(trim(strip_tags($content)))) {
            $content = $template->fields['name'] ?? __('Task', 'tasksmanager');
        }

        $input = [
            'tickets_id'  => $tickets_id,
            'content'     => $content,
            'name'        => $template->fields['name'] ?? '',
            'state'       => 1,
            'is_private'  => (int)($template->fields['is_private'] ?? 0),
            'actiontime'  => (int)($template->fields['actiontime'] ?? 0),
        ];

        if (!empty($template->fields['taskcategories_id'])) {
            $input['taskcategories_id'] = (int)$template->fields['taskcategories_id'];
        }
        if (!empty($template->fields['users_id_tech'])) {
            $input['users_id_tech'] = (int)$template->fields['users_id_tech'];
        }
        if (!empty($template->fields['groups_id_tech'])) {
            $input['groups_id_tech'] = (int)$template->fields['groups_id_tech'];
        }

        // IMPORTANT: swap the ticket's ASSIGN actors BEFORE creating the task,
        // so GLPI's "new task" notification (sent inside TicketTask::add())
        // goes to the new step's team, not the previous one.
        if (!empty($template->fields['users_id_tech']) || !empty($template->fields['groups_id_tech'])) {
            self::swapAssignActors(
                $tickets_id,
                (int)($template->fields['users_id_tech']  ?? 0),
                (int)($template->fields['groups_id_tech'] ?? 0)
            );
        }

        $task        = new \TicketTask();
        $new_task_id = $task->add($input);

        if (!$new_task_id) {
            return false;
        }

        $DB->update(
            'glpi_plugin_tasksmanager_taskstates',
            [
                'ticket_workflows_id' => $ticket_workflows_id,
                'workflow_step_order' => (int)$step['step_order'],
            ],
            ['tickettasks_id' => $new_task_id]
        );

        return true;
    }

    /**
     * Replace the ticket's ASSIGN actors (tech + group) while preserving
     * requesters and observers untouched.
     *
     * Goes through Ticket::update(['_actors' => …]) — the canonical GLPI 11
     * path — so the actor-service cache is invalidated and the sidebar
     * reflects the new assignment immediately on reload. Direct inserts to
     * glpi_groups_tickets / glpi_users_tickets bypass that cache and leave
     * the sidebar showing the old assignment.
     *
     * Pass 0 (or a falsy value) for $new_user_id / $new_group_id to skip
     * adding that actor type.
     */
    public static function swapAssignActors(int $tickets_id, int $new_user_id, int $new_group_id): void
    {
        global $DB;

        $actors = ['requester' => [], 'observer' => [], 'assign' => []];

        // Preserve current requesters and observers — both users and groups
        $type_map = [
            \CommonITILActor::REQUESTER => 'requester',
            \CommonITILActor::OBSERVER  => 'observer',
        ];

        foreach ($type_map as $type_const => $type_key) {
            foreach ($DB->request([
                'FROM'  => 'glpi_tickets_users',
                'WHERE' => ['tickets_id' => $tickets_id, 'type' => $type_const],
            ]) as $row) {
                $actors[$type_key][] = [
                    'itemtype'          => 'User',
                    'items_id'          => (int)$row['users_id'],
                    'use_notification'  => $row['use_notification'] ?? 1,
                    'alternative_email' => $row['alternative_email'] ?? '',
                ];
            }
            foreach ($DB->request([
                'FROM'  => 'glpi_groups_tickets',
                'WHERE' => ['tickets_id' => $tickets_id, 'type' => $type_const],
            ]) as $row) {
                $actors[$type_key][] = [
                    'itemtype' => 'Group',
                    'items_id' => (int)$row['groups_id'],
                ];
            }
        }

        // Build the new ASSIGN actor set
        if ($new_user_id > 0) {
            $actors['assign'][] = [
                'itemtype'          => 'User',
                'items_id'          => $new_user_id,
                'use_notification'  => 1,
                'alternative_email' => '',
            ];
        }
        if ($new_group_id > 0) {
            $actors['assign'][] = [
                'itemtype' => 'Group',
                'items_id' => $new_group_id,
            ];
        }

        $ticket = new \Ticket();
        $ticket->update([
            'id'      => $tickets_id,
            '_actors' => $actors,
        ]);
    }
}
