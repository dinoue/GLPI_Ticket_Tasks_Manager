<?php

/**
 * Tasks Manager - Workflow editor
 */

use GlpiPlugin\Tasksmanager\Workflow;

include('../../../inc/includes.php');

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

global $DB;

$workflow_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_new      = ($workflow_id === 0);

// ── Save workflow metadata ─────────────────────────────────────────────────
if (isset($_POST['save_workflow'])) {
    $name      = trim($_POST['name'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        Session::addMessageAfterRedirect(__('Name is required.', 'tasksmanager'), true, ERROR);
        Html::redirect($_SERVER['REQUEST_URI']);
    }

    if ($is_new) {
        $DB->insert('glpi_plugin_tasksmanager_workflows', [
            'name'          => $name,
            'is_active'     => $is_active,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $workflow_id = $DB->insertId();
        Session::addMessageAfterRedirect(__('Workflow created.', 'tasksmanager'), true, INFO);
        Html::redirect('workflow.form.php?id=' . $workflow_id);
    } else {
        $DB->update('glpi_plugin_tasksmanager_workflows',
            ['name' => $name, 'is_active' => $is_active],
            ['id'   => $workflow_id]
        );
        Session::addMessageAfterRedirect(__('Workflow saved.', 'tasksmanager'), true, INFO);
        Html::redirect($_SERVER['REQUEST_URI']);
    }
}

// ── Load existing workflow ─────────────────────────────────────────────────
if (!$is_new) {
    $wf_iter = $DB->request([
        'FROM'  => 'glpi_plugin_tasksmanager_workflows',
        'WHERE' => ['id' => $workflow_id],
        'LIMIT' => 1,
    ]);
    if (count($wf_iter) === 0) {
        Html::displayNotFoundError();
    }
    $wf_data = $wf_iter->current();

    $steps = iterator_to_array($DB->request([
        'SELECT'    => ['wfs.id', 'wfs.step_order', 'tt.name AS tpl_name'],
        'FROM'      => 'glpi_plugin_tasksmanager_workflow_steps AS wfs',
        'LEFT JOIN' => [
            'glpi_tasktemplates AS tt' => ['ON' => ['wfs' => 'tasktemplates_id', 'tt' => 'id']],
        ],
        'WHERE' => ['wfs.workflows_id' => $workflow_id],
        'ORDER' => ['wfs.step_order ASC'],
    ]));
} else {
    $wf_data = ['name' => '', 'is_active' => 1];
    $steps   = [];
}

// All task templates for the "add step" dropdown
$all_templates = iterator_to_array($DB->request([
    'FROM'  => 'glpi_tasktemplates',
    'ORDER' => ['name ASC'],
]));

$ajax_url = Plugin::getWebDir('tasksmanager') . '/ajax/workflow.php';

Html::header(
    $is_new ? __('New Workflow', 'tasksmanager') : __('Edit Workflow', 'tasksmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    Workflow::class
);
?>
<div class="container-fluid mt-3" style="max-width:800px">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="ti ti-git-branch me-2"></i>
            <?= $is_new ? __('New Workflow', 'tasksmanager') : htmlspecialchars($wf_data['name']) ?>
        </h2>
        <a href="workflow.list.php" class="btn btn-outline-secondary btn-sm">
            <i class="ti ti-arrow-left me-1"></i><?= __('Back to list', 'tasksmanager') ?>
        </a>
    </div>

    <!-- Workflow metadata -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><?= __('Workflow details', 'tasksmanager') ?></h5></div>
        <div class="card-body">
            <form method="post" action="">
                <input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">
                <div class="mb-3">
                    <label class="form-label fw-bold" for="wf-name">
                        <?= __('Name') ?> <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="wf-name" name="name" class="form-control"
                           value="<?= htmlspecialchars($wf_data['name']) ?>" required>
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="wf-active" name="is_active"
                           <?= $wf_data['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wf-active"><?= __('Active') ?></label>
                </div>
                <button type="submit" name="save_workflow" class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i><?= __('Save') ?>
                </button>
            </form>
        </div>
    </div>

<?php if (!$is_new): ?>
    <!-- Steps builder -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="ti ti-list-numbers me-1"></i><?= __('Workflow steps', 'tasksmanager') ?>
            </h5>
        </div>
        <div class="card-body">

            <p class="text-muted small mb-3">
                <?= __('When a step\'s task is completed, the next step\'s task is automatically added to the ticket.', 'tasksmanager') ?>
            </p>

            <!-- Existing steps -->
            <table class="table table-sm mb-3" id="tm-steps-table">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px">#</th>
                        <th><?= __('Task template', 'tasksmanager') ?></th>
                        <th style="width:120px"></th>
                    </tr>
                </thead>
                <tbody id="tm-steps-body">
                <?php if (empty($steps)): ?>
                    <tr id="tm-empty-row">
                        <td colspan="3" class="text-muted text-center py-3">
                            <?= __('No steps yet. Add a task template below.', 'tasksmanager') ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($steps as $i => $step): ?>
                    <tr data-step-id="<?= (int)$step['id'] ?>">
                        <td class="tm-step-num text-muted"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($step['tpl_name'] ?? '—') ?></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary tm-btn-up px-1"
                                    title="<?= __('Move up') ?>" onclick="tmMoveStep(this, -1)">
                                <i class="ti ti-arrow-up"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary tm-btn-down px-1"
                                    title="<?= __('Move down') ?>" onclick="tmMoveStep(this, 1)">
                                <i class="ti ti-arrow-down"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger px-1"
                                    title="<?= __('Remove') ?>" onclick="tmRemoveStep(this)">
                                <i class="ti ti-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Add step -->
            <div class="d-flex gap-2 align-items-center">
                <select id="tm-new-tpl" class="form-select form-select-sm" style="max-width:400px">
                    <option value=""><?= __('-- Select a task template to add --', 'tasksmanager') ?></option>
                    <?php foreach ($all_templates as $tpl): ?>
                        <option value="<?= (int)$tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary btn-sm" onclick="tmAddStep()">
                    <i class="ti ti-plus me-1"></i><?= __('Add step', 'tasksmanager') ?>
                </button>
            </div>

        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info">
        <i class="ti ti-info-circle me-1"></i>
        <?= __('Save the workflow first, then you can add steps.', 'tasksmanager') ?>
    </div>
<?php endif; ?>
</div>

<script>
// Populate CSRF tokens from GLPI's meta tag (CheckCsrfListener validates this)
(function () {
    const token = document.querySelector('meta[property="glpi:csrf_token"]')?.getAttribute('content') ?? '';
    document.querySelectorAll('.glpi-csrf-token').forEach(el => { el.value = token; });
})();

(function () {
    const WORKFLOW_ID = <?= (int)$workflow_id ?>;
    const AJAX_URL    = <?= json_encode($ajax_url) ?>;

    function csrfToken() {
        const m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    }

    function post(data) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        return fetch(AJAX_URL, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrfToken(),
            },
        }).then(r => {
            if (!r.ok) return Promise.reject('HTTP ' + r.status);
            return r.json();
        }).catch(err => { console.error('Workflow AJAX error:', err); return {success: false, message: String(err)}; });
    }

    function renumber() {
        document.querySelectorAll('#tm-steps-body tr[data-step-id] .tm-step-num').forEach((el, i) => {
            el.textContent = i + 1;
        });
        const rows = document.querySelectorAll('#tm-steps-body tr[data-step-id]');
        const empty = document.getElementById('tm-empty-row');
        if (empty) empty.style.display = rows.length ? 'none' : '';
    }

    window.tmAddStep = function () {
        const sel = document.getElementById('tm-new-tpl');
        if (!sel.value) return;
        const tplId   = sel.value;
        const tplName = sel.options[sel.selectedIndex].text;

        post({action: 'add_step', workflows_id: WORKFLOW_ID, tasktemplates_id: tplId})
            .then(data => {
                if (!data.success) { alert(data.message || 'Error'); return; }
                const tbody = document.getElementById('tm-steps-body');
                const tr = document.createElement('tr');
                tr.dataset.stepId = data.step_id;
                tr.innerHTML = `
                    <td class="tm-step-num text-muted"></td>
                    <td>${escHtml(tplName)}</td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary tm-btn-up px-1"
                                title="Move up" onclick="tmMoveStep(this,-1)"><i class="ti ti-arrow-up"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary tm-btn-down px-1"
                                title="Move down" onclick="tmMoveStep(this,1)"><i class="ti ti-arrow-down"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-danger px-1"
                                title="Remove" onclick="tmRemoveStep(this)"><i class="ti ti-trash"></i></button>
                    </td>`;
                tbody.appendChild(tr);
                renumber();
                sel.value = '';
            });
    };

    window.tmRemoveStep = function (btn) {
        const row    = btn.closest('tr');
        const stepId = row.dataset.stepId;
        post({action: 'remove_step', step_id: stepId})
            .then(data => {
                if (!data.success) { alert(data.message || 'Error'); return; }
                row.remove();
                renumber();
            });
    };

    window.tmMoveStep = function (btn, dir) {
        const row    = btn.closest('tr');
        const tbody  = document.getElementById('tm-steps-body');
        const rows   = Array.from(tbody.querySelectorAll('tr[data-step-id]'));
        const idx    = rows.indexOf(row);
        const target = rows[idx + dir];
        if (!target) return;

        if (dir === -1) tbody.insertBefore(row, target);
        else            tbody.insertBefore(target, row);

        renumber();

        // Persist the new order
        const ids = Array.from(tbody.querySelectorAll('tr[data-step-id]')).map(r => r.dataset.stepId);
        post({action: 'reorder_steps', workflows_id: WORKFLOW_ID, order: JSON.stringify(ids)});
    };

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    renumber();
})();
</script>
<?php Html::footer(); ?>
