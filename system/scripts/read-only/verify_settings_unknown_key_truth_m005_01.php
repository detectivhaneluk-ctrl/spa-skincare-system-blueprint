<?php

declare(strict_types=1);

/**
 * M-005 read-only: settings write unknown-key auditing must use raw `settings[]` POST truth,
 * not only the section-scoped payload.
 *
 * Run from project root: php system/scripts/read-only/verify_settings_unknown_key_truth_m005_01.php
 */
$systemRoot = dirname(__DIR__, 2);

function src(string $relativeFromSystem): string
{
    global $systemRoot;

    return (string) file_get_contents($systemRoot . '/' . $relativeFromSystem);
}

$ctrl = src('modules/settings/controllers/SettingsController.php');

$checks = [
    'SettingsController readable' => $ctrl !== '',
    'raw-based unknown collector exists' => str_contains($ctrl, 'collectUnknownRawKeysFromSettingsPost'),
    'store wires unknowns from raw POST' => str_contains($ctrl, 'collectUnknownRawKeysFromSettingsPost($rawPost)'),
    'no legacy scoped-only unknown collector name' => !str_contains($ctrl, 'collectUnknownKeys('),
    'settings_unknown audit carries unknown_raw_keys' => str_contains($ctrl, "'unknown_raw_keys' => \$unknownRawKeys"),
    'stripped audit carries posted + scoped key snapshots' => str_contains($ctrl, "'posted_settings_keys' => \$postedSettingsKeys")
        && str_contains($ctrl, "'scoped_settings_keys' => \$scopedSettingsKeys"),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
