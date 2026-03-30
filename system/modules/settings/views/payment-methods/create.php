<?php
$title = 'Add Payment Method';
$method = $method ?? ['type_label' => '', 'name' => '', 'is_active' => true, 'sort_order' => 0];
$errors = $errors ?? [];
ob_start();
require base_path('modules/settings/views/establishment/_styles.php');
?>
<section class="settings-establishment">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Add payment method</h2>
        <p class="settings-establishment__lead">
            Creates a global method row. Code is generated automatically from display name and stays fixed after save. Family is inferred from code and name for Payment Settings.
        </p>
        <div class="settings-establishment-actions">
            <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/payment-methods">← Back to catalog</a>
            <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?section=payments">Payment Settings</a>
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
        <form method="post" action="/settings/payment-methods" class="settings-form entity-form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="settings-grid">
                <div class="setting-row">
                    <label for="type_label">Type label</label>
                    <input type="text" id="type_label" name="type_label" maxlength="50" value="<?= htmlspecialchars((string) ($method['type_label'] ?? '')) ?>" autocomplete="off">
                    <p class="setting-help">Optional operator-facing label only (for example Card, Digital Wallet, Bank Transfer).</p>
                </div>
                <div class="setting-row">
                    <label for="name">Display name *</label>
                    <input type="text" id="name" name="name" required maxlength="100" value="<?= htmlspecialchars($method['name'] ?? '') ?>" autocomplete="off">
                    <p class="setting-help">Used to generate immutable code on create; keep this clear for operators.</p>
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
                <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Create</button>
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
