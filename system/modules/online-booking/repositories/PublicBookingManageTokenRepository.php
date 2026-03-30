<?php

declare(strict_types=1);

namespace Modules\OnlineBooking\Repositories;

use Core\App\Database;

final class PublicBookingManageTokenRepository
{
    public function __construct(private Database $db)
    {
    }

    public function upsertForAppointment(int $appointmentId, int $branchId, string $tokenHash, string $expiresAt): void
    {
        $this->db->query(
            'INSERT INTO public_booking_manage_tokens (appointment_id, branch_id, token_hash, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), revoked_at = NULL, updated_at = UTC_TIMESTAMP()',
            [$appointmentId, $branchId, $tokenHash, $expiresAt]
        );
    }

    public function touchLastUsed(int $tokenId): void
    {
        $this->db->query(
            'UPDATE public_booking_manage_tokens SET last_used_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$tokenId]
        );
    }

    public function revokeByAppointmentId(int $appointmentId): void
    {
        $this->db->query(
            'UPDATE public_booking_manage_tokens
             SET revoked_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE appointment_id = ? AND revoked_at IS NULL',
            [$appointmentId]
        );
    }

    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT t.id AS token_id, a.id AS appointment_id, a.status, a.start_at, a.end_at, a.branch_id,
                    b.name AS branch_name,
                    s.id AS service_id, s.name AS service_name,
                    st.id AS staff_id, st.first_name AS staff_first_name, st.last_name AS staff_last_name
             FROM public_booking_manage_tokens t
             INNER JOIN appointments a ON a.id = t.appointment_id AND a.deleted_at IS NULL
             LEFT JOIN branches b ON b.id = a.branch_id
             LEFT JOIN services s ON s.id = a.service_id AND s.deleted_at IS NULL
             LEFT JOIN staff st ON st.id = a.staff_id AND st.deleted_at IS NULL
             WHERE t.token_hash = ?
               AND t.revoked_at IS NULL
               AND t.expires_at > UTC_TIMESTAMP()
             LIMIT 1',
            [$tokenHash]
        );

        return $row ?: null;
    }
}

