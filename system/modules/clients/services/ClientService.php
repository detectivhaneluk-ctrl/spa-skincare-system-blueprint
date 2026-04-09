<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Errors\AccessDeniedException;
use Core\Errors\SafeDomainException;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;
use Core\Organization\OrganizationScopedBranchAssert;
use Modules\Clients\Repositories\ClientFieldDefinitionRepository;
use Modules\Clients\Repositories\ClientFieldValueRepository;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Clients\Support\ClientCanonicalPhone;

final class ClientService
{
    private const CLIENT_NOTE_MAX_LENGTH = 10000;

    public function __construct(
        private ClientRepository $repo,
        private AuditService $audit,
        private Database $db,
        private ClientFieldDefinitionRepository $fieldDefinitions,
        private ClientFieldValueRepository $fieldValues,
        private RequestContextHolder $contextHolder,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
        private AuthorizerInterface $authorizer,
    ) {
    }

    public function getDisplayName(array $client): string
    {
        return trim($client['first_name'] . ' ' . $client['last_name']);
    }

    /**
     * Canonical primary phone for list/profile/duplicate matching (mobile → home → work → legacy {@code phone}).
     *
     * @param array<string, mixed> $client
     */
    public function getCanonicalPrimaryPhone(array $client): ?string
    {
        return ClientCanonicalPhone::displayPrimary($client);
    }

