<?php
$title = 'VAT Types';
$rates = $rates ?? [];
ob_start();
require base_path('modules/settings/views/establishment/_styles.php');
?>
<section class="settings-establishment settings-vat-types-catalog">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">VAT Types</h2>
        <p class="settings-establishment__lead">
            Store VAT type catalog truth for settings and service selection. Runtime invoice math and product tax behavior remain unchanged in this wave.
        </p>
        <div class="settings-establishment-actions">
            <a class="settings-establishment-btn settings-establishment-btn--primary" href="/settings/vat-rates/create">Add VAT type</a>
        </div>
    </header>

    <div class="settings-establishment-card settings-establishment-card--full">
        <?php if ($rates === []): ?>
            <p class="settings-establishment-note">No VAT types yet.</p>
        <?php else: ?>
            <div class="settings-vat-types-table-wrap">
                <table class="settings-establishment-hours-table settings-vat-types-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Rate</th>
                            <th>Flexible</th>
                            <th>Price includes tax</th>
                            <th>Applied to</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rates as $r): ?>
                            <?php $appliesTo = is_array($r['applies_to_json'] ?? null) ? $r['applies_to_json'] : []; ?>
                            <tr>
                                <td>
                                    <div><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></div>
                                    <div class="settings-vat-types-meta"><code><?= htmlspecialchars((string) ($r['code'] ?? '')) ?></code></div>
                                </td>
                                <td><?= !empty($r['is_active']) ? 'Active' : 'Archived' ?></td>
                                <td><?= htmlspecialchars(number_format((float) ($r['rate_percent'] ?? 0), 2)) ?>%</td>
                                <td><?= !empty($r['is_flexible']) ? 'Yes' : 'No' ?></td>
                                <td><?= !empty($r['price_includes_tax']) ? 'Yes' : 'No' ?></td>
                                <td><?= $appliesTo === [] ? 'All / unspecified' : htmlspecialchars(implode(', ', $appliesTo)) ?></td>
                                <td>
                                    <div class="settings-vat-types-actions">
                                        <a class="settings-establishment-btn settings-establishment-btn--small settings-establishment-btn--muted" href="/settings/vat-rates/<?= (int) ($r['id'] ?? 0) ?>/edit">Edit</a>
                                        <?php if (!empty($r['is_active'])): ?>
                                            <form method="post" action="/settings/vat-rates/<?= (int) ($r['id'] ?? 0) ?>/archive" onsubmit="return confirm('Archive this VAT type? This will deactivate it.');">
                                                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                                <button type="submit" class="settings-establishment-btn settings-establishment-btn--small settings-establishment-btn--muted">Archive</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
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
    .settings-vat-types-catalog .settings-establishment-btn--primary { border-color: #111827; color: #fff; background: #111827; }
    .settings-vat-types-table-wrap { overflow-x: auto; }
    .settings-vat-types-table th, .settings-vat-types-table td { vertical-align: top; }
    .settings-vat-types-meta { margin-top: 0.35rem; color: #6b7280; font-size: 0.8rem; }
    .settings-vat-types-actions { display: flex; gap: 0.5rem; align-items: center; }
    .settings-vat-types-actions form { margin: 0; }
</style>
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
