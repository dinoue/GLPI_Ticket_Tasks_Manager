<?php

/**
 * Tasks Manager - VM provisioning review page
 *
 * Opened from the ticket's Workflow tab for a `draft` vSphere automation
 * job. GET renders the form (prefilled from the form answers, dropdowns
 * from vCenter); POST deploy validates, checks the name and queues the job
 * for the automationjobs cron. No vCenter write happens in this request.
 */

use GlpiPlugin\Tasksmanager\Automation\ConnectorException;
use GlpiPlugin\Tasksmanager\Automation\Provision;
use GlpiPlugin\Tasksmanager\Automation\VsphereConnector;
use GlpiPlugin\Tasksmanager\Profile;

include('../../../inc/includes.php');

global $CFG_GLPI;

$plugin = new Plugin();
if (!$plugin->isInstalled('tasksmanager') || !$plugin->isActivated('tasksmanager')) {
    Html::displayNotFoundError();
}

Session::checkRight(Profile::RIGHT_PROVISION, UPDATE);

$job_id = (int)($_GET['job'] ?? $_POST['job'] ?? 0);
$job    = $job_id > 0 ? Provision::getJob($job_id) : null;
if ($job === null || $job['connector'] !== VsphereConnector::NAME) {
    Html::displayNotFoundError();
}
if (!Provision::canDeploy($job)) {
    Html::displayRightError();
}

$ticket_url = Ticket::getFormURLWithID((int)$job['tickets_id']);
$vcenter    = new VsphereConnector();
$errors     = [];
$form_error = null;

// ── Deploy ────────────────────────────────────────────────────────────────
// CSRF already validated by GLPI 11 CheckCsrfListener
if (isset($_POST['deploy'])) {
    [$values, $errors] = Provision::validate($_POST);
    if (!$errors) {
        $form_error = Provision::approve($job, $values, $vcenter);
        if ($form_error === null) {
            Session::addMessageAfterRedirect(
                sprintf(__('Deployment of %s queued. The ticket is updated when it finishes.', 'tasksmanager'), $values['vm_name']),
                true,
                INFO
            );
            Html::redirect($ticket_url);
        }
    }
} else {
    [$values, $errors] = Provision::prefill($job);
}

// ── vCenter inventory for the dropdowns ───────────────────────────────────
$lists = [];
$inventory_error = null;
try {
    $lists = [
        'template'           => $vcenter->listTemplates(),
        'cluster'            => $vcenter->listClusters(),
        'folder'             => $vcenter->listFolders(),
        'datastore'          => $vcenter->listDatastores(),
        'network'            => $vcenter->listNetworks(),
        'customization_spec' => $vcenter->listCustomizationSpecs(),
    ];
} catch (ConnectorException $e) {
    $inventory_error = $e->getMessage();
}

/**
 * Select an option by id or, failing that, by visible name — a mapping may
 * give "Windows Server 2025" where the list is keyed by library item id.
 */
function tm_provision_selected(array $options, mixed $value): string
{
    $value = (string)$value;
    if ($value === '' || isset($options[$value])) {
        return $value;
    }
    foreach ($options as $id => $label) {
        if (strcasecmp((string)$label, $value) === 0 || stripos((string)$label, $value . ' (') === 0) {
            return (string)$id;
        }
    }
    return '';
}

function tm_provision_select(string $name, array $options, mixed $value, bool $required, array $errors): void
{
    $selected = tm_provision_selected($options, $value);
    echo '<select name="' . $name . '" id="tm-p-' . $name . '" class="form-select'
        . (isset($errors[$name]) ? ' is-invalid' : '') . '"' . ($required ? ' required' : '') . '>';
    echo '<option value="">' . ($required ? __('-- Select --', 'tasksmanager') : __('-- None --', 'tasksmanager')) . '</option>';
    foreach ($options as $id => $label) {
        echo '<option value="' . htmlspecialchars((string)$id, ENT_QUOTES) . '"'
            . ((string)$id === $selected ? ' selected' : '') . '>'
            . htmlspecialchars((string)$label) . '</option>';
    }
    echo '</select>';
    if ((string)$value !== '' && $selected === '') {
        echo '<div class="form-text text-warning">'
            . sprintf(htmlspecialchars(__('The form answered "%s", which matches nothing in vCenter.', 'tasksmanager')), htmlspecialchars((string)$value))
            . '</div>';
    }
    if (isset($errors[$name])) {
        echo '<div class="invalid-feedback d-block">' . htmlspecialchars($errors[$name]) . '</div>';
    }
}

