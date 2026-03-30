<?php

declare(strict_types=1);

namespace Modules\Organizations\Repositories;

use Core\App\Database;

/**
 * Persistence for control-plane TOTP enrollment (founder MFA foundation).
 */
final class ControlPlaneTotpUserRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{control_plane_totp_secret_ciphertext: ?string, control_plane_totp_enabled: int}|null
     */
    public function fetchTotpRow(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT control_plane_totp_secret_ciphertext, control_plane_totp_enabled FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId]
        );

        return $row === null ? null : $row;
    }

    public function setTotpSecretAndEnable(int $userId, string $ciphertextBinary): void
    {
        $this->db->query(
            'UPDATE users SET control_plane_totp_secret_ciphertext = ?, control_plane_totp_enabled = 1 WHERE id = ? AND deleted_at IS NULL',
            [$ciphertextBinary, $userId]
        );
    }

    public function clearTotp(int $userId): void
    {
        $this->db->query(
            'UPDATE users SET control_plane_totp_secret_ciphertext = NULL, control_plane_totp_enabled = 0 WHERE id = ?',
            [$userId]
        );
    }
}
