<?php
/**
 * Read-only verifier: BUSINESS-NAV-ENTRY-CLARITY-SAFE-LANE-02
 * Run: php system/scripts/read-only/verify_business_nav_entry_clarity_safe_lane_02.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0; $checks = [];

function chk(string $label, string $file, string $needle, bool $want = true): void {
    global $pass, $fail, $checks;
    if (!file_exists($file)) { $checks[] = ['FAIL', $label, "File not found: $file"]; $fail++; return; }
    $found = str_contains(file_get_contents($file), $needle);
    if ($found === $want) { $checks[] = ['PASS', $label, '']; $pass++; }
    else { $checks[] = ['FAIL', $label, ($want ? 'expected: ' : 'expected absent: ') . substr($needle, 0, 80)]; $fail++; }
}

$base  = dirname(__DIR__, 2);
$navB  = $base . '/shared/layout/base.php';
$shell = $base . '/modules/settings/views/partials/shell.php';
$idx   = $base . '/modules/settings/views/index.php';
$pay   = $base . '/modules/settings/views/partials/payment-settings.php';
$ctrl  = $base . '/modules/settings/controllers/SettingsController.php';
$svcIdx= $base . '/modules/services-resources/views/index.php';
$salesS= $base . '/modules/sales/views/partials/sales-workspace-shell.php';
$invIdx= $base . '/modules/inventory/views/index.php';
$memIdx= $base . '/modules/memberships/views/definitions/index.php';
$pkgIdx= $base . '/modules/packages/views/definitions/index.php';
$mktNav= $base . '/modules/marketing/views/partials/marketing-top-nav.php';
$clIdx = $base . '/modules/marketing/views/contact-lists/index.php';

// ── A. REGRESSION: Admin IA lane (7fafa71) still intact ──────────────────────
chk('A1: top-nav Overview', $navB, "'Overview'");
chk('A2: top-nav Calendar', $navB, "'Calendar'");
chk('A3: top-nav Team', $navB, "'Team'");
chk('A4: top-nav Admin', $navB, "'Admin'");
chk('A5: /settings href in navItems', $navB, "'/settings'");
chk('A6: settingsActivePrefixes /settings', $navB, "'/settings'");
chk('A7: settingsActivePrefixes excludes /memberships (Catalog/Clients own surfaces)', $navB, "        '/memberships',", false);
chk('A7b: client-held memberships highlight variable', $navB, '$navIsClientsMemberships');
chk('A8: settingsActivePrefixes /branches', $navB, "'/branches'");
chk('A9: Admin prefix list is control-plane only (settings, branches)', $navB, "'/settings',\n        '/branches',");
chk('A10: Team nav active covers /payroll', $navB, "str_starts_with(\$navPath, '/payroll')");
chk('A10b: Catalog family uses navIsCatalog (Admin active, not a primary home)', $navB, '$navIsCatalog');
chk('A10c: Reports nav uses navIsReports', $navB, '$navIsReports');
chk('A10d: primary nav has no Catalog module entry', $navB, "'/services-resources', 'Catalog'", false);
chk('A10e: navItems Reports href and label', $navB, "'/reports', 'Reports'");
chk('A10f: Admin active includes catalog definition surfaces', $navB, '$navIsSettings = $navIsSettings || $navIsCatalog');
chk('A11: sidebar module launchers absent', $shell, 'data-group="branches"', false);
chk('A12: info-only nodes absent', $shell, 'Users (info only)', false);
chk('A13: Business Setup label in sidebar', $shell, 'Business Setup');
chk('A14: Booking Rules label in sidebar', $shell, 'Booking Rules');
chk('A15: Online Channels label in sidebar', $shell, 'Online Channels');
chk('A16: Payments Checkout Tax label in sidebar', $shell, 'Payments, Checkout');
chk('A16b: Settings sidebar Services & Pricing → hub', $shell, 'href="/services-resources">Services &amp; Pricing</a>');
chk('A16c: Services & Pricing gated by services-resources.view', $shell, '$canViewServicesResourcesLink');
chk('A17: Not in this build absent', $pay, 'Not in this build', false);
chk('A18: controller section public_channels intact', $ctrl, "'public_channels'");
chk('A19: public_channels write keys intact', $ctrl, 'PUBLIC_CHANNELS_WRITE_KEYS');
chk('A20: Admin page title in index.php', $idx, "\$settingsPageTitle = 'Admin'");

// ── B. Nav route targets preserved ───────────────────────────────────────────
chk('B1: /dashboard in navItems', $navB, "'/dashboard'");
chk('B2: /appointments/calendar/day in navItems', $navB, "'/appointments/calendar/day'");
chk('B3: /staff in navItems', $navB, "'/staff'");
chk('B4: /sales in navItems', $navB, "'/sales'");
chk('B5: /inventory in navItems', $navB, "'/inventory'");
chk('B6: /marketing/campaigns in navItems', $navB, "'/marketing/campaigns'");

// ── C. Sales family active grouping preserved ────────────────────────────────
chk('C1: navIsSales covers /gift-cards', $navB, "str_starts_with(\$navPath, '/gift-cards')");
chk('C2: navIsSales is only /sales and /gift-cards', $navB, "\$navIsSales = str_starts_with(\$navPath, '/sales')\n        || str_starts_with(\$navPath, '/gift-cards');");
chk('C2b: Catalog includes package plan paths', $navB, "str_starts_with(\$navPath, '/packages')\n            && ! \$navIsClientsPackages");
chk('C2c: client packages highlight variable', $navB, '$navIsClientsPackages');
chk('C3: navIsSales covers /sales', $navB, "str_starts_with(\$navPath, '/sales')");

// ── D. Catalog discovery surface exists ──────────────────────────────────────
chk('D1: catalog-hub class in services-resources index', $svcIdx, 'catalog-hub');
chk('D2: Catalog title in services-resources index', $svcIdx, '<h1 class="catalog-hub__title">Catalog</h1>');
chk('D3: link to /services-resources/services', $svcIdx, '/services-resources/services');
chk('D4: link to /packages', $svcIdx, 'href="/packages"');
chk('D5: link to /memberships', $svcIdx, 'href="/memberships"');
chk('D6: link to /gift-cards', $svcIdx, 'href="/gift-cards"');
chk('D7: link to /services-resources/rooms', $svcIdx, '/services-resources/rooms');
chk('D8: link to /services-resources/equipment', $svcIdx, '/services-resources/equipment');
chk('D9: old bare h1 absent', $svcIdx, "<h1>Services &amp; Resources</h1>", false);
chk('D9b: old bare h1 alt absent', $svcIdx, "<h1>Services & Resources</h1>", false);

// ── E. Sales entry clarity improvements ──────────────────────────────────────
chk('E1: sales shell subtitle updated', $salesS, 'Invoices, checkout, payments');
chk('E2: old subtitle absent', $salesS, 'Staff checkout, orders, gift cards, packages, and register.', false);

// ── F. Inventory clarity ─────────────────────────────────────────────────────
chk('F1: inventory-hub class present', $invIdx, 'inventory-hub');
chk('F2: inventory business lead present', $invIdx, 'Products, stock movements, and supplier records');
chk('F3: old Foundation module hint absent', $invIdx, 'Foundation module for products', false);

// ── G. Membership title business-first ───────────────────────────────────────
chk('G1: Membership Plans title in definitions index', $memIdx, "Membership Plans");
chk('G2: old Membership Definitions title absent', $memIdx, 'Membership Definitions', false);

// ── H. Packages title business-first ─────────────────────────────────────────
chk('H1: Package plan definitions h2 present', $pkgIdx, '<h2 class="sales-workspace-section-title">Package plan definitions</h2>');
chk('H2: Package Definitions h2 absent', $pkgIdx, 'Package Definitions', false);

// ── I. Marketing nav cleanup ──────────────────────────────────────────────────
chk('I1: Campaigns tab present in marketing nav', $mktNav, "'email_campaigns'");
chk('I2: Automations tab present', $mktNav, "'automated'");
chk('I3: Promotions tab present', $mktNav, "'promotions'");
chk('I4: Contact lists tab present', $mktNav, "'contact_lists'");
chk('I5: old Marketing suite label absent', $mktNav, 'Marketing suite', false);
chk('I6: old suite tab id absent', $mktNav, "['id' => 'suite'", false);
chk('I7: old listing tab absent', $mktNav, "'listing'", false);
chk('I8: old social tab absent', $mktNav, "Facebook / Twitter", false);
chk('I9: contact-lists nav links to live route', $mktNav, '/marketing/contact-lists');
chk('I10: contact-lists view uses correct tab id', $clIdx, "\$marketingTopActive = 'contact_lists'");

// ── J. Reports home is honest (real GET targets only) ─────────────────────────
$reportsView = $base . '/modules/reports/views/index.php';
if (!file_exists($reportsView)) {
    $checks[] = ['FAIL', 'J1: reports views/index.php missing (Reports home required)', ''];
    $fail++;
} else {
    $rv = (string) file_get_contents($reportsView);
    $jNeedles = [
        '/reports/revenue-summary',
        '/reports/payments-by-method',
        '/reports/refunds-summary',
        '/reports/appointments-volume',
        '/reports/new-clients',
        '/reports/staff-appointment-count',
        '/reports/gift-card-liability',
        '/reports/inventory-movements',
        '/reports/vat-distribution',
    ];
    $jOk = true;
    foreach ($jNeedles as $jn) {
        if (!str_contains($rv, $jn)) {
            $jOk = false;
            $checks[] = ['FAIL', 'J1: reports index missing link: ' . $jn, ''];
            $fail++;
        }
    }
    if ($jOk) {
        $checks[] = ['PASS', 'J1: reports index lists all live report GET paths', ''];
        $pass++;
    }
}

// ── Report ────────────────────────────────────────────────────────────────────
echo "\nVERIFIER: verify_business_nav_entry_clarity_safe_lane_02\n";
echo str_repeat('─', 72) . "\n";
foreach ($checks as [$s, $l, $d]) {
    echo sprintf("  [%s] %s%s\n", $s, $l, $d !== '' ? "\n         → $d" : '');
}
echo str_repeat('─', 72) . "\n";
echo sprintf("  PASSED: %d   FAILED: %d   TOTAL: %d\n\n", $pass, $fail, $pass + $fail);
if ($fail > 0) { echo "STATUS: FAIL\n\n"; exit(1); }
echo "STATUS: PASS\n\n"; exit(0);
