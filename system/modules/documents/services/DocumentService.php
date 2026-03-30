<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Organization\OrganizationRepositoryScope;
use Core\Storage\Contracts\StorageProviderInterface;
use Core\Storage\StorageKey;
use Core\Tenant\TenantOwnedDataScopeGuard;
use Modules\Documents\Repositories\DocumentRepository;

final class DocumentService
{
    private const ALLOWED_OWNER_TYPES = ['client', 'appointment', 'invoice', 'staff'];
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    private const MAX_FILE_BYTES = 10485760; // 10MB
    /** DB + disk layout: only files under this relative prefix are eligible for download. */
    private const STORAGE_DOCUMENTS_PREFIX = 'storage/documents/';

    public function __construct(
        private DocumentRepository $documents,
        private AuditService $audit,
        private Database $db,
        private TenantOwnedDataScopeGuard $tenantScopeGuard,
        private OrganizationRepositoryScope $orgScope,
        private StorageProviderInterface $storage,
    ) {
    }

    public function registerUpload(array $file, array $owner): array
    {
        $ownerType = $this->normalizeOwnerType((string) ($owner['owner_type'] ?? ''));
        $ownerId = (int) ($owner['owner_id'] ?? 0);
        if ($ownerId <= 0) {
            throw new \InvalidArgumentException('owner_id is required.');
        }

        $ownerInfo = $this->resolveOwner($ownerType, $ownerId);
        $branchId = $ownerInfo['branch_id'];

        $normalizedFile = $this->validateAndNormalizeUpload($file);
        $stored = $this->storeUploadedFile($normalizedFile);

        return $this->transactional(function () use ($normalizedFile, $stored, $ownerType, $ownerId, $branchId): array {
            try {
                $documentId = $this->documents->createDocument([
                    'branch_id' => $branchId,
                    'original_name' => $normalizedFile['original_name'],
                    'stored_name' => $stored['stored_name'],
                    'mime_type' => $stored['mime_type'],
                    'extension' => $stored['extension'],
                    'size_bytes' => $stored['size_bytes'],
                    'storage_disk' => 'local',
                    'storage_path' => $stored['storage_path'],
                    'checksum_sha256' => $stored['checksum_sha256'],
                    'status' => 'active',
                    'uploaded_by' => $this->currentUserId(),
                    'updated_by' => $this->currentUserId(),
                ]);

                $linkId = $this->documents->createLink([
                    'document_id' => $documentId,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'branch_id' => $branchId,
                    'status' => 'active',
                    'linked_by' => $this->currentUserId(),
                    'updated_by' => $this->currentUserId(),
                ]);

                $this->audit->log('document_uploaded', 'document', $documentId, $this->currentUserId(), $branchId, [
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'link_id' => $linkId,
                    'mime_type' => $stored['mime_type'],
                    'size_bytes' => $stored['size_bytes'],
                ]);

                return [
                    'document_id' => $documentId,
                    'link_id' => $linkId,
                ];
            } catch (\Throwable $e) {
                if (isset($stored['storage_key']) && $stored['storage_key'] instanceof StorageKey) {
                    $this->storage->deleteFileIfExists($stored['storage_key']);
                }
                throw $e;
            }
        }, 'document upload/register');
    }

    public function listByOwner(string $ownerType, int $ownerId): array
    {
        $ownerType = $this->normalizeOwnerType($ownerType);
        $owner = $this->resolveOwner($ownerType, $ownerId);

        return $this->documents->listByOwnerInTenant($ownerType, $ownerId, $owner['branch_id']);
    }

    public function showMetadata(int $documentId): array
    {
        return $this->loadDocumentForScopedRead($documentId);
    }

    /**
     * Authenticated internal binary delivery only. Same branch + existence rules as {@see showMetadata()}.
     * Validates stored path stays under storage/documents (no traversal). Does not expose paths in HTTP body.
     */
    public function deliverAuthenticatedDownload(int $documentId): void
    {
        $doc = $this->loadDocumentForScopedRead($documentId);
        $key = StorageKey::fromDocumentsModuleStoragePath((string) ($doc['storage_path'] ?? ''));
        if ($key === null || !$this->storage->isReadableFile($key)) {
            throw new \RuntimeException('Document not found.');
        }
        $length = $this->storage->fileSizeOrFail($key);

        $branchId = (int) $doc['branch_id'];
        $link = $this->documents->findFirstActiveLinkForDocumentInTenant($documentId, $branchId);
        $auditMeta = [
            'mime_type' => (string) $doc['mime_type'],
            'size_bytes' => (int) $doc['size_bytes'],
        ];
        if ($link !== null) {
            $auditMeta['link_id'] = (int) $link['id'];
            $auditMeta['owner_type'] = (string) $link['owner_type'];
            $auditMeta['owner_id'] = (int) $link['owner_id'];
        }
        $this->audit->log('document_downloaded', 'document', $documentId, $this->currentUserId(), $branchId, $auditMeta);

        $this->emitAttachmentDownload(
            $key,
            (string) $doc['mime_type'],
            (string) $doc['original_name'],
            $length
        );
    }

