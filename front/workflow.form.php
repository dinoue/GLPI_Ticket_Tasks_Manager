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

Session::checkRight('plugin_tasksmanager_workflows', UPDATE);

global $DB;

$workflow_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_new      = ($workflow_id === 0);

// ── Save workflow metadata ─────────────────────────────────────────────────
if (isset($_POST['save_workflow'])) {
    $name                  = trim($_POST['name'] ?? '');
    $is_active             = isset($_POST['is_active'])             ? 1 : 0;
    $assign_ticket_to_task = isset($_POST['assign_ticket_to_task']) ? 1 : 0;
    $groups_id_completion  = (int)($_POST['groups_id_completion'] ?? 0);

    if ($name === '') {
        Session::addMessageAfterRedirect(__('Name is required.', 'tasksmanager'), true, ERROR);
        Html::redirect($_SERVER['REQUEST_URI']);
    }

    if ($is_new) {
        $DB->insert('glpi_plugin_tasksmanager_workflows', [
            'name'                  => $name,
            'is_active'             => $is_active,
            'assign_ticket_to_task' => $assign_ticket_to_task,
            'groups_id_completion'  => $groups_id_completion,
            'date_creation'         => date('Y-m-d H:i:s'),
        ]);
        $workflow_id = $DB->insertId();
        Session::addMessageAfterRedirect(__('Workflow created.', 'tasksmanager'), true, INFO);
        Html::redirect('workflow.form.php?id=' . $workflow_id);
    } else {
        $DB->update('glpi_plugin_tasksmanager_workflows',
            [
                'name'                  => $name,
                'is_active'             => $is_active,
                'assign_ticket_to_task' => $assign_ticket_to_task,
                'groups_id_completion'  => $groups_id_completion,
            ],
            ['id' => $workflow_id]
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
        'SELECT'    => [
            'wfs.id', 'wfs.step_order', 'wfs.tasktemplates_id',
            'tt.name AS tpl_name',
            'tt.comment AS tpl_comment',
        ],
        'FROM'      => 'glpi_plugin_tasksmanager_workflow_steps AS wfs',
        'LEFT JOIN' => [
            'glpi_tasktemplates AS tt' => ['ON' => ['wfs' => 'tasktemplates_id', 'tt' => 'id']],
        ],
        'WHERE' => ['wfs.workflows_id' => $workflow_id],
        'ORDER' => ['wfs.step_order ASC'],
    ]));

