<?php

declare(strict_types=1);

namespace Modules\Payroll\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Branch\BranchContext;
use Core\Permissions\PermissionService;
use Modules\Payroll\Repositories\PayrollCompensationRuleRepository;
use Modules\Payroll\Services\PayrollRuleService;
use Modules\Payroll\Services\PayrollService;

final class PayrollRuleController
{
    public function __construct(
        private PayrollCompensationRuleRepository $rules,
        private PayrollRuleService $ruleService,
        private BranchContext $branchContext,
        private AuthService $auth,
        private PermissionService $perms,
    ) {
    }

    public function index(): void
    {
        $this->requireManage();
        $branchId = $this->branchContext->getCurrentBranchId();
        $items = $this->rules->listAllForBranchFilter($branchId, 200, 0);
        $flash = flash();
        $title = 'Compensation rules';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/payroll/views/rules/index.php');
    }

    public function create(): void
    {
        $this->requireManage();
        $rule = $this->emptyRuleForm();
        $errors = [];
        $title = 'Create compensation rule';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/payroll/views/rules/create.php');
    }

    public function store(): void
    {
        $this->requireManage();
        $data = $this->parseRulePost();
        $errors = $this->validateRule($data, true);
        if ($errors !== []) {
            $rule = array_merge($this->emptyRuleForm(), $data);
            $title = 'Create compensation rule';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/payroll/views/rules/create.php');
            return;
        }
        try {
            $user = $this->auth->user();
            $uid = $user ? (int) $user['id'] : null;
            $this->ruleService->createRule($data, $uid);
        } catch (\DomainException $e) {
            $errors = ['_general' => $e->getMessage()];
            $rule = array_merge($this->emptyRuleForm(), $this->parseRulePost());
            $title = 'Create compensation rule';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/payroll/views/rules/create.php');
            return;
        }
        flash('success', 'Rule created.');
        header('Location: /payroll/rules');
        exit;
    }

    public function edit(int $id): void
    {
        $this->requireManage();
        $rule = $this->rules->find($id);
        if (!$rule) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($rule)) {
            return;
        }
        $errors = [];
        $title = 'Edit compensation rule';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/payroll/views/rules/edit.php');
    }

    public function update(int $id): void
    {
        $this->requireManage();
        $rule = $this->rules->find($id);
        if (!$rule) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($rule)) {
            return;
        }
        $data = $this->parseRulePost();
        $errors = $this->validateRule($data, false);
        if ($errors !== []) {
            $rule = array_merge($rule, $data);
            $title = 'Edit compensation rule';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/payroll/views/rules/edit.php');
            return;
        }
        try {
            $user = $this->auth->user();
            $uid = $user ? (int) $user['id'] : null;
            $this->ruleService->updateRule($id, $data, $uid);
        } catch (\DomainException $e) {
            $errors = ['_general' => $e->getMessage()];
            $rule = array_merge($rule, $data);
            $title = 'Edit compensation rule';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/payroll/views/rules/edit.php');
            return;
        }
        flash('success', 'Rule updated.');
        header('Location: /payroll/rules');
        exit;
    }

    private function requireManage(): void
    {
        $user = $this->auth->user();
        if (!$user || !$this->perms->has((int) $user['id'], 'payroll.manage')) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            exit;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRuleForm(): array
    {
        return [
            'name' => '',
            'branch_id' => $this->branchContext->getCurrentBranchId(),
            'staff_id' => '',
            'service_id' => '',
            'service_category_id' => '',
            'rule_kind' => PayrollService::RULE_PERCENT,
            'rate_percent' => '',
            'fixed_amount' => '',
            'currency' => '',
            'priority' => 0,
            'is_active' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRulePost(): array
    {
        $kind = (string) ($_POST['rule_kind'] ?? PayrollService::RULE_PERCENT);
        if (!in_array($kind, [PayrollService::RULE_PERCENT, PayrollService::RULE_FIXED], true)) {
            $kind = PayrollService::RULE_PERCENT;
        }

        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'branch_id' => $this->nullableInt($_POST['branch_id'] ?? null),
            'staff_id' => $this->nullableInt($_POST['staff_id'] ?? null),
            'service_id' => $this->nullableInt($_POST['service_id'] ?? null),
            'service_category_id' => $this->nullableInt($_POST['service_category_id'] ?? null),
            'rule_kind' => $kind,
            'rate_percent' => $_POST['rate_percent'] !== '' && $_POST['rate_percent'] !== null
                ? (float) $_POST['rate_percent']
                : null,
            'fixed_amount' => $_POST['fixed_amount'] !== '' && $_POST['fixed_amount'] !== null
                ? (float) $_POST['fixed_amount']
                : null,
            'currency' => trim((string) ($_POST['currency'] ?? '')) ?: null,
            'priority' => (int) ($_POST['priority'] ?? 0),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateRule(array $data, bool $isCreate): array
    {
        $errors = [];
        if (!in_array($data['rule_kind'] ?? '', [PayrollService::RULE_PERCENT, PayrollService::RULE_FIXED], true)) {
            $errors['rule_kind'] = 'Invalid rule kind.';
        }
        if (($data['rule_kind'] ?? '') === PayrollService::RULE_PERCENT) {
            if ($data['rate_percent'] === null || (float) $data['rate_percent'] <= 0) {
                $errors['rate_percent'] = 'Enter a positive rate percent.';
            }
        } else {
            if ($data['fixed_amount'] === null || (float) $data['fixed_amount'] <= 0) {
                $errors['fixed_amount'] = 'Enter a positive fixed amount.';
            }
            if (empty($data['currency'])) {
                $errors['currency'] = 'Currency is required for fixed rules.';
            }
        }
        if ($isCreate) {
            // branch_id may be null (global rule); enforced when branch context is set via enforceBranchOnCreate
        }

        return $errors;
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null
                ? (int) $entity['branch_id']
                : null;
            $this->branchContext->assertBranchMatchStrict($branchId);

            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }
}
