<?php
$title = 'Price Modification Reasons';
$reasons = $reasons ?? [];
ob_start();
require base_path('modules/settings/views/establishment/_styles.php');
?>
<section class="settings-establishment settings-price-modification-reasons">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Price Modification Reasons</h2>
        <p class="settings-establishment__lead">
            Internal catalog for why a staff member manually changes a service/product price.
            This is not PSP, tax, or gateway logic.
        </p>
        <div class="settings-establishment-actions">
            <a class="settings-establishment-btn settings-establishment-btn--primary" href="/settings/price-modification-reasons/create">Add reason</a>
            <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?section=payments">Payment Settings</a>
        </div>
    </header>

    <div class="settings-establishment-card settings-establishment-card--full">
        <h3 class="settings-establishment-card__title">Reason catalog</h3>
        <p class="settings-establishment-card__help">
            Suggested uses: loyalty adjustment, goodwill discount, manager override, damaged item adjustment, promo match, manual correction.
        </p>

        <?php if (empty($storageReady)): ?>
            <p class="settings-establishment-note">Storage not ready yet. Apply the latest migration and reload this page.</p>
        <?php elseif ($reasons === []): ?>
            <p class="settings-establishment-note">No reasons yet. Add one to start standardizing manual price-change context.</p>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn settings-establishment-btn--primary" href="/settings/price-modification-reasons/create">Add reason</a>
            </div>
        <?php else: ?>
            <div class="settings-pmr-table-wrap">
                <table class="settings-establishment-hours-table settings-pmr-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Description</th>
                            <th>Active</th>
                            <th>Order</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reasons as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></td>
                                <td><code><?= htmlspecialchars((string) ($r['code'] ?? '')) ?></code></td>
                                <td class="settings-pmr-table__desc"><?= htmlspecialchars((string) ($r['description'] ?? '')) ?: '—' ?></td>
                                <td><?= !empty($r['is_active']) ? 'Yes' : 'No' ?></td>
                                <td><?= (int) ($r['sort_order'] ?? 0) ?></td>
                                <td>
                                    <a class="settings-establishment-btn settings-establishment-btn--small settings-establishment-btn--muted" href="/settings/price-modification-reasons/<?= (int) ($r['id'] ?? 0) ?>/edit">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<style>
    .settings-price-modification-reasons .settings-establishment-btn--primary {
        border-color: #111827;
        color: #fff;
        background: #111827;
    }
    .settings-pmr-table-wrap {
        overflow-x: auto;
    }
    .settings-pmr-table__desc {
        min-width: 16rem;
        max-width: 28rem;
        font-size: 0.82rem;
        color: #4b5563;
        line-height: 1.35;
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