$tasktemplate_base_url = TaskTemplate::getFormURL();
} else {
    $wf_data = [
        'name'                  => '',
        'is_active'             => 1,
        'assign_ticket_to_task' => 1,
        'groups_id_completion'  => 0,
    ];
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

// Ensure SortableJS is available for the flowchart drag-reorder.
// Loads from jsDelivr (fast, no install footprint) with a fallback to the
// GLPI-bundled copy (path varies by install — see GLPI-Shared conventions).
global $CFG_GLPI;
?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"
        crossorigin="anonymous"
        onerror="(function(){var s=document.createElement('script');s.src='<?= $CFG_GLPI['root_doc'] ?>/public/lib/sortablejs.js';document.head.appendChild(s);})();"></script>
<?php
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

                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="wf-assign-ticket"
                           name="assign_ticket_to_task"
                           <?= !empty($wf_data['assign_ticket_to_task']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wf-assign-ticket">
                        <?= __('Assign the ticket to each step\'s task team', 'tasksmanager') ?>
                    </label>
                    <div class="text-muted small">
                        <?= __('When checked, advancing the workflow swaps the ticket\'s assigned tech/group to match the new step\'s task template. Uncheck to leave the ticket\'s assignment untouched and only set the new task\'s tech/group.', 'tasksmanager') ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold" for="wf-completion-group">
                        <?= __('Reassign ticket to group on completion', 'tasksmanager') ?>
                    </label>
                    <div class="text-muted small mb-1">
                        <?= __('When the last step is marked done, the ticket\'s assigned group becomes this one. Leave empty to keep the last step\'s group.', 'tasksmanager') ?>
                    </div>
                    <?php
                    Group::dropdown([
                        'name'      => 'groups_id_completion',
                        'value'     => (int)($wf_data['groups_id_completion'] ?? 0),
                        'condition' => ['is_assign' => 1],
                        'display_emptychoice' => true,
                    ]);
                    ?>
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
                <i class="ti ti-info-circle me-1"></i>
                <?= __('When a step\'s task is completed, the next step\'s task is automatically added to the ticket. Drag the handle on the left to reorder steps.', 'tasksmanager') ?>
            </p>

            <!-- Vertical flowchart -->
            <div id="tm-flow" class="tm-flow mb-3">
                <?php if (empty($steps)): ?>
                    <div id="tm-empty-state" class="tm-flow-empty text-muted text-center py-4">
                        <i class="ti ti-arrow-down me-1"></i>
                        <?= __('No steps yet. Pick a task template below to add the first step.', 'tasksmanager') ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($steps as $i => $step):
                        $hasComment = !empty(trim(strip_tags($step['tpl_comment'] ?? '')));
                    ?>
                    <div class="tm-flow-step" data-step-id="<?= (int)$step['id'] ?>"
                         data-tasktemplates-id="<?= (int)$step['tasktemplates_id'] ?>">
                        <div class="tm-flow-card">
                            <div class="tm-flow-handle" title="<?= __('Drag to reorder', 'tasksmanager') ?>">
                                <i class="ti ti-grip-vertical"></i>
                            </div>
                            <div class="tm-flow-num"><span class="tm-step-num"><?= $i + 1 ?></span></div>
                            <div class="tm-flow-body">
                                <div class="tm-flow-title">
                                    <?php if (!empty($step['tasktemplates_id'])): ?>
                                        <a href="<?= $tasktemplate_base_url ?>?id=<?= (int)$step['tasktemplates_id'] ?>"
                                           target="_blank" rel="noopener"
                                           title="<?= __('Open this task template in a new tab', 'tasksmanager') ?>">
                                            <?= htmlspecialchars($step['tpl_name'] ?? '—') ?>
                                            <i class="ti ti-external-link ms-1 text-muted small"></i>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($step['tpl_name'] ?? '—') ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button"
                                        class="btn btn-sm btn-link p-0 text-decoration-none tm-toggle-desc"
                                        onclick="tmToggleDesc(this)">
                                    <i class="ti ti-chevron-right me-1"></i>
                                    <span class="tm-desc-label">
                                        <?= $hasComment
                                            ? __('Edit template comment', 'tasksmanager')
                                            : __('Add template comment', 'tasksmanager') ?>
                                    </span>
                                </button>
                                <div class="tm-flow-desc" style="display:none">
                                    <textarea class="form-control form-control-sm tm-desc-textarea" rows="3"
                                              placeholder="<?= __('Editing this updates the linked task template\'s comment field.', 'tasksmanager') ?>"
                                              onblur="tmSaveDesc(this)"
                                    ><?= htmlspecialchars($step['tpl_comment'] ?? '') ?></textarea>
                                    <div class="text-muted small mt-1">
                                        <i class="ti ti-info-circle me-1"></i>
                                        <?= __('This is the task template\'s comment field. Changes here apply to every workflow that uses the same template.', 'tasksmanager') ?>
                                        <span class="tm-desc-status ms-2"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="tm-flow-actions">
                                <button type="button" class="btn btn-sm btn-outline-danger px-1"
                                        title="<?= __('Remove') ?>" onclick="tmRemoveStep(this)">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="tm-flow-connector"><i class="ti ti-chevron-down"></i></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

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
    const WORKFLOW_ID       = <?= (int)$workflow_id ?>;
    const AJAX_URL          = <?= json_encode($ajax_url) ?>;
    const TASKTEMPLATE_URL  = <?= json_encode($tasktemplate_base_url) ?>;

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
            // Endpoint contract: { ok: bool, error?: string, data?: object }.
            // Parse the JSON regardless of HTTP status (errors come back as 4xx/5xx
            // with a JSON body), and surface a transport error only when the
            // body isn't parseable.
            return r.json().catch(() => ({ ok: false, error: 'HTTP ' + r.status }));
        }).catch(err => { console.error('Workflow AJAX error:', err); return {ok: false, error: String(err)}; });
    }

    function renumber() {
        document.querySelectorAll('#tm-flow .tm-flow-step .tm-step-num').forEach((el, i) => {
            el.textContent = i + 1;
        });
        const steps = document.querySelectorAll('#tm-flow .tm-flow-step');
        const empty = document.getElementById('tm-empty-state');
        if (empty) empty.style.display = steps.length ? 'none' : '';
    }

    function persistOrder() {
        const ids = Array.from(document.querySelectorAll('#tm-flow .tm-flow-step'))
            .map(el => el.dataset.stepId);
        post({action: 'reorder_steps', workflows_id: WORKFLOW_ID, order: JSON.stringify(ids)});
    }

    window.tmAddStep = function () {
        const sel = document.getElementById('tm-new-tpl');
        if (!sel.value) return;
        const tplId   = sel.value;
        const tplName = sel.options[sel.selectedIndex].text;

        post({action: 'add_step', workflows_id: WORKFLOW_ID, tasktemplates_id: tplId})
            .then(resp => {
                if (!resp.ok) { alert(resp.error || 'Error'); return; }
                const flow = document.getElementById('tm-flow');

                const stepDiv = document.createElement('div');
                stepDiv.classList.add('tm-flow-step');
                stepDiv.dataset.stepId = (resp.data && resp.data.step_id) ? resp.data.step_id : '';
                stepDiv.dataset.tasktemplatesId = tplId;
                stepDiv.innerHTML = `
                    <div class="tm-flow-card">
                        <div class="tm-flow-handle" title="<?= __('Drag to reorder', 'tasksmanager') ?>">
                            <i class="ti ti-grip-vertical"></i>
                        </div>
                        <div class="tm-flow-num"><span class="tm-step-num"></span></div>
                        <div class="tm-flow-body">
                            <div class="tm-flow-title">
                                <a href="${TASKTEMPLATE_URL}?id=${encodeURIComponent(tplId)}"
                                   target="_blank" rel="noopener"
                                   title="<?= __('Open this task template in a new tab', 'tasksmanager') ?>">
                                    ${escHtml(tplName)}
                                    <i class="ti ti-external-link ms-1 text-muted small"></i>
                                </a>
                            </div>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none tm-toggle-desc"
                                    onclick="tmToggleDesc(this)">
                                <i class="ti ti-chevron-right me-1"></i>
                                <span class="tm-desc-label"><?= __('Add template comment', 'tasksmanager') ?></span>
                            </button>
                            <div class="tm-flow-desc" style="display:none">
                                <textarea class="form-control form-control-sm tm-desc-textarea" rows="3"
                                          placeholder="<?= __('Editing this updates the linked task template\'s comment field.', 'tasksmanager') ?>"
                                          onblur="tmSaveDesc(this)"></textarea>
                                <div class="text-muted small mt-1">
                                    <i class="ti ti-info-circle me-1"></i>
                                    <?= __('This is the task template\'s comment field. Changes here apply to every workflow that uses the same template.', 'tasksmanager') ?>
                                    <span class="tm-desc-status ms-2"></span>
                                </div>
                            </div>
                        </div>
                        <div class="tm-flow-actions">
                            <button type="button" class="btn btn-sm btn-outline-danger px-1"
                                    title="<?= __('Remove') ?>" onclick="tmRemoveStep(this)">
                                <i class="ti ti-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="tm-flow-connector"><i class="ti ti-chevron-down"></i></div>`;
                flow.appendChild(stepDiv);

                renumber();
                sel.value = '';
            });
    };

    window.tmRemoveStep = function (btn) {
        const stepDiv = btn.closest('.tm-flow-step');
        const stepId  = stepDiv.dataset.stepId;
        post({action: 'remove_step', step_id: stepId})
            .then(resp => {
                if (!resp.ok) { alert(resp.error || 'Error'); return; }
                stepDiv.remove();
                renumber();
            });
    };

    window.tmToggleDesc = function (btn) {
        const desc = btn.parentElement.querySelector('.tm-flow-desc');
        if (!desc) return;

        const chevron = btn.querySelector('i');
        const open = desc.style.display === 'none';
        desc.style.display = open ? '' : 'none';
        if (chevron) {
            chevron.classList.toggle('ti-chevron-right', !open);
            chevron.classList.toggle('ti-chevron-down', open);
        }
        if (open) {
            const ta = desc.querySelector('textarea');
            if (ta) ta.focus();
        }
    };

    window.tmSaveDesc = function (ta) {
        const stepDiv = ta.closest('.tm-flow-step');
        if (!stepDiv) return;
        const tplId   = stepDiv.dataset.tasktemplatesId;
        const status  = stepDiv.querySelector('.tm-desc-status');

        if (!tplId) {
            if (status) status.textContent = 'No template linked';
            return;
        }

        if (status) status.textContent = '<?= __('Saving…', 'tasksmanager') ?>';

        post({action: 'update_template_comment', tasktemplates_id: tplId, comment: ta.value})
            .then(d => {
                if (!d.ok) {
                    if (status) status.textContent = d.error || 'Error';
                    return;
                }
                if (status) status.textContent = '<?= __('Saved', 'tasksmanager') ?>';
                setTimeout(() => { if (status) status.textContent = ''; }, 1500);

                const label = stepDiv.querySelector('.tm-desc-label');
                if (label) {
                    label.textContent = ta.value.trim()
                        ? '<?= __('Edit template comment', 'tasksmanager') ?>'
                        : '<?= __('Add template comment', 'tasksmanager') ?>';
                }
            });
    };

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── SortableJS drag-reorder ──────────────────────────────────────────────
    // GLPI 11 ships SortableJS at public/lib/sortablejs.js (loaded by the
    // central layout). We attach to the flow container and call our reorder
    // endpoint on drop.
    function initSortable() {
        if (typeof Sortable === 'undefined') {
            // SortableJS not loaded yet — retry shortly. GLPI sometimes loads
            // it after our inline script runs.
            return setTimeout(initSortable, 200);
        }
        const flow = document.getElementById('tm-flow');
        if (!flow) return;
        Sortable.create(flow, {
            handle:    '.tm-flow-handle',
            animation: 150,
            ghostClass: 'tm-flow-ghost',
            dragClass:  'tm-flow-drag',
            onEnd: function () {
                renumber();
                persistOrder();
            },
        });
    }

    initSortable();
    renumber();
})();
</script>
<?php Html::footer(); ?>
