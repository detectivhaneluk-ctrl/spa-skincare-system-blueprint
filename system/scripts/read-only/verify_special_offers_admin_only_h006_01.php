<?php

declare(strict_types=1);

/**
 * H-006-SPECIAL-OFFERS-FALSE-SURFACE-DISABLE-01: static proof that special offers are admin-only
 * (no create-as-active, no activation toggle, honest UI copy). No database.
 *
 * Usage:
 *   php system/scripts/read-only/verify_special_offers_admin_only_h006_01.php
 */

$base = dirname(__DIR__, 2);
$paths = [
    'service' => $base . '/modules/marketing/services/MarketingSpecialOfferService.php',
    'view' => $base . '/modules/marketing/views/promotions/special-offers.php',
    'controller' => $base . '/modules/marketing/controllers/MarketingPromotionsController.php',
    'repo' => $base . '/modules/marketing/repositories/MarketingSpecialOfferRepository.php',
];

$failed = false;
foreach ($paths as $label => $p) {
    if (!is_file($p)) {
        fwrite(STDERR, "FAIL: missing {$label} {$p}\n");
        $failed = true;
    }
}
if ($failed) {
    exit(1);
}

$sSvc = (string) file_get_contents($paths['service']);
$sView = (string) file_get_contents($paths['view']);
$sCtl = (string) file_get_contents($paths['controller']);
$sRepo = (string) file_get_contents($paths['repo']);

$checks = [
    'create and update both set is_active 0' => substr_count($sSvc, "'is_active' => 0") >= 2,
    'toggle rejects activation (inactive branch throws)' => str_contains($sSvc, 'Cannot activate:')
        && str_contains($sSvc, 'not wired'),
    'repository blocks activation path (H-006)' => str_contains($sRepo, 'if ($active)')
        && str_contains($sRepo, 'no invoice/booking/checkout pricing consumer'),
    'repository update sets is_active column' => str_contains($sRepo, 'o.is_active = ?'),
    'view banner mentions admin / not live pricing' => str_contains($sView, 'Admin catalog only')
        && str_contains($sView, 'not wired'),
    'view removes Activate button for inactive rows' => str_contains($sView, 'No activate (not wired)'),
    'controller success copy is admin-only honest' => str_contains($sCtl, 'admin catalog only')
        || str_contains($sCtl, 'admin-only'),
];

foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'MISSING') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
