<?php

/**
 * Read-only verifier: ADMIN-IA-BUSINESS-FIRST-SAFE-REFACTOR-01
 *
 * Asserts the IA refactor did not break any controller contracts and that
 * the expected UI copy changes are present in the view files.
 *
 * Run: php system/scripts/read-only/verify_admin_ia_business_first_truth_01.php
 * Expected exit code: 0 (all assertions pass)
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function assert_contains(string $label, string $file, string $needle, bool $shouldContain = true): void
{
    global $pass, $fail, $checks;
    if (!file_exists($file)) {
        $checks[] = ['FAIL', $label, "File not found: $file"];
        $fail++;
        return;
    }
    $content = file_get_contents($file);
    $found = str_contains($content, $needle);
    if ($found === $shouldContain) {
        $checks[] = ['PASS', $label, ''];
        $pass++;
    } else {
        $action = $shouldContain ? 'expected to find' : 'expected NOT to find';
        $checks[] = ['FAIL', $label, "$action: " . substr($needle, 0, 80)];
        $fail++;
    }
}

$base      = dirname(__DIR__, 2); // system/
$root      = dirname($base);      // repo root

$basePhp   = $base . '/shared/layout/base.php';
$shellPhp  = $base . '/modules/settings/views/partials/shell.php';
$indexPhp  = $base . '/modules/settings/views/index.php';
$payPhp    = $base . '/modules/settings/views/partials/payment-settings.php';
$ctrlPhp   = $base . '/modules/settings/controllers/SettingsController.php';

// ──────────────────────────────────────────────────────────────────────────────
// A. Top nav labels
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('A1: top-nav label Overview present', $basePhp, "'Overview'");
assert_contains('A2: top-nav label Calendar present', $basePhp, "'Calendar'");
assert_contains('A3: top-nav label Team present', $basePhp, "'Team'");
assert_contains('A4: top-nav label Admin present', $basePhp, "'Admin'");

// Old nav labels must be gone from the navItems array
assert_contains('A5: old nav label Dashboard absent from navItems', $basePhp, "'Dashboard'", false);
assert_contains('A6: old nav label Appointments absent from navItems', $basePhp, "'Appointments'", false);
assert_contains('A7: old nav label Staff absent from navItems', $basePhp, "'Staff'", false);
assert_contains('A8: old nav label Settings absent from navItems', $basePhp, "'Settings'", false);

// ──────────────────────────────────────────────────────────────────────────────
// B. /settings path is still the nav target for Admin
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('B1: /settings href in navItems', $basePhp, "'/settings'");

// ──────────────────────────────────────────────────────────────────────────────
// C. Settings sidebar no longer contains info-only dead-end nodes
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('C1: Users info-only node absent from sidebar', $shellPhp, 'Users (info only)', false);
assert_contains('C2: Series info-only node absent from sidebar', $shellPhp, 'Series (info only)', false);
assert_contains('C3: Document storage info-only node absent from sidebar', $shellPhp, 'Document storage (info only)', false);

// ──────────────────────────────────────────────────────────────────────────────
// D. Settings sidebar no longer contains related module launchers
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('D1: Branches launcher node absent from sidebar nav tree', $shellPhp, "data-group=\"branches\"", false);
assert_contains('D2: Spaces launcher node absent from sidebar nav tree', $shellPhp, "data-group=\"spaces\"", false);
assert_contains('D3: Equipment launcher node absent from sidebar nav tree', $shellPhp, "data-group=\"equipment\"", false);
assert_contains('D4: Staff launcher node absent from sidebar nav tree', $shellPhp, "data-group=\"staff\"", false);
assert_contains('D5: Services launcher node absent from sidebar nav tree', $shellPhp, "data-group=\"services\"", false);
assert_contains('D6: Packages launcher node absent from sidebar nav tree', $shellPhp, "data-group=\"packages\"", false);
assert_contains('D7: Memberships catalog launcher node absent from sidebar nav tree', $shellPhp, "data-group=\"memberships\"", false);

// ──────────────────────────────────────────────────────────────────────────────
// E. Settings shell is control-plane only (no second operational launcher hub)
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('E1: operational shortcuts grid absent from shell', $shellPhp, 'settings-operational-areas', false);
assert_contains('E2: hasAnyOperationalLink guard absent from shell', $shellPhp, '$hasAnyOperationalLink', false);
assert_contains('E3: no outbound Sales href in settings shell', $shellPhp, 'href="/sales"', false);

// ──────────────────────────────────────────────────────────────────────────────
// F. Visible Admin labels updated to business-first names
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('F1: Business Setup label present in sidebar', $shellPhp, 'Business Setup');
assert_contains('F2: Cancellation & No-show Policy label present', $shellPhp, 'Cancellation &amp; No-show Policy');
assert_contains('F3: Booking Rules label present', $shellPhp, 'Booking Rules');
assert_contains('F4: Payments, Checkout & Tax label in sidebar', $shellPhp, 'Payments, Checkout &amp; Tax');
assert_contains('F5: Notifications & Automations label present', $shellPhp, 'Notifications &amp; Automations');
assert_contains('F6: Devices & Integrations label present', $shellPhp, 'Devices &amp; Integrations');
assert_contains('F7: Access & Security label present', $shellPhp, 'Access &amp; Security');
assert_contains('F8: Marketing Defaults label present', $shellPhp, 'Marketing Defaults');
assert_contains('F9: Waitlist Rules label present', $shellPhp, 'Waitlist Rules');
assert_contains('F10: Online Channels label present', $shellPhp, 'Online Channels');
assert_contains('F11: Membership Defaults label present', $shellPhp, 'Membership Defaults');
assert_contains('F23: Services & Pricing entry in Admin sidebar', $shellPhp, 'href="/services-resources">Services &amp; Pricing</a>');

// Old mixed-scope labels must be gone from sidebar
assert_contains('F12: old Establishment Information label absent from sidebar', $shellPhp, 'Establishment Information - Mixed scope', false);
assert_contains('F13: old Cancellation Policy label absent from sidebar', $shellPhp, 'Cancellation Policy - Organization default', false);
assert_contains('F14: old Appointment Settings label absent from sidebar', $shellPhp, 'Appointment Settings - Mixed scope', false);
assert_contains('F15: old Payment Settings label absent from sidebar', $shellPhp, 'Payment Settings - Mixed scope', false);
assert_contains('F16: old Internal Notifications label absent from sidebar', $shellPhp, 'Internal Notifications - Mixed scope', false);
assert_contains('F17: old IT Hardware label absent from sidebar', $shellPhp, 'IT Hardware - Mixed scope', false);
assert_contains('F18: old Security label absent from sidebar', $shellPhp, 'Security - Mixed scope', false);
assert_contains('F19: old Marketing Settings label absent from sidebar', $shellPhp, 'Marketing Settings - Mixed scope', false);
assert_contains('F20: old Waitlist Settings label absent from sidebar', $shellPhp, 'Waitlist Settings - Mixed scope', false);
assert_contains('F21: old Public channels label absent from sidebar', $shellPhp, 'Public channels - Mixed scope', false);
assert_contains('F22: old Membership defaults label absent from sidebar', $shellPhp, 'Membership defaults - Mixed scope', false);

// ──────────────────────────────────────────────────────────────────────────────
// G. Payment settings operator page no longer shows "Not in this build"
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('G1: Not in this build absent from payment-settings.php', $payPhp, 'Not in this build', false);
assert_contains('G2: deferred-cluster absent from payment-settings.php', $payPhp, 'deferred-cluster', false);

// ──────────────────────────────────────────────────────────────────────────────
// H. Controller section keys still intact (read contracts preserved)
// ──────────────────────────────────────────────────────────────────────────────
foreach (['establishment', 'cancellation', 'appointments', 'payments', 'waitlist', 'marketing', 'security', 'notifications', 'hardware', 'memberships', 'public_channels'] as $sectionKey) {
    assert_contains("H-$sectionKey: section key present in controller", $ctrlPhp, "'$sectionKey'");
}

// ──────────────────────────────────────────────────────────────────────────────
// I. public_channels still exists as write contract
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('I1: public_channels in SECTION_ALLOWED_KEYS', $ctrlPhp, "'public_channels' => self::PUBLIC_CHANNELS_WRITE_KEYS");
assert_contains('I2: PUBLIC_CHANNELS_WRITE_KEYS constant defined', $ctrlPhp, 'PUBLIC_CHANNELS_WRITE_KEYS');
assert_contains('I3: online_booking.enabled in controller allowlist', $ctrlPhp, "'online_booking.enabled'");
assert_contains('I4: intake.public_enabled in controller allowlist', $ctrlPhp, "'intake.public_enabled'");
assert_contains('I5: public_commerce.enabled in controller allowlist', $ctrlPhp, "'public_commerce.enabled'");

// ──────────────────────────────────────────────────────────────────────────────
// J. Online Channels UI split: 3 named cards present in index.php
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('J1: Online Booking card heading in index.php', $indexPhp, 'Online Booking</h3>');
assert_contains('J2: Public Intake card heading in index.php', $indexPhp, 'Public Intake</h3>');
assert_contains('J3: Public Commerce card heading in index.php', $indexPhp, 'Public Commerce</h3>');
assert_contains('J4: section=public_channels POST contract preserved in index.php', $indexPhp, 'name="section" value="public_channels"');
assert_contains('J5: online_booking_context_branch_id hidden field preserved', $indexPhp, 'name="online_booking_context_branch_id"');

// ──────────────────────────────────────────────────────────────────────────────
// K. Branch scope params preserved in controller (no breakage)
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('K1: ONLINE_BOOKING_BRANCH_PARAM constant present', $ctrlPhp, "ONLINE_BOOKING_BRANCH_PARAM");
assert_contains('K2: APPOINTMENTS_BRANCH_PARAM constant present', $ctrlPhp, "APPOINTMENTS_BRANCH_PARAM");
assert_contains('K3: PAYMENTS_BRANCH_PARAM constant present', $ctrlPhp, "PAYMENTS_BRANCH_PARAM");
assert_contains('K4: WAITLIST_BRANCH_PARAM constant present', $ctrlPhp, "WAITLIST_BRANCH_PARAM");
assert_contains('K5: MARKETING_BRANCH_PARAM constant present', $ctrlPhp, "MARKETING_BRANCH_PARAM");

// ──────────────────────────────────────────────────────────────────────────────
// L. Admin active: control-plane prefixes; catalog definitions fold into Admin active (no Catalog primary tab)
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('L1: /settings prefix in settingsActivePrefixes', $basePhp, "'/settings'");
assert_contains('L2: /memberships not listed in settingsActivePrefixes (plan/client surfaces have primary homes)', $basePhp, "        '/memberships',", false);
assert_contains('L3: /branches prefix in settingsActivePrefixes', $basePhp, "'/branches'");
assert_contains('L4: client-held memberships nav split present', $basePhp, '$navIsClientsMemberships');
assert_contains('L5: Catalog family split via navIsCatalog', $basePhp, '$navIsCatalog');
assert_contains('L6: Reports split via navIsReports', $basePhp, '$navIsReports');
assert_contains('L7: Team active includes payroll operations prefix', $basePhp, "str_starts_with(\$navPath, '/payroll')");
assert_contains('L8: Admin primary tab active on catalog definition surfaces', $basePhp, '$navIsSettings = $navIsSettings || $navIsCatalog');

// ──────────────────────────────────────────────────────────────────────────────
// M. Page title changed to Admin in index.php
// ──────────────────────────────────────────────────────────────────────────────
assert_contains("M1: \$title = 'Admin' in index.php", $indexPhp, "\$title = 'Admin'");
assert_contains("M2: settingsPageTitle = 'Admin' in index.php", $indexPhp, "\$settingsPageTitle = 'Admin'");

// ──────────────────────────────────────────────────────────────────────────────
// N. No info-only text clutter in sidebar
// ──────────────────────────────────────────────────────────────────────────────
assert_contains('N1: Related module launchers label absent from sidebar', $shellPhp, 'Related module launchers', false);
assert_contains('N2: Information only label absent from sidebar', $shellPhp, 'Information only (not managed here)', false);

// ──────────────────────────────────────────────────────────────────────────────
// Report
// ──────────────────────────────────────────────────────────────────────────────
echo "\nVERIFIER: verify_admin_ia_business_first_truth_01\n";
echo str_repeat('─', 72) . "\n";
foreach ($checks as [$status, $label, $detail]) {
    $line = sprintf("  [%s] %s", $status, $label);
    if ($detail !== '') {
        $line .= "\n         → $detail";
    }
    echo $line . "\n";
}
echo str_repeat('─', 72) . "\n";
echo sprintf("  PASSED: %d   FAILED: %d   TOTAL: %d\n\n", $pass, $fail, $pass + $fail);

if ($fail > 0) {
    echo "STATUS: FAIL — $fail assertion(s) did not pass.\n\n";
    exit(1);
}

echo "STATUS: PASS — all assertions passed.\n\n";
exit(0);
