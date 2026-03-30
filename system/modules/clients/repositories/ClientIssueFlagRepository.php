<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class ClientIssueFlagRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function find(int $id): ?array
    {
        $join = $this->orgScope->clientIssueFlagTenantJoinSql('f', 'c');
        $params = array_merge($join['params'], [$id]);
        $sql = 'SELECT f.* FROM client_issue_flags f ' . $join['sql'] . ' WHERE f.id = ?';

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    public function listByClient(int $clientId, ?string $status = null, int $limit = 50): array
    {
        $limit = max(1, (int) $limit);
        $join = $this->orgScope->clientIssueFlagTenantJoinSql('f', 'c');
        $sql = 'SELECT f.* FROM client_issue_flags f ' . $join['sql'] . ' WHERE f.client_id = ?';
        $params = array_merge($join['params'], [$clientId]);
        if ($status !== null && $status !== '') {
            $sql .= ' AND f.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY f.created_at DESC LIMIT ?';
        $params[] = $limit;

        return $this->db->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        $allowed = [
            'client_id',
            'branch_id',
            'type',
            'status',
            'title',
            'notes',
            'created_by',
            'resolved_by',
            'resolved_at',
        ];
        $this->db->insert('client_issue_flags', array_intersect_key($data, array_flip($allowed)));

        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $allowed = ['status', 'title', 'notes', 'resolved_by', 'resolved_at', 'type', 'branch_id'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if ($payload === []) {
            return;
        }
        $cols = [];
        $vals = [];
        foreach ($payload as $k => $v) {
            $cols[] = 'f.' . $k . ' = ?';
            $vals[] = $v;
        }
        $join = $this->orgScope->clientIssueFlagTenantJoinSql('f', 'c');
        $vals = array_merge($join['params'], $vals, [$id]);
        $this->db->query(
            'UPDATE client_issue_flags f ' . $join['sql'] . ' SET ' . implode(', ', $cols) . ' WHERE f.id = ?',
            $vals
        );
    }
}

