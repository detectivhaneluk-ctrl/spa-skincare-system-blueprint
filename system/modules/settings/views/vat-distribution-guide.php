<?php
$title = 'Répartition TVA';
$vatRates = $vatRates ?? [];
$matrixDomains = $matrixDomains ?? ['products', 'services', 'memberships'];
ob_start();
require base_path('modules/settings/views/establishment/_styles.php');
?>
<section class="settings-establishment">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Répartition TVA</h2>
    </header>
    <div class="settings-establishment-card settings-establishment-card--full">
        <form method="post" action="/settings/vat-distribution-guide" class="settings-form entity-form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <h3 class="settings-establishment-card__title">Taxes Applicables</h3>
            <table class="settings-establishment-hours-table">
                <thead>
                    <tr>
                        <th>Type de TVA</th>
                        <th>Produit</th>
                        <th>Prestation</th>
                        <th>Membership</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vatRates as $rate): ?>
                        <?php
                        $id = (int) ($rate['id'] ?? 0);
                        $applies = is_array($rate['applies_to_json'] ?? null) ? $rate['applies_to_json'] : [];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($rate['name'] ?? '')) ?></td>
                            <td><input type="checkbox" name="matrix[<?= $id ?>][]" value="products" <?= in_array('products', $applies, true) ? 'checked' : '' ?>></td>
                            <td><input type="checkbox" name="matrix[<?= $id ?>][]" value="services" <?= in_array('services', $applies, true) ? 'checked' : '' ?>></td>
                            <td><input type="checkbox" name="matrix[<?= $id ?>][]" value="memberships" <?= in_array('memberships', $applies, true) ? 'checked' : '' ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($vatRates === []): ?>
                        <tr><td colspan="4">No active VAT types available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="settings-establishment-actions" style="margin-top: 1rem;">
                <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Enregistrer</button>
            </div>
        </form>
    </div>
</section>
<?php
$settingsWorkspaceContent = (string) ob_get_clean();
$activeSettingsSection = 'vat_distribution_guide';
$settingsPageTitle = 'Settings';
$settingsPageSubtitle = 'Choose a settings subsection from the sidebar. The workspace renders one focused area at a time.';
$settingsFlash = $flash ?? null;
$onlineBookingBranchId = 0;
$appointmentsBranchId = 0;
ob_start();
require base_path('modules/settings/views/partials/shell.php');
$content = ob_get_clean();
require shared_path('layout/base.php');
