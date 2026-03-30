<?php

$settingsPageTitle = (string) ($settingsPageTitle ?? 'Settings');

$settingsPageSubtitle = (string) ($settingsPageSubtitle ?? 'Choose a settings subsection from the sidebar.');

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

        <aside class="settings-sidebar" aria-label="Settings directory">

            <h2 class="settings-sidebar__title">Settings directory</h2>

            <nav class="settings-sidebar__nav">

                <p class="settings-sidebar__section-label">Editable settings sections</p>

                <details class="settings-tree" data-group="general" <?= $activeDirectoryGroup === 'general' ? 'open' : '' ?>>

                    <summary>General Settings</summary>

                    <?php if ($canViewSettingsLink): ?>

                    <a class="<?= $activeSection === 'establishment' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('establishment')) ?>">Establishment Information - Mixed scope</a>

                    <a class="<?= $activeSection === 'cancellation' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('cancellation')) ?>">Cancellation Policy - Organization default</a>

                    <a class="<?= $activeSection === 'appointments' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('appointments', null, $appointmentsBranchId)) ?>">Appointment Settings - Mixed scope</a>

                    <a class="<?= $activeSection === 'payments' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsPaymentsUrl) ?>">Payment Settings - Mixed scope</a>

                    <?php endif; ?>

                    <?php if ($canViewPaymentMethodsLink): ?>

                    <a class="<?= $activeSection === 'payment_methods' ? 'is-active' : '' ?>" href="/settings/payment-methods">Custom Payment Methods</a>

                    <?php endif; ?>

                    <?php if ($canViewPriceModificationReasonsLink): ?>

                    <a class="<?= $activeSection === 'price_modification_reasons' ? 'is-active' : '' ?>" href="/settings/price-modification-reasons">Price modification reasons</a>

                    <?php endif; ?>

                    <?php if ($canViewVatRatesLink): ?>

                    <a class="<?= $activeSection === 'vat_rates' ? 'is-active' : '' ?>" href="/settings/vat-rates">VAT Types</a>

                    <a class="<?= $activeSection === 'vat_distribution_guide' ? 'is-active' : '' ?>" href="/settings/vat-distribution-guide">VAT distribution</a>

                    <?php endif; ?>

                    <?php if ($canViewSettingsLink): ?>

                    <a class="<?= $activeSection === 'notifications' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('notifications')) ?>">Internal Notifications - Mixed scope</a>

                    <a class="<?= $activeSection === 'hardware' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('hardware')) ?>">IT Hardware - Mixed scope</a>

                    <a class="<?= $activeSection === 'security' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('security')) ?>">Security - Mixed scope</a>

                    <a class="<?= $activeSection === 'marketing' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('marketing')) ?>">Marketing Settings - Mixed scope</a>

                    <a class="<?= $activeSection === 'waitlist' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('waitlist')) ?>">Waitlist Settings - Mixed scope</a>

                    <a class="<?= $activeSection === 'public_channels' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('public_channels', $onlineBookingBranchId, null)) ?>">Public channels - Mixed scope</a>

                    <a class="<?= $activeSection === 'memberships' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($settingsUrl('memberships')) ?>">Membership defaults - Mixed scope</a>

                    <?php endif; ?>

                </details>

                <p class="settings-sidebar__section-label settings-sidebar__section-label--modules">Related module launchers</p>

                <?php if ($canViewBranchesLink): ?>

                <details class="settings-tree" data-group="branches">

                    <summary>Branches</summary>

                    <a href="/branches">Manage branches</a>

                </details>

                <?php endif; ?>

                <?php if ($canViewServicesResourcesLink): ?>

                <details class="settings-tree" data-group="spaces">

                    <summary>Spaces</summary>

                    <a href="/services-resources/rooms">All spaces</a>

                    <a href="/services-resources/rooms/create">New Space</a>

                </details>

                <details class="settings-tree" data-group="equipment">

                    <summary>Equipment</summary>

                    <a href="/services-resources/equipment">All equipment</a>

                    <a href="/services-resources/equipment/create">New Equipment</a>

                </details>

                <?php endif; ?>

                <?php if ($canViewStaffLink || $canCreateStaffLink || $canViewPayrollLink): ?>

                <details class="settings-tree" data-group="staff">

                    <summary>Staff</summary>

                    <?php if ($canViewStaffLink): ?>

                    <a href="/staff">All staff</a>

                    <?php endif; ?>

                    <?php if ($canCreateStaffLink): ?>

                    <a href="/staff/create">New Staff Member</a>

                    <?php endif; ?>

                    <?php if ($canViewStaffLink): ?>

                    <a href="/staff/groups">Groups</a>

                    <?php endif; ?>

                    <?php if ($canViewPayrollLink): ?>

                    <a href="/payroll/runs">Staff Hours &amp; Payroll</a>

                    <?php endif; ?>

                </details>

                <?php endif; ?>

                <?php if ($canViewServicesResourcesLink): ?>

                <details class="settings-tree" data-group="services">

                    <summary>Services</summary>

                    <a href="/services-resources/services">All services</a>

                    <a href="/services-resources/services/create">New Service</a>

                </details>

                <?php endif; ?>

                <?php if ($canViewPackagesLink): ?>

                <details class="settings-tree" data-group="packages">

                    <summary>Packages</summary>

                    <a href="/packages">All packages</a>

                    <a href="/packages/create">New Package</a>

                </details>

                <?php endif; ?>

                <?php if ($canViewMembershipsLink || $canViewSettingsLink): ?>

                <details class="settings-tree" data-group="memberships">

                    <summary>Memberships (catalog)</summary>

                    <?php if ($canViewMembershipsLink): ?>

                    <a href="/memberships">All memberships</a>

                    <a href="/memberships/create">New Membership</a>

                    <?php endif; ?>

                    <?php if ($canViewSettingsLink): ?>

                    <p class="settings-sidebar__info">Default terms and renewal hints live under <strong>Membership defaults</strong> in General Settings.</p>

                    <?php endif; ?>

                </details>

                <?php endif; ?>

                <p class="settings-sidebar__section-label settings-sidebar__section-label--info">Information only (not managed here)</p>

                <details class="settings-tree" data-group="connections">

                    <summary>Users (info only)</summary>

                    <p class="settings-sidebar__info">Tenant user accounts are not managed from Settings in this build (separate platform tools apply).</p>

                </details>

                <details class="settings-tree" data-group="series">

                    <summary>Series (info only)</summary>

                    <p class="settings-sidebar__info">Recurring series are handled from <a href="/appointments">Appointments</a> and the <a href="/calendar/day">day calendar</a>. There is no series list under Settings; staff APIs exist for series actions.</p>

                </details>

                <details class="settings-tree" data-group="documents">

                    <summary>Document storage (info only)</summary>

                    <p class="settings-sidebar__info">Definitions and files use JSON API routes under <code>/documents/…</code>. There is no HTML admin for document types in Settings.</p>

                </details>

            </nav>

        </aside>

        <section class="settings-workspace" aria-label="Settings workspace">

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



    .settings-sidebar__section-label--modules {

        margin-top: 0.85rem;

        padding-top: 0.7rem;

        border-top: 1px solid #e5e7eb;

    }

    .settings-sidebar__section-label--info {

        margin-top: 0.85rem;

        padding-top: 0.7rem;

        border-top: 1px solid #e5e7eb;

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

