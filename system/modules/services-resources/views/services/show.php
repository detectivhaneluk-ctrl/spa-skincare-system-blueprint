<?php
$title = 'Service: ' . htmlspecialchars($service['name'] ?? '');
$serviceIsTrashed = (bool) ($serviceIsTrashed ?? false);
ob_start();

$isActive  = !empty($service['is_active']);
$catName   = isset($service['category_name']) && $service['category_name'] !== null
             ? htmlspecialchars($service['category_name']) : null;
$svcType   = match($service['service_type'] ?? 'service') {
    'package_item' => 'Package item',
    'other'        => 'Other',
    default        => 'Service',
};
$feeMode   = $service['staff_fee_mode'] ?? 'none';
$feeLabel  = match($feeMode) {
    'percentage' => number_format((float)($service['staff_fee_value'] ?? 0), 2) . '%',
    'amount'     => number_format((float)($service['staff_fee_value'] ?? 0), 2),
    default      => null,
};

function _svcDur(int $min): string
{
    if ($min <= 0) return '—';
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h > 0 && $m > 0) return "{$h}h {$m}min";
    return $h > 0 ? "{$h}h" : "{$m}min";
}

function _svcFlag(mixed $val, string $yes = 'Yes', string $no = 'No'): string
{
    return !empty($val) ? $yes : $no;
}
?>
<div class="svc-step-header">
    <div class="svc-step-breadcrumb">
        <a href="/services-resources">Services &amp; Resources</a>
        <span class="svc-step-breadcrumb-sep">›</span>
        <a href="/services-resources/services">Services</a>
        <span class="svc-step-breadcrumb-sep">›</span>
        <span><?= htmlspecialchars($service['name'] ?? '') ?></span>
    </div>
    <h1><?= htmlspecialchars($service['name'] ?? '') ?></h1>
    <p style="margin-top:0.3rem; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
        <?php if ($serviceIsTrashed): ?>
        <span class="badge badge-muted">In Trash</span>
        <?php endif; ?>
        <span class="badge <?= $isActive ? 'badge-success' : 'badge-muted' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
        <span class="badge badge-muted"><?= htmlspecialchars($svcType) ?></span>
        <?php if ($catName): ?>
        <span style="font-size:0.875rem; color:#64748b;"><?= $catName ?></span>
        <?php endif; ?>
    </p>
</div>

<!-- Identity -->
<?php $desc = isset($service['description']) && $service['description'] !== null ? trim((string) $service['description']) : ''; ?>
<?php if ($desc !== ''): ?>
<div class="svc-step-section" style="max-width:700px; margin-bottom:1rem;">
    <p class="svc-step-section-title">Description</p>
    <pre style="white-space:pre-wrap; font-family:inherit; margin:0; font-size:0.9rem; color:#0f172a;"><?= htmlspecialchars($desc) ?></pre>
</div>
<?php endif; ?>

<div class="svc-step-section" style="max-width:700px; margin-bottom:1rem;">
    <p class="svc-step-section-title">Identity</p>
    <dl class="svc-step-detail-grid">
        <?php if (!empty($service['sku'])): ?>
        <dt>SKU</dt><dd><?= htmlspecialchars($service['sku']) ?></dd>
        <?php endif; ?>
        <?php if (!empty($service['barcode'])): ?>
        <dt>Barcode</dt><dd><?= htmlspecialchars($service['barcode']) ?></dd>
        <?php endif; ?>
        <?php if (!$catName && !empty($service['sku']) === false && empty($service['barcode'])): ?>
        <dt style="color:#94a3b8;">No SKU, barcode, or category set.</dt><dd></dd>
        <?php endif; ?>
    </dl>
</div>

<!-- Booking behaviour -->
<div class="svc-step-section" style="max-width:700px; margin-bottom:1rem;">
    <p class="svc-step-section-title">Booking &amp; operational behaviour</p>
    <dl class="svc-step-detail-grid">
        <dt>Duration</dt>
        <dd><?= _svcDur((int)($service['duration_minutes'] ?? 0)) ?></dd>
        <?php if ((int)($service['buffer_before_minutes'] ?? 0) > 0): ?>
        <dt>Prep time before</dt>
        <dd><?= _svcDur((int)$service['buffer_before_minutes']) ?></dd>
        <?php endif; ?>
        <?php if ((int)($service['buffer_after_minutes'] ?? 0) > 0): ?>
        <dt>Cleanup time after</dt>
        <dd><?= _svcDur((int)$service['buffer_after_minutes']) ?></dd>
        <?php endif; ?>
        <dt>Processing time</dt>
        <dd><?= _svcFlag($service['processing_time_required']) ?></dd>
        <dt>Add-on</dt>
        <dd><?= _svcFlag($service['add_on']) ?></dd>
        <dt>Requires two staff</dt>
        <dd><?= _svcFlag($service['requires_two_staff_members']) ?></dd>
        <dt>Applies to employee</dt>
        <dd><?= _svcFlag($service['applies_to_employee'] ?? 1) ?></dd>
        <dt>Applies to room</dt>
        <dd><?= _svcFlag($service['applies_to_room'] ?? 1) ?></dd>
        <dt>Requires equipment</dt>
        <dd><?= _svcFlag($service['requires_equipment']) ?></dd>
    </dl>
