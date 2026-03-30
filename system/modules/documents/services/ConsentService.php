<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\App\Application;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Documents\Repositories\ClientConsentRepository;
use Modules\Documents\Repositories\DocumentDefinitionRepository;
use Modules\Documents\Repositories\ServiceRequiredConsentRepository;

/**
 * Document/consent definitions and client consent records. Branch-aware; audit on state changes.
 *
 * Service-linked required consents are enforced in AppointmentService when a **positive client_id and service_id**
 * are part of the path: locked slot creation (internal slot book, public book, series), full appointment create when both
 * ids are present, scheduling-changing update, and reschedule. Appointments without a service skip consent checks.
 * Intake forms use a separate module and are not gated on the same flag at booking time.
 */
final class ConsentService
{
    public function __construct(
        private DocumentDefinitionRepository $definitions,
        private ClientConsentRepository $consents,
        private ServiceRequiredConsentRepository $serviceRequired,
        private AuditService $audit,
        private BranchContext $branchContext,
        private ClientRepository $clients,
    ) {
    }

    /**
     * Check whether client has valid (signed, not expired) consent for all consents required by the service.
     * Callers enforce this on service-based appointment create and on scheduling moves that re-run the locked pipeline.
     *
     * @return array{ok: bool, missing: list<array{id:int,code:string,name:string}>, expired: list<array{id:int,code:string,name:string,expires_at:string}>}
     */
    public function checkClientConsentsForService(int $clientId, int $serviceId, ?int $branchId = null): array
    {
        $branchId = $branchId ?? $this->branchContext->getCurrentBranchId();
        $requiredIds = $this->serviceRequired->getRequiredDefinitionIds($serviceId);
        if ($requiredIds === []) {
            return ['ok' => true, 'missing' => [], 'expired' => []];
        }
        $definitions = $this->definitions->listForBranch($branchId, true);
        $defById = [];
        foreach ($definitions as $d) {
            $defById[$d['id']] = $d;
        }
        $requiredDefs = array_filter($definitions, static fn (array $d): bool => in_array($d['id'], $requiredIds, true));
        if (empty($requiredDefs)) {
            return ['ok' => true, 'missing' => [], 'expired' => []];
        }
        $requiredIds = array_map(static fn (array $d): int => $d['id'], $requiredDefs);
        $statusMap = $this->consents->getConsentStatusForClientAndDefinitionsInTenantScope($clientId, $requiredIds, $branchId);
        $missing = [];
        $expired = [];
        foreach ($requiredDefs as $d) {
            $id = $d['id'];
            $info = $statusMap[$id] ?? null;
            if ($info === null || $info['status'] === 'pending' || $info['status'] === 'revoked') {
                $missing[] = ['id' => $id, 'code' => $d['code'], 'name' => $d['name']];
            } elseif ($info['status'] === 'expired') {
                $expired[] = [
                    'id' => $id,
                    'code' => $d['code'],
                    'name' => $d['name'],
                    'expires_at' => $info['expires_at'] ?? '',
                ];
            }
        }
        return [
            'ok' => empty($missing) && empty($expired),
            'missing' => $missing,
            'expired' => $expired,
        ];
    }

    /**
     * List consent definitions for branch (for admin / dropdowns).
     *
     * @return list<array{id:int,branch_id:int|null,code:string,name:string,description:string|null,valid_duration_days:int|null,is_active:int}>
     */
    public function listDefinitions(?int $branchId = null, bool $activeOnly = true): array
    {
        $branchId = $branchId ?? $this->branchContext->getCurrentBranchId();
        return $this->definitions->listForBranch($branchId, $activeOnly);
    }

    /**
     * List client's consent records for branch.
     *
     * @return list<array{id:int,client_id:int,document_definition_id:int,status:string,signed_at:string|null,expires_at:string|null,definition_code:string,definition_name:string}>
     */
    public function listClientConsents(int $clientId, ?int $branchId = null): array
    {
        $branchId = $branchId ?? $this->branchContext->getCurrentBranchId();
        return $this->consents->listByClientInTenantScope($clientId, $branchId);
    }

