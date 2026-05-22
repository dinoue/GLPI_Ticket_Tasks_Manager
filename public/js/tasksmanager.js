/**
 * Tasks Manager - Client-side interactions
 */
(function () {
    'use strict';

    const TasksManager = {

        _workflowAjaxUrl: null,

        // ── Helpers ───────────────────────────────────────────────────────

        _post: function (url, data, callback) {
            const fd = new FormData();
            fd.append('_glpi_csrf_token', getAjaxCsrfToken());
            for (const [k, v] of Object.entries(data)) fd.append(k, v);
            fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(callback)
                .catch(err => console.error('TasksManager AJAX error:', err));
        },

        // ── Task-state status update ──────────────────────────────────────

        updateStatus: function (taskStateId, newStatus, progress) {
            const url = CFG_GLPI.root_doc + '/plugins/tasksmanager/ajax/taskstate.php';

            const data = { action: 'update_status', id: taskStateId, plugin_status: newStatus };
            if (typeof progress !== 'undefined') data.progress = progress;

            TasksManager._post(url, data, function (resp) {
                if (resp.ok) {
                    location.reload();
                } else {
                    glpi_alert({ title: 'Tasks Manager', message: resp.error || 'Update failed' });
                }
            });
        },

        // ── Workflow: apply to ticket ─────────────────────────────────────

        applyWorkflow: function (ticketsId) {
            const sel = document.getElementById('tm-ticket-workflow-select');
            const workflowId = sel ? sel.value : '';

            if (!workflowId) {
                glpi_alert({ title: 'Tasks Manager', message: 'Please select a workflow.' });
                return;
            }

            const url = TasksManager._workflowAjaxUrl
                || (CFG_GLPI.root_doc + '/plugins/tasksmanager/ajax/workflow.php');

            TasksManager._post(url, {
                action:      'apply_to_ticket',
                tickets_id:  ticketsId,
                workflows_id: workflowId,
            }, function (resp) {
                if (resp.ok) {
                    location.reload();
                } else {
                    glpi_alert({ title: 'Tasks Manager', message: resp.error || 'Failed to apply workflow' });
                }
            });
        },

        // ── Workflow: remove from ticket ──────────────────────────────────

        removeWorkflow: function (ticketsId) {
            if (!confirm('Remove the active workflow from this ticket?')) return;

            const url = TasksManager._workflowAjaxUrl
                || (CFG_GLPI.root_doc + '/plugins/tasksmanager/ajax/workflow.php');

            TasksManager._post(url, {
                action:     'remove_from_ticket',
                tickets_id: ticketsId,
            }, function (resp) {
                if (resp.ok) {
                    location.reload();
                } else {
                    glpi_alert({ title: 'Tasks Manager', message: resp.error || 'Failed to remove workflow' });
                }
            });
        },

        // ── Generic click-action listener ─────────────────────────────────

        init: function () {
            document.querySelectorAll('[data-tasksmanager-action]').forEach(function (el) {
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    const action = el.dataset.tasksmanagerAction;
                    const id     = el.dataset.taskstateId;
                    const status = el.dataset.newStatus;

                    if (action === 'update_status' && id && status) {
                        TasksManager.updateStatus(id, status);
                    }
                });
            });
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', TasksManager.init);
    } else {
        TasksManager.init();
    }

    window.TasksManager = TasksManager;
})();