    /**
     * Shared read gate for document metadata and binary delivery (not broader than previous showMetadata behavior).
     *
     * @return array<string, mixed>
     */
    private function loadDocumentForScopedRead(int $documentId): array
    {
        $scope = $this->tenantScopeGuard->requireResolvedTenantScope();
        $doc = $this->documents->findDocumentInTenant($documentId, $scope['branch_id']);
        if (!$doc) {
            throw new \RuntimeException('Document not found.');
        }

        return $doc;
    }

    private function emitAttachmentDownload(StorageKey $key, string $mimeType, string $downloadName, int $sizeBytes): void
    {
        $safeAscii = $this->asciiFilenameForContentDisposition($downloadName);
        $baseName = basename(str_replace('\\', '/', $downloadName));
        $utf8Star = rawurlencode($baseName !== '' ? $baseName : 'document');
        $quotedAscii = str_replace(['\\', '"'], ['\\\\', '\\"'], $safeAscii);

        if (!headers_sent()) {
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . (string) $sizeBytes);
            header('Content-Disposition: attachment; filename="' . $quotedAscii . '"; filename*=UTF-8\'\'' . $utf8Star);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
        }

        $this->storage->readStreamToOutput($key);
        exit;
    }

    private function asciiFilenameForContentDisposition(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        if ($base === '' || $base === '.' || $base === '..') {
            return 'document';
        }
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $base);

        return is_string($ascii) && $ascii !== '' ? $ascii : 'document';
    }

    public function relink(int $documentId, array $owner): void
    {
        $scope = $this->tenantScopeGuard->requireResolvedTenantScope();
        $doc = $this->documents->findDocumentInTenant($documentId, $scope['branch_id']);
        if (!$doc) {
            throw new \RuntimeException('Document not found.');
        }

        $ownerType = $this->normalizeOwnerType((string) ($owner['owner_type'] ?? ''));
        $ownerId = (int) ($owner['owner_id'] ?? 0);
        if ($ownerId <= 0) {
            throw new \InvalidArgumentException('owner_id is required.');
        }
        $ownerInfo = $this->resolveOwner($ownerType, $ownerId);

        if ($this->documents->findActiveLinkInTenant($documentId, $ownerType, $ownerId, $scope['branch_id']) !== null) {
            throw new \DomainException('Document is already attached to this owner.');
        }

        $this->transactional(function () use ($documentId, $ownerType, $ownerId, $ownerInfo): void {
            $linkId = $this->documents->createLink([
                'document_id' => $documentId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'branch_id' => $ownerInfo['branch_id'],
                'status' => 'active',
                'linked_by' => $this->currentUserId(),
                'updated_by' => $this->currentUserId(),
            ]);
            $this->audit->log('document_relinked', 'document_link', $linkId, $this->currentUserId(), $ownerInfo['branch_id'], [
                'document_id' => $documentId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
            ]);
        }, 'document relink');
    }

    public function detach(int $documentId, string $ownerType, int $ownerId): void
    {
        $ownerType = $this->normalizeOwnerType($ownerType);
        if ($ownerId <= 0) {
            throw new \InvalidArgumentException('owner_id is required.');
        }
        $ownerInfo = $this->resolveOwner($ownerType, $ownerId);

        $link = $this->documents->findActiveLinkInTenant($documentId, $ownerType, $ownerId, $ownerInfo['branch_id']);
        if (!$link) {
            throw new \DomainException('Active document link not found.');
        }

        $this->transactional(function () use ($link, $ownerInfo): void {
            $this->documents->detachLinkInTenant((int) $link['id'], $ownerInfo['branch_id'], $this->currentUserId());
            $this->audit->log('document_detached', 'document_link', (int) $link['id'], $this->currentUserId(), $ownerInfo['branch_id'], [
                'document_id' => (int) $link['document_id'],
                'owner_type' => (string) $link['owner_type'],
                'owner_id' => (int) $link['owner_id'],
            ]);
        }, 'document detach');
    }

    public function archive(int $documentId): void
    {
        $doc = $this->loadDocumentForScopedRead($documentId);
        $branchId = (int) $doc['branch_id'];

        $this->transactional(function () use ($documentId, $branchId): void {
            $this->documents->updateDocumentInTenant($documentId, $branchId, [
                'status' => 'archived',
                'updated_by' => $this->currentUserId(),
            ]);
            $this->audit->log('document_archived', 'document', $documentId, $this->currentUserId(), $branchId, []);
        }, 'document archive');
    }

    public function deleteSoft(int $documentId): void
    {
        $doc = $this->loadDocumentForScopedRead($documentId);
        $branchId = (int) $doc['branch_id'];

        $this->transactional(function () use ($documentId, $branchId): void {
            $this->documents->softDeleteLinksByDocumentInTenant($documentId, $branchId, $this->currentUserId());
            $this->documents->softDeleteDocumentInTenant($documentId, $branchId, $this->currentUserId());
            $this->audit->log('document_deleted', 'document', $documentId, $this->currentUserId(), $branchId, []);
        }, 'document delete');
    }

    private function normalizeOwnerType(string $ownerType): string
    {
        $normalized = strtolower(trim($ownerType));
        if (!in_array($normalized, self::ALLOWED_OWNER_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported owner_type.');
        }
        return $normalized;
    }

    private function resolveOwner(string $ownerType, int $ownerId): array
    {
        $scope = $this->tenantScopeGuard->requireResolvedTenantScope();

        return match ($ownerType) {
            'client' => $this->fetchBranchScopedTableOwner('clients', $ownerId, $scope),
            'appointment' => $this->fetchBranchScopedTableOwner('appointments', $ownerId, $scope),
            'invoice' => $this->tenantScopeGuard->requireInvoiceBranchForDocumentOwner($ownerId),
            'staff' => $this->fetchBranchScopedTableOwner('staff', $ownerId, $scope),
            default => throw new \InvalidArgumentException('Unsupported owner_type.')
        };
    }

    /**
     * @param array{organization_id: int, branch_id: int} $scope
     * @return array{id: int, branch_id: int}
     */
    private function fetchBranchScopedTableOwner(string $table, int $ownerId, array $scope): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('t');
        $sql = "SELECT t.id, t.branch_id FROM {$table} t
                WHERE t.id = ? AND t.deleted_at IS NULL AND t.branch_id = ?" . $frag['sql'];
        $row = $this->db->fetchOne($sql, array_merge([$ownerId, $scope['branch_id']], $frag['params']));
        if ($row === null) {
            throw new \DomainException('Owner not found.');
        }

        return ['id' => $ownerId, 'branch_id' => (int) $row['branch_id']];
    }

    private function validateAndNormalizeUpload(array $file): array
    {
        if (!isset($file['tmp_name'], $file['name'], $file['size']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
            throw new \InvalidArgumentException('File is required.');
        }
        $tmpPath = $file['tmp_name'];
        if (!is_file($tmpPath)) {
            throw new \InvalidArgumentException('Uploaded file is invalid.');
        }
        $size = (int) $file['size'];
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('File size is invalid or exceeds 10MB.');
        }

        $originalName = trim((string) $file['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported file extension.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpPath);
        $allowedMime = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ];
        if (!in_array($mime, $allowedMime, true)) {
            throw new \InvalidArgumentException('Unsupported file type.');
        }

        return [
            'tmp_path' => $tmpPath,
            'size' => $size,
            'original_name' => $originalName !== '' ? $originalName : 'file.' . $ext,
            'extension' => $ext,
            'mime_type' => $mime,
        ];
    }

    private function storeUploadedFile(array $normalizedFile): array
    {
        $datePath = date('Y/m');
        $storedName = bin2hex(random_bytes(16)) . '.' . $normalizedFile['extension'];
        $rel = $datePath . '/' . $storedName;
        $destKey = StorageKey::documents($rel);
        $this->storage->ensureParentDirectoryExists($destKey);
        $isUpload = is_uploaded_file($normalizedFile['tmp_path']);
        $this->storage->importLocalFile($normalizedFile['tmp_path'], $destKey, $isUpload);

        return [
            'stored_name' => $storedName,
            'storage_path' => self::STORAGE_DOCUMENTS_PREFIX . $rel,
            'storage_key' => $destKey,
            'size_bytes' => $this->storage->fileSizeOrFail($destKey),
            'mime_type' => $normalizedFile['mime_type'],
            'extension' => $normalizedFile['extension'],
            'checksum_sha256' => $this->storage->computeSha256HexForKey($destKey),
        ];
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $callback();
            if ($started) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'documents.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Document operation failed.');
        }
    }
}
