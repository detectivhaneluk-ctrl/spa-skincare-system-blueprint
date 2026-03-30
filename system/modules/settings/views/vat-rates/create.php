<?php
$title = 'Add VAT Type';
$rate = $rate ?? [
    'name' => '',
    'rate_percent' => '0',
    'is_flexible' => false,
    'price_includes_tax' => false,
    'applies_to_json' => [],
    'is_active' => true,
    'sort_order' => 0
];
$appliesToOptions = $appliesToOptions ?? \Modules\Sales\Services\VatRateService::ALLOWED_APPLIES_TO;
$errors = $errors ?? [];
ob_start();
require base_path('modules/settings/views/establishment/_styles.php');
?>
<section class="settings-establishment">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Add VAT type</h2>
        <div class="settings-establishment-actions">
            <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/vat-rates">← VAT types</a>
        </div>
    </header>

    <div class="settings-establishment-card settings-establishment-card--full">
        <?php if (!empty($errors)): ?>
            <ul class="form-errors">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" action="/settings/vat-rates" class="settings-form entity-form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="settings-grid">
                <div class="setting-row">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required maxlength="100" value="<?= htmlspecialchars((string) ($rate['name'] ?? '')) ?>">
                </div>
                <div class="setting-row">
                    <label for="rate_percent">Rate % *</label>
                    <input type="number" id="rate_percent" name="rate_percent" required min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($rate['rate_percent'] ?? '0')) ?>">
                </div>
                <div class="setting-row">
                    <input type="hidden" name="is_flexible" value="0">
                    <label><input type="checkbox" name="is_flexible" value="1" <?= !empty($rate['is_flexible']) ? 'checked' : '' ?>> Flexible</label>
                </div>
                <div class="setting-row">
                    <input type="hidden" name="price_includes_tax" value="0">
                    <label><input type="checkbox" name="price_includes_tax" value="1" <?= !empty($rate['price_includes_tax']) ? 'checked' : '' ?>> Price includes tax</label>
                </div>
                <div class="setting-row">
                    <span>Applied to</span>
                    <?php $selectedAppliesTo = is_array($rate['applies_to_json'] ?? null) ? $rate['applies_to_json'] : []; ?>
                    <?php foreach ($appliesToOptions as $token): ?>
                        <label><input type="checkbox" name="applies_to[]" value="<?= htmlspecialchars((string) $token) ?>" <?= in_array($token, $selectedAppliesTo, true) ? 'checked' : '' ?>> <?= htmlspecialchars((string) $token) ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="setting-row">
                    <input type="hidden" name="is_active" value="0">
                    <label><input type="checkbox" name="is_active" value="1" <?= !empty($rate['is_active']) ? 'checked' : '' ?>> Active</label>
                </div>
                <div class="setting-row">
                    <label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($rate['sort_order'] ?? 0) ?>" min="-32768" max="32767">
                </div>
            </div>
            <div class="settings-establishment-actions">
                <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Create</button>
                <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/vat-rates">Cancel</a>
            </div>
        </form>
    </div>
</section>
<?php
$settingsWorkspaceContent = (string) ob_get_clean();
$activeSettingsSection = 'vat_rates';
$settingsPageTitle = 'Settings';
$settingsPageSubtitle = 'Choose a settings subsection from the sidebar. The workspace renders one focused area at a time.';
$settingsFlash = $flash ?? null;
$onlineBookingBranchId = 0;
$appointmentsBranchId = 0;
ob_start();
require base_path('modules/settings/views/partials/shell.php');
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
