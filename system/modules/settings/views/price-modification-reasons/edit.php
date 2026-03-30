<?php
$title = 'Edit Price Modification Reason';
$reason = $reason ?? [];
$errors = $errors ?? [];
ob_start();
require base_path('modules/settings/views/establishment/_styles.php');
?>
<section class="settings-establishment">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Edit price modification reason</h2>
        <p class="settings-establishment__lead">
            Maintain the internal catalog used to explain manual price changes and corrections.
        </p>
        <div class="settings-establishment-actions">
            <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/price-modification-reasons">← Back to catalog</a>
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

        <form method="post" action="/settings/price-modification-reasons/<?= (int) ($reason['id'] ?? 0) ?>" class="settings-form entity-form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="settings-grid">
                <div class="setting-row">
                    <label for="code">Code *</label>
                    <input type="text" id="code" name="code" required maxlength="64" value="<?= htmlspecialchars((string) ($reason['code'] ?? '')) ?>" autocomplete="off">
                </div>
                <div class="setting-row">
                    <label for="name">Label *</label>
                    <input type="text" id="name" name="name" required maxlength="120" value="<?= htmlspecialchars((string) ($reason['name'] ?? '')) ?>" autocomplete="off">
                </div>
                <div class="setting-row">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3" maxlength="500"><?= htmlspecialchars((string) ($reason['description'] ?? '')) ?></textarea>
                </div>
                <div class="setting-row">
                    <input type="hidden" name="is_active" value="0">
                    <label><input type="checkbox" name="is_active" value="1" <?= !empty($reason['is_active']) ? 'checked' : '' ?>> Active</label>
                </div>
                <div class="setting-row">
                    <label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($reason['sort_order'] ?? 0) ?>" min="0" max="32767">
                </div>
            </div>
            <div class="settings-establishment-actions">
                <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Save</button>
                <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/price-modification-reasons">Cancel</a>
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
$activeSettingsSection = 'price_modification_reasons';
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

