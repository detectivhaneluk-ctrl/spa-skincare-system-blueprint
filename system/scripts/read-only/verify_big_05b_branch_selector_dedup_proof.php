<?php

declare(strict_types=1);

/**
 * BIG-05B — Branch selector duplication regression proof.
 *
 * Verifies that BranchDirectory enforces name uniqueness within an organisation
 * for both create and update paths, and that the calendar-day view cannot render
 * duplicate options from a single source list.
 *
 * Usage:
 *   php system/scripts/read-only/verify_big_05b_branch_selector_dedup_proof.php
 */

$root   = dirname(__DIR__, 3);
$system = $root . '/system';

/**
 * @return string
 */
function readOrFail05b(string $path): string
{
    $src = @file_get_contents($path);
    if (!is_string($src) || $src === '') {
        fwrite(STDERR, "FAIL: unreadable file {$path}\n");
        exit(1);
    }

    return $src;
}

$branchDir   = readOrFail05b($system . '/core/Branch/BranchDirectory.php');
$calView     = readOrFail05b($system . '/modules/appointments/views/calendar-day.php');
$migration   = readOrFail05b($system . '/data/migrations/127_branches_enforce_unique_name_per_org.sql');

$checks = [];

// ── 1. isNameTaken method exists and checks deleted_at IS NULL within org scope ──────────────
$checks['BranchDirectory has private isNameTaken method checking deleted_at IS NULL'] =
    str_contains($branchDir, 'private function isNameTaken(string $name, ?int $excludeId): bool')
    && str_contains($branchDir, 'AND deleted_at IS NULL')
    && preg_match('/isNameTaken.*SELECT id FROM branches.*deleted_at IS NULL/s', $branchDir) === 1;

// ── 2. createBranch enforces name uniqueness ─────────────────────────────────────────────────
$checks['BranchDirectory::createBranch calls isNameTaken with null excludeId'] =
    str_contains($branchDir, 'isNameTaken($name, null)');

// ── 3. updateBranch enforces name uniqueness (exclude self) ──────────────────────────────────
$checks['BranchDirectory::updateBranch calls isNameTaken with $id exclusion'] =
    str_contains($branchDir, 'isNameTaken($name, $id)');

// ── 4. createBranch still checks code taken (regression guard) ───────────────────────────────
$checks['BranchDirectory::createBranch still has isCodeTaken guard (no regression)'] =
    str_contains($branchDir, 'isCodeTaken($code, null)');

// ── 5. getActiveBranchesForSelection uses org-scoped simple SELECT — no JOIN that could fan out
$checks['BranchDirectory::getActiveBranchesForSelection uses flat org-scoped SELECT without JOINs'] =
    str_contains($branchDir, 'SELECT id, name, code FROM branches WHERE deleted_at IS NULL AND organization_id = ?')
    && !preg_match('/getActiveBranchesForSelection[^}]+JOIN/s', $branchDir);

// ── 6. calendar-day view iterates $branches exactly once per selector (two selectors, no extra)
$calendarSelectCount = substr_count($calView, 'foreach ($branches as $b)');
$checks['calendar-day.php iterates $branches exactly twice (one per selector — calendar and blocked-slots)'] =
    $calendarSelectCount === 2;

// ── 7. No branch option injection outside the $branches foreach in the view ──────────────────
// Confirm there is no standalone "<option" that contains a branch name variable outside the foreach.
// Proxy: count $b['id'] usages matches count of foreach ($branches as $b) blocks × options-per-block (1 each).
$branchOptionLines = substr_count($calView, '(int) $b[\'id\']');
$checks['calendar-day.php renders exactly one <option> per foreach iteration (no extra option injections)'] =
    $branchOptionLines === 2  // one per selector
    && $calendarSelectCount === 2;

// ── 8. JavaScript in calendar-day.php does not modify branch selector options ────────────────
// Confirm the JS does not call .appendChild / .add on the branch select element.
$scriptSection = '';
$scriptStart = strpos($calView, '<script>');
if ($scriptStart !== false) {
    $scriptSection = substr($calView, $scriptStart);
}
$checks['calendar-day.php JavaScript does not inject branch <option> elements client-side'] =
    $scriptSection !== ''
    && !preg_match('/branchEl\s*\.\s*(appendChild|add|innerHTML)\s*[=(]/', $scriptSection)
    && !preg_match('/calendar-branch.*appendChild|appendChild.*calendar-branch/', $scriptSection);

// ── 9. Migration 127 exists and contains the deduplication UPDATE ────────────────────────────
$checks['Migration 127 exists and soft-deletes duplicate-named active branches'] =
    str_contains($migration, 'UPDATE branches b1')
    && str_contains($migration, 'b1.organization_id = b2.organization_id')
    && str_contains($migration, 'b1.name            = b2.name')
    && str_contains($migration, 'b1.id              > b2.id')
    && str_contains($migration, 'b2.deleted_at IS NULL')
    && str_contains($migration, 'SET b1.deleted_at  = CURRENT_TIMESTAMP')
    && str_contains($migration, 'WHERE b1.deleted_at IS NULL');

// ── 10. isNameTaken uses excludeId for update path (prevents "name taken by self" false positive)
$checks['isNameTaken correctly excludes current branch id on update (not flagging self as duplicate)'] =
    str_contains($branchDir, 'AND id <> ? LIMIT 1')
    && preg_match('/isNameTaken[^}]+excludeId !== null[^}]+AND id <> \?/s', $branchDir) === 1;

// ─────────────────────────────────────────────────────────────────────────────────────────────

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "verify_big_05b_branch_selector_dedup_proof: OK\n";
exit(0);
