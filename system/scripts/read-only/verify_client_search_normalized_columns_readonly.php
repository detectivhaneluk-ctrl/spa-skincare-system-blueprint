<?php

declare(strict_types=1);

/**
 * CLIENT-NORMALIZED-SEARCH-COLUMNS-AND-REPOSITORY-SWITCH-01 — static + tiny runtime checks (no DB).
 *
 *   php system/scripts/read-only/verify_client_search_normalized_columns_readonly.php
 */

$base = dirname(__DIR__, 2);
require_once $base . '/modules/clients/support/PublicContactNormalizer.php';
require_once $base . '/modules/clients/support/ClientSearchNormalization.php';

use Modules\Clients\Support\ClientSearchNormalization;
use Modules\Clients\Support\PublicContactNormalizer;

$repoPath = $base . '/modules/clients/repositories/ClientRepository.php';
$migPath = $base . '/data/migrations/119_clients_search_normalized_columns.sql';
$repo = (string) file_get_contents($repoPath);
$mig = is_file($migPath) ? (string) file_get_contents($migPath) : '';

$checks = [];

$checks['migration defines email_lc + phone_*_digits + indexes'] =
    str_contains($mig, 'email_lc')
    && str_contains($mig, 'phone_digits')
    && str_contains($mig, 'phone_home_digits')
    && str_contains($mig, 'phone_mobile_digits')
    && str_contains($mig, 'phone_work_digits')
    && str_contains($mig, 'idx_clients_email_lc');

$checks['ClientRepository list filter uses email_lc + stored phone digit columns'] =
    str_contains($repo, 'c.email_lc = ?')
    && str_contains($repo, 'c.phone_mobile_digits = ?');

$checks['ClientRepository has no sqlExprNormalizedPhoneDigits (client search/duplicate paths)'] =
    !str_contains($repo, 'sqlExprNormalizedPhoneDigits');

$checks['lockActiveByEmailBranch uses email_lc'] =
    str_contains($repo, 'email_lc = ?') && str_contains($repo, 'function lockActiveByEmailBranch');

$checks['lockActiveByPhoneDigitsBranch uses phone_digits only'] =
    str_contains($repo, 'phone_digits = ?') && str_contains($repo, 'function lockActiveByPhoneDigitsBranch');

$el = ClientSearchNormalization::emailLcForStorage('  Foo@BAR.com ');
$checks['ClientSearchNormalization::emailLcForStorage sample'] = ($el === 'foo@bar.com');

$pd = ClientSearchNormalization::phoneDigitsForStorage('+1 (555) 123-4567');
$checks['ClientSearchNormalization::phoneDigitsForStorage matches PublicContactNormalizer'] =
    ($pd === PublicContactNormalizer::normalizePhoneDigitsForMatch('+1 (555) 123-4567'));

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

echo PHP_EOL . 'Client search normalized columns static checks passed.' . PHP_EOL;
exit(0);
