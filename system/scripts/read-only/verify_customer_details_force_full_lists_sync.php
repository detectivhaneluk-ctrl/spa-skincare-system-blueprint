<?php
/**
 * Smoke: composer + client details render use the same "force full width" layout keys.
 * Run: php system/scripts/read-only/verify_customer_details_force_full_lists_sync.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composer = $root . '/modules/clients/views/custom-fields-composer-refactored.php';
$render   = $root . '/modules/clients/views/partials/client-details-layout-render.php';

$extract = static function (string $path, string $varName): array {
    $src = file_get_contents($path);
    if ($src === false) {
        throw new RuntimeException('Cannot read: ' . $path);
    }
    if (!preg_match(
        '/\$' . preg_quote($varName, '/') . '\s*=\s*\[(.*?)\];/s',
        $src,
        $m
    )) {
        throw new RuntimeException('Array not found: ' . $varName . ' in ' . $path);
    }
    preg_match_all("/'([a-z0-9_]+)'/", $m[1], $keys);
    return $keys[1];
};

$composerKeys = $extract($composer, 'customerDetailsLayoutFlowForceFullKeys');
$renderKeys   = $extract($render, 'detailsLayoutForceFullWidthKeys');

sort($composerKeys);
sort($renderKeys);

$expected = ['phone_contact_block', 'summary_primary_phone'];
sort($expected);

if ($composerKeys !== $renderKeys) {
    fwrite(STDERR, "FAIL: lists differ.\ncomposer: " . json_encode($composerKeys) . "\nrender:   " . json_encode($renderKeys) . "\n");
    exit(1);
}

if ($composerKeys !== $expected) {
    fwrite(STDERR, "FAIL: unexpected keys (update this script if intentional).\nGot: " . json_encode($composerKeys) . "\n");
    exit(1);
}

echo "OK: force-full keys match (" . count($composerKeys) . " keys).\n";
exit(0);
