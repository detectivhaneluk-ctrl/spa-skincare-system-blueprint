<?php

use Modules\Sales\Support\PaymentMethodFamily;

$title = 'Payment Methods';
$methods = $methods ?? [];
ob_start();
require base_path('modules/settings/views/establishment/_styles.php');
?>
<section class="settings-establishment settings-payment-methods-catalog">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Custom Payment Methods</h2>
        <p class="settings-establishment__lead">
            Manage operator-facing payment method labels and status in one settings subsection. Family remains derived from code/name for Payment Settings and recording behavior.
        </p>
        <div class="settings-establishment-actions">
            <a class="settings-establishment-btn settings-establishment-btn--primary" href="/settings/payment-methods/create">Add payment method</a>
            <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?section=payments">Payment Settings</a>
        </div>
    </header>

    <div class="settings-establishment-card settings-establishment-card--full">
        <h3 class="settings-establishment-card__title">Method catalog</h3>
        <p class="settings-establishment-card__help">
            Type is display-only. Code stays immutable and family classification stays derived from code/name; this page does not change payment engine or processor behavior.
        </p>

        <?php if ($methods === []): ?>
            <p class="settings-establishment-note">No payment methods yet. Add a method to define which tenders can be recorded.</p>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn settings-establishment-btn--primary" href="/settings/payment-methods/create">Add payment method</a>
            </div>
        <?php else: ?>
            <div class="settings-payment-methods-table-wrap">
                <table class="settings-establishment-hours-table settings-payment-methods-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($methods as $m): ?>
                            <?php
                            $fam = (string) ($m['family'] ?? PaymentMethodFamily::OTHER_RECORDED);
                            $badgeClass = 'settings-payment-method-family--' . preg_replace('/[^a-z0-9_-]/', '', $fam);
                            ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars((string) (($m['type_label'] ?? '') !== '' ? $m['type_label'] : 'Unspecified')) ?>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars((string) ($m['name'] ?? '')) ?></div>
                                    <div class="settings-payment-methods-meta">
                                        <code><?= htmlspecialchars((string) ($m['code'] ?? '')) ?></code>
                                        <span class="settings-payment-method-family <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars((string) ($m['family_label'] ?? '')) ?></span>
                                    </div>
                                </td>
                                <td><?= !empty($m['is_active']) ? 'Active' : 'Archived' ?></td>
                                <td>
                                    <div class="settings-payment-methods-actions">
                                        <a class="settings-establishment-btn settings-establishment-btn--small settings-establishment-btn--muted" href="/settings/payment-methods/<?= (int) ($m['id'] ?? 0) ?>/edit">Edit</a>
                                        <?php if (!empty($m['is_active'])): ?>
                                            <form method="post" action="/settings/payment-methods/<?= (int) ($m['id'] ?? 0) ?>/archive" onsubmit="return confirm('Archive this payment method? This will deactivate it.');">
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
    .settings-payment-methods-catalog .settings-establishment-btn--primary {
        border-color: #111827;
        color: #fff;
        background: #111827;
    }
    .settings-payment-methods-table-wrap {
        overflow-x: auto;
    }
    .settings-payment-methods-table th,
    .settings-payment-methods-table td {
        vertical-align: top;
    }
    .settings-payment-methods-meta {
        display: flex;
        gap: 0.45rem;
        align-items: center;
        margin-top: 0.35rem;
        color: #6b7280;
        font-size: 0.8rem;
    }
    .settings-payment-methods-actions {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }
    .settings-payment-methods-actions form {
        margin: 0;
    }
    .settings-payment-method-family {
        display: inline-block;
        padding: 0.15rem 0.45rem;
        border-radius: 0.35rem;
        font-size: 0.78rem;
        font-weight: 600;
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #e5e7eb;
        white-space: nowrap;
    }
    .settings-payment-method-family--check { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
    .settings-payment-method-family--cash { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
    .settings-payment-method-family--gift_card { background: #fdf4ff; border-color: #e9d5ff; color: #6b21a8; }
    .settings-payment-method-family--card_recorded { background: #fffbeb; border-color: #fde68a; color: #92400e; }
    .settings-payment-method-family--other_recorded { background: #f9fafb; border-color: #e5e7eb; color: #374151; }
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