    /**
     * @see system/docs/CLIENT-BACKEND-CONTRACT-FREEZE.md §2–§3 for payload and phone persistence.
     */
    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::CLIENT_CREATE, ResourceRef::collection('client'));
            if (!isset($data['branch_id']) || $data['branch_id'] === '' || $data['branch_id'] === null) {
                $data['branch_id'] = $scope['branch_id'];
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization(
                isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
                    ? (int) $data['branch_id']
                    : null
            );
            $customFields = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
            unset($data['custom_fields']);
            $this->finalizeContactAddressForPersistence($data, null);
            $userId = $this->currentUserId();
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;
            $id = $this->repo->create($data);
            $this->saveClientCustomFieldValues($id, $customFields, $data);
            $this->audit->log('client_created', 'client', $id, $userId, $data['branch_id'] ?? null, [
                'client' => $data,
            ]);
            return $id;
        }, 'client create');
    }

    /**
     * Updates only {@code clients.notes} and {@code updated_by}. Avoids {@see finalizeContactAddressForPersistence}
     * so partial saves from Commentaires cannot clear contact/address flags.
     */
    public function updateProfileNotes(int $id, ?string $notes): void
    {
        $this->transactional(function () use ($id, $notes): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::CLIENT_MODIFY, ResourceRef::instance('client', $id));
            $current = $this->repo->find($id);
            if (!$current) {
                throw new \RuntimeException('Client not found');
            }
            $entityBranchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            if ($entityBranchId !== null && $entityBranchId !== $scope['branch_id']) {
                throw new AccessDeniedException('Client is not accessible in the current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($entityBranchId);
            $userId = $this->currentUserId();
            $patch = ['notes' => $notes, 'updated_by' => $userId];
            $this->repo->update($id, $patch);
            $this->audit->log('client_updated', 'client', $id, $userId, $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => array_merge($current, $patch),
                'profile_notes_only' => true,
            ]);
        }, 'client profile notes');
    }

    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::CLIENT_MODIFY, ResourceRef::instance('client', $id));
            $current = $this->repo->find($id);
            if (!$current) {
                throw new \RuntimeException('Client not found');
            }
            $entityBranchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            if ($entityBranchId !== null && $entityBranchId !== $scope['branch_id']) {
                throw new AccessDeniedException('Client is not accessible in the current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($entityBranchId);
            $customFields = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
            unset($data['custom_fields']);
            $this->finalizeContactAddressForPersistence($data, $current);
            $userId = $this->currentUserId();
            $data['updated_by'] = $userId;
            $this->repo->update($id, $data);
            $this->saveClientCustomFieldValues($id, $customFields, $current);
            $this->audit->log('client_updated', 'client', $id, $userId, $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => array_merge($current, $data),
            ]);
        }, 'client update');
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::CLIENT_DELETE, ResourceRef::instance('client', $id));
            $client = $this->repo->find($id);
            if (!$client) {
                throw new \RuntimeException('Client not found');
            }
            $entityBranchId = $client['branch_id'] !== null && $client['branch_id'] !== '' ? (int) $client['branch_id'] : null;
            if ($entityBranchId !== null && $entityBranchId !== $scope['branch_id']) {
                throw new AccessDeniedException('Client is not accessible in the current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($entityBranchId);
            $this->repo->softDelete($id);
            $this->audit->log('client_deleted', 'client', $id, $this->currentUserId(), $client['branch_id'] ?? null, [
                'client' => $client,
            ]);
        }, 'client delete');
    }

    /**
     * Soft-delete multiple clients; each id uses the same rules as {@see delete()}.
     * Invalid, cross-branch, or unauthorized ids are skipped (no partial transaction rollback of successful deletes).
     *
     * @param list<int|string> $ids
     * @return array{deleted: int, skipped: int}
     */
    public function bulkDelete(array $ids): array
    {
        $unique = [];
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $unique[$id] = true;
            }
        }
        $uniqueIds = array_keys($unique);
        $deleted = 0;
        $skipped = 0;
        foreach ($uniqueIds as $id) {
            try {
                $this->delete($id);
                $deleted++;
            } catch (AccessDeniedException | SafeDomainException | \RuntimeException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Structured CRM note (`client_notes` row). Target audit remains `client` so profile history query stays aligned.
     */
    public function addClientNote(int $clientId, string $content): int
    {
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('Note content is required.');
        }
        if (strlen($content) > self::CLIENT_NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException('Note exceeds maximum length.');
        }

        return $this->transactional(function () use ($clientId, $content): int {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $client = $this->repo->find($clientId);
            if (!$client) {
                throw new \RuntimeException('Client not found');
            }
            $entityBranchId = $client['branch_id'] !== null && $client['branch_id'] !== '' ? (int) $client['branch_id'] : null;
            if ($entityBranchId !== null && $entityBranchId !== $scope['branch_id']) {
                throw new AccessDeniedException('Client is not accessible in the current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($entityBranchId);
            $userId = $this->currentUserId();
            $noteId = $this->repo->createNote($clientId, $content, $userId);
            $branchId = $client['branch_id'] !== null && $client['branch_id'] !== '' ? (int) $client['branch_id'] : null;
            $this->audit->log('client_note_created', 'client', $clientId, $userId, $branchId, [
                'note_id' => $noteId,
                'content_preview' => substr($content, 0, 240),
            ]);

            return $noteId;
        }, 'client note create');
    }

    public function deleteClientNote(int $clientId, int $noteId): void
    {
        $this->transactional(function () use ($clientId, $noteId): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $client = $this->repo->find($clientId);
            if (!$client) {
                throw new \RuntimeException('Client not found');
            }
            $entityBranchId = $client['branch_id'] !== null && $client['branch_id'] !== '' ? (int) $client['branch_id'] : null;
            if ($entityBranchId !== null && $entityBranchId !== $scope['branch_id']) {
                throw new AccessDeniedException('Client is not accessible in the current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($entityBranchId);
            $note = $this->repo->findNoteForClient($clientId, $noteId);
            if (!$note) {
                throw new \RuntimeException('Note not found');
            }
            $this->repo->softDeleteNoteForClient($clientId, $noteId);
            $branchId = $client['branch_id'] !== null && $client['branch_id'] !== '' ? (int) $client['branch_id'] : null;
            $this->audit->log('client_note_deleted', 'client', $clientId, $this->currentUserId(), $branchId, [
                'note_id' => $noteId,
            ]);
        }, 'client note delete');
    }

    public function isNormalizedSearchSchemaReady(): bool
    {
        $this->contextHolder->requireContext()->requireResolvedTenant();

        return $this->repo->isNormalizedSearchSchemaReady();
    }

    public function findDuplicates(int $excludeId, array $criteria): array
    {
        $this->contextHolder->requireContext()->requireResolvedTenant();
        return $this->repo->findDuplicates($excludeId, $criteria);
    }

    /**
     * @param array{full_name?:string|null,phone?:string|null,email?:string|null} $criteria
     */
    public function searchDuplicates(
        array $criteria,
        ?int $excludeId = null,
        bool $exact = true,
        bool $partial = true,
        int $limit = 30,
        int $offset = 0,
    ): array {
        $this->contextHolder->requireContext()->requireResolvedTenant();

        return $this->repo->searchDuplicates($criteria, $excludeId, $exact, $partial, $limit, $offset);
    }

    /**
     * Paginated duplicate candidate search (tenant-scoped via repository).
     *
     * @param array{full_name?:string|null,phone?:string|null,email?:string|null} $criteria
     * @return array{total:int, rows:list<array<string,mixed>>, page:int, per_page:int, normalized_search_schema_ready:bool}
     */
    public function searchDuplicateCandidatesPaginated(
        array $criteria,
        ?int $excludeId,
        bool $exact,
        bool $partial,
        int $page,
        int $perPage,
    ): array {
        $this->contextHolder->requireContext()->requireResolvedTenant();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        if (!$this->repo->isNormalizedSearchSchemaReady()) {
            return [
                'total' => 0,
                'rows' => [],
                'page' => $page,
                'per_page' => $perPage,
                'normalized_search_schema_ready' => false,
            ];
        }
        $total = $this->repo->countSearchDuplicates($criteria, $excludeId, $exact, $partial);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($total === 0) {
            return [
                'total' => 0,
                'rows' => [],
                'page' => 1,
                'per_page' => $perPage,
                'normalized_search_schema_ready' => true,
            ];
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $rows = $this->repo->searchDuplicates($criteria, $excludeId, $exact, $partial, $perPage, $offset);

        return [
            'total' => $total,
            'rows' => $rows,
            'page' => $page,
            'per_page' => $perPage,
            'normalized_search_schema_ready' => true,
        ];
    }

    public function getMergePreview(int $primaryId, int $secondaryId): array
    {
        $this->contextHolder->requireContext()->requireResolvedTenant();
        if ($primaryId <= 0 || $secondaryId <= 0 || $primaryId === $secondaryId) {
            throw new \InvalidArgumentException('Primary and secondary clients must be different valid ids.');
        }
        $primary = $this->repo->find($primaryId);
        $secondary = $this->repo->find($secondaryId);
        if (!$primary || !$secondary) {
            throw new \RuntimeException('Primary or secondary client not found.');
        }
        $counts = $this->repo->countLinkedRecords($secondaryId);
        $this->audit->log('client_merge_previewed', 'client', $primaryId, $this->currentUserId(), $primary['branch_id'] ?? null, [
            'primary_id' => $primaryId,
            'secondary_id' => $secondaryId,
            'secondary_linked_counts' => $counts,
        ]);
        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'secondary_linked_counts' => $counts,
        ];
    }

    public function mergeClients(int $primaryId, int $secondaryId, ?string $notes = null): array
    {
        return $this->mergeClientsAsActor($primaryId, $secondaryId, $notes, $this->currentUserId());
    }

    /**
     * Core merge execution (advisory lock + transaction). Use {@see mergeClients} for request-scoped actor;
     * async jobs pass the enqueueing operator id as {@code $actorUserId}.
     *
     * db->fetchOne is retained for MySQL advisory locking (SELECT GET_LOCK / RELEASE_LOCK) which is
     * infrastructure (mutex for concurrent merge of the same client pair), not tenant data access.
     * This is an explicit architectural exception — same rationale as WaitlistService advisory lock (BIG-04).
     */
    public function mergeClientsAsActor(int $primaryId, int $secondaryId, ?string $notes, ?int $actorUserId): array
    {
        $this->contextHolder->requireContext()->requireResolvedTenant();
        if ($primaryId <= 0 || $secondaryId <= 0 || $primaryId === $secondaryId) {
            throw new \InvalidArgumentException('Primary and secondary clients must be different valid ids.');
        }
        $lockKey = 'client-merge:' . min($primaryId, $secondaryId) . ':' . max($primaryId, $secondaryId);
        $lockRow = $this->db->fetchOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockKey]);
        if ((int) ($lockRow['acquired'] ?? 0) !== 1) {
            throw new SafeDomainException(
                'MERGE_BUSY',
                'Another merge is already running for these clients.',
                'GET_LOCK not acquired',
                409
            );
        }
        try {
            return $this->transactional(function () use ($primaryId, $secondaryId, $notes, $actorUserId): array {
                $ctx = $this->contextHolder->requireContext();
                $scope = $ctx->requireResolvedTenant();
                $primary = $this->repo->findForUpdate($primaryId);
                $secondary = $this->repo->findForUpdate($secondaryId);
                if (!$primary || !$secondary) {
                    throw new \RuntimeException('Primary or secondary client not found.');
                }
                $primaryBranchId = $primary['branch_id'] !== null && $primary['branch_id'] !== '' ? (int) $primary['branch_id'] : null;
                if ($primaryBranchId !== null && $primaryBranchId !== $scope['branch_id']) {
                    throw new AccessDeniedException('Primary client is not accessible in the current branch context.');
                }
                $secondaryBranchId = $secondary['branch_id'] !== null && $secondary['branch_id'] !== '' ? (int) $secondary['branch_id'] : null;
                if ($secondaryBranchId !== null && $secondaryBranchId !== $scope['branch_id']) {
                    throw new AccessDeniedException('Secondary client is not accessible in the current branch context.');
                }
                $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($primaryBranchId);
                $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($secondaryBranchId);
                if (!empty($secondary['merged_into_client_id'])) {
                    throw new \DomainException('Secondary client is already merged.');
                }

                $beforeCounts = $this->repo->countLinkedRecords($secondaryId);
                $this->mergeCustomFieldValues($primaryId, $secondaryId);
                $remapped = $this->repo->remapClientReferences($primaryId, $secondaryId);

                $primaryPatch = $this->buildPrimaryMergePatch($primary, $secondary);
                if ($primaryPatch !== []) {
                    $primaryPatch['updated_by'] = $actorUserId;
                    $this->repo->update($primaryId, $primaryPatch);
                }
                $this->repo->markMerged($secondaryId, $primaryId, $actorUserId);

                $this->audit->log('client_merged', 'client', $primaryId, $actorUserId, $primary['branch_id'] ?? null, [
                    'primary_id' => $primaryId,
                    'secondary_id' => $secondaryId,
                    'secondary_before' => $secondary,
                    'secondary_linked_counts' => $beforeCounts,
                    'remapped_rows' => $remapped,
                    'notes' => $notes,
                ]);

                return [
                    'primary_id' => $primaryId,
                    'secondary_id' => $secondaryId,
                    'secondary_linked_counts' => $beforeCounts,
                    'remapped_rows' => $remapped,
                ];
            }, 'client merge');
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS released', [$lockKey]);
        }
    }

    public function getCustomFieldDefinitions(?int $branchId = null, bool $onlyActive = false): array
    {
        return $this->fieldDefinitions->list($branchId, $onlyActive);
    }

    public function getClientCustomFieldValuesMap(int $clientId): array
    {
        $rows = $this->fieldValues->listByClientId($clientId);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['field_definition_id']] = $row['value_text'];
        }
        return $out;
    }

    public function createCustomFieldDefinition(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $fieldKey = trim((string) ($data['field_key'] ?? ''));
            $label = trim((string) ($data['label'] ?? ''));
            $fieldType = trim((string) ($data['field_type'] ?? 'text'));
            if ($fieldKey === '' || $label === '') {
                throw new \InvalidArgumentException('field_key and label are required.');
            }
            if (!in_array($fieldType, self::allowedCustomFieldTypes(), true)) {
                throw new \InvalidArgumentException('Invalid field_type.');
            }
            $userId = $this->currentUserId();
            $optionsRaw = trim((string) ($data['options_json'] ?? ''));
            $payload = [
                'branch_id' => isset($data['branch_id']) && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null,
                'field_key' => $fieldKey,
                'label' => $label,
                'field_type' => $fieldType,
                'options_json' => $this->normalizeCustomFieldOptionsJson($optionsRaw !== '' ? $optionsRaw : null),
                'is_required' => !empty($data['is_required']) ? 1 : 0,
                'is_active' => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'created_by' => $userId,
                'updated_by' => $userId,
            ];
            if ($payload['branch_id'] === null) {
                $payload['branch_id'] = $scope['branch_id'];
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization(
                isset($payload['branch_id']) && $payload['branch_id'] !== '' && $payload['branch_id'] !== null
                    ? (int) $payload['branch_id']
                    : null
            );
            $id = $this->fieldDefinitions->create($payload);
            $this->audit->log('client_custom_field_created', 'client_field_definition', $id, $userId, $payload['branch_id'], [
                'field' => $payload,
            ]);
            return $id;
        }, 'client custom field create');
    }

    public function updateCustomFieldDefinition(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $existing = $this->fieldDefinitions->find($id);
            if (!$existing) {
                throw new \RuntimeException('Custom field definition not found.');
            }
            $entityBranchId = $existing['branch_id'] !== null && $existing['branch_id'] !== '' ? (int) $existing['branch_id'] : null;
            if ($entityBranchId !== null && $entityBranchId !== $scope['branch_id']) {
                throw new AccessDeniedException('Custom field definition is not accessible in the current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($entityBranchId);
            $patch = [];
            if (array_key_exists('label', $data)) {
                $patch['label'] = trim((string) $data['label']);
            }
            if (array_key_exists('field_type', $data)) {
                $ft = trim((string) $data['field_type']);
                if (!in_array($ft, self::allowedCustomFieldTypes(), true)) {
                    throw new \InvalidArgumentException('Invalid field_type.');
                }
                $patch['field_type'] = $ft;
            }
            if (array_key_exists('options_json', $data)) {
                $raw = trim((string) $data['options_json']);
                $patch['options_json'] = $this->normalizeCustomFieldOptionsJson($raw !== '' ? $raw : null);
            }
            if (array_key_exists('is_required', $data)) {
                $patch['is_required'] = !empty($data['is_required']) ? 1 : 0;
            }
            if (array_key_exists('is_active', $data)) {
                $patch['is_active'] = !empty($data['is_active']) ? 1 : 0;
            }
            if (array_key_exists('sort_order', $data)) {
                $patch['sort_order'] = (int) $data['sort_order'];
            }
            $patch['updated_by'] = $this->currentUserId();
            $this->fieldDefinitions->update($id, $patch);
            $this->audit->log('client_custom_field_updated', 'client_field_definition', $id, $this->currentUserId(), $existing['branch_id'] ?? null, [
                'before' => $existing,
                'after' => array_merge($existing, $patch),
            ]);
        }, 'client custom field update');
    }

    public function deleteCustomFieldDefinition(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $existing = $this->fieldDefinitions->find($id);
            if (!$existing) {
                throw new \RuntimeException('Custom field definition not found.');
            }
            $entityBranchId = $existing['branch_id'] !== null && $existing['branch_id'] !== '' ? (int) $existing['branch_id'] : null;
            if ($entityBranchId !== null && $entityBranchId !== $scope['branch_id']) {
                throw new AccessDeniedException('Custom field definition is not accessible in the current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($entityBranchId);
            $this->fieldDefinitions->softDelete($id, $this->currentUserId());
            $this->audit->log('client_custom_field_deleted', 'client_field_definition', $id, $this->currentUserId(), $existing['branch_id'] ?? null, [
                'field' => $existing,
            ]);
        }, 'client custom field delete');
    }

    private function normalizeCustomFieldOptionsJson(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SafeDomainException(
                'INVALID_OPTIONS_JSON',
                'Options must be valid JSON.',
                $e->getMessage(),
                422
            );
        }
        if (!is_array($decoded) || array_values($decoded) !== $decoded) {
            throw new SafeDomainException(
                'INVALID_OPTIONS_JSON',
                'Options must be a JSON array.',
                'Non-list JSON passed',
                422
            );
        }
        $clean = [];
        foreach ($decoded as $option) {
            if (!is_string($option)) {
                throw new SafeDomainException(
                    'INVALID_OPTIONS_JSON',
                    'Every option must be a string.',
                    'Non-string option',
                    422
                );
            }
            $option = trim($option);
            if ($option === '' || mb_strlen($option) > 120) {
                throw new SafeDomainException(
                    'INVALID_OPTIONS_JSON',
                    'Each option must be 1–120 characters.',
                    'Option length invalid',
                    422
                );
            }
            $clean[] = $option;
        }
        $clean = array_values(array_unique($clean));

        return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<string>
     */
    private static function allowedCustomFieldTypes(): array
    {
        return ['text', 'textarea', 'number', 'date', 'select', 'boolean', 'multiselect', 'phone', 'email', 'address'];
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
            slog('error', 'clients.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Client operation failed.');
        }
    }

    /**
     * @param array<int|string,mixed> $inputValues
     */
    /**
     * @param array<string, mixed>|null $clientContextRow Client row (or create payload with branch_id) for definition branch resolution.
     */
    private function saveClientCustomFieldValues(int $clientId, array $inputValues, ?array $clientContextRow = null): void
    {
        if ($clientId <= 0 || $inputValues === []) {
            return;
        }
        $branchFilter = $this->branchIdForCustomFieldDefinitions($clientContextRow);
        $definitions = $this->fieldDefinitions->list($branchFilter, false);
        $validById = [];
        foreach ($definitions as $def) {
            $validById[(int) $def['id']] = $def;
        }
        $batch = [];
        foreach ($inputValues as $fieldIdRaw => $value) {
            $fieldId = (int) $fieldIdRaw;
            if ($fieldId <= 0 || !isset($validById[$fieldId])) {
                continue;
            }
            $normalized = is_scalar($value) ? trim((string) $value) : null;
            $batch[$fieldId] = $normalized !== '' ? $normalized : null;
        }
        $this->fieldValues->bulkUpsertValues($clientId, $batch);
    }

    /**
     * Definitions are branch-scoped in the DB; use the client's branch, else resolved TenantContext branch.
     *
     * @param array<string, mixed>|null $clientRow
     */
    private function branchIdForCustomFieldDefinitions(?array $clientRow): ?int
    {
        if ($clientRow !== null) {
            $b = $clientRow['branch_id'] ?? null;
            if ($b !== null && $b !== '' && (int) $b > 0) {
                return (int) $b;
            }
        }
        $ctx = $this->contextHolder->get();
        if ($ctx !== null && $ctx->tenantContextResolved && $ctx->branchId !== null && $ctx->branchId > 0) {
            return $ctx->branchId;
        }

        return null;
    }

    private function mergeCustomFieldValues(int $primaryId, int $secondaryId): void
    {
        $primaryValues = $this->getClientCustomFieldValuesMap($primaryId);
        $secondaryRows = $this->fieldValues->listByClientId($secondaryId);
        $batch = [];
        foreach ($secondaryRows as $row) {
            $defId = (int) $row['field_definition_id'];
            $secondaryValue = $row['value_text'] !== null ? trim((string) $row['value_text']) : null;
            $primaryValue = isset($primaryValues[$defId]) ? trim((string) $primaryValues[$defId]) : null;
            if (($primaryValue === null || $primaryValue === '') && $secondaryValue !== null && $secondaryValue !== '') {
                $batch[$defId] = $secondaryValue;
            }
        }
        if ($batch !== []) {
            $this->fieldValues->bulkUpsertValues($primaryId, $batch);
        }
        $this->fieldValues->deleteByClientId($secondaryId);
    }

    private function finalizeContactAddressForPersistence(array &$data, ?array $current): void
    {
        $data['delivery_same_as_home'] = !empty($data['delivery_same_as_home']) ? 1 : 0;
        if ($data['delivery_same_as_home'] === 1) {
            $data['delivery_address_1'] = $data['home_address_1'] ?? null;
            $data['delivery_address_2'] = $data['home_address_2'] ?? null;
            $data['delivery_city'] = $data['home_city'] ?? null;
            $data['delivery_postal_code'] = $data['home_postal_code'] ?? null;
            $data['delivery_country'] = $data['home_country'] ?? null;
        }
        $data['phone'] = ClientCanonicalPhone::resolvePrimaryForPersistence($data, $current);
    }

    private function buildPrimaryMergePatch(array $primary, array $secondary): array
    {
        $fillable = [
            'phone', 'email', 'birth_date', 'anniversary', 'gender', 'preferred_contact_method',
            'occupation', 'language',
            'receive_emails', 'receive_sms', 'marketing_opt_in',
            'booking_alert', 'check_in_alert', 'check_out_alert',
            'referral_information', 'referral_history', 'referred_by', 'customer_origin',
            'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
            'inactive_flag',
            'phone_home', 'phone_mobile', 'mobile_operator', 'phone_work', 'phone_work_ext',
            'home_address_1', 'home_address_2', 'home_city', 'home_postal_code', 'home_country',
            'delivery_address_1', 'delivery_address_2', 'delivery_city', 'delivery_postal_code', 'delivery_country',
        ];
        $patch = [];
        foreach (['receive_emails', 'receive_sms', 'marketing_opt_in', 'inactive_flag'] as $key) {
            $p = (int) ($primary[$key] ?? 0);
            $s = (int) ($secondary[$key] ?? 0);
            if ($p === 0 && $s === 1) {
                $patch[$key] = 1;
            }
        }
        foreach ($fillable as $key) {
            if (in_array($key, ['receive_emails', 'receive_sms', 'marketing_opt_in', 'inactive_flag'], true)) {
                continue;
            }
            $primaryVal = trim((string) ($primary[$key] ?? ''));
            $secondaryVal = trim((string) ($secondary[$key] ?? ''));
            if ($primaryVal === '' && $secondaryVal !== '') {
                $patch[$key] = $secondary[$key];
            }
        }
        $primaryNotes = trim((string) ($primary['notes'] ?? ''));
        $secondaryNotes = trim((string) ($secondary['notes'] ?? ''));
        if ($secondaryNotes !== '' && stripos($primaryNotes, $secondaryNotes) === false) {
            $patch['notes'] = trim($primaryNotes . "\n" . '[merged-client-note] ' . $secondaryNotes);
        }
        return $patch;
    }
}
