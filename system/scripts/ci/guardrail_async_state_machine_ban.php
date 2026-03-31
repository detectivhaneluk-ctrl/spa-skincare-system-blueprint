<?php

declare(strict_types=1);

/**
 * PLT-Q-01 Guardrail: Async State Machine Ban (outside canonical control-plane)
 *
 * Detects new standalone async state-machine constant files or classes that define
 * job status values (QUEUED / RUNNING / PENDING / PROCESSING / etc.) outside the
 * canonical control-plane namespace (Core\Runtime\Queue) without being in the
 * explicit allowlist below.
 *
 * Why this guardrail exists:
 *   Every new ad-hoc status constant file creates a fragmented async pathway that
 *   bypasses canonical claim/retry/stale-recovery semantics. This guardrail makes
 *   such drift immediately visible at CI time.
 *
 * Detection:
 *   Files that define two or more of the canonical job-status constant names in
 *   combination (QUEUED + RUNNING, PENDING + PROCESSING, etc.) outside the allowlist
 *   are flagged as potential ad-hoc state machines.
 *
 * Allowlist (frozen at PLT-Q-01 cutover 2026-03-31):
 *   - ClientMergeJobStatuses — pre-canonical domain-level state parallel to runtime_async_jobs
 *
 * How to extend the allowlist:
 *   If a new domain legitimately needs its own parallel job state machine
 *   (e.g. for domain-visibility reasons alongside the canonical runtime row),
 *   add its relative path to $allowlistFiles below with a documented rationale comment.
 *   The default expectation is that new work uses only runtime_async_jobs statuses.
 *
 * Run from repo root:
 *   php system/scripts/ci/guardrail_async_state_machine_ban.php
 */

$repoRoot = dirname(__DIR__, 3);
$systemRoot = $repoRoot . '/system';

// ---------------------------------------------------------------------------
// Canonical control-plane files — never flagged.
// These define the authoritative status constants for the queue backbone.
// ---------------------------------------------------------------------------
$canonicalFiles = [
    'system/core/Runtime/Queue/RuntimeAsyncJobRepository.php', // STATUS_PENDING / PROCESSING / SUCCEEDED / FAILED / DEAD
    'system/core/Runtime/Queue/AsyncJobHandlerInterface.php',
    'system/core/Runtime/Queue/AsyncJobHandlerRegistry.php',
    'system/core/Runtime/Queue/AsyncQueueWorkerLoop.php',
    'system/core/Runtime/Queue/AsyncQueueStatusReader.php',
    'system/core/Runtime/Queue/RuntimeAsyncJobWorkload.php',
    'system/core/Runtime/Queue/RuntimeMediaImagePipelineCliRunner.php',
];

// ---------------------------------------------------------------------------
// Allowlisted domain state machines — frozen at PLT-Q-01 cutover.
// Each entry must have an inline rationale comment.
// ---------------------------------------------------------------------------
$allowlistFiles = [
    // ClientMergeJobStatuses: pre-canonical domain-level state machine for client_merge_jobs
    // table. Exists in parallel with runtime_async_jobs dispatch signal.
    // The domain row tracks merge-specific execution steps; the runtime row is only the
    // dispatch carrier. This dual-table pattern is explicitly accepted at PLT-Q-01 cutover
    // and must not be extended without a new allowlist entry.
    'system/modules/clients/support/ClientMergeJobStatuses.php',
];

// ---------------------------------------------------------------------------
// Detection: combinations of canonical status names that indicate a state machine.
// Two or more of these in the same file (outside canonical / allowlist) = violation.
// ---------------------------------------------------------------------------
$statusMarkers = ['QUEUED', 'RUNNING', 'PENDING', 'PROCESSING', 'SUCCEEDED', 'FAILED', 'DEAD'];

$violations = [];
$scanned = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($systemRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $absPath = $file->getRealPath();
    if ($absPath === false) {
        continue;
    }

    // Normalize to repo-relative path (forward slashes for comparison).
    $relPath = ltrim(str_replace('\\', '/', substr($absPath, strlen($repoRoot) + 1)), '/');

    // Skip canonical files.
    if (in_array($relPath, $canonicalFiles, true)) {
        continue;
    }

    // Skip allowlisted files.
    if (in_array($relPath, $allowlistFiles, true)) {
        continue;
    }

    // Skip script files (verifiers, guardrails themselves, dev scripts).
    if (str_starts_with($relPath, 'system/scripts/')) {
        continue;
    }

    $content = (string) file_get_contents($absPath);
    ++$scanned;

    // Count how many status-marker constant names appear as PHP constant definitions
    // (const FOO = '...') in this file.
    $found = [];
    foreach ($statusMarkers as $marker) {
        // Match: const STATUS_<MARKER> = or const <MARKER> = (but not just a string literal usage)
        if (preg_match('/\bconst\s+(?:STATUS_)?' . $marker . '\s*=/', $content)) {
            $found[] = $marker;
        }
    }

    if (count($found) >= 2) {
        $violations[] = "AD-HOC STATE MACHINE DETECTED\n"
            . "  File: {$relPath}\n"
            . "  Markers found: " . implode(', ', $found) . "\n"
            . "  Fix: Use RuntimeAsyncJobRepository status constants or add file to guardrail allowlist\n"
            . "       with a documented rationale comment (see PLT-Q-01 policy).";
    }
}

if ($violations !== []) {
    fwrite(STDERR, "guardrail_async_state_machine_ban: FAIL — " . count($violations) . " violation(s)\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, $v . "\n\n");
    }
    fwrite(STDERR, "Policy: New async state machines must use canonical RuntimeAsyncJobRepository statuses.\n");
    fwrite(STDERR, "See: PLT-Q-01 / system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md\n");
    exit(1);
}

echo "guardrail_async_state_machine_ban: PASS ({$scanned} PHP files scanned, 0 violations)\n";
exit(0);
