<?php
$title = 'Appointments';
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$workspace['shell_modifier'] = 'workspace-shell--list';
ob_start();
?>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<?php
$apptListCalendarQ = [];
if (isset($_GET['branch_id']) && (string) $_GET['branch_id'] !== '') {
    $apptListCalendarQ['branch_id'] = (int) $_GET['branch_id'];
}
$apptListCalendarHref = '/appointments/calendar/day' . ($apptListCalendarQ !== [] ? '?' . http_build_query($apptListCalendarQ) : '');
?>

<div class="appointments-list-page">
<div class="appt-list-op-canvas">
    <div class="appt-list-toolbar" role="region" aria-label="Appointment list filters and actions">
        <form method="get" class="appt-list-toolbar__form" id="appt-list-filter-form">
            <div class="appt-list-toolbar__filters">
                <div class="appt-list-filter-cluster" aria-label="Scope">
                    <span class="appt-list-filter-cluster__label">Scope</span>
                    <div class="appt-list-filter-cluster__fields">
                        <div class="appt-list-field">
                            <label class="appt-list-field__label" for="appt-list-branch">Branch</label>
                            <select id="appt-list-branch" name="branch_id" class="appt-list-field__control">
                                <option value="">All branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= (isset($_GET['branch_id']) && (int)$_GET['branch_id'] === (int)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="appt-list-filter-cluster" aria-label="Date range">
                    <span class="appt-list-filter-cluster__label">Date range</span>
                    <div class="appt-list-filter-cluster__fields">
                        <div class="appt-list-field">
                            <label class="appt-list-field__label" for="appt-list-from">From</label>
                            <input id="appt-list-from" class="appt-list-field__control" type="date" name="from_date" value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>">
                        </div>
                        <div class="appt-list-field">
                            <label class="appt-list-field__label" for="appt-list-to">To</label>
                            <input id="appt-list-to" class="appt-list-field__control" type="date" name="to_date" value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>">
                        </div>
                        <div class="appt-list-field appt-list-field--action">
                            <span class="appt-list-field__label appt-list-field__label--spacer" aria-hidden="true">&nbsp;</span>
                            <button type="submit" class="appt-list-btn appt-list-btn--primary">Filter</button>
                        </div>
                    </div>
                </div>
                <div class="appt-list-filter-cluster" aria-label="Status">
                    <span class="appt-list-filter-cluster__label">Status</span>
                    <div class="appt-list-filter-cluster__fields">
                        <div class="appt-list-field">
                            <label class="appt-list-field__label" for="appt-list-status">Status</label>
                            <select id="appt-list-status" name="status" class="appt-list-field__control">
                                <option value=""<?= (($status_filter_selected ?? '') === '') ? ' selected' : '' ?>>All statuses</option>
                                <?php foreach (($status_filter_labels ?? []) as $stVal => $stLab): ?>
                                <option value="<?= htmlspecialchars((string) $stVal) ?>"<?= (($status_filter_selected ?? '') === (string) $stVal) ? ' selected' : '' ?>><?= htmlspecialchars((string) $stLab) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <div class="appt-list-toolbar__actions">
            <a href="<?= htmlspecialchars($apptListCalendarHref) ?>" class="appt-list-btn appt-list-btn--ghost">Day calendar</a>
            <a href="/appointments/create" class="appt-list-btn appt-list-btn--solid appt-list-btn--with-icon" title="New appointment"><span class="appt-list-btn__ic" aria-hidden="true">+</span><span>Add Appointment</span></a>
        </div>
    </div>

    <div class="appt-list-main">
    <div class="appt-list-results" role="region" aria-label="Appointment results">
<?php
foreach ($appointments as &$a) {
    $a['staff_display'] = trim(($a['staff_first_name'] ?? '') . ' ' . ($a['staff_last_name'] ?? '')) ?: '—';
    $st = (string) ($a['status'] ?? '');
    $label = htmlspecialchars((string) ($a['status_label'] ?? ''), ENT_QUOTES, 'UTF-8');
    $mod = strtolower(preg_replace('/[^a-z0-9]+/', '-', str_replace('_', ' ', $st)));
    $mod = trim($mod, '-');
    if ($mod === '') {
        $mod = 'na';
    }
    $a['status_display'] = '<span class="appt-status-pill appt-status-pill--' . htmlspecialchars($mod, ENT_QUOTES, 'UTF-8') . '">' . $label . '</span>';
}
unset($a);
$rows = $appointments;
$headers = [
    ['key' => 'display_summary', 'label' => 'Client / Time', 'link' => true, 'th_class' => 'appt-list-th-primary', 'cell_class' => 'appt-list-col-primary'],
    ['key' => 'service_name', 'label' => 'Service', 'th_class' => 'appt-list-th-secondary', 'cell_class' => 'appt-list-col-service'],
    ['key' => 'staff_display', 'label' => 'Staff', 'th_class' => 'appt-list-th-secondary', 'cell_class' => 'appt-list-col-staff'],
    ['key' => 'room_name', 'label' => 'Room', 'th_class' => 'appt-list-th-secondary', 'cell_class' => 'appt-list-col-room'],
    ['key' => 'status_display', 'label' => 'Status', 'link' => false, 'raw' => true, 'th_class' => 'appt-list-th-status', 'cell_class' => 'appt-list-col-status'],
];
$rowUrl = fn ($r) => '/appointments/' . $r['id'];
$actions = function ($r) use ($csrf) {
    $html = '<div class="appt-list-row-actions">';
    $html .= '<a class="appt-list-row-actions__link" href="/appointments/' . $r['id'] . '/edit">Edit</a>';
    if (!in_array((string) ($r['status'] ?? ''), ['cancelled', 'no_show'], true)) {
        $html .= '<form class="appt-list-row-actions__form" method="post" action="/appointments/' . $r['id'] . '/cancel" onsubmit="return confirm(\'Cancel appointment?\')"><input type="hidden" name="' . htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) . '" value="' . htmlspecialchars($csrf) . '"><button type="submit" class="appt-list-row-actions__btn">Cancel</button></form>';
    }
    $html .= '<form class="appt-list-row-actions__form" method="post" action="/appointments/' . $r['id'] . '/delete" onsubmit="return confirm(\'Delete?\')"><input type="hidden" name="' . htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) . '" value="' . htmlspecialchars($csrf) . '"><button type="submit" class="appt-list-row-actions__btn appt-list-row-actions__btn--danger">Delete</button></form>';
    $html .= '</div>';
    return $html;
};
if (count($rows) === 0) {
    echo '<div class="appt-list-empty" role="status">';
    echo '<div class="appt-list-empty__inner">';
    echo '<p class="appt-list-empty__title">No appointments match your filters</p>';
    echo '<p class="appt-list-empty__text">Adjust branch, date range, or status and use <strong>Filter</strong>, or create a new booking.</p>';
    echo '<p class="appt-list-empty__actions">';
    echo '<a class="appt-list-empty__link" href="/appointments/create">Add Appointment</a>';
    echo '<span class="appt-list-empty__sep" aria-hidden="true">·</span>';
    echo '<a class="appt-list-empty__link" href="' . htmlspecialchars($apptListCalendarHref, ENT_QUOTES, 'UTF-8') . '">Day calendar</a>';
    echo '</p>';
    echo '</div></div>';
} else {
    require shared_path('layout/table.php');
}
?>
    </div>
    </div>

<?php if ($total > count($appointments)): ?>
    <div class="appt-list-footer">
        <p class="pagination appt-list-pagination">Page <?= (int) $page ?> · <?= (int) $total ?> total</p>
    </div>
<?php endif; ?>
</div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
