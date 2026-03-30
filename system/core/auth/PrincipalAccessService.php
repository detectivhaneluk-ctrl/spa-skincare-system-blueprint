<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\App\Database;

/**
 * FOUNDATION-100 principal-plane classifier.
 *
 * Platform principal is explicit by role code, not inferred from permission strings.
 */
final class PrincipalAccessService
{
    /** @var list<string> */
    private const PLATFORM_ROLE_CODES = ['platform_founder'];

    public function __construct(private Database $db)
    {
    }

    public function isPlatformPrincipal(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $placeholders = implode(', ', array_fill(0, count(self::PLATFORM_ROLE_CODES), '?'));
        $params = array_merge([$userId], self::PLATFORM_ROLE_CODES);
        $row = $this->db->fetchOne(
            "SELECT 1
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.deleted_at IS NULL AND r.code IN ({$placeholders})
             LIMIT 1",
            $params
        );

        return $row !== null;
    }
}
