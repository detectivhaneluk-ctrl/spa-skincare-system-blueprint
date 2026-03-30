<?php

declare(strict_types=1);

/**
 * Read-only: proves FOUNDATION-TENANT-REPOSITORY-CLOSURE-01 Tier A — tenant-scoped UPDATEs on
 * public_commerce_purchases and membership_sales (no runtime DB writes).
 *
 * From repository root:
 *   php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_07_readonly_01.php
 *
 * Exit: 0 if anchors present; 1 if regression detected.
 */

$root = realpath(dirname(__DIR__, 3));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$checks = [
    [
        'PublicCommercePurchaseRepository scoped UPDATE',
        $root . '/system/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php',
        [
            'UPDATE public_commerce_purchases p SET',
            'WHERE p.id = ?',
            'branchColumnOwnedByResolvedOrganizationExistsClause',
        ],
    ],
    [
        'MembershipSaleRepository scoped UPDATE',
        $root . '/system/modules/memberships/Repositories/MembershipSaleRepository.php',
        [
            'UPDATE membership_sales ms SET',
            'WHERE ms.id = ?',
            'branchColumnOwnedByResolvedOrganizationExistsClause',
        ],
    ],
];

$failed = false;
foreach ($checks as [$label, $path, $needles]) {
    if (!is_readable($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        $failed = true;
        continue;
    }
    $body = (string) file_get_contents($path);
    foreach ($needles as $n) {
        if (!str_contains($body, $n)) {
            fwrite(STDERR, "FAIL {$label}: expected anchor not found: {$n}\n");
            $failed = true;
        }
    }
    if (str_contains($body, 'UPDATE public_commerce_purchases SET ') && !str_contains($body, 'UPDATE public_commerce_purchases p SET')) {
        fwrite(STDERR, "FAIL {$label}: unqualified UPDATE public_commerce_purchases SET must not remain.\n");
        $failed = true;
    }
    if (preg_match('/UPDATE\\s+membership_sales\\s+SET\\s+/i', $body) === 1 && !str_contains($body, 'UPDATE membership_sales ms SET')) {
        fwrite(STDERR, "FAIL {$label}: unqualified UPDATE membership_sales SET must not remain.\n");
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo "verify_tenant_closure_wave_fnd_tnt_07_readonly_01: OK\n";
exit(0);
