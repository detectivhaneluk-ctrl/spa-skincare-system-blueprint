<?php
/**
 * Read-only verifier: CATALOG-AND-GROWTH-SUBSECTION-BUSINESS-CLARITY-03
 * Run: php system/scripts/read-only/verify_catalog_growth_subsection_business_clarity_03.php
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

$base    = dirname(__DIR__, 2);
$navB    = $base . '/shared/layout/base.php';
$shell   = $base . '/modules/settings/views/partials/shell.php';
$ctrl    = $base . '/modules/settings/controllers/SettingsController.php';
$svcHub  = $base . '/modules/services-resources/views/index.php';
$svcIdx  = $base . '/modules/services-resources/views/services/index.php';
$eqIdx   = $base . '/modules/services-resources/views/equipment/index.php';
$rmIdx   = $base . '/modules/services-resources/views/rooms/index.php';
$memDef  = $base . '/modules/memberships/views/definitions/index.php';
$memCli  = $base . '/modules/memberships/views/client-memberships/index.php';
$pkgDef  = $base . '/modules/packages/views/definitions/index.php';
$pkgCli  = $base . '/modules/packages/views/client-packages/index.php';
$gcIdx   = $base . '/modules/gift-cards/views/index.php';
$gcIssue = $base . '/modules/gift-cards/views/issue.php';
$gcRdm   = $base . '/modules/gift-cards/views/redeem.php';
$mktCL   = $base . '/modules/marketing/views/contact-lists/index.php';

// ── A. Lane-01 regression guard ──────────────────────────────────────────────
chk('A1: top-nav Overview', $navB, "'Overview'");
chk('A2: top-nav Calendar', $navB, "'Calendar'");
chk('A3: top-nav Admin', $navB, "'Admin'");
chk('A4: /settings in navItems', $navB, "'/settings'");
chk('A5: sidebar Business Setup', $shell, 'Business Setup');
chk('A6: sidebar Booking Rules', $shell, 'Booking Rules');
chk('A7: public_channels controller key', $ctrl, "'public_channels'");
chk('A8: module launchers absent from sidebar', $shell, 'data-group="branches"', false);

// ── B. Lane-02 regression guard ──────────────────────────────────────────────
chk('B1: Catalog hub exists', $svcHub, 'catalog-hub');
chk('B2: navIsSales covers /gift-cards', $navB, "str_starts_with(\$navPath, '/gift-cards')");
chk('B3: navIsSales is only /sales and /gift-cards', $navB, "\$navIsSales = str_starts_with(\$navPath, '/sales')\n        || str_starts_with(\$navPath, '/gift-cards');");
chk('B3b: client packages nav split variable', $navB, '$navIsClientsPackages');
chk('B4: /memberships not in Admin-only settingsActivePrefixes', $navB, "        '/memberships',", false);
chk('B4b: membership client records use navIsClientsMemberships', $navB, '$navIsClientsMemberships');
chk('B5: Catalog active-state via navIsCatalog (/services-resources)', $navB, '$navIsCatalog');

// ── C. Services subscreen ────────────────────────────────────────────────────
chk('C1: services backlink says Catalog', $svcIdx, '← Catalog');
chk('C2: old Services & Resources backlink absent', $svcIdx, '← Services &amp; Resources', false);
chk('C3: CTA says New service', $svcIdx, 'New service');
chk('C4: old Add Service CTA absent', $svcIdx, 'Add Service', false);
chk('C5: route /services-resources/services/create unchanged', $svcIdx, '/services-resources/services/create');

// ── D. Equipment subscreen ───────────────────────────────────────────────────
chk('D1: equipment backlink says Catalog', $eqIdx, '← Catalog');
chk('D2: old Services & Resources backlink absent from equipment', $eqIdx, '← Services & Resources', false);
chk('D3: CTA says New equipment', $eqIdx, 'New equipment');
chk('D4: old Add Equipment CTA absent', $eqIdx, 'Add Equipment', false);
chk('D5: equipment route unchanged', $eqIdx, '/services-resources/equipment/create');
chk('D6: equipment subtitle present', $eqIdx, 'Equipment resources used during services');

// ── E. Spaces (rooms) subscreen ──────────────────────────────────────────────
chk('E1: spaces title is Spaces', $rmIdx, '<h1>Spaces</h1>');
chk('E2: old Rooms h1 absent', $rmIdx, '<h1>Rooms</h1>', false);
chk('E3: spaces backlink says Catalog', $rmIdx, '← Catalog');
chk('E4: old Services & Resources backlink absent from rooms', $rmIdx, '← Services & Resources', false);
chk('E5: CTA says New space', $rmIdx, 'New space');
chk('E6: old Add Room CTA absent', $rmIdx, 'Add Room', false);
chk('E7: rooms route unchanged', $rmIdx, '/services-resources/rooms/create');

// ── F. Membership plans subscreen ────────────────────────────────────────────
chk('F1: Membership Plans h1 present', $memDef, '<h1>Membership Plans</h1>');
chk('F2: Membership Definitions h1 absent', $memDef, 'Membership Definitions', false);
chk('F3: plan description hint present', $memDef, 'plan definitions');
chk('F4: New membership plan CTA', $memDef, 'New membership plan');
chk('F5: old Create Membership Definition CTA absent', $memDef, 'Create Membership Definition', false);
chk('F6: no client-memberships launcher on plan definitions index', $memDef, 'href="/memberships/client-memberships"', false);
chk('F6b: prose names Clients for enrollments', $memDef, 'managed in Clients');
chk('F7: Organisation-wide only filter option', $memDef, 'Organisation-wide only');
chk('F8: old Global only option absent', $memDef, 'Global only', false);
chk('F9: /memberships/create route unchanged', $memDef, '/memberships/create');

// ── G. Active client memberships subscreen ───────────────────────────────────
chk('G1: Active Client Memberships h1', $memCli, '<h1>Active Client Memberships</h1>');
chk('G2: old Client Memberships h1 absent', $memCli, '<h1>Client Memberships</h1>', false);
chk('G3: enrol CTA present', $memCli, 'Enrol client in membership');
chk('G4: old Assign Membership to Client absent', $memCli, 'Assign Membership to Client', false);
chk('G5: Membership plans back link', $memCli, '← Membership plans');
chk('G6: old Membership Definitions back link absent', $memCli, 'Membership Definitions', false);
chk('G7: /memberships/client-memberships/assign route unchanged', $memCli, '/memberships/client-memberships/assign');
chk('G8: Organisation-wide filter in client memberships', $memCli, 'Organisation-wide only');

// ── H. Package plans subscreen ───────────────────────────────────────────────
chk('H1: Packages h2 present', $pkgDef, 'sales-workspace-section-title">Packages</h2>');
chk('H2: Package Definitions h2 absent', $pkgDef, 'Package Definitions', false);
chk('H3: plan description hint present', $pkgDef, 'Package plan definitions');
chk('H4: New package plan CTA', $pkgDef, 'New package plan');
chk('H5: old Create Package Definition CTA absent', $pkgDef, 'Create Package Definition', false);
chk('H5b: no client-packages launcher on plan definitions index', $pkgDef, 'href="/packages/client-packages"', false);
chk('H5c: prose names Clients for held packages', $pkgDef, 'managed in Clients');
chk('H6: Organisation-wide only filter', $pkgDef, 'Organisation-wide only');
chk('H7: old All branches (explicit mix) absent', $pkgDef, 'All branches (explicit mix)', false);
chk('H8: business scope hint updated', $pkgDef, 'Organisation-wide plans are available across all branches');
chk('H9: /packages/create route unchanged', $pkgDef, '/packages/create');

// ── I. Client packages subscreen ─────────────────────────────────────────────
chk('I1: Client packages hint present', $pkgCli, 'Packages currently held by clients');
chk('I2: Package plans back link', $pkgCli, '← Package plans');
chk('I3: old Package Definitions back link absent', $pkgCli, 'Package Definitions', false);
chk('I4: assign CTA lowercase', $pkgCli, 'Assign package to client');
chk('I5: Organisation-wide only filter in client packages', $pkgCli, 'Organisation-wide only');
chk('I6: old All branches (explicit mix) absent', $pkgCli, 'All branches (explicit mix)', false);
chk('I7: branch hint updated (no explicit mix language)', $pkgCli, 'Branch-assigned packages are managed within their branch');
chk('I8: /packages/client-packages/assign route unchanged', $pkgCli, '/packages/client-packages/assign');

// ── J. Gift cards index ───────────────────────────────────────────────────────
chk('J1: Gift Cards h2 updated', $gcIdx, 'Gift Cards</h2>');
chk('J2: old developer hint absent', $gcIdx, 'no separate', false);
chk('J3: business description present', $gcIdx, 'issue, redeem, or adjust the balance');
chk('J4: branch filter label cleaned', $gcIdx, 'Current branch + organisation-wide cards');
chk('J5: old Branch cards + org-wide label absent', $gcIdx, 'Branch cards + org-wide (client) cards', false);
chk('J6: Organisation-wide cards only option', $gcIdx, 'Organisation-wide cards only');
chk('J7: old Organization-wide cards only absent', $gcIdx, 'Organization-wide cards only', false);
chk('J8: Org-wide column value replaced', $gcIdx, 'Organisation-wide');
chk('J9: old Org-wide absent', $gcIdx, "'Org-wide'", false);
chk('J10: bulk expiry wording updated', $gcIdx, 'Update expiry date');
chk('J11: old Bulk expiration label absent', $gcIdx, 'Bulk expiration (active cards only)', false);
chk('J12: Remove expiry option', $gcIdx, 'Remove expiry (cards never expire)');
chk('J13: route /gift-cards unchanged', $gcIdx, "action=\"/gift-cards\"");

// ── K. Gift card issue form ───────────────────────────────────────────────────
chk('K1: Value label (not Amount)', $gcIssue, '>Value *<');
chk('K2: old Amount label absent', $gcIssue, '>Amount *<', false);
chk('K3: Issue date label', $gcIssue, '>Issue date *<');
chk('K4: old Issued At label absent', $gcIssue, '>Issued At *<', false);
chk('K5: Expiry date label', $gcIssue, '>Expiry date');
chk('K6: old Expires At label absent', $gcIssue, '>Expires At<', false);
chk('K7: Not assigned to a client option', $gcIssue, 'Not assigned to a client');
chk('K8: old No client option absent', $gcIssue, '>No client<', false);
chk('K9: Organisation-wide branch option', $gcIssue, 'Organisation-wide (no branch)');
chk('K10: old Global option absent', $gcIssue, '>Global<', false);
chk('K11: Issue gift card submit button', $gcIssue, 'Issue gift card</button>');
chk('K12: /gift-cards/issue POST route unchanged', $gcIssue, 'action="/gift-cards/issue"');

// ── L. Gift card redeem form ──────────────────────────────────────────────────
chk('L1: Redeem heading cleaned', $gcRdm, 'Redeem: ');
chk('L2: old Redeem Gift Card heading absent', $gcRdm, 'Redeem Gift Card ', false);
chk('L3: Balance label (not Current Balance)', $gcRdm, '<strong>Balance:</strong>');
chk('L4: Amount to redeem label', $gcRdm, 'Amount to redeem *');
chk('L5: old Redeem Amount label absent', $gcRdm, 'Redeem Amount *', false);
chk('L6: Redeem gift card submit button', $gcRdm, 'Redeem gift card</button>');
chk('L7: old Redeem submit absent', $gcRdm, '>Redeem</button>', false);
chk('L8: /redeem POST route unchanged', $gcRdm, '/redeem" class="entity-form"');

// ── M. Marketing contact lists ────────────────────────────────────────────────
chk('M1: Audiences section heading', $mktCL, 'Audiences');
chk('M2: Smart Segments heading', $mktCL, 'Smart Segments');
chk('M3: old Smart Lists heading absent', $mktCL, '>Smart Lists<', false);
chk('M4: Run migrations copy absent', $mktCL, 'Run migrations', false);
chk('M5: operator-friendly not-ready message', $mktCL, 'Contact your system administrator');
chk('M6: Manage this list label', $mktCL, 'Manage this list');
chk('M7: old Manage Active Manual List absent', $mktCL, 'Manage Active Manual List', false);
chk('M8: New list button label', $mktCL, '+ New list');
chk('M9: old + New Manual List absent', $mktCL, '+ New Manual List', false);
chk('M10: Marketing consent column header', $mktCL, 'Marketing consent');
chk('M11: old Marketing Communications absent', $mktCL, 'Marketing Communications', false);
chk('M12: title updated', $mktCL, 'Audiences & Contact Lists');
chk('M13: contact_lists tab active', $mktCL, "\$marketingTopActive = 'contact_lists'");
chk('M14: /marketing/contact-lists route unchanged', $mktCL, 'action="/marketing/contact-lists"');

// ── N. No route changes ───────────────────────────────────────────────────────
chk('N1: /services-resources/services route intact', $svcIdx, '/services-resources/services');
chk('N2: /services-resources/equipment route intact', $eqIdx, '/services-resources/equipment');
chk('N3: /services-resources/rooms route intact', $rmIdx, '/services-resources/rooms');
chk('N4: /memberships route intact in catalog hub', $svcHub, 'href="/memberships"');
chk('N5: /packages route intact in catalog hub', $svcHub, 'href="/packages"');
chk('N6: /gift-cards route intact', $gcIdx, 'href="/gift-cards"');

// ── Report ────────────────────────────────────────────────────────────────────
echo "\nVERIFIER: verify_catalog_growth_subsection_business_clarity_03\n";
echo str_repeat('─', 72) . "\n";
foreach ($checks as [$s, $l, $d]) {
    echo sprintf("  [%s] %s%s\n", $s, $l, $d !== '' ? "\n         → $d" : '');
}
echo str_repeat('─', 72) . "\n";
echo sprintf("  PASSED: %d   FAILED: %d   TOTAL: %d\n\n", $pass, $fail, $pass + $fail);
if ($fail > 0) { echo "STATUS: FAIL\n\n"; exit(1); }
echo "STATUS: PASS\n\n"; exit(0);
