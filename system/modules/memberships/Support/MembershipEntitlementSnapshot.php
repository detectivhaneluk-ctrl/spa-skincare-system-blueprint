<?php

declare(strict_types=1);

namespace Modules\Memberships\Support;

/**
 * Canonical sell-time / grant-time membership entitlement payload (JSON-serialized on sale + client_memberships rows).
 */
final class MembershipEntitlementSnapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string, mixed> $def membership_definitions row (branch-owned)
     * @return array<string, mixed>
     */
    public static function fromDefinitionRow(array $def, int $definitionBranchId): array
    {
        $id = (int) ($def['id'] ?? 0);

        return [
            'v' => self::SCHEMA_VERSION,
            'membership_definition_id' => $id,
            'definition_branch_id' => $definitionBranchId,
            'duration_days' => (int) ($def['duration_days'] ?? 0),
            'benefits_json' => $def['benefits_json'] ?? null,
            'name' => (string) ($def['name'] ?? ''),
            'definition_status' => (string) ($def['status'] ?? 'active'),
            'billing_enabled' => !empty($def['billing_enabled']) ? 1 : 0,
            'billing_interval_unit' => $def['billing_interval_unit'] ?? null,
            'billing_interval_count' => isset($def['billing_interval_count']) && $def['billing_interval_count'] !== '' && $def['billing_interval_count'] !== null
                ? (int) $def['billing_interval_count']
                : null,
            'renewal_price' => $def['renewal_price'] ?? null,
            'renewal_invoice_due_days' => (int) ($def['renewal_invoice_due_days'] ?? 14),
            'billing_auto_renew_enabled' => !empty($def['billing_auto_renew_enabled']) ? 1 : 0,
            'price' => $def['price'] ?? null,
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
        if ((int) ($data['membership_definition_id'] ?? 0) <= 0 || (int) ($data['duration_days'] ?? 0) <= 0) {
            return null;
        }

        return $data;
    }

    public static function encode(array $snapshot): string
    {
        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }
}
