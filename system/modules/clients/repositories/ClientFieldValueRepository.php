<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class ClientFieldValueRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * @return array<int, array{id:int,field_definition_id:int,field_key:string,label:string,field_type:string,value_text:string|null}>
     */
    public function listByClientId(int $clientId): array
    {
        $tenant = $this->orgScope->clientFieldDefinitionTenantBranchClause('d');
        $params = array_merge([$clientId], $tenant['params']);

        return $this->db->fetchAll(
            'SELECT v.id, v.field_definition_id, v.value_text, d.field_key, d.label, d.field_type
             FROM client_field_values v
             INNER JOIN client_field_definitions d ON d.id = v.field_definition_id AND d.deleted_at IS NULL' . $tenant['sql'] . '
             WHERE v.client_id = ?
             ORDER BY d.sort_order ASC, d.id ASC',
            $params
        );
    }

    /**
     * Atomic upsert for many field values (same unique key as single upsert).
     *
     * @param array<int, string|null> $fieldDefinitionIdToValue definition id => value_text
     */
    public function bulkUpsertValues(int $clientId, array $fieldDefinitionIdToValue): void
    {
        if ($fieldDefinitionIdToValue === []) {
            return;
        }
        $tenant = $this->orgScope->clientFieldDefinitionTenantBranchClause('d');
        $ids = array_keys($fieldDefinitionIdToValue);
        $ids = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $ids)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $allowedRows = $this->db->fetchAll(
            "SELECT d.id FROM client_field_definitions d WHERE d.id IN ({$in}) AND d.deleted_at IS NULL" . $tenant['sql'],
            array_merge($ids, $tenant['params'])
        );
        $allowed = [];
        foreach ($allowedRows as $r) {
            $allowed[(int) $r['id']] = true;
        }
        $placeholders = [];
        $params = [];
        foreach ($fieldDefinitionIdToValue as $fieldDefinitionId => $valueText) {
            $fid = (int) $fieldDefinitionId;
            if ($fid <= 0 || !isset($allowed[$fid])) {
                continue;
            }
            $placeholders[] = '(?, ?, ?)';
            $params[] = $clientId;
            $params[] = $fid;
            $params[] = $valueText;
        }
        if ($placeholders === []) {
            return;
        }
        $sql = 'INSERT INTO client_field_values (client_id, field_definition_id, value_text) VALUES '
            . implode(', ', $placeholders)
            . ' ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_at = NOW()';
        $this->db->query($sql, $params);
    }

    public function deleteByClientId(int $clientId): void
    {
        $this->db->query('DELETE FROM client_field_values WHERE client_id = ?', [$clientId]);
    }
}
