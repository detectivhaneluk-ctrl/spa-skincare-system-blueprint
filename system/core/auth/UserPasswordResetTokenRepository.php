<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\App\Database;

final class UserPasswordResetTokenRepository
{
    public function __construct(private Database $db)
    {
    }

    public function insert(int $userId, string $tokenHash, string $expiresAt): int
    {
        return $this->db->insert('user_password_reset_tokens', [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'used_at' => null,
        ]);
    }

    /**
     * Read-only active lookup (e.g. bind email link token into session without consuming).
     *
     * @return ?array<string, mixed>
     */
    public function findActiveByHash(string $tokenHash): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM user_password_reset_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [$tokenHash]
        );

        return $row ?: null;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findActiveByHashForUpdate(string $tokenHash): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM user_password_reset_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1 FOR UPDATE',
            [$tokenHash]
        );

        return $row ?: null;
    }

    public function markUsed(int $id): void
    {
        $this->db->query(
            'UPDATE user_password_reset_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL',
            [$id]
        );
    }

    public function revokeUnusedForUser(int $userId): void
    {
        $this->db->query(
            'UPDATE user_password_reset_tokens SET used_at = NOW()
             WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }
}
