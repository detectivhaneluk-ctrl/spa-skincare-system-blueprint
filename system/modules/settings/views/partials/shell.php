<?php

$settingsPageTitle = (string) ($settingsPageTitle ?? 'Admin');

$settingsPageSubtitle = (string) ($settingsPageSubtitle ?? 'Configure your business settings from the sidebar.');

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

$canViewBranchesLink = !empty($canViewBranchesLink);

$canViewServicesResourcesLink = !empty($canViewServicesResourcesLink);

$canViewGiftCardsLink = !empty($canViewGiftCardsLink);

$canViewPackagesLink = !empty($canViewPackagesLink);

$canViewMembershipsLink = !empty($canViewMembershipsLink);

$canViewPayrollLink = !empty($canViewPayrollLink);

$canViewPaymentMethodsLink = !empty($canViewPaymentMethodsLink);

$canViewPriceModificationReasonsLink = !empty($canViewPriceModificationReasonsLink);

$canViewVatRatesLink = !empty($canViewVatRatesLink);

$canViewSettingsLink = !empty($canViewSettingsLink);

$canViewStaffLink = !empty($canViewStaffLink);

$canCreateStaffLink = !empty($canCreateStaffLink);

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

$hasAnyOperationalLink = $canViewBranchesLink
    || $canViewServicesResourcesLink
    || ($canViewStaffLink || $canCreateStaffLink || $canViewPayrollLink)
    || $canViewPackagesLink
    || $canViewMembershipsLink;

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

        <aside class="settings-sidebar" aria-label="Admin settings navigation">

            <h2 class="settings-sidebar__title">Settings</h2>

            <nav class="settings-sidebar__nav">

                <p class="settings-sidebar__section-label">Editable settings</p>

                <details class="settings-tree" data-group="general" <?= $activeDirectoryGroup === 'general' ? 'open' : '' ?>>

                    <summary>All settings</summary>

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

            <?php if ($hasAnyOperationalLink): ?>
            <div class="settings-operational-areas" role="complementary" aria-label="Manage operational areas">
                <h2 class="settings-operational-areas__title">Manage operational areas</h2>
                <p class="settings-operational-areas__lead">These are separate operational modules, not editable settings.</p>
                <div class="settings-operational-areas__grid">

                    <?php if ($canViewBranchesLink): ?>
                    <div class="settings-op-card">
                        <h3 class="settings-op-card__title">Branches</h3>
                        <p class="settings-op-card__desc">Manage physical locations and their configurations.</p>
                        <a class="settings-op-card__link" href="/branches">Manage branches</a>
                    </div>
                    <?php endif; ?>

                    <?php if ($canViewStaffLink || $canCreateStaffLink || $canViewPayrollLink): ?>
                    <div class="settings-op-card">
                        <h3 class="settings-op-card__title">Team</h3>
                        <p class="settings-op-card__desc">Staff profiles, groups, schedules, and payroll.</p>
                        <?php if ($canViewStaffLink): ?>
                        <a class="settings-op-card__link" href="/staff">View team</a>
                        <?php endif; ?>
                        <?php if ($canCreateStaffLink): ?>
                        <a class="settings-op-card__link settings-op-card__link--secondary" href="/staff/create">Add member</a>
                        <?php endif; ?>
                        <?php if ($canViewPayrollLink): ?>
                        <a class="settings-op-card__link settings-op-card__link--secondary" href="/payroll/runs">Staff hours &amp; payroll</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($canViewServicesResourcesLink): ?>
                    <div class="settings-op-card">
                        <h3 class="settings-op-card__title">Services</h3>
                        <p class="settings-op-card__desc">Service catalog, categories, and pricing.</p>
                        <a class="settings-op-card__link" href="/services-resources/services">View services</a>
                        <a class="settings-op-card__link settings-op-card__link--secondary" href="/services-resources/services/create">Add service</a>
                    </div>
                    <?php endif; ?>

                    <?php if ($canViewServicesResourcesLink): ?>
                    <div class="settings-op-card">
                        <h3 class="settings-op-card__title">Spaces &amp; Equipment</h3>
                        <p class="settings-op-card__desc">Treatment rooms, spaces, and equipment resources.</p>
                        <a class="settings-op-card__link" href="/services-resources/rooms">Spaces</a>
                        <a class="settings-op-card__link settings-op-card__link--secondary" href="/services-resources/equipment">Equipment</a>
                    </div>
                    <?php endif; ?>

                    <?php if ($canViewPackagesLink): ?>
                    <div class="settings-op-card">
                        <h3 class="settings-op-card__title">Packages</h3>
                        <p class="settings-op-card__desc">Prepaid service packages and session bundles.</p>
                        <a class="settings-op-card__link" href="/packages">View packages</a>
                        <a class="settings-op-card__link settings-op-card__link--secondary" href="/packages/create">Add package</a>
                    </div>
                    <?php endif; ?>

                    <?php if ($canViewMembershipsLink): ?>
                    <div class="settings-op-card">
                        <h3 class="settings-op-card__title">Memberships</h3>
                        <p class="settings-op-card__desc">Shortcuts: plan definitions live in <strong>Catalog</strong>; records attached to clients live under <strong>Clients</strong>. Policy and default renewal text live under <a href="<?= htmlspecialchars($settingsUrl('memberships')) ?>">Membership Defaults</a>.</p>
                        <a class="settings-op-card__link" href="/memberships">Membership plans (Catalog)</a>
                        <a class="settings-op-card__link settings-op-card__link--secondary" href="/memberships/client-memberships">Active client memberships</a>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php endif; ?>

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

    .settings-operational-areas {

        margin-top: 2.5rem;

        padding-top: 1.5rem;

        border-top: 2px solid #e5e7eb;

    }

    .settings-operational-areas__title {

        margin: 0 0 0.3rem;

        font-size: 1rem;

        font-weight: 700;

        color: #111827;

    }

    .settings-operational-areas__lead {

        margin: 0 0 1rem;

        font-size: 0.85rem;

        color: #6b7280;

    }

    .settings-operational-areas__grid {

        display: grid;

        grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));

        gap: 0.85rem;

    }

    .settings-op-card {

        padding: 0.9rem 1rem;

        border: 1px solid #e5e7eb;

        border-radius: 0.65rem;

        background: #f9fafb;

    }

    .settings-op-card__title {

        margin: 0 0 0.2rem;

        font-size: 0.92rem;

        font-weight: 600;

        color: #111827;

    }

    .settings-op-card__desc {

        margin: 0 0 0.65rem;

        font-size: 0.82rem;

        color: #4b5563;

        line-height: 1.4;

    }

    .settings-op-card__desc a {

        color: #2563eb;

    }

    .settings-op-card__link {

        display: inline-block;

        margin-right: 0.5rem;

        margin-bottom: 0.25rem;

        font-size: 0.83rem;

        color: #2563eb;

        text-decoration: none;

    }

    .settings-op-card__link:hover {

        text-decoration: underline;

    }

    .settings-op-card__link--secondary {

        color: #4b5563;

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

