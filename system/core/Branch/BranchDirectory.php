<?php

declare(strict_types=1);

namespace Core\Branch;

use Core\App\Database;
use Core\Errors\AccessDeniedException;
use Core\Organization\OrganizationContext;
use Core\Organization\OrganizationScopedBranchAssert;

/**
 * Branch catalog: active = `branches.deleted_at` IS NULL. Used by {@see BranchContextMiddleware}, public validators,
 * and operational UI selectors.
 *
 * **Tenant-internal catalog (default):** {@see self::getActiveBranchesForSelection()}, {@see self::listAllBranchesForAdmin()},
 * {@see self::getBranchByIdForAdmin()}, {@see self::createBranch()}, {@see self::updateBranch()}, {@see self::softDeleteBranch()}
 * require {@see OrganizationContext::MODE_BRANCH_DERIVED} with a positive organization id (WAVE-02). No global listing,
 * no id-only admin fetch, and no implicit lowest-org pin on create.
 *
 * **Tenant entry resolver:** {@see self::listAllActiveBranchesUnscopedForTenantEntryResolver()} only — explicit opt-in when
 * organization context may still be unresolved ({@code GET /tenant-entry}).
 *
 * **Platform / single-org bootstrap (explicit):** {@see self::listAllBranchesIncludingDeletedGloballyForPlatformAdmin()} and
 * {@see self::createBranchPinningLowestActiveOrganizationWhenContextUnresolved()} for true control-plane or installer use only.
 */
final class BranchDirectory
{
    /** @see AccessDeniedException */
    public const EXCEPTION_TENANT_BRANCH_CATALOG_CONTEXT = 'Branch-derived organization context is required for branch catalog operations.';

    public function __construct(
        private Database $db,
        private OrganizationContext $organizationContext,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
    ) {
    }

    public function isActiveBranchId(int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM branches WHERE id = ? AND deleted_at IS NULL',
            [$branchId]
        );