function tm_provision_input(string $name, mixed $value, array $errors, string $type = 'text', string $extra = ''): void
{
    echo '<input type="' . $type . '" name="' . $name . '" id="tm-p-' . $name . '" class="form-control'
        . (isset($errors[$name]) ? ' is-invalid' : '') . '" value="'
        . htmlspecialchars((string)$value, ENT_QUOTES) . '" ' . $extra . '>';
    if (isset($errors[$name])) {
        echo '<div class="invalid-feedback d-block">' . htmlspecialchars($errors[$name]) . '</div>';
    }
}

Html::header(__('Build VM', 'tasksmanager'), $_SERVER['PHP_SELF'], 'helpdesk', Ticket::class);

$labels = [
    'template'           => __('Template', 'tasksmanager'),
    'vm_name'            => __('VM name', 'tasksmanager'),
    'cluster'            => __('Cluster', 'tasksmanager'),
    'folder'             => __('Folder', 'tasksmanager'),
    'datastore'          => __('Datastore', 'tasksmanager'),
    'network'            => __('VLAN (port group)', 'tasksmanager'),
    'cpu'                => __('vCPU', 'tasksmanager'),
    'cores_per_socket'   => __('Cores per socket', 'tasksmanager'),
    'memory_gb'          => __('Memory (GB)', 'tasksmanager'),
    'disks'              => __('Extra disks (GB)', 'tasksmanager'),
    'customization_spec' => __('Customization spec', 'tasksmanager'),
    'ip'                 => __('IP address', 'tasksmanager'),
    'prefix'             => __('Prefix length', 'tasksmanager'),
    'gateway'            => __('Gateway', 'tasksmanager'),
];
?>
<div class="container-fluid mt-3" style="max-width:900px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="ti ti-server-2 me-2"></i><?= __('Build VM', 'tasksmanager') ?></h2>
        <a href="<?= htmlspecialchars($ticket_url) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="ti ti-arrow-left me-1"></i><?= sprintf(__('Back to ticket %d', 'tasksmanager'), (int)$job['tickets_id']) ?>
        </a>
    </div>

    <?php if ($job['status'] !== 'draft'): ?>
        <div class="alert alert-info">
            <?= htmlspecialchars(sprintf(__('This deployment is already %s.', 'tasksmanager'), $job['status'])) ?>
        </div>
    <?php else: ?>

    <?php if ($inventory_error !== null): ?>
        <div class="alert alert-danger">
            <i class="ti ti-plug-connected-x me-1"></i>
            <?= htmlspecialchars(__('Cannot load the vCenter inventory:', 'tasksmanager') . ' ' . $inventory_error) ?>
        </div>
    <?php endif; ?>
    <?php if ($form_error !== null): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($form_error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars(Plugin::getWebDir('tasksmanager') . '/front/provision.form.php') ?>">
        <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">
        <input type="hidden" name="job" value="<?= (int)$job['id'] ?>">

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0"><?= __('Template and placement', 'tasksmanager') ?></h5></div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="tm-p-template"><?= $labels['template'] ?> *</label>
                    <?php tm_provision_select('template', $lists['template'] ?? [], $values['template'], true, $errors); ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="tm-p-vm_name"><?= $labels['vm_name'] ?> *</label>
                    <?php tm_provision_input('vm_name', $values['vm_name'], $errors, 'text', 'required maxlength="63"'); ?>
                    <div class="form-text" id="tm-p-name-hint"><?= __('Checked for duplicates in vCenter when you deploy. Windows computer names are limited to 15 characters.', 'tasksmanager') ?></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tm-p-cluster"><?= $labels['cluster'] ?> *</label>
                    <?php tm_provision_select('cluster', $lists['cluster'] ?? [], $values['cluster'], true, $errors); ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tm-p-folder"><?= $labels['folder'] ?></label>
                    <?php tm_provision_select('folder', $lists['folder'] ?? [], $values['folder'], false, $errors); ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tm-p-datastore"><?= $labels['datastore'] ?> *</label>
                    <?php tm_provision_select('datastore', $lists['datastore'] ?? [], $values['datastore'], true, $errors); ?>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0"><?= __('Hardware', 'tasksmanager') ?></h5></div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="tm-p-cpu"><?= $labels['cpu'] ?></label>
                    <?php tm_provision_input('cpu', $values['cpu'], $errors, 'number', 'min="1" max="128"'); ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tm-p-cores_per_socket"><?= $labels['cores_per_socket'] ?></label>
                    <?php tm_provision_input('cores_per_socket', $values['cores_per_socket'], $errors, 'number', 'min="1" max="128"'); ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tm-p-memory_gb"><?= $labels['memory_gb'] ?></label>
                    <?php tm_provision_input('memory_gb', $values['memory_gb'], $errors, 'number', 'min="1" max="4096"'); ?>
                </div>
                <div class="col-12">
                    <label class="form-label"><?= $labels['disks'] ?></label>
                    <div id="tm-p-disks">
                        <?php foreach ((array)$values['disks'] as $size): ?>
                            <div class="input-group input-group-sm mb-1 tm-p-disk" style="max-width:260px">
                                <input type="number" name="disks[]" class="form-control" min="1" max="65536"
                                       value="<?= (int)$size ?>">
                                <span class="input-group-text">GB</span>
                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('.tm-p-disk').remove()">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="tm-p-add-disk">
                        <i class="ti ti-plus me-1"></i><?= __('Add disk', 'tasksmanager') ?>
                    </button>
                    <?php if (isset($errors['disks'])): ?>
                        <div class="invalid-feedback d-block"><?= htmlspecialchars($errors['disks']) ?></div>
                    <?php endif; ?>
                    <div class="form-text"><?= __('In addition to the template\'s own disks. Leave CPU / memory empty to keep the template values.', 'tasksmanager') ?></div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0"><?= __('Network and guest OS', 'tasksmanager') ?></h5></div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="tm-p-network"><?= $labels['network'] ?></label>
                    <?php tm_provision_select('network', $lists['network'] ?? [], $values['network'], false, $errors); ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="tm-p-customization_spec"><?= $labels['customization_spec'] ?></label>
                    <?php tm_provision_select('customization_spec', $lists['customization_spec'] ?? [], $values['customization_spec'], false, $errors); ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="tm-p-ip"><?= $labels['ip'] ?></label>
                    <?php tm_provision_input('ip', $values['ip'], $errors, 'text', 'placeholder="' . htmlspecialchars(__('Empty = DHCP / as in the spec', 'tasksmanager'), ENT_QUOTES) . '"'); ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="tm-p-prefix"><?= $labels['prefix'] ?></label>
                    <?php tm_provision_input('prefix', $values['prefix'], $errors, 'number', 'min="1" max="32"'); ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="tm-p-gateway"><?= $labels['gateway'] ?></label>
                    <?php tm_provision_input('gateway', $values['gateway'], $errors); ?>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="<?= htmlspecialchars($ticket_url) ?>" class="btn btn-outline-secondary"><?= __('Cancel') ?></a>
            <button type="submit" name="deploy" value="1" class="btn btn-primary"
                    <?= $inventory_error !== null ? 'disabled' : '' ?>
                    onclick="return confirm(<?= htmlspecialchars(json_encode(__('Deploy this VM in vCenter now?', 'tasksmanager')), ENT_QUOTES) ?>)">
                <i class="ti ti-rocket me-1"></i><?= __('Deploy', 'tasksmanager') ?>
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
(function () {
    const add = document.getElementById('tm-p-add-disk');
    if (!add) return;
    add.addEventListener('click', function () {
        const row = document.createElement('div');
        row.className = 'input-group input-group-sm mb-1 tm-p-disk';
        row.style.maxWidth = '260px';
        row.innerHTML = '<input type="number" name="disks[]" class="form-control" min="1" max="65536">'
            + '<span class="input-group-text">GB</span>'
            + '<button type="button" class="btn btn-outline-danger"><i class="ti ti-trash"></i></button>';
        row.querySelector('button').addEventListener('click', () => row.remove());
        document.getElementById('tm-p-disks').appendChild(row);
        row.querySelector('input').focus();
    });
})();
</script>
<?php
Html::footer();
