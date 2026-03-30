<?php

declare(strict_types=1);

/**
 * FND-TNT-17 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-11
 * Public client phone lock + excluding read: same contract as lockActiveByEmailBranch (positive branch + live branch/org EXISTS).
 */

$root = dirname(__DIR__, 3);
$repo = (string) file_get_contents($root . '/system/modules/clients/repositories/ClientRepository.php');

function extractMethodBody(string $src, string $name): string
{
    $needle = 'function ' . $name;
    $pos = strpos($src, $needle);
    if ($pos === false) {
        return '';
    }

    return substr($src, $pos, 3500);
}

$ok = true;
$phoneLock = extractMethodBody($repo, 'lockActiveByPhoneDigitsBranch');
$phoneExcl = extractMethodBody($repo, 'findActiveClientIdByPhoneDigitsExcluding');

if ($phoneLock === '' || $phoneExcl === '') {
    fwrite(STDERR, "FAIL: ClientRepository missing phone public resolution methods.\n");
    exit(1);
}

foreach (['lockActiveByPhoneDigitsBranch' => $phoneLock, 'findActiveClientIdByPhoneDigitsExcluding' => $phoneExcl] as $label => $body) {
    if (!preg_match('/if\s*\(\s*\$branchId\s*<=\s*0/', $body)) {
        fwrite(STDERR, "FAIL: {$label} must fail-closed for non-positive branchId.\n");
        $ok = false;
    }
    if (!str_contains($body, 'publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause(\'c\')')) {
        fwrite(STDERR, "FAIL: {$label} must use publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c').\n");
        $ok = false;
    }
    if (!str_contains($body, 'FROM clients c')) {
        fwrite(STDERR, "FAIL: {$label} must alias clients as c.\n");
        $ok = false;
    }
}

if (substr_count($phoneLock, 'FROM clients c') < 2) {
    fwrite(STDERR, "FAIL: lockActiveByPhoneDigitsBranch must use clients c in both normalized and legacy SQL paths.\n");
    $ok = false;
}
if (substr_count($phoneExcl, 'FROM clients c') < 2) {
    fwrite(STDERR, "FAIL: findActiveClientIdByPhoneDigitsExcluding must use clients c in both SQL paths.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
