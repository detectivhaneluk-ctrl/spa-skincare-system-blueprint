<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

/**
 * Legacy registry routes redirect to the salon-centric founder surfaces.
 *
 * @see PlatformSalonController
 */
final class PlatformOrganizationRegistryController
{
    public function index(): void
    {
        $qs = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        header('Location: /platform-admin/salons' . ($qs !== '' ? '?' . $qs : ''), true, 302);
        exit;
    }

    public function show(int $id): void
    {
        header('Location: /platform-admin/salons/' . (int) $id, true, 302);
        exit;
    }
}
