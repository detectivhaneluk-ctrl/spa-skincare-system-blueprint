<?php

declare(strict_types=1);

namespace Modules\Documents\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class DocumentRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    public function createDocument(array $data): int
    {
        $this->db->insert('documents', $this->normalizeDocument($data));
        return (int) $this->db->lastInsertId();
    }

    /**
     * Tenant-protected read: document must belong to {@see $branchId} and to an active branch in the resolved organization.
     */
    public function findDocumentInTenant(int $id, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('d');
        $sql = 'SELECT d.* FROM documents d
                WHERE d.id = ? AND d.deleted_at IS NULL AND d.branch_id = ?' . $frag['sql'];

        return $this->db->fetchOne($sql, array_merge([$id, $branchId], $frag['params']));
    }

    public function updateDocumentInTenant(int $id, int $branchId, array $data): void
    {
        $norm = $this->normalizeDocument($data);
        if ($norm === []) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('d');
        $cols = array_map(fn (string $k): string => 'd.' . $k . ' = ?', array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals[] = $branchId;
        $this->db->query(
            'UPDATE documents d SET ' . implode(', ', $cols)
            . ' WHERE d.id = ? AND d.deleted_at IS NULL AND d.branch_id = ?' . $frag['sql'],
            array_merge($vals, $frag['params'])
        );
    }

    public function softDeleteDocumentInTenant(int $id, int $branchId, ?int $updatedBy): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('d');
        $this->db->query(
            'UPDATE documents d SET d.deleted_at = NOW(), d.updated_by = ?
             WHERE d.id = ? AND d.deleted_at IS NULL AND d.branch_id = ?' . $frag['sql'],
            array_merge([$updatedBy, $id, $branchId], $frag['params'])
        );
    }

    public function createLink(array $data): int
    {
        $this->db->insert('document_links', $this->normalizeLink($data));
        return (int) $this->db->lastInsertId();
    }

    public function findActiveLinkInTenant(int $documentId, string $ownerType, int $ownerId, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('d');
        $sql = 'SELECT dl.* FROM document_links dl
                INNER JOIN documents d ON d.id = dl.document_id AND d.deleted_at IS NULL
                WHERE dl.document_id = ? AND dl.owner_type = ? AND dl.owner_id = ?
                  AND dl.status = ? AND dl.deleted_at IS NULL
                  AND d.branch_id = ?' . $frag['sql'];

        return $this->db->fetchOne(
            $sql,
            array_merge([$documentId, $ownerType, $ownerId, 'active', $branchId], $frag['params'])
        );
    }

    /**
     * First active link for audit / download context (deterministic order).
     *
     * @return array<string, mixed>|null
     */
    public function findFirstActiveLinkForDocumentInTenant(int $documentId, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('d');
        $sql = 'SELECT dl.id, dl.owner_type, dl.owner_id, dl.branch_id
                FROM document_links dl
                INNER JOIN documents d ON d.id = dl.document_id AND d.deleted_at IS NULL
                WHERE dl.document_id = ? AND dl.status = ? AND dl.deleted_at IS NULL
                  AND d.branch_id = ?' . $frag['sql'] . '
                ORDER BY dl.created_at ASC
                LIMIT 1';

        return $this->db->fetchOne(
            $sql,
            array_merge([$documentId, 'active', $branchId], $frag['params'])
        );
    }

    public function listByOwnerInTenant(string $ownerType, int $ownerId, int $branchId): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('d');
        $sql = 'SELECT dl.*, d.original_name, d.stored_name, d.mime_type, d.extension, d.size_bytes, d.storage_path, d.status AS document_status, d.deleted_at AS document_deleted_at
                FROM document_links dl
                INNER JOIN documents d ON d.id = dl.document_id AND d.deleted_at IS NULL
                WHERE dl.owner_type = ? AND dl.owner_id = ? AND dl.status = ? AND dl.deleted_at IS NULL
                  AND d.branch_id = ?' . $frag['sql'] . '
                ORDER BY dl.created_at DESC';

        return $this->db->fetchAll(
            $sql,
            array_merge([$ownerType, $ownerId, 'active', $branchId], $frag['params'])
        );
    }

    public function updateLink(int $id, array $data): void
    {
        $norm = $this->normalizeLink($data);
        if ($norm === []) {
            return;
        }
        $cols = array_map(fn (string $k): string => $k . ' = ?', array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE document_links SET ' . implode(', ', $cols) . ' WHERE id = ? AND deleted_at IS NULL', $vals);
    }

    public function detachLinkInTenant(int $id, int $branchId, ?int $updatedBy): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('d');
        $this->db->query(
            'UPDATE document_links dl
             INNER JOIN documents d ON d.id = dl.document_id AND d.deleted_at IS NULL
             SET dl.status = ?, dl.updated_by = ?, dl.updated_at = NOW()
             WHERE dl.id = ? AND dl.deleted_at IS NULL AND d.branch_id = ?' . $frag['sql'],
            array_merge(['detached', $updatedBy, $id, $branchId], $frag['params'])
        );
    }

    public function softDeleteLinksByDocumentInTenant(int $documentId, int $branchId, ?int $updatedBy): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('d');
        $this->db->query(
            'UPDATE document_links dl
             INNER JOIN documents d ON d.id = dl.document_id AND d.deleted_at IS NULL
             SET dl.deleted_at = NOW(), dl.status = ?, dl.updated_by = ?
             WHERE dl.document_id = ? AND dl.deleted_at IS NULL AND d.branch_id = ?' . $frag['sql'],
            array_merge(['detached', $updatedBy, $documentId, $branchId], $frag['params'])
        );
    }

    private function normalizeDocument(array $data): array
    {
        $allowed = [
            'branch_id',
            'original_name',
            'stored_name',
            'mime_type',
            'extension',
            'size_bytes',
            'storage_disk',
            'storage_path',
            'checksum_sha256',
            'status',
            'uploaded_by',
            'updated_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }

    private function normalizeLink(array $data): array
    {
        $allowed = [
            'document_id',
            'owner_type',
            'owner_id',
            'branch_id',
            'status',
            'linked_by',
            'updated_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
