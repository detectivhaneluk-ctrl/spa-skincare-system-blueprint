<?php
$title       = 'New Service: Select Spaces (Step 4 of 4)';
ob_start();
$currentStep = 4;
require __DIR__ . '/_wizard_nav.php';

$assignedRoomSet = array_flip($assignedRoomIds ?? []);
$svcId = (int) ($service['id'] ?? 0);
$csrf  = $csrf ?? '';
?>

<?php if (!empty($errors['rooms'])): ?>
<div class="alert alert-danger" style="margin-bottom:1rem;">
    <?= htmlspecialchars($errors['rooms']) ?>
</div>
<?php endif; ?>

<?php if (!empty($flash['success'])): ?>
<div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($flash['success']) ?></div>
<?php endif; ?>

<form method="post" action="/services-resources/services/<?= $svcId ?>/step-4" class="svc-step-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">

    <div class="svc-step-section">
        <p class="svc-step-section-title">Spaces</p>
        <p style="font-size:0.875rem; color:#64748b; margin-bottom:1rem;">
            Select which spaces can be used for this service.
            Leave all unchecked to allow any space.
        </p>

        <?php if (empty($rooms)): ?>
        <p style="color:#94a3b8; font-style:italic;">No spaces found in the current branch.</p>
        <?php else: ?>

        <div style="margin-bottom:0.75rem; display:flex; gap:1rem;">
            <button type="button" id="check-all-rooms" class="btn-ghost" style="font-size:0.8125rem;">Check All</button>
            <button type="button" id="uncheck-all-rooms" class="btn-ghost" style="font-size:0.8125rem;">Uncheck All</button>
            <span id="room-count-label" style="font-size:0.8125rem; color:#64748b; align-self:center;"></span>
        </div>

        <div class="svc-checklist">
            <?php foreach ($rooms as $room): ?>
            <?php $checked = isset($assignedRoomSet[(int) $room['id']]); ?>
            <label class="svc-checklist-item">
                <input type="checkbox" name="room_ids[]" value="<?= (int) $room['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                <?= htmlspecialchars($room['name'] ?? '') ?>
                <?php if (!empty($room['code'])): ?>
                <span style="color:#94a3b8; font-size:0.8rem;">(<?= htmlspecialchars($room['code']) ?>)</span>
                <?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>

    <div class="svc-step-actions">
        <button type="submit" class="btn-primary">
            Finish
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
        </button>
        <a href="/services-resources/services/<?= $svcId ?>/step-3" class="btn-ghost">← Back to Step 3</a>
        <a href="/services-resources/services/<?= $svcId ?>" class="btn-ghost">View service</a>
    </div>
</form>

<script>
(function () {
    var checkboxes = document.querySelectorAll('input[name="room_ids[]"]');
    var label = document.getElementById('room-count-label');

    function updateCount() {
        if (!label) return;
        var n = document.querySelectorAll('input[name="room_ids[]"]:checked').length;
        label.textContent = n + ' selected';
    }
    updateCount();
    checkboxes.forEach(function (cb) { cb.addEventListener('change', updateCount); });

    var checkAll   = document.getElementById('check-all-rooms');
    var uncheckAll = document.getElementById('uncheck-all-rooms');
    if (checkAll)   checkAll.addEventListener('click', function () { checkboxes.forEach(function (c) { c.checked = true; }); updateCount(); });
    if (uncheckAll) uncheckAll.addEventListener('click', function () { checkboxes.forEach(function (c) { c.checked = false; }); updateCount(); });
}());
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
