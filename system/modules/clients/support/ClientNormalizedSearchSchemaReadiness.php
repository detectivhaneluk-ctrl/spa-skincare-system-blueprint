<?php

declare(strict_types=1);

namespace Modules\Clients\Support;

use Core\App\Database;

/**
 * Canonical gate for migration 119 stored columns used by client list fast paths and duplicate matching.
 *
 * @see system/data/migrations/119_clients_search_normalized_columns.sql
 */
final class ClientNormalizedSearchSchemaReadiness
{
    private const TABLE = 'clients';

    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'email_lc',
        'phone_digits',
        'phone_home_digits',
        'phone_mobile_digits',
        'phone_work_digits',
    ];

    /** User-facing explanation when duplicate / indexed search paths are intentionally skipped. */
    public const PUBLIC_UNAVAILABLE_MESSAGE = 'Client duplicate matching and indexed email/phone search require migration 119 (normalized columns on clients). Until then, duplicate checks are not run and the client list uses basic text search only.';

    private ?bool $cached = null;

    public function __construct(private Database $db)
    {
    }

    public function isReady(): bool
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $placeholders = implode(',', array_fill(0, count(self::REQUIRED_COLUMNS), '?'));
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME IN ({$placeholders})",
            array_merge([self::TABLE], self::REQUIRED_COLUMNS)
        );
        $n = (int) ($row['c'] ?? 0);
        $this->cached = $n === count(self::REQUIRED_COLUMNS);

        return $this->cached;
    }
}
