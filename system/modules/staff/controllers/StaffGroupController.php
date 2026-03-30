<?php

declare(strict_types=1);

namespace Modules\Staff\Controllers;

use Core\App\Application;
use Core\App\Response;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Staff\Repositories\StaffGroupRepository;
use Modules\Staff\Services\StaffGroupPermissionService;
use Modules\Staff\Services\StaffGroupService;

final class StaffGroupController
{
    public function __construct(
        private StaffGroupRepository $groups,
        private StaffGroupService $service,
        private StaffGroupPermissionService $groupPermissions
    ) {
    }

    public function index(): void
    {
        $activeRaw = trim((string) ($_GET['active'] ?? '1'));
        $filters = [
            'active' => $activeRaw !== '0',
        ];
        $branchContext = Application::container()->get(\Core\Branch\BranchContext::class);
        $orgScope = Application::container()->get(OrganizationRepositoryScope::class);
        $ctxBranch = $branchContext->getCurrentBranchId();
        $pickBranch = $ctxBranch;
        if ($pickBranch === null) {
            $branchRaw = trim((string) ($_GET['branch_id'] ?? ''));
            if ($branchRaw !== '') {
                $pickBranch = (int) $branchRaw;
                $filters['branch_id'] = $pickBranch;
            }
        } else {
            $filters['branch_id'] = $ctxBranch;
        }
        if ($pickBranch !== null && $pickBranch > 0) {
            $anchor = $pickBranch;
            $rows = $this->groups->listInTenantScope($anchor, $filters, 200, 0);
        } else {
            $any = $orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
            $rows = $any !== null
                ? $this->groups->listInTenantScope($any, $filters, 200, 0)
                : $this->groups->list($filters, 200, 0);
        }
        $this->respondJson([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function show(int $id): void
    {
        $group = $this->groups->find($id);
        if (!$group) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', 'Staff group not found.');

            return;
        }
        try {
            Application::container()->get(\Core\Branch\BranchContext::class)
                ->assertBranchMatchOrGlobalEntity($group['branch_id'] !== null ? (int) $group['branch_id'] : null);
        } catch (\DomainException) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', 'Forbidden.');

            return;
        }
        $members = $this->groups->listMemberStaff($id);
        $this->respondJson([
            'success' => true,
            'data' => [
                'group' => $group,
                'members' => $members,
            ],
        ]);
    }

    public function store(): void
    {
        $payload = [
            'branch_id' => trim((string) ($_POST['branch_id'] ?? '')) !== '' ? (int) $_POST['branch_id'] : null,
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
        ];
        try {
            $id = $this->service->create($payload);
            $this->respondJson([
                'success' => true,
                'data' => ['id' => $id],
            ], 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to create staff group.');
        }
    }

    public function update(int $id): void
    {
        $payload = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
        ];
        if (array_key_exists('is_active', $_POST)) {
            $payload['is_active'] = !empty($_POST['is_active']);
        }
        try {
            $this->service->update($id, $payload);
            $this->respondJson(['success' => true]);
        } catch (\InvalidArgumentException|\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to update staff group.');
        }
    }

    public function deactivate(int $id): void
    {
        try {
            $this->service->deactivate($id);
            $this->respondJson(['success' => true]);
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to deactivate staff group.');
        }
    }

    public function attachStaff(int $id, int $staffId): void
    {
        try {
            $this->service->attachStaff($id, $staffId);
            $this->respondJson(['success' => true], 201);
        } catch (\DomainException|\InvalidArgumentException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to attach staff member.');
        }
    }

    public function detachStaff(int $id, int $staffId): void
    {
        try {
            $this->service->detachStaff($id, $staffId);
            $this->respondJson(['success' => true]);
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to detach staff member.');
        }
    }

    /**
     * JSON: assigned permission ids for the group + full `permissions` catalog (same source as role grants).
     */
    public function permissions(int $id): void
    {
        try {
            $state = $this->groupPermissions->getAssignmentStateForAdmin($id);
            $this->respondJson(['success' => true, 'data' => $state]);
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to load staff group permissions.');
        }
    }

    /**
     * JSON/form: replace the group's `staff_group_permissions` set (removes unlisted ids). Body: `{ "permission_ids": [1,2,3] }` or `permission_ids[]` form keys.
     */
    public function replacePermissions(int $id): void
    {
        try {
            $ids = $this->parsePermissionIdsFromRequest();
        } catch (\InvalidArgumentException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());

            return;
        }
        try {
            $this->groupPermissions->replacePermissions($id, $ids);
            $after = $this->groupPermissions->getAssignmentStateForAdmin($id);
            $this->respondJson([
                'success' => true,
                'data' => ['assigned_permission_ids' => $after['assigned_permission_ids']],
            ]);
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to update staff group permissions.');
        }
    }

    /**
     * @return list<int>
     */
    private function parsePermissionIdsFromRequest(): array
    {
        $decoded = $this->readJsonRequestBody();
        if (is_array($decoded) && array_key_exists('permission_ids', $decoded)) {
            $rawList = $decoded['permission_ids'];
            if (!is_array($rawList)) {
                throw new \InvalidArgumentException('permission_ids must be an array.');
            }
        } else {
            $rawList = $_POST['permission_ids'] ?? [];
        }
        if (!is_array($rawList)) {
            throw new \InvalidArgumentException('permission_ids must be an array.');
        }
        $out = [];
        foreach ($rawList as $v) {
            if (is_int($v) || (is_string($v) && $v !== '' && ctype_digit($v))) {
                $out[] = (int) $v;
                continue;
            }
            throw new \InvalidArgumentException('permission_ids must contain only integer permission ids.');
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonRequestBody(): ?array
    {
        $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($ct, 'application/json') === false) {
            return null;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function respondJson(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
