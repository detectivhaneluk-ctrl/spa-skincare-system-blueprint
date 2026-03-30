<?php

declare(strict_types=1);

namespace Core\Branch;

use Core\Errors\AccessDeniedException;

/**
 * Request-scoped current branch. Set by BranchContextMiddleware; read by services and controllers.
 * When non-null, the id is an **active** branch (`branches.deleted_at` IS NULL); soft-deleted ids are never set.
 * When set, branch-scoped writes must match; when null, global/superadmin access is allowed (or no valid branch resolved).
 *
 * **Organization:** {@see \Core\Middleware\OrganizationContextMiddleware} runs next and derives {@see \Core\Organization\OrganizationContext} from this branch (or single-org fallback when branch is null).
 *
 * **A-006 branch vs global:** Use {@see self::assertBranchMatchStrict} when the entity must carry a concrete branch id that equals
 * context. Use {@see self::assertBranchMatchOrGlobalEntity} when `branch_id` may be null (organization-wide / global row) and that
 * is intentionally allowed alongside a branch-scoped operator session — this is **not** a branch “match”, only “allowed”.
 */
final class BranchContext
{
    private ?int $currentBranchId = null;

    public function setCurrentBranchId(?int $branchId): void
    {
        $this->currentBranchId = $branchId;
    }

    public function getCurrentBranchId(): ?int
    {
        return $this->currentBranchId;
    }

    /**
     * When request branch context is set, the entity must have a **positive** `branch_id` equal to that context.
     * Fails closed: null, zero, or negative entity branch with active context is denied (not “matched” and not silently “global allowed”).
     *
     * No-op when context is unset (HQ / no resolved branch).
     */
    public function assertBranchMatchStrict(?int $entityBranchId): void
    {
        if ($this->currentBranchId === null) {
            return;
        }
        if ($entityBranchId === null || $entityBranchId <= 0) {
            throw new AccessDeniedException('This action requires a branch-scoped record; global (branchless) rows are not allowed here.');
        }
        if ((int) $entityBranchId !== (int) $this->currentBranchId) {
            throw new AccessDeniedException('This record belongs to another branch and cannot be modified.');
        }
    }

    /**
     * When request branch context is set: allow entities with **null** `branch_id` (global/org-wide rows) without treating that as a
     * branch identity match; if the entity has a branch id, it must equal context.
     *
     * No-op when context is unset.
     */
    public function assertBranchMatchOrGlobalEntity(?int $entityBranchId): void
    {
        if ($this->currentBranchId === null) {
            return;
        }
        if ($entityBranchId === null) {
            return;
        }
        if ((int) $entityBranchId !== (int) $this->currentBranchId) {
            throw new AccessDeniedException('This record belongs to another branch and cannot be modified.');
        }
    }

    /**
     * For create payloads: when context is set, ensure data has branch_id equal to context.
     * If context is set and data has a different branch_id, throws. If missing, sets it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function enforceBranchOnCreate(array $data, string $key = 'branch_id'): array
    {
        if ($this->currentBranchId === null) {
            return $data;
        }
        $provided = isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null
            ? (int) $data[$key]
            : null;
        if ($provided !== null && $provided !== $this->currentBranchId) {
            throw new AccessDeniedException('Branch does not match your assigned branch.');
        }
        $data[$key] = $this->currentBranchId;
        return $data;
    }

    /**
     * When request branch context is set, the entity's branch_id cannot be reassigned via update (including to global or another branch).
     * If the payload repeats the same value, it is removed so the row is not redundantly rewritten.
     *
     * @param array<string, mixed> $data
     */
    public function enforceBranchIdImmutableWhenScoped(array &$data, ?int $existingBranchId, string $key = 'branch_id'): void
    {
        if ($this->currentBranchId === null) {
            return;
        }
        if (!array_key_exists($key, $data)) {
            return;
        }
        $posted = $this->normalizeOptionalBranchId($data[$key]);
        $existing = $this->normalizeOptionalBranchId($existingBranchId);
        if ($posted !== $existing) {
            throw new AccessDeniedException('Branch assignment cannot be changed while working in a branch context.');
        }
        unset($data[$key]);
    }

    private function normalizeOptionalBranchId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }
}
