<?php

declare(strict_types=1);

namespace Modules\Documents\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * {@see client_consents}: reads/writes are anchored through {@see OrganizationRepositoryScope::clientProfileOrgMembershipExistsClause()}
 * on {@code clients} and catalog unions on {@code document_definitions} (same families as {@see DocumentDefinitionRepository}).
 */
final class ClientConsentRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function findInTenantScope(int $id, ?int $operationBranchId): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $ddVis = $this->definitionDdVisibility($operationBranchId);
        $sql = 'SELECT cc.*, dd.code AS definition_code, dd.name AS definition_name
             FROM client_consents cc
             INNER JOIN clients c ON c.id = cc.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
             INNER JOIN document_definitions dd ON dd.id = cc.document_definition_id AND dd.deleted_at IS NULL
               AND (' . $ddVis['sql'] . ')
             WHERE cc.id = ?';
        $params = array_merge($cFrag['params'], $ddVis['params'], [$id]);

        return $this->db->fetchOne($sql, $params);
    }

    public function findByClientAndDefinitionInTenantScope(int $clientId, int $documentDefinitionId, ?int $operationBranchId): ?array
    {
        if ($clientId <= 0 || $documentDefinitionId <= 0) {
            return null;
        }
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $ddVis = $this->definitionDdVisibility($operationBranchId);
        $sql = 'SELECT cc.* FROM client_consents cc
             INNER JOIN clients c ON c.id = cc.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
             INNER JOIN document_definitions dd ON dd.id = cc.document_definition_id AND dd.deleted_at IS NULL
               AND (' . $ddVis['sql'] . ')
             WHERE cc.client_id = ? AND cc.document_definition_id = ?';
        $params = array_merge($cFrag['params'], $ddVis['params'], [$clientId, $documentDefinitionId]);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function definitionDdVisibility(?int $operationBranchId): array
    {
        if ($operationBranchId !== null && $operationBranchId > 0) {
            return $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('dd', $operationBranchId);
        }

        return $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('dd');
    }

    /**
     * @return list<array{id:int,client_id:int,document_definition_id:int,status:string,signed_at:string|null,expires_at:string|null,definition_code:string,definition_name:string}>
     */
    public function listByClientInTenantScope(int $clientId, ?int $operationBranchId): array
    {
        if ($clientId <= 0) {
            return [];
        }
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $ddVis = $this->definitionDdVisibility($operationBranchId);
        $sql = 'SELECT cc.id, cc.client_id, cc.document_definition_id, cc.status, cc.signed_at, cc.expires_at,
                       dd.code AS definition_code, dd.name AS definition_name
                FROM client_consents cc
                INNER JOIN clients c ON c.id = cc.client_id AND c.id = ? AND c.deleted_at IS NULL' . $cFrag['sql'] . '
                INNER JOIN document_definitions dd ON dd.id = cc.document_definition_id AND dd.deleted_at IS NULL
                  AND (' . $ddVis['sql'] . ')';
        $params = array_merge([$clientId], $cFrag['params'], $ddVis['params']);
        if ($operationBranchId !== null && $operationBranchId > 0) {
            $ccVis = $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('cc', $operationBranchId);
            $sql .= ' AND (' . $ccVis['sql'] . ')';
            $params = array_merge($params, $ccVis['params']);
        }
        $sql .= ' ORDER BY dd.name ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'client_id' => (int) $r['client_id'],
                'document_definition_id' => (int) $r['document_definition_id'],
                'status' => (string) $r['status'],
                'signed_at' => $r['signed_at'] !== null ? (string) $r['signed_at'] : null,
                'expires_at' => $r['expires_at'] !== null ? (string) $r['expires_at'] : null,
                'definition_code' => (string) $r['definition_code'],
                'definition_name' => (string) $r['definition_name'],
            ];
        }

        return $out;
    }

    /**
     * @throws \DomainException when client or definition is not tenant-visible for {@code $operationBranchId}
     */
    public function createInTenantScope(array $data, ?int $operationBranchId): int
    {
        $allowed = ['client_id', 'document_definition_id', 'status', 'signed_at', 'expires_at', 'branch_id', 'notes'];
        $payload = array_intersect_key($data, array_flip($allowed));
        $clientId = (int) ($payload['client_id'] ?? 0);
        $defId = (int) ($payload['document_definition_id'] ?? 0);
        if ($clientId <= 0 || $defId <= 0) {
            throw new \DomainException('client_id and document_definition_id are required for consent insert.');
        }
        $this->assertClientAndDocumentDefinitionVisibleForConsentInsert($clientId, $defId, $operationBranchId);
        $this->db->insert('client_consents', $payload);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Narrow INSERT gate: same client + definition visibility as list/find paths (no existing consent row required).
     */
    private function assertClientAndDocumentDefinitionVisibleForConsentInsert(int $clientId, int $documentDefinitionId, ?int $operationBranchId): void
    {
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $ddVis = $this->definitionDdVisibility($operationBranchId);
        $sql = 'SELECT 1 AS ok FROM clients c
             INNER JOIN document_definitions dd ON dd.id = ? AND dd.deleted_at IS NULL AND (' . $ddVis['sql'] . ')
             WHERE c.id = ? AND c.deleted_at IS NULL' . $cFrag['sql'];
        $params = array_merge([$documentDefinitionId], $ddVis['params'], [$clientId], $cFrag['params']);
        $row = $this->db->fetchOne($sql, $params);
        if ($row === null) {
            throw new \DomainException('Client or document definition is not visible for this tenant consent insert.');
        }
    }

    public function updateInTenantScope(int $id, ?int $operationBranchId, array $data): void
    {
        $allowed = ['status', 'signed_at', 'expires_at', 'notes'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if ($payload === []) {
            return;
        }
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $ddVis = $this->definitionDdVisibility($operationBranchId);
        $sets = [];
        $setParams = [];
        foreach ($payload as $k => $v) {
            $sets[] = "cc.{$k} = ?";
            $setParams[] = $v;
        }
        $sql = 'UPDATE client_consents cc
             INNER JOIN clients c ON c.id = cc.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
             INNER JOIN document_definitions dd ON dd.id = cc.document_definition_id AND dd.deleted_at IS NULL
               AND (' . $ddVis['sql'] . ')
             SET ' . implode(', ', $sets) . '
             WHERE cc.id = ?';
        $params = array_merge($cFrag['params'], $ddVis['params'], $setParams, [$id]);
        $this->db->query($sql, $params);
    }

    /**
     * @param int[] $definitionIds
     * @return array<int, array{status: string, expires_at: string|null}> definition_id => status info
     */
    public function getConsentStatusForClientAndDefinitionsInTenantScope(int $clientId, array $definitionIds, ?int $operationBranchId): array
    {
        if ($definitionIds === [] || $clientId <= 0) {
            return [];
        }
        $definitionIds = array_values(array_unique(array_filter(array_map('intval', $definitionIds), fn ($x) => $x > 0)));
        if ($definitionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($definitionIds), '?'));
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $ddVis = $this->definitionDdVisibility($operationBranchId);
        $sql = "SELECT cc.document_definition_id, cc.status, cc.expires_at FROM client_consents cc
             INNER JOIN clients c ON c.id = cc.client_id AND c.deleted_at IS NULL{$cFrag['sql']}
             INNER JOIN document_definitions dd ON dd.id = cc.document_definition_id AND dd.deleted_at IS NULL
               AND dd.id IN ($placeholders) AND ({$ddVis['sql']})
             WHERE cc.client_id = ? AND cc.document_definition_id IN ($placeholders)";
        $params = array_merge(
            $cFrag['params'],
            $definitionIds,
            $ddVis['params'],
            [$clientId],
            $definitionIds
        );
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $defId = (int) $r['document_definition_id'];
            $status = (string) $r['status'];
            $expiresAt = $r['expires_at'] !== null ? (string) $r['expires_at'] : null;
            if ($status === 'signed' && $expiresAt !== null && $expiresAt < date('Y-m-d')) {
                $status = 'expired';
            }
            $out[$defId] = ['status' => $status, 'expires_at' => $expiresAt];
        }

        return $out;
    }
}