        return $row !== null;
    }

    /**
     * Tenant entry / resolver only: all active branches in the deployment (caller filters to allowed ids).
     * Returns organization_id and organization_name so the chooser can group branches per org when a
     * multi-org principal sees same-name branches from different organisations.
     * Do not use from tenant-protected module controllers.
     *
     * @return list<array{id: int|string, name: string, code: string|null, organization_id: int|string, organization_name: string}>
     */
    public function listAllActiveBranchesUnscopedForTenantEntryResolver(): array
    {
        return $this->db->fetchAll(
            'SELECT b.id, b.name, b.code, b.organization_id,
                    o.name AS organization_name
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id
             WHERE b.deleted_at IS NULL
             ORDER BY o.name, b.name'
        );
    }

    /**
     * Canonical list for staff operational dropdowns (create/edit filters, register, invoices, etc.).
     *
     * @return list<array{id: int|string, name: string, code: string|null}>
     *
     * @throws AccessDeniedException when tenant branch catalog context is not satisfied
     */
    public function getActiveBranchesForSelection(): array
    {
        $this->assertTenantInternalBranchCatalogContext();
        $orgId = (int) $this->organizationContext->getCurrentOrganizationId();

        return $this->db->fetchAll(
            'SELECT id, name, code FROM branches WHERE deleted_at IS NULL AND organization_id = ? ORDER BY name',
            [$orgId]
        );
    }

    /**
     * Admin index: all branches including soft-deleted, scoped to resolved organization.
     *
     * @return list<array{id: int|string, name: string, code: string|null, deleted_at: string|null}>
     *
     * @throws AccessDeniedException when tenant branch catalog context is not satisfied
     */
    public function listAllBranchesForAdmin(): array
    {
        $this->assertTenantInternalBranchCatalogContext();
        $orgId = (int) $this->organizationContext->getCurrentOrganizationId();

        return $this->db->fetchAll(
            'SELECT id, name, code, deleted_at FROM branches WHERE organization_id = ? ORDER BY name',
            [$orgId]
        );
    }

    /**
     * Single row for admin edit (includes soft-deleted), org-scoped only.
     *
     * @return array{id: int|string, name: string, code: string|null, deleted_at: string|null}|null
     *
     * @throws AccessDeniedException when tenant branch catalog context is not satisfied
     */
    public function getBranchByIdForAdmin(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $this->assertTenantInternalBranchCatalogContext();
        $orgId = (int) $this->organizationContext->getCurrentOrganizationId();

        return $this->db->fetchOne(
            'SELECT id, name, code, deleted_at FROM branches WHERE id = ? AND organization_id = ?',
            [$id, $orgId]
        );
    }

    /**
     * Platform / installer: every branch row in the database (no org filter).
     *
     * @return list<array{id: int|string, name: string, code: string|null, deleted_at: string|null}>
     */
    public function listAllBranchesIncludingDeletedGloballyForPlatformAdmin(): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, code, deleted_at FROM branches ORDER BY name'
        );
    }

    /**
     * @throws AccessDeniedException when tenant branch catalog context is not satisfied
     */
    public function createBranch(string $name, ?string $code): int
    {
        $this->assertTenantInternalBranchCatalogContext();
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Branch name is required.');
        }
        if (strlen($name) > 255) {
            throw new \InvalidArgumentException('Branch name is too long.');
        }
        $code = $this->normalizeCode($code);
        if ($code !== null && $this->isCodeTaken($code, null)) {
            throw new \InvalidArgumentException('That branch code is already in use.');
        }
        if ($this->isNameTaken($name, null)) {
            throw new \InvalidArgumentException('That branch name is already in use within this organisation.');
        }

        $organizationId = (int) $this->organizationContext->getCurrentOrganizationId();

        return $this->db->insert('branches', [
            'name' => $name,
            'code' => $code,
            'organization_id' => $organizationId,
        ]);
    }

    /**
     * Bootstrap / repair only: creates a branch, pinning `organization_id` to the lowest active org when HTTP tenant
     * context is not branch-derived. Not for normal tenant admin routes.
     */
    public function createBranchPinningLowestActiveOrganizationWhenContextUnresolved(string $name, ?string $code): int
    {
        if ($this->organizationContext->getCurrentOrganizationId() !== null
            && (int) $this->organizationContext->getCurrentOrganizationId() > 0
            && $this->organizationContext->getResolutionMode() === OrganizationContext::MODE_BRANCH_DERIVED) {
            return $this->createBranch($name, $code);
        }

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Branch name is required.');
        }
        if (strlen($name) > 255) {
            throw new \InvalidArgumentException('Branch name is too long.');
        }
        $code = $this->normalizeCode($code);
        if ($code !== null && $this->isCodeTakenGlobal($code, null)) {
            throw new \InvalidArgumentException('That branch code is already in use.');
        }
        $organizationId = $this->lowestActiveOrganizationId();

        return $this->db->insert('branches', [
            'name' => $name,
            'code' => $code,
            'organization_id' => $organizationId,
        ]);
    }

    /**
     * Single-org deployment default: smallest id among non-deleted organizations (see FOUNDATION-08).
     *
     * @throws \RuntimeException when no active organization exists
     */
    private function lowestActiveOrganizationId(): int
    {
        $row = $this->db->fetchOne(
            'SELECT MIN(id) AS id FROM organizations WHERE deleted_at IS NULL'
        );
        if ($row === null || $row['id'] === null || (int) $row['id'] <= 0) {
            throw new \RuntimeException(
                'No active organization is available for new branches. Apply migration 086 and ensure organizations has at least one row.'
            );
        }

        return (int) $row['id'];
    }

    public function updateBranch(int $id, string $name, ?string $code): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid branch.');
        }
        $existing = $this->getBranchByIdForAdmin($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Branch not found.');
        }
        $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($id);
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Branch name is required.');
        }
        if (strlen($name) > 255) {
            throw new \InvalidArgumentException('Branch name is too long.');
        }
        $code = $this->normalizeCode($code);
        if ($code !== null && $this->isCodeTaken($code, $id)) {
            throw new \InvalidArgumentException('That branch code is already in use.');
        }
        if ($this->isNameTaken($name, $id)) {
            throw new \InvalidArgumentException('That branch name is already in use within this organisation.');
        }
        $orgId = (int) $this->organizationContext->getCurrentOrganizationId();
        $this->db->query(
            'UPDATE branches SET name = ?, code = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND organization_id = ?',
            [$name, $code, $id, $orgId]
        );
    }

    /** Sets deleted_at when currently active. Idempotent when already soft-deleted. */
    public function softDeleteBranch(int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid branch.');
        }
        $row = $this->getBranchByIdForAdmin($id);
        if ($row === null) {
            throw new \InvalidArgumentException('Branch not found.');
        }
        $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($id);
        if ($row['deleted_at'] !== null && $row['deleted_at'] !== '') {
            return;
        }
        $orgId = (int) $this->organizationContext->getCurrentOrganizationId();
        $this->db->query(
            'UPDATE branches SET deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND organization_id = ? AND deleted_at IS NULL',
            [$id, $orgId]
        );
    }

    private function assertTenantInternalBranchCatalogContext(): void
    {
        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId === null || $orgId <= 0) {
            throw new AccessDeniedException(self::EXCEPTION_TENANT_BRANCH_CATALOG_CONTEXT);
        }
        if ($this->organizationContext->getResolutionMode() !== OrganizationContext::MODE_BRANCH_DERIVED) {
            throw new AccessDeniedException(self::EXCEPTION_TENANT_BRANCH_CATALOG_CONTEXT);
        }
    }

    private function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = trim($code);

        return $code === '' ? null : substr($code, 0, 50);
    }

    private function isCodeTaken(string $code, ?int $excludeId): bool
    {
        $this->assertTenantInternalBranchCatalogContext();
        $orgId = (int) $this->organizationContext->getCurrentOrganizationId();
        if ($excludeId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM branches WHERE code = ? AND organization_id = ? AND id <> ? LIMIT 1',
                [$code, $orgId, $excludeId]
            );
        } else {
            $row = $this->db->fetchOne(
                'SELECT id FROM branches WHERE code = ? AND organization_id = ? LIMIT 1',
                [$code, $orgId]
            );
        }

        return $row !== null;
    }

    /**
     * Name must be unique among non-deleted branches within the same organisation.
     * Called by {@see createBranch()} and {@see updateBranch()} to prevent selector duplication.
     * Canonical identity is branch id; duplicate names cause visually identical options in UI selectors.
     */
    private function isNameTaken(string $name, ?int $excludeId): bool
    {
        $this->assertTenantInternalBranchCatalogContext();
        $orgId = (int) $this->organizationContext->getCurrentOrganizationId();
        if ($excludeId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM branches WHERE name = ? AND organization_id = ? AND deleted_at IS NULL AND id <> ? LIMIT 1',
                [$name, $orgId, $excludeId]
            );
        } else {
            $row = $this->db->fetchOne(
                'SELECT id FROM branches WHERE name = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
                [$name, $orgId]
            );
        }

        return $row !== null;
    }

    private function isCodeTakenGlobal(string $code, ?int $excludeId): bool
    {
        if ($excludeId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM branches WHERE code = ? AND id <> ? LIMIT 1',
                [$code, $excludeId]
            );
        } else {
            $row = $this->db->fetchOne('SELECT id FROM branches WHERE code = ? LIMIT 1', [$code]);
        }

        return $row !== null;
    }
}
