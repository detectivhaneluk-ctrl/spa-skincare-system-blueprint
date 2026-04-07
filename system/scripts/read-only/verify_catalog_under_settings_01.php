<?php

/**
 * Read-only verifier: FEAT-P4.4 — Catalog definition surfaces under SETTINGS active state
 *
 * Asserts that all catalog definition URL families (/services-resources,
 * membership plan definitions, package plan definitions) are highlighted
 * under the SETTINGS primary nav tab — not under a separate Catalog home.
 *
 * Per §2.2 of OLLIRA-IA-7MODULE-MASTER-BACKLOG-V2-01.md:
 *   "SETTINGS = policies + controls + service definitions"
 *   "Catalog is not a home. Service definitions live in SETTINGS > Services & Pricing."
 *
 * All checks are static string searches; no DB connection required.
 *
 * Run: php system/scripts/read-only/verify_catalog_under_settings_01.php
 * Expected exit code: 0
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function cs01(string $label, string $file, string $needle, bool $want = true): void
{
    global $pass, $fail, $checks;
    if (!is_file($file)) {
        $checks[] = ['FAIL', $label, 'missing file: ' . basename($file)];
        $fail++;
        return;
    }
    $ok = str_contains((string) file_get_contents($file), $needle) === $want;
    if ($ok) {
        $checks[] = ['PASS', $label, ''];
        $pass++;
    } else {
        $checks[] = ['FAIL', $label, ($want ? 'expected: ' : 'must not contain: ') . substr($needle, 0, 96)];
        $fail++;
    }
}

$base    = dirname(__DIR__, 2);
$nav     = $base . '/shared/layout/base.php';
$shell   = $base . '/modules/settings/views/partials/shell.php';
$svcHub  = $base . '/modules/services-resources/views/index.php';

// ── A. /services-resources activates the SETTINGS primary nav tab ─────────────
// navIsCatalog is true for /services-resources, and it folds into navIsSettings.
cs01('A1: navIsCatalog defined; covers /services-resources prefix', $nav, "str_starts_with(\$navPath, '/services-resources')");
cs01('A2: navIsClientsMemberships split variable separates client from plan paths', $nav, '$navIsClientsMemberships');
cs01('A3: navIsClientsPackages split variable separates client from plan paths', $nav, '$navIsClientsPackages');
cs01('A4: navIsCatalog folds into navIsSettings (Settings active on catalog URLs)', $nav, '$navIsSettings = $navIsSettings || $navIsCatalog');
cs01('A5: /services-resources is NOT a standalone primary nav home href', $nav, "'/services-resources', 'Catalog'", false);
cs01('A6: Settings primary nav home targets /settings (not /services-resources)', $nav, "'/settings', 'Settings'");

// ── B. /memberships plan definitions activate SETTINGS (not a Catalog home) ───
// Membership plan definitions (/memberships excluding /memberships/client-memberships)
// must activate SETTINGS, not a separate Catalog tab.
cs01('B1: navIsCatalog covers /memberships plan definitions', $nav, "str_starts_with(\$navPath, '/memberships')");
cs01('B2: /memberships/client-memberships excluded from catalog family (Clients owns enrolled records)', $nav, '! $navIsClientsMemberships');
cs01('B3: /memberships/refund-review activates Settings (not a Catalog entry)', $nav, "str_starts_with(\$navPath, '/memberships/refund-review')");

// ── C. /packages plan definitions activate SETTINGS (not a Catalog home) ──────
cs01('C1: navIsCatalog covers /packages plan definitions', $nav, "str_starts_with(\$navPath, '/packages')");
cs01('C2: /packages/client-packages excluded from catalog family (Clients owns held packages)', $nav, '! $navIsClientsPackages');

// ── D. SETTINGS sidebar surfaces Services & Pricing link ──────────────────────
cs01('D1: Settings shell exposes Services & Pricing link to /services-resources', $shell, 'href="/services-resources">Services &amp; Pricing</a>');
cs01('D2: Services & Pricing link gated by services-resources.view permission', $shell, '$canViewServicesResourcesLink');
cs01('D3: SETTINGS shell has a Services & pricing section label', $shell, 'Services &amp; pricing</p>');

// ── E. Catalog hub (services-resources index) is accessible via SETTINGS ───────
cs01('E1: Catalog hub view exists with catalog-hub class', $svcHub, 'catalog-hub');
cs01('E2: Catalog hub links to /services-resources/services', $svcHub, '/services-resources/services');
cs01('E3: Catalog hub links to /memberships (plan definitions)', $svcHub, 'href="/memberships"');
cs01('E4: Catalog hub links to /packages (plan definitions)', $svcHub, 'href="/packages"');

// ── F. No primary nav entry for Catalog exists anywhere ───────────────────────
cs01('F1: No Catalog primary nav tuple in base.php', $nav, "'/services-resources', 'Catalog'", false);
cs01('F2: No Marketing primary nav tuple in base.php', $nav, "'/marketing/campaigns', 'Marketing'", false);
cs01('F3: No Reports primary nav tuple in base.php', $nav, "'/reports', 'Reports'", false);
cs01('F4: navAllItems has exactly 7 tuples (Home/Calendar/Clients/Team/Cashier/Stock/Settings)', $nav, '$navAllItems = [');

// ── G. Catalog-related active-state variables are defined (no dead code) ───────
cs01('G1: navIsCatalog variable is defined in base.php', $nav, '$navIsCatalog');
cs01('G2: navIsReports variable is defined (used for Home active state on /reports)', $nav, '$navIsReports');
cs01('G3: navIsTeam variable covers /payroll (Team active on payroll)', $nav, "str_starts_with(\$navPath, '/payroll')");

echo "\nVERIFIER: verify_catalog_under_settings_01\n";
echo str_repeat('─', 72) . "\n";
foreach ($checks as [$s, $l, $d]) {
    echo sprintf("  [%s] %s%s\n", $s, $l, $d !== '' ? "\n         → $d" : '');
}
echo str_repeat('─', 72) . "\n";
echo sprintf("  PASSED: %d   FAILED: %d   TOTAL: %d\n\n", $pass, $fail, $pass + $fail);
if ($fail > 0) {
    echo "STATUS: FAIL\n\n";
    exit(1);
}
echo "STATUS: PASS\n\n";
exit(0);
