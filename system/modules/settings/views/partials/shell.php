<?php

$settingsPageTitle = (string) ($settingsPageTitle ?? 'Admin');

$settingsPageSubtitle = (string) ($settingsPageSubtitle ?? 'Organization policies, defaults, and controls only. Day-to-day work stays in the main navigation — Calendar, Clients, Sales, Team, and the rest. Service definitions and pricing structures live under Services & Pricing below.');

$activeSection = (string) ($activeSettingsSection ?? 'establishment');

$settingsWorkspaceContent = (string) ($settingsWorkspaceContent ?? '');

$settingsFlash = $settingsFlash ?? null;

$onlineBookingBranchId = (int) ($onlineBookingBranchId ?? 0);

$appointmentsBranchId = (int) ($appointmentsBranchId ?? 0);

$paymentsBranchId = (int) ($paymentsBranchId ?? 0);

$settingsPaymentsQuery = ['section' => 'payments'];
if ($paymentsBranchId > 0) {
    $settingsPaymentsQuery['payments_branch_id'] = $paymentsBranchId;
}
$settingsPaymentsUrl = '/settings?' . http_build_query($settingsPaymentsQuery);

$canViewPaymentMethodsLink = !empty($canViewPaymentMethodsLink);

$canViewPriceModificationReasonsLink = !empty($canViewPriceModificationReasonsLink);

$canViewVatRatesLink = !empty($canViewVatRatesLink);

$canViewSettingsLink = !empty($canViewSettingsLink);

$canViewServicesResourcesLink = !empty($canViewServicesResourcesLink);

$settingsUrl = static function (string $section, ?int $onlineBookingBranchIdParam = null, ?int $appointmentsBranchIdParam = null): string {

    $query = ['section' => $section];

    if ($onlineBookingBranchIdParam !== null && $onlineBookingBranchIdParam > 0) {

        $query['online_booking_branch_id'] = $onlineBookingBranchIdParam;

    }

    if ($appointmentsBranchIdParam !== null && $appointmentsBranchIdParam > 0) {

        $query['appointments_branch_id'] = $appointmentsBranchIdParam;

    }

    return '/settings?' . http_build_query($query);

};

$nativeSections = [

    'establishment',

    'cancellation',

    'appointments',

    'payments',

    'notifications',

    'hardware',

    'security',

    'marketing',

    'waitlist',

    'public_channels',

    'memberships',

    'payment_methods',

    'price_modification_reasons',

    'vat_rates',

    'vat_distribution_guide',

];

$isNativeActive = in_array($activeSection, $nativeSections, true);

$activeDirectoryGroup = $isNativeActive ? 'general' : '';

?>

