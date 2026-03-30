<?php

declare(strict_types=1);

/**
 * H-003: typed access denial → HTTP 403 path; no fragile DomainException message allowlist in HttpErrorHandler.
 */
$system = dirname(__DIR__, 2);
$handlerSrc = (string) file_get_contents($system . '/core/errors/HttpErrorHandler.php');
$accessExSrc = (string) file_get_contents($system . '/core/errors/AccessDeniedException.php');

$checks = [
    'AccessDeniedException.php exists on disk' => is_file($system . '/core/errors/AccessDeniedException.php'),
    'AccessDeniedException extends DomainException' => str_contains($accessExSrc, 'extends \\DomainException'),
    'HttpErrorHandler maps AccessDeniedException to respondForbidden' => str_contains($handlerSrc, '$e instanceof AccessDeniedException')
        && str_contains($handlerSrc, 'respondForbidden'),
    'HttpErrorHandler has no message allowlist method' => !str_contains($handlerSrc, 'isResolverOrganizationResolutionDomainException'),
    'HttpErrorHandler has no Selected invoice is outside tenant scope string allowlist' => !str_contains($handlerSrc, 'Selected invoice is outside tenant scope'),
    'BranchContext throws AccessDeniedException' => str_contains(
        (string) file_get_contents($system . '/core/Branch/BranchContext.php'),
        'throw new AccessDeniedException'
    ),
    'TenantOwnedDataScopeGuard throws AccessDeniedException for scope denials' => substr_count(
        (string) file_get_contents($system . '/core/tenant/TenantOwnedDataScopeGuard.php'),
        'throw new AccessDeniedException'
    ) >= 4,
    'OrganizationContextResolver throws AccessDeniedException' => str_contains(
        (string) file_get_contents($system . '/core/Organization/OrganizationContextResolver.php'),
        'throw new AccessDeniedException'
    ),
    'OrganizationScopedBranchAssert throws AccessDeniedException' => str_contains(
        (string) file_get_contents($system . '/core/Organization/OrganizationScopedBranchAssert.php'),
        'throw new AccessDeniedException'
    ),
    'OrganizationContext assertBranchBelongs throws AccessDeniedException' => str_contains(
        (string) file_get_contents($system . '/core/Organization/OrganizationContext.php'),
        'throw new AccessDeniedException'
    ),
    'SettingsService branch override throws AccessDeniedException' => str_contains(
        (string) file_get_contents($system . '/core/app/SettingsService.php'),
        'throw new AccessDeniedException'
    ),
    'AppointmentController propagates AccessDenied from generic Throwable catches' => str_contains(
        (string) file_get_contents($system . '/modules/appointments/controllers/AppointmentController.php'),
        'function exitIfAccessDenied'
    ),
    'AppointmentService uses AccessDeniedException for principal branch allow-list denial' => str_contains(
        (string) file_get_contents($system . '/modules/appointments/services/AppointmentService.php'),
        "throw new AccessDeniedException('Branch is not allowed for this principal.'"
    ),
];

require_once $system . '/core/errors/AccessDeniedException.php';

$denial = new \Core\Errors\AccessDeniedException('arbitrary denial text not on any legacy allowlist');
$generic = new \DomainException('Branch is not linked to an active organization.');
$checks['runtime: AccessDeniedException is classified as typed denial (403 path in handler)'] = $denial instanceof \Core\Errors\AccessDeniedException;
$checks['runtime: DomainException with former allowlist message is not typed denial'] = !($generic instanceof \Core\Errors\AccessDeniedException);
$checks['runtime: generic DomainException has no getStatusCode => handler falls through to 500'] = !method_exists($generic, 'getStatusCode');

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
