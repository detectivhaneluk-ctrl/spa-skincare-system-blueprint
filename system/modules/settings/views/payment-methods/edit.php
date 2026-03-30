<?php
$title = 'Edit Payment Method';
$method = $method ?? [];
$errors = $errors ?? [];
ob_start();
require base_path('modules/settings/views/establishment/_styles.php');
?>
<section class="settings-establishment">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Edit payment method</h2>
        <p class="settings-establishment__lead">
            Code is immutable (payments reference it). Family remains derived from code and name; type label is display-only metadata.
        </p>
        <div class="settings-establishment-actions">
            <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/payment-methods">← Back to catalog</a>
            <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?section=payments">Payment Settings</a>
        </div>
    </header>

    <div class="settings-establishment-card settings-establishment-card--full">
        <div class="settings-establishment-summary" style="margin-bottom: 1rem;">
            <div class="settings-establishment-summary__row">
                <span class="settings-establishment-summary__key">Code</span>
                <span class="settings-establishment-summary__value"><code><?= htmlspecialchars((string) ($method['code'] ?? '')) ?></code> (fixed)</span>
            </div>
            <div class="settings-establishment-summary__row">
                <span class="settings-establishment-summary__key">Family (inferred)</span>
                <span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($method['family_label'] ?? '')) ?></span>
            </div>
            <div class="settings-establishment-summary__row">
                <span class="settings-establishment-summary__key">Context</span>
                <span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($method['family_usage_note'] ?? '')) ?></span>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <ul class="form-errors">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="/settings/payment-methods/<?= (int) ($method['id'] ?? 0) ?>" class="settings-form entity-form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="settings-grid">
                <div class="setting-row">
                    <label for="type_label">Type label</label>
                    <input type="text" id="type_label" name="type_label" maxlength="50" value="<?= htmlspecialchars((string) ($method['type_label'] ?? '')) ?>" autocomplete="off">
                    <p class="setting-help">Optional operator-facing label only. Does not affect family classification or payment validation rules.</p>
                </div>
                <div class="setting-row">
                    <label for="name">Display name *</label>
                    <input type="text" id="name" name="name" required maxlength="100" value="<?= htmlspecialchars($method['name'] ?? '') ?>" autocomplete="off">
                </div>
                <div class="setting-row">
                    <input type="hidden" name="is_active" value="0">
                    <label><input type="checkbox" name="is_active" value="1" <?= !empty($method['is_active']) ? 'checked' : '' ?>> Active</label>
                </div>
                <div class="setting-row">
                    <label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($method['sort_order'] ?? 0) ?>" min="-32768" max="32767">
                </div>
            </div>
            <div class="settings-establishment-actions">
                <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Save</button>
                <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/payment-methods">Cancel</a>
            </div>
        </form>
    </div>
</section>
<style>
    .settings-establishment .settings-establishment-btn--primary {
        border-color: #111827;
        color: #fff;
        background: #111827;
    }
</style>
<?php
$settingsWorkspaceContent = (string) ob_get_clean();
$activeSettingsSection = 'payment_methods';
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
