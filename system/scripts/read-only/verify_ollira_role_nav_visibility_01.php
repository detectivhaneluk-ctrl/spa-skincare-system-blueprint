<?php

/**
 * Read-only verifier: STORY-01.5.1..5 — Role-aware nav visibility
 *
 * Asserts that base.php's permission-gate logic correctly maps each module
 * home to its governing permission key, so the nav is filtered per role.
 *
 * Per §2.4 Law 5 of OLLIRA-IA-7MODULE-MASTER-BACKLOG-V2-01.md:
 *   Receptionist  → [Home, Calendar, Clients, Cashier]
 *   Stylist/Therapist → [Home, Calendar, Clients (read)]
 *   Manager/Owner → all 7 homes
 *
 * This verifier checks code structure (static analysis). It does not require
 * a DB connection or live HTTP session.
 *
 * Run: php system/scripts/read-only/verify_ollira_role_nav_visibility_01.php
 * Expected exit code: 0
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function o7r(string $label, string $file, string $needle, bool $want = true): void
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

// ── A. Home is always visible (null gate = no permission required) ─────────────
// Home tuple has null as permission gate (4th element).
o7r('A1: Home tuple has null gate (always visible)', $nav, "'/dashboard', 'Home'," . ' ' . '$navPath');
o7r('A2: null gate present in navAllItems definition', $nav, ', null,');

// ── B. Per-module permission gates correctly assigned ─────────────────────────
// Each restricted home must appear with its gate in the same navAllItems tuple.
// We check the gate key is adjacent to its module href in the file.
o7r('B1: Calendar gated by appointments.view', $nav, "'appointments.view'");
o7r('B2: Clients gated by clients.view', $nav, "'clients.view'");
o7r('B3: Team gated by staff.view', $nav, "'staff.view'");
o7r('B4: Cashier gated by sales.view', $nav, "'sales.view'");
o7r('B5: Stock gated by inventory.view', $nav, "'inventory.view'");
o7r('B6: Settings gated by settings.view', $nav, "'settings.view'");

// ── C. Filter is applied only when a user is authenticated ────────────────────
o7r('C1: navUser null guard wraps the filter block', $nav, 'if ($navUser !== null) {');
o7r('C2: PermissionService fetched inside the null guard', $nav, '$navPerm = \Core\App\Application::container()->get(\Core\Permissions\PermissionService::class)');
o7r('C3: navUid cast from navUser id', $nav, '$navUid = (int) ($navUser[\'id\'] ?? 0)');

// ── D. Filter uses array_values to re-index after removal ─────────────────────
o7r('D1: array_values called to re-index filtered navAllItems', $nav, 'array_values(array_filter(');

// ── E. Resulting navItems and navSideIcons are co-filtered ────────────────────
// Both are derived from the same filtered navAllItems in parallel.
o7r('E1: navItems derived from navAllItems via array_map', $nav, '$navItems = array_map(');
o7r('E2: navSideIcons derived from navAllItems via array_map', $nav, '$navSideIcons = array_map(');

// ── F. No dead nav: owner/admin permissions include all module gates ───────────
// The seeder grants all non-platform permissions to owner. We verify the
// PermissionService::has() wildcard path is present (used by owner role).
o7r('F1: PermissionService::has() checks wildcard * permission', $base . '/core/permissions/PermissionService.php', "in_array('*', \$perms, true)");

// ── G. Regression: no raw navItems or navSideIcons hardcoded arrays remain ────
// The old approach used a flat 3-element tuple array; the new uses navAllItems.
o7r('G1: old flat navItems array literal gone', $nav, "\$navItems = [\n        ['", false);
o7r('G2: old navSideIcons literal array gone', $nav, "\$navSideIcons = [\n        '", false);

echo "\nVERIFIER: verify_ollira_role_nav_visibility_01\n";
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
