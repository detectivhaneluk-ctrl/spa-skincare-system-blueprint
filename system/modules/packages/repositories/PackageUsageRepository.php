<?php

declare(strict_types=1);

namespace Modules\Packages\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class PackageUsageRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function create(array $data): int
    {
        $this->db->insert('package_usages', $this->normalize($data));

        return $this->db->lastInsertId();
    }

    /**
     * Tenant-safe id read: usage must belong to a client package whose branch is in the resolved tenant org.
     */
    public function find(int $id): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cp');

        return $this->db->fetchOne(
            'SELECT pu.* FROM package_usages pu
             INNER JOIN client_packages cp ON cp.id = pu.client_package_id
             WHERE pu.id = ?' . $frag['sql'],
            array_merge([$id], $frag['params'])
        ) ?: null;
    }

    public function listByClientPackage(int $clientPackageId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM package_usages WHERE client_package_id = ? ORDER BY id DESC',
            [$clientPackageId]
        );
    }

    public function latestForClientPackage(int $clientPackageId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM package_usages WHERE client_package_id = ? ORDER BY id DESC LIMIT 1',
            [$clientPackageId]
        );
    }

    public function findReverseForUsage(int $usageId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM package_usages WHERE usage_type = 'reverse' AND reference_type = 'package_usage' AND reference_id = ? ORDER BY id DESC LIMIT 1",
            [$usageId]
        );
    }

    public function existsUsageByReference(
        int $clientPackageId,
        string $usageType,
        string $referenceType,
        int $referenceId
    ): bool {
        $row = $this->db->fetchOne(
            'SELECT id
             FROM package_usages
             WHERE client_package_id = ?
               AND usage_type = ?
               AND reference_type = ?
               AND reference_id = ?
             LIMIT 1',
            [$clientPackageId, $usageType, $referenceType, $referenceId]
        );
        return $row !== null;
    }

    public function listAppointmentConsumptions(int $appointmentId): array
    {
        return $this->db->fetchAll(
            "SELECT pu.id AS usage_id,
                    pu.client_package_id,
                    p.name AS package_name,
                    pu.quantity,
                    pu.remaining_after,
                    pu.branch_id,
                    pu.created_at
             FROM package_usages pu
             INNER JOIN client_packages cp ON cp.id = pu.client_package_id
             INNER JOIN packages p ON p.id = cp.package_id
             WHERE pu.usage_type = 'use'
               AND pu.reference_type = 'appointment'
               AND pu.reference_id = ?
             ORDER BY pu.id DESC",
            [$appointmentId]
        );
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'client_package_id', 'branch_id', 'usage_type', 'quantity', 'remaining_after',
            'reference_type', 'reference_id', 'notes', 'created_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
