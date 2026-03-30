<?php

declare(strict_types=1);

/**
 * Read-only guardrail for active tenant runtime surfaces.
 *
 * Policy:
 * - Scoped methods (findInTenantScope/findLive*OnBranch/findBy* scoped locks) are always allowed.
 * - Raw `->find(` / `->findForUpdate(` in scanned files must be explicitly allowlisted here with reason.
 * - `REPO_FOOTGUN_ALLOW` inline marker can suppress one line with explicit local justification.
 */

$root = dirname(__DIR__);
$rootNorm = str_replace('\\', '/', realpath($root) ?: $root);

$scanFiles = [
    // Client profile providers
    $root . '/modules/gift-cards/providers/ClientGiftCardProfileProviderImpl.php',
    $root . '/modules/packages/providers/ClientPackageProfileProviderImpl.php',
    $root . '/modules/sales/providers/ClientSalesProfileProviderImpl.php',
    $root . '/modules/appointments/providers/ClientAppointmentProfileProviderImpl.php',
    // Sell/grant/fulfillment paths
    $root . '/modules/memberships/Services/MembershipSaleService.php',
    $root . '/modules/memberships/Services/MembershipService.php',
    $root . '/modules/packages/services/PackageService.php',
    $root . '/modules/gift-cards/Services/GiftCardService.php',
    $root . '/modules/public-commerce/services/PublicCommerceService.php',
    $root . '/modules/public-commerce/services/PublicCommerceFulfillmentReconciler.php',
];

/**
 * Keys are `relative/path.php|receiver|method` -> justification.
 *
 * Keep tight: only add when the call is intentionally safe (scoped repo, FK existence, or local-row continuity).
 * Any new raw find in scanned surfaces fails until reviewed.
 *
 * @var array<string, string>
 */
$allowlist = [
    'modules/memberships/Services/MembershipSaleService.php|invoiceRepo|find' => 'InvoiceRepository::find is tenant-scoped by SalesTenantScope; used for canonical invoice status settlement.',
    'modules/memberships/Services/MembershipSaleService.php|sales|find' => 'Same sale-row continuity check immediately after transactional update.',
    'modules/memberships/Services/MembershipSaleService.php|sales|findForUpdate' => 'Authoritative sale row lock before settlement transitions.',
    'modules/memberships/Services/MembershipSaleService.php|definitions|find' => 'Existence/FK guard only after snapshot proof in activation path.',
    'modules/memberships/Services/MembershipService.php|clients|findForUpdate' => 'Client row lock for deterministic membership assignment transaction.',
    'modules/memberships/Services/MembershipService.php|definitions|find' => 'Manual assignment fallback path; branch safety enforced separately in method.',
    'modules/memberships/Services/MembershipService.php|clientMemberships|find' => 'Post-create/read-back of same row id for return payload.',
    'modules/packages/services/PackageService.php|usages|find' => 'Local package usage correction row lookup before same-branch checks.',
    'modules/packages/services/PackageService.php|clients|find' => 'Existence gate only; no cross-tenant list/read payload.',
    'modules/gift-cards/Services/GiftCardService.php|clients|find' => 'Client existence gate only; branch safety enforced in downstream scoped reads.',
    'modules/public-commerce/services/PublicCommerceService.php|invoiceRepo|find' => 'InvoiceRepository::find is tenant-scoped and used for payment truth.',
    'modules/public-commerce/services/PublicCommerceFulfillmentReconciler.php|invoiceRepo|find' => 'InvoiceRepository::find is tenant-scoped canonical paid-state gate.',
];

$scopedMethodPattern = '/->\s*(findInTenantScope|findLiveReadOnBranch|findLiveForUpdateOnBranch|findForUpdateInTenantScope|findBranchOwnedPublicPurchasable|findForUpdateByTokenHash|findForUpdateByInvoiceIdForBranch|findForUpdateByInvoiceIdAttachedToLiveInvoice|findForUpdateCorrelatedToInvoiceRow|findByTokenHash|findByInvoiceIdForBranch|findByInvoiceIdAttachedToLiveInvoice|findCorrelatedToInvoiceRow|findBlocking[A-Za-z0-9_]*)\s*\(/';
$rawFindPattern = '/\$this->([A-Za-z_][A-Za-z0-9_]*)->\s*(find|findForUpdate)\s*\(/';

$violations = [];

foreach ($scanFiles as $path) {
    if (!is_file($path)) {
        continue;
    }
    $src = (string) file_get_contents($path);
    $pathNorm = str_replace('\\', '/', $path);
    $rel = str_starts_with($pathNorm, $rootNorm . '/') ? substr($pathNorm, strlen($rootNorm) + 1) : $pathNorm;

    if (!preg_match_all($rawFindPattern, $src, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    foreach ($m[0] as $idx => $fullMatch) {
        $matchText = (string) $fullMatch[0];
        $offset = (int) $fullMatch[1];
        $receiver = (string) $m[1][$idx][0];
        $method = (string) $m[2][$idx][0];
        $lineNo = 1 + substr_count(substr($src, 0, $offset), "\n");
        $lineStart = strrpos(substr($src, 0, $offset), "\n");
        $lineStart = ($lineStart === false) ? 0 : ($lineStart + 1);
        $lineEndPos = strpos($src, "\n", $offset);
        $lineEndPos = ($lineEndPos === false) ? strlen($src) : $lineEndPos;
        $line = trim(substr($src, $lineStart, $lineEndPos - $lineStart));
        if (str_contains($line, 'REPO_FOOTGUN_ALLOW')) {
            continue;
        }
        if (preg_match($scopedMethodPattern, $line)) {
            continue;
        }

        $allowKey = $rel . '|' . $receiver . '|' . $method;
        if (isset($allowlist[$allowKey])) {
            continue;
        }

        $violations[] = $rel . ':' . $lineNo . ': ' . $matchText . ' :: ' . $line;
    }
}

if ($violations !== []) {
    fwrite(STDERR, "REPO_FOOTGUN violations:\n" . implode("\n", $violations) . "\n");
    exit(1);
}

echo "verify_tenant_repository_footguns: OK (expanded active runtime coverage clean).\n";
exit(0);
