<?php

declare(strict_types=1);

namespace Modules\Packages\Support;

/**
 * Canonical sell-time / grant-time package entitlement payload.
 */
final class PackageEntitlementSnapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string, mixed> $pkg packages row
     * @return array<string, mixed>
     */
    public static function fromPackageRow(array $pkg, int $packageBranchId): array
    {
        return [
            'v' => self::SCHEMA_VERSION,
            'package_id' => (int) ($pkg['id'] ?? 0),
            'package_branch_id' => $packageBranchId,
            'total_sessions' => (int) ($pkg['total_sessions'] ?? 0),
            'validity_days' => isset($pkg['validity_days']) && $pkg['validity_days'] !== '' && $pkg['validity_days'] !== null
                ? (int) $pkg['validity_days']
                : null,
            'name' => (string) ($pkg['name'] ?? ''),
            'package_status' => (string) ($pkg['status'] ?? 'active'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || (int) ($data['v'] ?? 0) !== self::SCHEMA_VERSION) {
            return null;
        }
        if ((int) ($data['package_id'] ?? 0) <= 0 || (int) ($data['total_sessions'] ?? 0) <= 0) {
            return null;
        }

        return $data;
    }

    public static function encode(array $snapshot): string
    {
        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }
}
