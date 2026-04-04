<?php

declare(strict_types=1);

namespace Modules\Appointments\Repositories;

use Core\App\Database;

final class CalendarSavedViewsRepository
{
    public function __construct(private Database $db)
    {
    }

    public function countForUser(int $organizationId, int $userId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM calendar_saved_views WHERE organization_id = ? AND user_id = ?',
            [$organizationId, $userId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<array{id:int,name:string,is_default:bool,created_at:string,updated_at:string}>
     */
    public function listForUser(int $organizationId, int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, name, is_default, created_at, updated_at
             FROM calendar_saved_views
             WHERE organization_id = ? AND user_id = ?
             ORDER BY is_default DESC, name ASC',
            [$organizationId, $userId]
        );

        return array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'is_default' => !empty($r['is_default']),
                'created_at' => (string) ($r['created_at'] ?? ''),
                'updated_at' => (string) ($r['updated_at'] ?? ''),
            ];
        }, $rows);
    }

    public function find(int $organizationId, int $userId, int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, name, config_json, is_default, created_at, updated_at
             FROM calendar_saved_views
             WHERE id = ? AND organization_id = ? AND user_id = ?',
            [$id, $organizationId, $userId]
        );
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'config_json' => (string) ($row['config_json'] ?? '{}'),
            'is_default' => !empty($row['is_default']),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @throws \JsonException
     */
    public function insert(int $organizationId, int $userId, string $name, array $config, bool $isDefault): int
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->query(
            'INSERT INTO calendar_saved_views (organization_id, user_id, name, config_json, is_default)
             VALUES (?, ?, ?, ?, ?)',
            [$organizationId, $userId, $name, $json, $isDefault ? 1 : 0]
        );

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $organizationId, int $userId, int $id): void
    {
        $this->db->query(
            'DELETE FROM calendar_saved_views WHERE id = ? AND organization_id = ? AND user_id = ?',
            [$id, $organizationId, $userId]
        );
    }

    public function clearDefaultForUser(int $organizationId, int $userId): void
    {
        $this->db->query(
            'UPDATE calendar_saved_views SET is_default = 0 WHERE organization_id = ? AND user_id = ?',
            [$organizationId, $userId]
        );
    }

    public function setDefault(int $organizationId, int $userId, int $id): void
    {
        $this->clearDefaultForUser($organizationId, $userId);
        $this->db->query(
            'UPDATE calendar_saved_views SET is_default = 1 WHERE id = ? AND organization_id = ? AND user_id = ?',
            [$id, $organizationId, $userId]
        );
    }

    public function findDefault(int $organizationId, int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, name, config_json, is_default, created_at, updated_at
             FROM calendar_saved_views
             WHERE organization_id = ? AND user_id = ? AND is_default = 1
             LIMIT 1',
            [$organizationId, $userId]
        );
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'config_json' => (string) ($row['config_json'] ?? '{}'),
            'is_default' => true,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
