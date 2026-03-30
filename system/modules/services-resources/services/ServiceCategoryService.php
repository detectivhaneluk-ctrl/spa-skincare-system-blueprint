<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\ServicesResources\Repositories\ServiceCategoryRepository;

final class ServiceCategoryService
{
    public function __construct(
        private ServiceCategoryRepository $repo,
        private AuditService $audit,
        private BranchContext $branchContext
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $this->validateParentHierarchy(null, $data);
            $id = $this->repo->create($data);
            $this->audit->log('service_category_created', 'service_category', $id, $this->userId(), $data['branch_id'] ?? null, [
                'service_category' => $data,
            ]);
            return $id;
        }, 'service category create');
    }

    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $current = $this->repo->find($id);
            if (!$current) throw new \RuntimeException('Not found');
            $this->branchContext->assertBranchMatchOrGlobalEntity($current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null);
            $this->validateParentHierarchy($id, $data, $current);
            $this->repo->update($id, $data);
            $this->audit->log('service_category_updated', 'service_category', $id, $this->userId(), $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => array_merge($current, $data),
            ]);
        }, 'service category update');
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $cat = $this->repo->find($id);
            if (!$cat) throw new \RuntimeException('Not found');
            $this->branchContext->assertBranchMatchOrGlobalEntity($cat['branch_id'] !== null && $cat['branch_id'] !== '' ? (int) $cat['branch_id'] : null);
            $this->repo->softDelete($id);
            $this->audit->log('service_category_deleted', 'service_category', $id, $this->userId(), $cat['branch_id'] ?? null, [
                'service_category' => $cat,
            ]);
        }, 'service category delete');
    }

    private function userId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current for update
     */
    private function validateParentHierarchy(?int $categoryId, array $data, ?array $current = null): void
    {
        $parentId = null;
        if (array_key_exists('parent_id', $data)) {
            $raw = $data['parent_id'];
            $parentId = ($raw === null || $raw === '') ? null : (int) $raw;
        } elseif ($current !== null) {
            $pb = $current['parent_id'] ?? null;
            $parentId = ($pb !== null && $pb !== '') ? (int) $pb : null;
        }

        $this->repo->assertValidParentAssignment($categoryId, $parentId);
        if ($parentId === null) {
            return;
        }

        $parentRow = $this->repo->find($parentId);
        if ($parentRow === null) {
            throw new \InvalidArgumentException('Parent category not found.');
        }

        $childBranch = $this->effectiveCategoryBranchId($data, $current);
        $this->assertParentBranchScope($childBranch, $parentRow);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current
     */
    private function effectiveCategoryBranchId(array $data, ?array $current): ?int
    {
        if (array_key_exists('branch_id', $data)) {
            $b = $data['branch_id'];
            if ($b === null || $b === '') {
                return null;
            }

            return (int) $b;
        }
        if ($current === null) {
            return null;
        }
        $b = $current['branch_id'] ?? null;

        return ($b !== null && $b !== '') ? (int) $b : null;
    }

    /**
     * Branch-scoped parent must match child branch; global parent (NULL branch) allowed under any child branch.
     *
     * @param array<string, mixed> $parentRow
     */
    private function assertParentBranchScope(?int $childBranchId, array $parentRow): void
    {
        $pBranch = $parentRow['branch_id'] ?? null;
        $pBranch = ($pBranch !== null && $pBranch !== '') ? (int) $pBranch : null;
        if ($pBranch === null) {
            return;
        }
        if ($childBranchId === null) {
            throw new \DomainException('A global service category cannot have a branch-scoped parent.');
        }
        if ($childBranchId !== $pBranch) {
            throw new \DomainException('Parent category must belong to the same branch as the child.');
        }
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $db = Application::container()->get(\Core\App\Database::class);
        $pdo = $db->connection();
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
            slog('error', 'services_resources.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Service category operation failed.');
        }
    }
}
