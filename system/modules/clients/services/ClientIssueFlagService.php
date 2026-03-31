<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Errors\AccessDeniedException;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;
use Core\Organization\OrganizationScopedBranchAssert;
use Modules\Clients\Repositories\ClientIssueFlagRepository;
use Modules\Clients\Repositories\ClientRepository;

final class ClientIssueFlagService
{
    public function __construct(
        private ClientIssueFlagRepository $repo,
        private ClientRepository $clientRepo,
        private Database $db,
        private AuditService $audit,
        private RequestContextHolder $contextHolder,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
        private AuthorizerInterface $authorizer,
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::CLIENT_MODIFY, ResourceRef::collection('client'));
            if (!isset($data['branch_id']) || $data['branch_id'] === '' || $data['branch_id'] === null) {
                $data['branch_id'] = $scope['branch_id'];
            }
            $clientId = (int) ($data['client_id'] ?? 0);
            if ($clientId <= 0) {
                throw new \InvalidArgumentException('client_id is required.');
            }
            $client = $this->clientRepo->find($clientId);
            if (!$client) {
                throw new \RuntimeException('Client not found.');
            }
            $entityBranchId = $client['branch_id'] !== null && $client['branch_id'] !== '' ? (int) $client['branch_id'] : null;
            if ($entityBranchId !== null && $entityBranchId !== $scope['branch_id']) {
                throw new AccessDeniedException('Client is not accessible in the current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($entityBranchId);
            $type = trim((string) ($data['type'] ?? ''));
            if (!in_array($type, ['invalid_payment_card', 'account_follow_up', 'front_desk_warning'], true)) {
                throw new \InvalidArgumentException('Invalid flag type.');
            }
            $title = trim((string) ($data['title'] ?? ''));
            if ($title === '') {
                throw new \InvalidArgumentException('Title is required.');
            }

            $payload = [
                'client_id' => $clientId,
                'branch_id' => $data['branch_id'] ?? ($client['branch_id'] ?? null),
                'type' => $type,
                'status' => 'open',
                'title' => $title,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_by' => $this->currentUserId(),
            ];
            $id = $this->repo->create($payload);
            $this->audit->log('client_issue_flag_created', 'client_issue_flag', $id, $this->currentUserId(), $payload['branch_id'], [
                'flag' => $payload,
            ]);
            return $id;
        }, 'client issue flag create');
    }

    public function resolve(int $id, ?string $notes = null): void
    {
        $this->transactional(function () use ($id, $notes): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::CLIENT_MODIFY, ResourceRef::instance('client_issue_flag', $id));
            $existing = $this->repo->find($id);
            if (!$existing) {
                throw new \RuntimeException('Client issue flag not found.');
            }
            $entityBranchId = $existing['branch_id'] !== null && $existing['branch_id'] !== '' ? (int) $existing['branch_id'] : null;
            if ($entityBranchId !== null && $entityBranchId !== $scope['branch_id']) {
                throw new AccessDeniedException('Client issue flag is not accessible in the current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($entityBranchId);
            if ((string) $existing['status'] === 'resolved') {
                return;
            }
            $patch = [
                'status' => 'resolved',
                'resolved_by' => $this->currentUserId(),
                'resolved_at' => date('Y-m-d H:i:s'),
            ];
            if ($notes !== null && trim($notes) !== '') {
                $currentNotes = trim((string) ($existing['notes'] ?? ''));
                $suffix = trim($notes);
                $patch['notes'] = $currentNotes === '' ? ('[resolved-note] ' . $suffix) : ($currentNotes . "\n" . '[resolved-note] ' . $suffix);
            }
            $this->repo->update($id, $patch);
            $this->audit->log('client_issue_flag_resolved', 'client_issue_flag', $id, $this->currentUserId(), $existing['branch_id'] ?? null, [
                'before_status' => $existing['status'],
                'after_status' => 'resolved',
                'notes' => $notes,
            ]);
        }, 'client issue flag resolve');
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
            throw new \DomainException('Client issue flag operation failed.');
        }
    }
}