</div>

<!-- Commercial -->
<div class="svc-step-section" style="max-width:700px; margin-bottom:1rem;">
    <p class="svc-step-section-title">Commercial &amp; sales</p>
    <dl class="svc-step-detail-grid">
        <dt>Price</dt>
        <dd><?= htmlspecialchars(number_format((float)($service['price'] ?? 0), 2)) ?></dd>
        <?php
        $vatDisplay = null;
        if (!empty($service['vat_rate_name'])) {
            $vatDisplay = htmlspecialchars($service['vat_rate_name']);
        } elseif (!empty($service['vat_rate_id'])) {
            $vatDisplay = 'Rate #' . (int) $service['vat_rate_id'];
        }
        if ($vatDisplay):
        ?>
        <dt>VAT / tax rate</dt><dd><?= $vatDisplay ?></dd>
        <?php endif; ?>
        <dt>Staff fee</dt>
        <dd><?= $feeLabel !== null
            ? htmlspecialchars(ucfirst($feeMode) . ': ' . $feeLabel)
            : 'None' ?></dd>
        <?php if (!empty($service['billing_code'])): ?>
        <dt>Billing code</dt><dd><?= htmlspecialchars($service['billing_code']) ?></dd>
        <?php endif; ?>
        <dt>Show in online menu</dt>
        <dd><?= _svcFlag($service['show_in_online_menu']) ?></dd>
        <dt>Gift voucher sale</dt>
        <dd><?= _svcFlag($service['allow_on_gift_voucher_sale']) ?></dd>
    </dl>
</div>

<?php
// Products section
$productRows = $service['product_rows'] ?? [];
$productCost = 0.0;
foreach ($productRows as $r) {
    $productCost += (float) $r['quantity_used'] * (float) ($r['unit_cost_snapshot'] ?? $r['cost_price'] ?? 0);
}
?>
<div class="svc-step-section" style="max-width:700px; margin-bottom:1rem;">
    <p class="svc-step-section-title" style="display:flex; align-items:center; justify-content:space-between;">
        <span>Products / consumables</span>
        <a href="/services-resources/services/<?= (int) $service['id'] ?>/step-2" style="font-size:0.8rem; color:#2563eb;">Edit</a>
    </p>
    <?php if (empty($productRows)): ?>
    <p style="font-size:0.875rem; color:#94a3b8;">No products assigned.</p>
    <?php else: ?>
    <table style="width:100%; border-collapse:collapse; font-size:0.875rem; margin-bottom:0.5rem;">
        <thead><tr>
            <th style="text-align:left; padding:0.3rem 0.5rem; border-bottom:1px solid #e2e8f0; color:#64748b;">Product</th>
            <th style="text-align:left; padding:0.3rem 0.5rem; border-bottom:1px solid #e2e8f0; color:#64748b;">SKU</th>
            <th style="text-align:right; padding:0.3rem 0.5rem; border-bottom:1px solid #e2e8f0; color:#64748b;">Qty</th>
            <th style="text-align:right; padding:0.3rem 0.5rem; border-bottom:1px solid #e2e8f0; color:#64748b;">Unit cost</th>
        </tr></thead>
        <tbody>
        <?php foreach ($productRows as $r): ?>
        <tr>
            <td style="padding:0.3rem 0.5rem;"><?= htmlspecialchars($r['name'] ?? '') ?></td>
            <td style="padding:0.3rem 0.5rem; color:#64748b; font-size:0.8rem;"><?= htmlspecialchars($r['sku'] ?? '') ?></td>
            <td style="padding:0.3rem 0.5rem; text-align:right;"><?= number_format((float) $r['quantity_used'], 3) ?></td>
            <td style="padding:0.3rem 0.5rem; text-align:right;">
                <?= number_format((float) ($r['unit_cost_snapshot'] ?? $r['cost_price'] ?? 0), 2) ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="font-size:0.875rem; color:#475569;">Total cost per service: <strong>€<?= number_format($productCost, 2) ?></strong></div>
    <?php endif; ?>