    /**
     * Record or update client consent: set status to signed, set signed_at and expires_at.
     * Enforces branch on definition and client.
     */
    public function recordSigned(int $clientId, int $documentDefinitionId, ?int $branchId = null, ?string $notes = null): void
    {
        $branchId = $branchId ?? $this->branchContext->getCurrentBranchId();
        $def = $this->definitions->findInTenantScope($documentDefinitionId, $branchId);
        if (!$def || $def['deleted_at'] !== null) {
            throw new \DomainException('Document definition not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($def['branch_id'] !== null ? (int) $def['branch_id'] : null);
        $clientBranch = $this->branchIdForConsentBranchAssert($clientId);
        if ($clientBranch !== null) {
            $this->branchContext->assertBranchMatchOrGlobalEntity($clientBranch);
        }
        $existing = $this->consents->findByClientAndDefinitionInTenantScope($clientId, $documentDefinitionId, $branchId);
        $now = date('Y-m-d H:i:s');
        $expiresAt = null;
        if (!empty($def['valid_duration_days'])) {
            $days = (int) $def['valid_duration_days'];
            $expiresAt = date('Y-m-d', strtotime("+{$days} days", strtotime($now)));
        }
        if ($existing !== null) {
            $this->consents->updateInTenantScope((int) $existing['id'], $branchId, [
                'status' => 'signed',
                'signed_at' => $now,
                'expires_at' => $expiresAt,
                'notes' => $notes,
            ]);
            $this->audit->log('client_consent_signed', 'client_consent', (int) $existing['id'], $this->currentUserId(), $branchId, [
                'client_id' => $clientId,
                'document_definition_id' => $documentDefinitionId,
                'signed_at' => $now,
                'expires_at' => $expiresAt,
            ]);
            return;
        }
        $id = $this->consents->createInTenantScope([
            'client_id' => $clientId,
            'document_definition_id' => $documentDefinitionId,
            'status' => 'signed',
            'signed_at' => $now,
            'expires_at' => $expiresAt,
            'branch_id' => $branchId,
            'notes' => $notes,
        ], $branchId);
        $this->audit->log('client_consent_created', 'client_consent', $id, $this->currentUserId(), $branchId, [
            'client_id' => $clientId,
            'document_definition_id' => $documentDefinitionId,
            'status' => 'signed',
            'signed_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Set client consent to pending (e.g. awaiting signature) or revoke.
     */
    public function setStatus(int $clientId, int $documentDefinitionId, string $status, ?int $branchId = null): void
    {
        if (!in_array($status, ['pending', 'revoked'], true)) {
            throw new \InvalidArgumentException('Status must be pending or revoked.');
        }
        $branchId = $branchId ?? $this->branchContext->getCurrentBranchId();
        $existing = $this->consents->findByClientAndDefinitionInTenantScope($clientId, $documentDefinitionId, $branchId);
        if ($existing === null) {
            if ($status === 'pending') {
                $def = $this->definitions->findInTenantScope($documentDefinitionId, $branchId);
                if (!$def || $def['deleted_at'] !== null) {
                    throw new \DomainException('Document definition not found.');
                }
                $this->branchContext->assertBranchMatchOrGlobalEntity($def['branch_id'] !== null ? (int) $def['branch_id'] : null);
                $id = $this->consents->createInTenantScope([
                    'client_id' => $clientId,
                    'document_definition_id' => $documentDefinitionId,
                    'status' => 'pending',
                    'branch_id' => $branchId,
                ], $branchId);
                $this->audit->log('client_consent_created', 'client_consent', $id, $this->currentUserId(), $branchId, [
                    'client_id' => $clientId,
                    'document_definition_id' => $documentDefinitionId,
                    'status' => 'pending',
                ]);
            }
            return;
        }
        $this->consents->updateInTenantScope((int) $existing['id'], $branchId, [
            'status' => $status,
            'signed_at' => $status === 'revoked' ? null : $existing['signed_at'],
            'expires_at' => $status === 'revoked' ? null : $existing['expires_at'],
        ]);
        $this->audit->log('client_consent_' . $status, 'client_consent', (int) $existing['id'], $this->currentUserId(), $branchId ?? null, [
            'client_id' => $clientId,
            'document_definition_id' => $documentDefinitionId,
        ]);
    }

    /**
     * Create document definition. Branch-scoped users can only create for their branch.
     */
    public function createDefinition(array $data, ?int $branchId = null): int
    {
        $contextBranch = $this->branchContext->getCurrentBranchId();
        if ($contextBranch !== null) {
            $branchId = $contextBranch;
        } else {
            $branchId = $branchId ?? null;
        }
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('Code is required.');
        }
        if ($this->definitions->findByBranchAndCode($branchId, $code) !== null) {
            throw new \DomainException('A document with this code already exists for this branch.');
        }
        $payload = [
            'branch_id' => $branchId,
            'code' => $code,
            'name' => trim((string) ($data['name'] ?? '')) ?: $code,
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'valid_duration_days' => isset($data['valid_duration_days']) && $data['valid_duration_days'] !== '' ? (int) $data['valid_duration_days'] : null,
            'is_active' => !empty($data['is_active']),
        ];
        $id = $this->definitions->create($payload);
        $this->audit->log('document_definition_created', 'document_definition', $id, $this->currentUserId(), $branchId, ['definition' => $payload]);
        return $id;
    }

    /**
     * Required definition IDs for a service (for admin / assignment).
     *
     * @return list<int>
     */
    public function getRequiredDefinitionIdsForService(int $serviceId): array
    {
        return $this->serviceRequired->getRequiredDefinitionIds($serviceId);
    }

    /**
     * Set which consents are required for a service. Replaces existing.
     *
     * @param list<int> $documentDefinitionIds
     */
    public function setServiceRequiredConsents(int $serviceId, array $documentDefinitionIds, ?int $branchId = null): void
    {
        $branchId = $branchId ?? $this->branchContext->getCurrentBranchId();
        foreach ($documentDefinitionIds as $defId) {
            $def = $this->definitions->findInTenantScope((int) $defId, $branchId);
            if (!$def || $def['deleted_at'] !== null) {
                throw new \DomainException('Document definition not found: ' . $defId);
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($def['branch_id'] !== null ? (int) $def['branch_id'] : null);
        }
        $this->serviceRequired->setRequired($serviceId, array_map('intval', $documentDefinitionIds));
        $this->audit->log('service_required_consents_updated', 'service', $serviceId, $this->currentUserId(), $branchId, [
            'document_definition_ids' => array_map('intval', $documentDefinitionIds),
        ]);
    }

    /** Tenant-scoped client read for branch envelope checks ({@see ClientRepository::find()}). */
    private function branchIdForConsentBranchAssert(int $clientId): ?int
    {
        $row = $this->clients->find($clientId);
        if ($row === null) {
            return null;
        }

        return isset($row['branch_id']) && $row['branch_id'] !== null && $row['branch_id'] !== ''
            ? (int) $row['branch_id'] : null;
    }

    private function currentUserId(): ?int
    {
        $auth = Application::container()->get(\Core\Auth\SessionAuth::class);
        return $auth->id();
    }
}
