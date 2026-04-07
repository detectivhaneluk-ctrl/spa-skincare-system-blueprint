<?php

/**
 * Read-only verifier: STORY-01.5.1..5 — Ollira 7-module nav structure
 *
 * Asserts that base.php contains the canonical 7-module nav labels,
 * the permission-gate tuple structure, and the role-aware filter block.
 * All checks are static string searches; no DB connection required.
 *
 * Run: php system/scripts/read-only/verify_ollira_7module_nav_structure_01.php
 * Expected exit code: 0
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function o7s(string $label, string $file, string $needle, bool $want = true): void
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

$base = dirname(__DIR__, 2);
$nav  = $base . '/shared/layout/base.php';

// ── 1. Canonical 7-module label names present ─────────────────────────────────
o7s('L1: Home label present (module 1)', $nav, "'Home'");
o7s('L2: Calendar label present (module 2)', $nav, "'Calendar'");
o7s('L3: Clients label present (module 3)', $nav, "'Clients'");
o7s('L4: Team label present (module 4)', $nav, "'Team'");
o7s('L5: Cashier label present (module 5)', $nav, "'Cashier'");
o7s('L6: Stock label present (module 6)', $nav, "'Stock'");
o7s('L7: Settings label present (module 7)', $nav, "'Settings'");

// ── 2. Old superseded label names absent from nav tuples ──────────────────────
o7s('O1: Overview label absent (renamed to Home)', $nav, "'Overview'", false);
o7s('O2: Sales label absent (renamed to Cashier)', $nav, "'Sales'", false);
o7s('O3: Inventory label absent (renamed to Stock)', $nav, "'Inventory'", false);
o7s('O4: Admin label absent (renamed to Settings)', $nav, "'Admin'", false);
o7s('O5: Catalog label absent (never a primary home)', $nav, "'Catalog'", false);
o7s('O6: Marketing label absent (never a primary home)', $nav, "'Marketing'", false);
o7s('O7: Reports label absent (never a primary home)', $nav, "'Reports'", false);

// ── 3. Permission gate keys present in nav tuple definitions ──────────────────
o7s('G1: appointments.view gate for Calendar', $nav, "'appointments.view'");
o7s('G2: clients.view gate for Clients', $nav, "'clients.view'");
o7s('G3: staff.view gate for Team', $nav, "'staff.view'");
o7s('G4: sales.view gate for Cashier', $nav, "'sales.view'");
o7s('G5: inventory.view gate for Stock', $nav, "'inventory.view'");
o7s('G6: settings.view gate for Settings', $nav, "'settings.view'");

// ── 4. Role-aware filter block present ────────────────────────────────────────
o7s('F1: navAllItems tuple array defined', $nav, '$navAllItems = [');
o7s('F2: PermissionService resolved in nav filter', $nav, 'get(\Core\Permissions\PermissionService::class)');
o7s('F3: array_filter permission gate block present', $nav, 'array_filter(');
o7s('F4: navPerm->has() called in filter', $nav, '$navPerm->has($navUid');
o7s('F5: navItems built from filtered navAllItems', $nav, '$navItems = array_map(');
o7s('F6: navSideIcons built from filtered navAllItems', $nav, '$navSideIcons = array_map(');

// ── 5. Route targets unchanged ────────────────────────────────────────────────
o7s('R1: /dashboard href unchanged', $nav, "'/dashboard'");
o7s('R2: /appointments/calendar/day href unchanged', $nav, "'/appointments/calendar/day'");
o7s('R3: /clients href unchanged', $nav, "'/clients'");
o7s('R4: /staff href unchanged', $nav, "'/staff'");
o7s('R5: /sales href unchanged', $nav, "'/sales'");
o7s('R6: /inventory href unchanged', $nav, "'/inventory'");
o7s('R7: /settings href unchanged', $nav, "'/settings'");

// ── 6. Active-state expressions preserved ─────────────────────────────────────
o7s('S1: navIsReports merges into Home (dashboard) active state', $nav, "str_starts_with(\$navPath, '/dashboard') || \$navIsReports");
o7s('S2: navIsTeam covers /payroll', $nav, "str_starts_with(\$navPath, '/payroll')");
o7s('S3: navIsCatalog folds into Settings active state', $nav, '$navIsSettings = $navIsSettings || $navIsCatalog');
o7s('S4: Clients active includes /marketing prefix', $nav, "str_starts_with(\$navPath, '/marketing')");

echo "\nVERIFIER: verify_ollira_7module_nav_structure_01\n";
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