<div class="settings-page">

    <header class="settings-page__header">

        <h1 class="settings-page__title"><?= htmlspecialchars($settingsPageTitle) ?></h1>

        <p class="settings-page__subtitle"><?= htmlspecialchars($settingsPageSubtitle) ?></p>

    </header>

    <?php if ($settingsFlash && is_array($settingsFlash)): $t = array_key_first($settingsFlash); ?>

    <div class="flash flash-<?= htmlspecialchars((string) $t) ?>"><?= htmlspecialchars((string) ($settingsFlash[$t] ?? '')) ?></div>

    <?php endif; ?>

    <div class="settings-shell">

        <aside class="settings-sidebar" aria-label="Admin navigation">

            <h2 class="settings-sidebar__title">Admin</h2>

            <nav class="settings-sidebar__nav">

                <?php if ($canViewServicesResourcesLink): ?>

                <p class="settings-sidebar__section-label">Services &amp; pricing</p>

                <a class="settings-sidebar__services-pricing-link" href="/services-resources">Services &amp; Pricing</a>

                <p class="settings-sidebar__section-label">Policies and defaults</p>

                <?php else: ?>

                <p class="settings-sidebar__section-label">Policies and defaults</p>

                <?php endif; ?>

                <details class="settings-tree" data-group="general" <?= $activeDirectoryGroup === 'general' ? 'open' : '' ?>>

                    <summary>All sections</summary>

                    <?php if ($canViewSettingsLink): ?>

                    <a class="<?= $activeSection === 'establishment' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('establishment')) ?>">Business Setup</a>

                    <a class="<?= $activeSection === 'cancellation' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('cancellation')) ?>">Cancellation &amp; No-show Policy</a>

                    <a class="<?= $activeSection === 'appointments' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('appointments', null, $appointmentsBranchId)) ?>">Booking Rules</a>

                    <a class="<?= $activeSection === 'payments' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsPaymentsUrl) ?>">Payments, Checkout &amp; Tax</a>

                    <?php endif; ?>

                    <?php if ($canViewPaymentMethodsLink): ?>

                    <a class="<?= $activeSection === 'payment_methods' ? 'is-active' : '' ?>" href="/settings/payment-methods">Custom Payment Methods</a>

                    <?php endif; ?>

                    <?php if ($canViewPriceModificationReasonsLink): ?>

                    <a class="<?= $activeSection === 'price_modification_reasons' ? 'is-active' : '' ?>" href="/settings/price-modification-reasons">Price Modification Reasons</a>

                    <?php endif; ?>

                    <?php if ($canViewVatRatesLink): ?>

                    <a class="<?= $activeSection === 'vat_rates' ? 'is-active' : '' ?>" href="/settings/vat-rates">VAT Types</a>

                    <a class="<?= $activeSection === 'vat_distribution_guide' ? 'is-active' : '' ?>" href="/settings/vat-distribution-guide">VAT Distribution Guide</a>

                    <?php endif; ?>

                    <?php if ($canViewSettingsLink): ?>

                    <a class="<?= $activeSection === 'notifications' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('notifications')) ?>">Notifications &amp; Automations</a>

                    <a class="<?= $activeSection === 'hardware' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('hardware')) ?>">Devices &amp; Integrations</a>

                    <a class="<?= $activeSection === 'security' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('security')) ?>">Access &amp; Security</a>

                    <a class="<?= $activeSection === 'marketing' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('marketing')) ?>">Marketing Defaults</a>

                    <a class="<?= $activeSection === 'waitlist' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('waitlist')) ?>">Waitlist Rules</a>

                    <a class="<?= $activeSection === 'public_channels' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('public_channels', $onlineBookingBranchId, null)) ?>">Online Channels</a>

                    <a class="<?= $activeSection === 'memberships' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('memberships')) ?>">Membership Defaults</a>

                    <?php endif; ?>

                </details>

            </nav>

        </aside>

        <section class="settings-workspace" aria-label="Admin workspace">

            <?= $settingsWorkspaceContent ?>

        </section>

    </div>

</div>

<style>

    .settings-sidebar__section-label {

        margin: 0.15rem 0 0.35rem;

        font-size: 0.72rem;

        font-weight: 700;

        text-transform: uppercase;

        letter-spacing: 0.03em;

        color: #6b7280;

    }

    .settings-sidebar__services-pricing-link {

        display: block;

        margin: 0 0 0.5rem;

        font-size: 0.9rem;

        font-weight: 600;

        color: #2563eb;

        text-decoration: none;

    }

    .settings-sidebar__services-pricing-link:hover {

        text-decoration: underline;

    }

    .settings-sidebar__info {

        margin: 0.25rem 0 0;

        padding: 0.45rem 0.6rem;

        font-size: 0.82rem;

        line-height: 1.45;

        color: #4b5563;

    }

    .settings-sidebar__info code {

        font-size: 0.78rem;

    }

    .settings-sidebar__info a {

        color: #2563eb;

    }

</style>

<script>

    (function () {

        var detailsNodes = document.querySelectorAll('.settings-sidebar .settings-tree');

        detailsNodes.forEach(function (node) {

            node.addEventListener('toggle', function () {

                if (!node.open) {

                    return;

                }

                detailsNodes.forEach(function (other) {

                    if (other !== node) {

                        other.open = false;

                    }

                });

            });

        });

    }());

</script>

