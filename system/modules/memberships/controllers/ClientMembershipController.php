<?php

declare(strict_types=1);

namespace Modules\Memberships\Controllers;

use Core\App\Application;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Contracts\ClientListProvider;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Memberships\Repositories\ClientMembershipRepository;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Memberships\Services\MembershipService;

final class ClientMembershipController
{
    public function __construct(
        private ClientMembershipRepository $clientMemberships,
        private MembershipDefinitionRepository $definitions,
        private MembershipService $service,
        private ClientListProvider $clientListProvider,
        private BranchContext $branchContext,
        private SettingsService $settings,
        private BranchDirectory $branchDirectory,
        private ClientRepository $clients
    ) {
    }

    private static function coerceGetString(string $key): string
    {
        $v = $_GET[$key] ?? '';

        return is_string($v) ? trim($v) : '';
    }

    public function index(): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $search = self::coerceGetString('search');
        $status = self::coerceGetString('status');
        $branchRaw = self::coerceGetString('branch_id');
        $filterClientId = max(0, (int) ($_GET['client_id'] ?? 0));

        $filters = [
            'search' => $search ?: null,
            'status' => $status ?: null,
        ];
        if ($filterClientId > 0) {
            $filters['client_id'] = $filterClientId;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $items = $this->clientMemberships->listInTenantScope($filters, $tenantBranchId, $perPage, ($page - 1) * $perPage);
        $total = $this->clientMemberships->countInTenantScope($filters, $tenantBranchId);
        $flash = flash();
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/memberships/views/client-memberships/index.php');
    }

    public function assign(): void
    {
        $this->tenantBranchOrRedirect();
        $this->renderAssignForm(['starts_at' => date('Y-m-d')], []);
    }

