<?php
$title       = 'New Service: Select Employees (Step 3 of 4)';
ob_start();
$currentStep = 3;
require __DIR__ . '/_wizard_nav.php';

$assignedSet = array_flip($assignedIds ?? []);
$svcId = (int) ($service['id'] ?? 0);
$csrf  = $csrf ?? '';
?>

<?php if (!empty($errors['staff'])): ?>
<div class="alert alert-danger" style="margin-bottom:1rem;">
    <?= htmlspecialchars($errors['staff']) ?>
</div>
<?php endif; ?>

<?php if (!empty($flash['success'])): ?>
<div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($flash['success']) ?></div>
<?php endif; ?>

<form method="post" action="/services-resources/services/<?= $svcId ?>/step-3" class="svc-step-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">

    <div class="svc-step-section">
        <p class="svc-step-section-title">Employees</p>
        <p style="font-size:0.875rem; color:#64748b; margin-bottom:1rem;">
            Select which employees can perform this service.
            Leave all unchecked to allow any active employee (no restriction).
        </p>

        <?php if (empty($staffList)): ?>
        <p style="color:#94a3b8; font-style:italic;">No employees found in the current branch.</p>
        <?php else: ?>

        <div style="margin-bottom:0.75rem; display:flex; gap:1rem;">
            <button type="button" id="check-all-staff" class="btn-ghost" style="font-size:0.8125rem;">Check all</button>
            <button type="button" id="uncheck-all-staff" class="btn-ghost" style="font-size:0.8125rem;">Uncheck all</button>
            <span id="staff-count-label" style="font-size:0.8125rem; color:#64748b; align-self:center;"></span>
        </div>

        <div class="svc-checklist">
            <?php foreach ($staffList as $member): ?>
            <?php $checked = isset($assignedSet[(int) $member['id']]); ?>
            <label class="svc-checklist-item">
                <input type="checkbox" name="staff_ids[]" value="<?= (int) $member['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                <?= htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?>
            </label>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>

    <div class="svc-step-actions">
        <button type="submit" class="btn-primary">
            Save &amp; continue
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
        <a href="/services-resources/services/<?= $svcId ?>/step-2" class="btn-ghost">← Back to Step 2</a>
        <a href="/services-resources/services/<?= $svcId ?>/step-4" class="btn-ghost">Skip to Step 4 →</a>
        <a href="/services-resources/services/<?= $svcId ?>" class="btn-ghost">View service</a>
    </div>
</form>

<script>
(function () {
    var checkboxes = document.querySelectorAll('input[name="staff_ids[]"]');
    var label = document.getElementById('staff-count-label');

    function updateCount() {
        if (!label) return;
        var n = document.querySelectorAll('input[name="staff_ids[]"]:checked').length;
        label.textContent = n + ' selected';
    }
    updateCount();
    checkboxes.forEach(function (cb) { cb.addEventListener('change', updateCount); });

    var checkAll   = document.getElementById('check-all-staff');
    var uncheckAll = document.getElementById('uncheck-all-staff');
    if (checkAll)   checkAll.addEventListener('click', function () { checkboxes.forEach(function (c) { c.checked = true; }); updateCount(); });
    if (uncheckAll) uncheckAll.addEventListener('click', function () { checkboxes.forEach(function (c) { c.checked = false; }); updateCount(); });
}());
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
