<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\App\Database;

/**
 * Durable session revocation counter per user (logout-all / security events).
 * Works with Redis or file session handlers: {@see SessionAuth::SESSION_EPOCH_KEY} must match DB.
 */
final class UserSessionEpochRepository
{
    public function __construct(private Database $db)
    {
    }

    public function getSessionVersion(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT session_version AS v FROM users WHERE id = ? AND deleted_at IS NULL',
                [$userId]
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'session_version')) {
                return 0;
            }
            throw $e;
        }

        return (int) ($row['v'] ?? 0);
    }

    public function incrementSessionVersion(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $this->db->query(
                'UPDATE users SET session_version = session_version + 1 WHERE id = ? AND deleted_at IS NULL',
                [$userId]
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'session_version')) {
                return;
            }
            throw $e;
        }
    }
}
