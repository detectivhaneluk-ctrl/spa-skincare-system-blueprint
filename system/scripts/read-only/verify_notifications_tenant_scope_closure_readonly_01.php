<?php

declare(strict_types=1);

/**
 * FOUNDATION-NOTIFICATIONS-TENANCY-CANONICAL-CONTRACT-CLOSURE-04 — Tier A read-only proof for
 * {@see \Modules\Notifications\Repositories\NotificationRepository} org-gated branch / global-null visibility.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_notifications_tenant_scope_closure_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$checks = [];

$orgScope = (string) file_get_contents($system . '/core/Organization/OrganizationRepositoryScope.php');
$repo = (string) file_get_contents($system . '/modules/notifications/repositories/NotificationRepository.php');
$bootstrap = (string) file_get_contents($system . '/modules/bootstrap/register_appointments_documents_notifications.php');

$checks['OrganizationRepositoryScope defines notification tenant delegates'] = str_contains($orgScope, 'function notificationBranchOverlayOrGlobalNullFromOperationBranchClause')
    && str_contains($orgScope, 'function notificationGlobalNullBranchOrgAnchoredSql')
    && str_contains($orgScope, 'function notificationTenantWideBranchOrGlobalNullClause');

$checks['NotificationRepository injects OrganizationRepositoryScope and uses notification* helpers'] = str_contains($repo, 'OrganizationRepositoryScope')
    && str_contains($repo, 'notificationBranchOverlayOrGlobalNullFromOperationBranchClause')
    && str_contains($repo, 'notificationGlobalNullBranchOrgAnchoredSql')
    && str_contains($repo, 'notificationTenantWideBranchOrGlobalNullClause');

$checks['NotificationRepository avoids hand-rolled branch_id = ? OR branch_id IS NULL'] = !preg_match('/branch_id\s*=\s*\?\s+OR\s+branch_id\s+IS\s+NULL/i', $repo)
    && !preg_match('/branch_id\s+IS\s+NULL\s+OR\s+branch_id\s*=/i', $repo);

$notifDi = 'new \Modules\Notifications\Repositories\NotificationRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))';
$checks['Bootstrap wires OrganizationRepositoryScope into NotificationRepository'] = str_contains($bootstrap, $notifDi);

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

exit(0);
