<?php

declare(strict_types=1);

namespace Modules\Memberships\Repositories;

use Core\App\Database;

final class MembershipBenefitUsageRepository
{
    public function __construct(private Database $db)
    {
    }

    public function countForClientMembership(int $clientMembershipId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM membership_benefit_usages WHERE client_membership_id = ?',
            [$clientMembershipId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void
    {
        $this->db->insert('membership_benefit_usages', [
            'client_membership_id' => (int) $data['client_membership_id'],
            'appointment_id' => (int) $data['appointment_id'],
            'client_id' => (int) $data['client_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockByAppointmentId(int $appointmentId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM membership_benefit_usages WHERE appointment_id = ? FOR UPDATE',
            [$appointmentId]
        );
    }

    public function deleteByAppointmentId(int $appointmentId): int
    {
        $stmt = $this->db->query(
            'DELETE FROM membership_benefit_usages WHERE appointment_id = ?',
            [$appointmentId]
        );

        return $stmt->rowCount();
    }
}
