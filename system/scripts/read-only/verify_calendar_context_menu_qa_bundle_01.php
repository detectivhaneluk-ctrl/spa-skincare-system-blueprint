<?php

declare(strict_types=1);

/**
 * Runs a small read-only QA bundle for calendar context menu work.
 * From system/: php scripts/read-only/verify_calendar_context_menu_qa_bundle_01.php
 *
 * Scripts: calendar menu static proof, WAVE-06 cache proof, appointment print consumer proof.
 */

$system = dirname(__DIR__, 2);
$scripts = [
    'Calendar context menu (routes + JS handlers)' => $system . '/scripts/read-only/verify_calendar_context_menu_backend_routes_01.php',
    'WAVE-06 hot path cache effectiveness' => $system . '/scripts/read-only/verify_wave06_hot_path_cache_effectiveness_01.php',
    'Appointment print consumer foundation' => $system . '/scripts/verify_appointment_print_consumer_foundation_01.php',
];

$fail = false;
foreach ($scripts as $label => $path) {
    echo "\n========== {$label} ==========\n";
    if (!is_file($path)) {
        echo "[FAIL] Missing file: {$path}\n";
        $fail = true;
        continue;
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    passthru($cmd, $code);
    if ($code !== 0) {
        $fail = true;
    }
}

exit($fail ? 1 : 0);
