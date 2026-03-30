<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationScopedBranchAssert;
use Core\Errors\SafeDomainException;
use Core\Tenant\TenantOwnedDataScopeGuard;
use Modules\Clients\Repositories\ClientRegistrationRequestRepository;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Clients\Support\ClientRegistrationValidationException;
use Modules\Clients\Support\ClientRegistrationValidator;

final class ClientRegistrationService
{
    public function __construct(
        private ClientRegistrationRequestRepository $repo,
        private ClientRepository $clientRepo,
        private ClientService $clientService,
        private Database $db,
        private AuditService $audit,
        private BranchContext $branchContext,
        private SettingsService $settings,
        private TenantOwnedDataScopeGuard $tenantScopeGuard,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $validationErrors = ClientRegistrationValidator::validateCreate($data);
            if ($validationErrors !== []) {
                throw new ClientRegistrationValidationException($validationErrors);
            }
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization(
                isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
                    ? (int) $data['branch_id']
                    : null
            );
            $fullName = trim((string) ($data['full_name'] ?? ''));
            $status = trim((string) ($data['status'] ?? 'new'));
            if (!in_array($status, ['new', 'reviewed', 'converted', 'rejected'], true)) {
                throw new \InvalidArgumentException('Invalid registration status.');
            }
            $source = trim((string) ($data['source'] ?? 'manual'));
            if ($source === '') {
                $source = 'manual';
            }

            $payload = [
                'branch_id' => $data['branch_id'] ?? null,
                'full_name' => $fullName,
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'email' => trim((string) ($data['email'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'source' => $source,
                'status' => $status,
                'linked_client_id' => null,
                'created_by' => $this->currentUserId(),
            ];
            $id = $this->repo->create($payload);
            $this->audit->log('client_registration_created', 'client_registration_request', $id, $this->currentUserId(), $payload['branch_id'], [
                'registration' => $payload,
            ]);
            return $id;
        }, 'client registration create');
    }

    public function updateStatus(int $id, string $status, ?string $notes = null): void
    {
        $this->transactional(function () use ($id, $status, $notes): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $existing = $this->repo->find($id);
            if (!$existing) {
                throw new \RuntimeException('Registration request not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($existing['branch_id'] !== null && $existing['branch_id'] !== '' ? (int) $existing['branch_id'] : null);
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization(
                $existing['branch_id'] !== null && $existing['branch_id'] !== '' ? (int) $existing['branch_id'] : null
            );
            $next = trim($status);
            if (!in_array($next, ['new', 'reviewed', 'converted', 'rejected'], true)) {
                throw new \InvalidArgumentException('Invalid registration status.');
            }
            if ((string) $existing['status'] === 'converted' && $next !== 'converted') {
                throw new \DomainException('Converted registration cannot be moved back.');
            }
            $patch = ['status' => $next];
            if ($notes !== null && trim($notes) !== '') {
                $existingNotes = trim((string) ($existing['notes'] ?? ''));
                $suffix = trim($notes);
                $patch['notes'] = $existingNotes === '' ? $suffix : ($existingNotes . "\n" . '[status-note] ' . $suffix);
            }
            $this->repo->update($id, $patch);
            $this->audit->log('client_registration_reviewed', 'client_registration_request', $id, $this->currentUserId(), $existing['branch_id'] ?? null, [
                'before_status' => $existing['status'],
                'after_status' => $next,
                'notes' => $notes,
            ]);
        }, 'client registration status update');
    }

    public function convert(int $id, ?int $existingClientId = null): int
    {
        return $this->transactional(function () use ($id, $existingClientId): int {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $existing = $this->repo->find($id);
            if (!$existing) {
                throw new SafeDomainException(
                    'REGISTRATION_NOT_FOUND',
                    'Registration not found.',
                    'Registration missing',
                    404
                );
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($existing['branch_id'] !== null && $existing['branch_id'] !== '' ? (int) $existing['branch_id'] : null);
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization(
                $existing['branch_id'] !== null && $existing['branch_id'] !== '' ? (int) $existing['branch_id'] : null
            );
            if ((string) $existing['status'] === 'rejected') {
                throw new \DomainException('Rejected registration cannot be converted.');
            }
            if ((string) $existing['status'] === 'converted' && (int) ($existing['linked_client_id'] ?? 0) > 0) {
                return (int) $existing['linked_client_id'];
            }

            $clientId = $existingClientId ?? 0;
            if ($clientId > 0) {
                $client = $this->clientRepo->find($clientId);
                if (!$client) {
                    throw new SafeDomainException(
                        'CLIENT_NOT_FOUND',
                        'Target client was not found.',
                        'Linked client missing',
                        404
                    );
                }
                $registrationBranchId = $existing['branch_id'] !== null && $existing['branch_id'] !== ''
                    ? (int) $existing['branch_id']
                    : null;
                $clientBranchId = $client['branch_id'] !== null && $client['branch_id'] !== ''
                    ? (int) $client['branch_id']
                    : null;
                if ($registrationBranchId !== null && $clientBranchId !== null && $registrationBranchId !== $clientBranchId) {
                    throw new SafeDomainException(
                        'BRANCH_MISMATCH',
                        'Registration can only be linked to a client in the same branch.',
                        sprintf('Registration branch %d does not match client branch %d', $registrationBranchId, $clientBranchId),
                        422
                    );
                }
                if ($registrationBranchId === null && $clientBranchId !== null) {
                    throw new SafeDomainException(
                        'BRANCH_ATTACHMENT_AMBIGUOUS',
                        'A branchless registration cannot be linked to a branch-specific client. Set the registration branch or choose a branchless client.',
                        'registration branch_id NULL with concrete client.branch_id',
                        422
                    );
                }
            } else {
                [$firstName, $lastName] = $this->splitName((string) $existing['full_name']);
                $regBranchId = $existing['branch_id'] !== null && $existing['branch_id'] !== ''
                    ? (int) $existing['branch_id']
                    : null;
                $marketing = $this->settings->getMarketingSettings($regBranchId);
                $defaultOptIn = !empty($marketing['default_opt_in']) ? 1 : 0;
                $clientId = $this->clientService->create([
                    'branch_id' => $regBranchId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $existing['phone'] ?: null,
                    'email' => $existing['email'] ?: null,
                    'marketing_opt_in' => $defaultOptIn,
                    'notes' => $this->buildClientNotesFromRegistration($existing),
                    'custom_fields' => [],
                ]);
            }

            $this->repo->update($id, [
                'status' => 'converted',
                'linked_client_id' => $clientId,
            ]);

            $this->audit->log('client_registration_converted', 'client_registration_request', $id, $this->currentUserId(), $existing['branch_id'] ?? null, [
                'linked_client_id' => $clientId,
                'used_existing_client' => $existingClientId !== null && $existingClientId > 0,
            ]);

            return $clientId;
        }, 'client registration convert');
    }

    private function buildClientNotesFromRegistration(array $registration): ?string
    {
        $notes = trim((string) ($registration['notes'] ?? ''));
        $source = trim((string) ($registration['source'] ?? 'manual'));
        if ($notes === '') {
            return '[registration-source] ' . $source;
        }
        return '[registration-source] ' . $source . "\n" . $notes;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitName(string $fullName): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $fullName) ?? '');
        if ($clean === '') {
            return ['Unknown', 'Client'];
        }
        $parts = explode(' ', $clean);
        if (count($parts) === 1) {
            return [$parts[0], 'Client'];
        }
        $lastName = (string) array_pop($parts);
        $firstName = trim(implode(' ', $parts));
        return [$firstName !== '' ? $firstName : 'Unknown', $lastName !== '' ? $lastName : 'Client'];
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
            throw new \DomainException('Client registration operation failed.');
        }
    }
}