</div>

<?php
// Employees section — load fresh from service row
$staffIds = $service['staff_ids'] ?? [];
?>
<div class="svc-step-section" style="max-width:700px; margin-bottom:1rem;">
    <p class="svc-step-section-title" style="display:flex; align-items:center; justify-content:space-between;">
        <span>Employees (<?= count($staffIds) ?>)</span>
        <a href="/services-resources/services/<?= (int) $service['id'] ?>/step-3" style="font-size:0.8rem; color:#2563eb;">Edit</a>
    </p>
    <?php if (empty($staffIds)): ?>
    <p style="font-size:0.875rem; color:#94a3b8;">No employees directly assigned (any active employee may perform this service).</p>
    <?php else: ?>
    <?php
    // Load staff names for display
    $staffNameMap = [];
    try {
        $staffProvider = \Core\App\Application::container()->get(\Core\Contracts\StaffListProvider::class);
        foreach ($staffProvider->list() as $s) {
            $staffNameMap[(int) $s['id']] = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
        }
    } catch (\Throwable $e) {}
    ?>
    <ul style="margin:0; padding-left:1.25rem; font-size:0.875rem;">
        <?php foreach ($staffIds as $sid): ?>
        <li><?= htmlspecialchars($staffNameMap[(int) $sid] ?? ('Staff #' . (int) $sid)) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php
// Rooms section
$roomIds = $service['room_ids'] ?? [];
?>
<div class="svc-step-section" style="max-width:700px; margin-bottom:1rem;">
    <p class="svc-step-section-title" style="display:flex; align-items:center; justify-content:space-between;">
        <span>Rooms (<?= count($roomIds) ?>)</span>
        <a href="/services-resources/services/<?= (int) $service['id'] ?>/step-4" style="font-size:0.8rem; color:#2563eb;">Edit</a>
    </p>
    <?php if (empty($roomIds)): ?>
    <p style="font-size:0.875rem; color:#94a3b8;">No rooms directly assigned (any available room may be used).</p>
    <?php else: ?>
    <?php
    $roomNameMap = [];
    try {
        $roomRepo2 = \Core\App\Application::container()->get(\Modules\ServicesResources\Repositories\RoomRepository::class);
        foreach ($roomRepo2->list() as $rm) {
            $roomNameMap[(int) $rm['id']] = $rm['name'] ?? ('Room #' . $rm['id']);
        }
    } catch (\Throwable $e) {}
    ?>
    <ul style="margin:0; padding-left:1.25rem; font-size:0.875rem;">
        <?php foreach ($roomIds as $rid): ?>
        <li><?= htmlspecialchars($roomNameMap[(int) $rid] ?? ('Room #' . (int) $rid)) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div style="margin-top:1.25rem; display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
    <?php if (!$serviceIsTrashed): ?>
    <a href="/services-resources/services/<?= (int) $service['id'] ?>/edit" class="btn">Edit Step 1</a>
    <a href="/services-resources/services/<?= (int) $service['id'] ?>/step-2" class="btn">Edit Step 2</a>
    <a href="/services-resources/services/<?= (int) $service['id'] ?>/step-3" class="btn">Edit Step 3</a>
    <a href="/services-resources/services/<?= (int) $service['id'] ?>/step-4" class="btn">Edit Step 4</a>
    <form method="post" action="/services-resources/services/<?= (int) $service['id'] ?>/delete"
        style="display:inline" onsubmit="return confirm('Move this service to Trash?')">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn" style="color:#dc2626;">Trash</button>
    </form>
    <?php else: ?>
    <form method="post" action="/services-resources/services/<?= (int) $service['id'] ?>/restore" style="display:inline">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn">Restore</button>
    </form>
    <form method="post" action="/services-resources/services/<?= (int) $service['id'] ?>/permanent-delete"
        style="display:inline" onsubmit="return confirm('Permanently delete this service? This cannot be undone.')">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn" style="color:#dc2626;">Delete permanently</button>
    </form>
    <?php endif; ?>
</div>
<p style="margin-top:1rem;"><a href="/services-resources/services">← Back to Services</a><?php if ($serviceIsTrashed): ?> · <a href="/services-resources/services?status=trash">View Trash</a><?php endif; ?></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
