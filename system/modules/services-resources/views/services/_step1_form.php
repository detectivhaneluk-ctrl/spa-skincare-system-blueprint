<?php
/**
 * Shared Step 1 form body — included by create.php and edit.php.
 *
 * Expected variables injected by the including template:
 *   $service      — array of current field values
 *   $categories   — array from ServiceCategoryRepository::list() (kept for BC)
 *   $catTreeRows  — flat DFS-ordered tree from ServiceCategoryRepository::buildTreeFlat()
 *   $vatRates     — array from VatRateService::listActive()
 *   $errors       — array of field => message
 *   $formAction   — string POST URL
 *   $csrf         — CSRF token value
 *   $isCreate     — bool: true on create, false on edit
 */

$selCatId = isset($service['category_id']) && $service['category_id'] !== '' && $service['category_id'] !== null
    ? (int) $service['category_id'] : null;

// Build path label for currently selected category (display only)
$selCatPath = '';
if ($selCatId !== null && !empty($catTreeRows)) {
    foreach ($catTreeRows as $tr) {
        if ((int) $tr['id'] === $selCatId) {
            $selCatPath = $tr['path'] ?? $tr['name'] ?? '';
            break;
        }
    }
}

$feeMode = $service['staff_fee_mode'] ?? 'none';
?>
<?php if (!empty($errors['_general'])): ?>
<div class="svc-step-general-err"><?= htmlspecialchars($errors['_general']) ?></div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($formAction) ?>" class="svc-step-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">

    <!-- ══ A — CLASSIFICATION ══════════════════════════════════════ -->
    <div class="svc-step-section">
        <p class="svc-step-section-title">A — Classification</p>

        <div class="svc-step-row">
            <label for="service_type">Service type</label>
            <select id="service_type" name="service_type">
                <option value="service" <?= ($service['service_type'] ?? 'service') === 'service' ? 'selected' : '' ?>>Service</option>
                <option value="package_item" <?= ($service['service_type'] ?? '') === 'package_item' ? 'selected' : '' ?>>Package item</option>
                <option value="other" <?= ($service['service_type'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
        </div>

        <div class="svc-step-row">
            <label for="category_id">Category</label>
            <?php if (empty($catTreeRows)): ?>
            <span class="svc-hint">No categories defined yet.
                <a href="/services-resources/categories" target="_blank">Manage categories →</a>
            </span>
            <input type="hidden" name="category_id" value="">
            <?php else: ?>
            <select id="category_id" name="category_id" class="svc-cat-tree-select">
                <option value="">— No category —</option>
                <?php foreach ($catTreeRows as $tr): ?>
                <?php $depth = (int) ($tr['depth'] ?? 0); ?>
                <option value="<?= (int) $tr['id'] ?>"
                    <?= $selCatId === (int) $tr['id'] ? 'selected' : '' ?>>
                    <?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth) ?><?= $depth > 0 ? '└ ' : '' ?><?= htmlspecialchars($tr['name'] ?? '') ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($selCatPath !== ''): ?>
            <span class="svc-cat-selected-path svc-hint">Path: <strong><?= htmlspecialchars($selCatPath) ?></strong></span>
            <?php endif; ?>
            <span class="svc-hint">
                Any node in the taxonomy tree.
                <a href="/services-resources/categories" target="_blank" class="svc-hint-link">Manage categories →</a>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ B — SERVICE IDENTITY ════════════════════════════════════ -->
    <div class="svc-step-section">
        <p class="svc-step-section-title">B — Service identity</p>

        <div class="svc-step-row">
            <label for="name">Name <span class="svc-req">*</span></label>
            <input type="text" id="name" name="name" required maxlength="200"
                value="<?= htmlspecialchars($service['name'] ?? '') ?>"
                placeholder="e.g. Signature facial">
            <?php if (!empty($errors['name'])): ?><span class="svc-err"><?= htmlspecialchars($errors['name']) ?></span><?php endif; ?>
        </div>

        <div class="svc-step-row">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="4"
                placeholder="What does this service involve?"><?= htmlspecialchars((string) ($service['description'] ?? '')) ?></textarea>
            <?php if (!empty($errors['description'])): ?><span class="svc-err"><?= htmlspecialchars($errors['description']) ?></span><?php endif; ?>
        </div>

        <div class="svc-step-inline">
            <div class="svc-step-row">
                <label for="sku">SKU / code</label>
                <input type="text" id="sku" name="sku" maxlength="100"
                    value="<?= htmlspecialchars($service['sku'] ?? '') ?>"
                    placeholder="e.g. SVC-001">
                <span class="svc-hint">Unique identifier. Leave blank if not used.</span>
                <?php if (!empty($errors['sku'])): ?><span class="svc-err"><?= htmlspecialchars($errors['sku']) ?></span><?php endif; ?>
            </div>
            <div class="svc-step-row">
                <label for="barcode">Barcode</label>
                <input type="text" id="barcode" name="barcode" maxlength="100"
                    value="<?= htmlspecialchars($service['barcode'] ?? '') ?>"
                    placeholder="e.g. 1234567890123">
                <?php if (!empty($errors['barcode'])): ?><span class="svc-err"><?= htmlspecialchars($errors['barcode']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ C — BOOKING / OPERATIONAL BEHAVIOUR ════════════════════ -->
    <div class="svc-step-section">
        <p class="svc-step-section-title">C — Booking &amp; operational behaviour</p>

        <div class="svc-step-inline">
            <div class="svc-step-row">
                <label for="duration_minutes">Duration (min) <span class="svc-req">*</span></label>
                <input type="number" id="duration_minutes" name="duration_minutes"
                    min="1" max="1440"
                    value="<?= htmlspecialchars((string)($service['duration_minutes'] ?? 60)) ?>">
                <?php if (!empty($errors['duration_minutes'])): ?><span class="svc-err"><?= htmlspecialchars($errors['duration_minutes']) ?></span><?php endif; ?>
            </div>
            <div class="svc-step-row">
                <label for="buffer_before_minutes">Prep time before (min)</label>
                <input type="number" id="buffer_before_minutes" name="buffer_before_minutes"
                    min="0" max="240"
                    value="<?= htmlspecialchars((string)($service['buffer_before_minutes'] ?? 0)) ?>">
                <span class="svc-hint">Setup before client arrives.</span>
            </div>
            <div class="svc-step-row">
                <label for="buffer_after_minutes">Cleanup time after (min)</label>
                <input type="number" id="buffer_after_minutes" name="buffer_after_minutes"
                    min="0" max="240"
                    value="<?= htmlspecialchars((string)($service['buffer_after_minutes'] ?? 0)) ?>">
                <span class="svc-hint">Turnover time after service ends.</span>
            </div>
        </div>

        <div class="svc-step-row" style="margin-top:0.75rem;">
            <span style="display:block; font-size:0.8rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.08em; margin-bottom:0.5rem;">Service flags</span>
            <div style="display:flex; flex-wrap:wrap; gap:0.5rem 1.5rem;">
                <label class="svc-step-toggle-row">
                    <input type="checkbox" name="processing_time_required" value="1" <?= !empty($service['processing_time_required']) ? 'checked' : '' ?>>
                    <span>Requires processing time</span>
                </label>
                <label class="svc-step-toggle-row">
                    <input type="checkbox" name="add_on" value="1" <?= !empty($service['add_on']) ? 'checked' : '' ?>>
                    <span>Add-on service</span>
                </label>
                <label class="svc-step-toggle-row">
                    <input type="checkbox" name="requires_two_staff_members" value="1" <?= !empty($service['requires_two_staff_members']) ? 'checked' : '' ?>>
                    <span>Requires two staff members</span>
                </label>
                <label class="svc-step-toggle-row">
                    <input type="checkbox" name="applies_to_employee" value="1" <?= ($service['applies_to_employee'] ?? 1) ? 'checked' : '' ?>>
                    <span>Applies to employee slot</span>
                </label>
                <label class="svc-step-toggle-row">
                    <input type="checkbox" name="applies_to_room" value="1" <?= ($service['applies_to_room'] ?? 1) ? 'checked' : '' ?>>
                    <span>Applies to room slot</span>
                </label>
                <label class="svc-step-toggle-row">
                    <input type="checkbox" name="requires_equipment" value="1" <?= !empty($service['requires_equipment']) ? 'checked' : '' ?>>
                    <span>Requires equipment</span>
                </label>
            </div>
        </div>
    </div>

    <!-- ══ D — COMMERCIAL / SALES BASICS ══════════════════════════ -->
    <div class="svc-step-section">
        <p class="svc-step-section-title">D — Commercial &amp; sales</p>

        <div class="svc-step-inline">
            <div class="svc-step-row">
                <label for="price">Price</label>
                <input type="number" id="price" name="price" min="0" step="0.01"
                    value="<?= htmlspecialchars(number_format((float)($service['price'] ?? 0), 2, '.', '')) ?>">
                <?php if (!empty($errors['price'])): ?><span class="svc-err"><?= htmlspecialchars($errors['price']) ?></span><?php endif; ?>
            </div>
            <div class="svc-step-row">
                <label for="vat_rate_id">VAT / tax rate</label>
                <select id="vat_rate_id" name="vat_rate_id">
                    <option value="">— No VAT —</option>
                    <?php foreach ($vatRates ?? [] as $vr): ?>
                    <option value="<?= (int) $vr['id'] ?>" <?= ((int)($service['vat_rate_id'] ?? 0)) === (int)$vr['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($vr['name']) ?> (<?= number_format((float)$vr['rate_percent'], 2) ?>%)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['vat_rate_id'])): ?><span class="svc-err"><?= htmlspecialchars($errors['vat_rate_id']) ?></span><?php endif; ?>
            </div>
        </div>

        <!-- Staff fee -->
        <div class="svc-step-row" style="margin-top:0.5rem;">
            <label>Staff fee</label>
            <div style="display:flex; gap:0.75rem; align-items:flex-start; flex-wrap:wrap;">
                <select id="staff_fee_mode" name="staff_fee_mode" onchange="svcToggleFeeValue(this.value)" style="width:auto; min-width:140px;">
                    <option value="none" <?= $feeMode === 'none' ? 'selected' : '' ?>>None</option>
                    <option value="percentage" <?= $feeMode === 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                    <option value="amount" <?= $feeMode === 'amount' ? 'selected' : '' ?>>Fixed amount</option>
                </select>
                <div id="svc_fee_val_wrap" style="<?= $feeMode === 'none' ? 'display:none' : '' ?>">
                    <input type="number" id="staff_fee_value" name="staff_fee_value"
                        min="0" step="0.01"
                        value="<?= htmlspecialchars((string)($service['staff_fee_value'] ?? '')) ?>"
                        placeholder="<?= $feeMode === 'percentage' ? '0–100' : '0.00' ?>"
                        style="width:120px;">
                    <?php if (!empty($errors['staff_fee_value'])): ?><span class="svc-err"><?= htmlspecialchars($errors['staff_fee_value']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        function svcToggleFeeValue(mode) {
            var wrap = document.getElementById('svc_fee_val_wrap');
            var inp  = document.getElementById('staff_fee_value');
            if (mode === 'none') {
                wrap.style.display = 'none';
                inp.value = '';
            } else {
                wrap.style.display = '';
                inp.placeholder = mode === 'percentage' ? '0–100' : '0.00';
            }
        }
        </script>

        <div class="svc-step-row" style="margin-top:0.75rem;">
            <label for="billing_code">Billing code</label>
            <input type="text" id="billing_code" name="billing_code" maxlength="50"
                value="<?= htmlspecialchars($service['billing_code'] ?? '') ?>"
                placeholder="External reference code">
            <?php if (!empty($errors['billing_code'])): ?><span class="svc-err"><?= htmlspecialchars($errors['billing_code']) ?></span><?php endif; ?>
        </div>

        <div style="margin-top:0.75rem; display:flex; flex-wrap:wrap; gap:0.5rem 1.5rem;">
            <label class="svc-step-toggle-row">
                <input type="checkbox" name="is_active" value="1" <?= !empty($service['is_active']) ? 'checked' : '' ?>>
                <span>Active — service appears in scheduling &amp; booking</span>
            </label>
            <label class="svc-step-toggle-row">
                <input type="checkbox" name="show_in_online_menu" value="1" <?= !empty($service['show_in_online_menu']) ? 'checked' : '' ?>>
                <span>Show in online menu</span>
            </label>
            <label class="svc-step-toggle-row">
                <input type="checkbox" name="allow_on_gift_voucher_sale" value="1" <?= !empty($service['allow_on_gift_voucher_sale']) ? 'checked' : '' ?>>
                <span>Allow on gift voucher sale</span>
            </label>
        </div>
    </div>

    <div class="svc-step-actions">
        <?php if ($isCreate): ?>
        <button type="submit" class="btn-primary">
            Save &amp; continue
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
        <a href="/services-resources/services" class="btn-ghost">Cancel</a>
        <?php else: ?>
        <button type="submit" class="btn-primary">
            Save &amp; continue
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
        <a href="/services-resources/services/<?= (int) $service['id'] ?>/step-2" class="btn-ghost">Skip to Step 2</a>
        <a href="/services-resources/services/<?= (int) $service['id'] ?>" class="btn-ghost">View service</a>
        <?php endif; ?>
    </div>
</form>
