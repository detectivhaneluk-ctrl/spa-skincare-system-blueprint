<?php

/**
 * Read-only verifier: STORY-07.3.9 — Catalog out of primary nav; SETTINGS > Services & Pricing
 *
 * Run: php system/scripts/read-only/verify_story_07_3_9_catalog_out_of_primary_nav_01.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function c79(string $label, string $file, string $needle, bool $want = true): void
{
    global $pass, $fail, $checks;
    if (!is_file($file)) {
        $checks[] = ['FAIL', $label, 'missing file'];
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
$nav = $base . '/shared/layout/base.php';
$shell = $base . '/modules/settings/views/partials/shell.php';

c79('N1: no primary nav tuple for Catalog home', $nav, "'/services-resources', 'Catalog'", false);
c79('N2: navIsCatalog still defined for active-state merge', $nav, '$navIsCatalog');
c79('N3: Admin tab active includes catalog definition URLs', $nav, '$navIsSettings = $navIsSettings || $navIsCatalog');
c79('N4: primary nav still targets real /settings for Settings', $nav, "'/settings', 'Settings'");
c79('S1: settings shell exposes Services & Pricing → /services-resources', $shell, 'href="/services-resources">Services &amp; Pricing</a>');
c79('S2: link gated by canViewServicesResourcesLink', $shell, '<?php if ($canViewServicesResourcesLink): ?>');
c79('S3: section label Services & pricing', $shell, 'Services &amp; pricing</p>');

echo "\nVERIFIER: verify_story_07_3_9_catalog_out_of_primary_nav_01\n";
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
