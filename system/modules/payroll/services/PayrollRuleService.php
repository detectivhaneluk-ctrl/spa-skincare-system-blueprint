<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Core\Organization\OrganizationScopedBranchAssert;
use Modules\Payroll\Repositories\PayrollCompensationRuleRepository;

/**
 * Centralized write discipline for payroll compensation rules.
 */
final class PayrollRuleService
{
    public function __construct(
        private PayrollCompensationRuleRepository $rules,
        private BranchContext $branchContext,
        private OrganizationContext $organizationContext,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRule(array $data, ?int $userId): int
    {
        $data = $this->branchContext->enforceBranchOnCreate($data, 'branch_id');
        $this->assertCreateScope($data);

        return $this->rules->create(array_merge($data, [
            'created_by' => $userId,
            'updated_by' => $userId,
        ]));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateRule(int $id, array $data, ?int $userId): void
    {
        $rule = $this->rules->find($id);
        if (!$rule) {
            throw new \DomainException('Rule not found.');
        }

        $this->branchContext->assertBranchMatchStrict(
            isset($rule['branch_id']) && $rule['branch_id'] !== '' && $rule['branch_id'] !== null
                ? (int) $rule['branch_id']
                : null
        );

        $candidateBranchId = array_key_exists('branch_id', $data)
            ? ($data['branch_id'] !== null && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null)
            : (isset($rule['branch_id']) && $rule['branch_id'] !== '' && $rule['branch_id'] !== null ? (int) $rule['branch_id'] : null);

        if ($candidateBranchId !== null) {
            $ctxBranch = $this->branchContext->getCurrentBranchId();
            if ($ctxBranch !== null && $candidateBranchId !== $ctxBranch) {
                throw new \DomainException('Branch does not match your assigned branch.');
            }
            if ($this->organizationContext->getCurrentOrganizationId() !== null) {
                $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($candidateBranchId);
            }
        }

        $this->rules->update($id, array_merge($data, ['updated_by' => $userId]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertCreateScope(array $data): void
    {
        if ($this->organizationContext->getCurrentOrganizationId() !== null) {
            $branchId = $data['branch_id'] ?? null;
            if ($branchId === null || $branchId === '') {
                throw new \DomainException('Rule branch is required when organization context is resolved.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization((int) $branchId);
        }
    }
}