    public function storeAssign(): void
    {
        $this->tenantBranchOrRedirect();
        $data = [
            'client_id' => (int) ($_POST['client_id'] ?? 0),
            'membership_definition_id' => (int) ($_POST['membership_definition_id'] ?? 0),
            'starts_at' => trim($_POST['starts_at'] ?? date('Y-m-d')),
            'notes' => trim($_POST['notes'] ?? ''),
        ];
        $data = $this->branchContext->enforceBranchOnCreate($data);

        $errors = [];
        if ($data['client_id'] <= 0) {
            $errors['client_id'] = 'Please select a client.';
        }
        if ($data['membership_definition_id'] <= 0) {
            $errors['membership_definition_id'] = 'Please select a membership plan.';
        }
        if ($data['starts_at'] === '') {
            $errors['starts_at'] = 'Start date is required.';
        }

        if ($errors === []) {
            try {
                $data = $this->mergeHqIssuanceBranchIntoPayload($data);
            } catch (\DomainException $e) {
                $errors['_general'] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            $this->renderAssignForm($data, $errors);

            return;
        }

        try {
            $this->service->assignToClient($data);
            flash('success', 'Membership assigned to client.');
            header('Location: /memberships/client-memberships');
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $data['starts_at'] = $data['starts_at'] ?? date('Y-m-d');
            $this->renderAssignForm($data, $errors);
        }
    }

    public function cancel(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $current = $this->clientMemberships->findInTenantScope($id, $tenantBranchId);
        if (!$current) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        try {
            $branchId = isset($current['branch_id']) && $current['branch_id'] !== '' && $current['branch_id'] !== null ? (int) $current['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return;
        }
        $notes = trim($_POST['notes'] ?? '');
        try {
            $this->service->cancelClientMembership($id, $notes);
            flash('success', 'Membership cancelled.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /memberships/client-memberships');
        exit;
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private function renderAssignForm(array $data, array $errors): void
    {
        $hintClientId = isset($data['client_id']) && (int) $data['client_id'] > 0 ? (int) $data['client_id'] : null;
        $scope = $this->resolveAssignListScope($hintClientId);
        $listBranchId = $scope['branch_id'];
        if ($listBranchId === null || $listBranchId <= 0) {
            throw new \DomainException('Tenant branch context is required to assign memberships.');
        }
        $definitions = $this->definitions->listActiveAssignableInTenantScope($listBranchId);
        $clients = $this->clientListProvider->list($listBranchId);
        $membershipSettings = $this->settings->getMembershipSettings($listBranchId);
        $assignBranchRoundTrip = $scope['pinned_from_assign_branch_param'];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/memberships/views/client-memberships/assign.php');
    }

    /**
     * List/settings scope for the assign form: operator branch, else optional assign_branch_id (GET/POST), else selected client's branch (HQ).
     *
     * @return array{branch_id: ?int, pinned_from_assign_branch_param: bool}
     */
    private function resolveAssignListScope(?int $submittedClientId): array
    {
        $ctx = $this->branchContext->getCurrentBranchId();
        if ($ctx !== null) {
            return ['branch_id' => $ctx, 'pinned_from_assign_branch_param' => false];
        }
        $explicit = $this->parseOptionalActiveAssignBranchFromRequest();
        if ($explicit !== null) {
            return ['branch_id' => $explicit, 'pinned_from_assign_branch_param' => true];
        }
        if ($submittedClientId !== null && $submittedClientId > 0) {
            $row = $this->clients->find($submittedClientId);
            $b = $this->branchIdFromClientRow($row);

            return ['branch_id' => $b, 'pinned_from_assign_branch_param' => false];
        }

        return ['branch_id' => null, 'pinned_from_assign_branch_param' => false];
    }

    /**
     * When operator context is unset (HQ), set payload branch_id from the client row so issuance matches
     * {@see MembershipService::assignToClientAuthoritative} rules without relying on a missing POST branch_id.
     * Optional assign_branch_id (GET/POST) must match the client's branch when both are set; cannot attach a branch-only issuance to a global client.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mergeHqIssuanceBranchIntoPayload(array $data): array
    {
        if ($this->branchContext->getCurrentBranchId() !== null) {
            return $data;
        }
        $explicit = $this->parseOptionalActiveAssignBranchFromRequest();
        $clientBranch = null;
        $cid = (int) ($data['client_id'] ?? 0);
        if ($cid > 0) {
            $row = $this->clients->find($cid);
            if ($row === null) {
                throw new \DomainException('Client not found.');
            }
            $clientBranch = $this->branchIdFromClientRow($row);
        }
        if ($explicit !== null && $clientBranch !== null && $explicit !== $clientBranch) {
            throw new \DomainException('assign_branch_id does not match the selected client\'s branch.');
        }
        if ($clientBranch === null && $explicit !== null) {
            throw new \DomainException(
                'Branch-scoped assignment (assign_branch_id) requires a client that belongs to that branch. Use a branch client or omit assign_branch_id for global clients.'
            );
        }
        $issuance = $clientBranch ?? $explicit;
        if ($issuance !== null) {
            $data['branch_id'] = $issuance;
        }

        return $data;
    }

    private function parseOptionalActiveAssignBranchFromRequest(): ?int
    {
        $raw = trim((string) ($_POST['assign_branch_id'] ?? $_GET['assign_branch_id'] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $id = (int) $raw;
        if ($id <= 0 || !$this->branchDirectory->isActiveBranchId($id)) {
            return null;
        }

        return $id;
    }

    /** @param array<string, mixed>|null $row */
    private function branchIdFromClientRow(?array $row): ?int
    {
        if ($row === null) {
            return null;
        }
        if (!isset($row['branch_id']) || $row['branch_id'] === '' || $row['branch_id'] === null) {
            return null;
        }

        return (int) $row['branch_id'];
    }

    private function tenantBranchOrRedirect(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            flash('error', 'Tenant branch context is required for client membership routes.');
            header('Location: /memberships/client-memberships');
            exit;
        }

        return $branchId;
    }
}
