<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use Core\Audit\AuditService;
use Core\Auth\SessionAuth;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;
use Modules\Settings\Repositories\AppointmentCancellationReasonRepository;

final class AppointmentCancellationReasonService
{
    public function __construct(
        private AppointmentCancellationReasonRepository $repo,
        private AuditService $audit,
        private SessionAuth $auth,
        private RequestContextHolder $contextHolder,
        private AuthorizerInterface $authorizer,
    ) {
    }

    public function isStorageReady(): bool
    {
        return $this->repo->isTableAvailable();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCurrentOrganization(bool $activeOnly = false): array
    {
        if (!$this->isStorageReady()) {
            return [];
        }
        $orgId = $this->repo->organizationId();

        return $this->repo->listByOrganization($orgId, $activeOnly);
    }

    /**
     * @return array{id:int}
     */
    public function create(array $input): array
    {
        $ctx = $this->contextHolder->requireContext();
        $ctx->requireResolvedTenant();
        $this->authorizer->requireAuthorized($ctx, ResourceAction::BRANCH_SETTINGS_MANAGE, ResourceRef::collection('branch-settings'));
        $orgId = $this->repo->organizationId();
        $row = $this->validateAndNormalize($input, null);
        $id = $this->repo->insert([
            'organization_id' => $orgId,
            'branch_id' => 0,
            'code' => $row['code'],
            'name' => $row['name'],
            'applies_to' => $row['applies_to'],
            'sort_order' => $row['sort_order'],
            'is_active' => $row['is_active'] ? 1 : 0,
        ]);
        $this->audit->log('cancellation_reason_created', 'settings', $id, $this->currentUserId(), null, [
            'organization_id' => $orgId,
            'code' => $row['code'],
        ]);

        return ['id' => $id];
    }

    public function update(int $id, array $input): void
    {
        $ctx = $this->contextHolder->requireContext();
        $ctx->requireResolvedTenant();
        $this->authorizer->requireAuthorized($ctx, ResourceAction::BRANCH_SETTINGS_MANAGE, ResourceRef::instance('branch-settings', $id));
        $orgId = $this->repo->organizationId();
        $existing = $this->repo->findById($orgId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Cancellation reason not found.');
        }
        $row = $this->validateAndNormalize($input, $id);
        $this->repo->update($orgId, $id, [
            'code' => $row['code'],
            'name' => $row['name'],
            'applies_to' => $row['applies_to'],
            'sort_order' => $row['sort_order'],
            'is_active' => $row['is_active'] ? 1 : 0,
        ]);
        $this->audit->log('cancellation_reason_updated', 'settings', $id, $this->currentUserId(), null, [
            'organization_id' => $orgId,
            'code' => $row['code'],
        ]);
    }

    public function delete(int $id): void
    {
        $ctx = $this->contextHolder->requireContext();
        $ctx->requireResolvedTenant();
        $this->authorizer->requireAuthorized($ctx, ResourceAction::BRANCH_SETTINGS_MANAGE, ResourceRef::instance('branch-settings', $id));
        $orgId = $this->repo->organizationId();
        $existing = $this->repo->findById($orgId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Cancellation reason not found.');
        }
        $this->repo->softDelete($orgId, $id);
        $this->audit->log('cancellation_reason_deleted', 'settings', $id, $this->currentUserId(), null, [
            'organization_id' => $orgId,
            'code' => (string) ($existing['code'] ?? ''),
        ]);
    }

    public function findActiveReasonForCurrentOrganization(int $id, string $appliesTo): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if (!$this->isStorageReady()) {
            return null;
        }
        $orgId = $this->repo->organizationId();

        return $this->repo->findActiveByIdAndAppliesTo($orgId, $id, $appliesTo);
    }

    private function validateAndNormalize(array $input, ?int $excludeId): array
    {
        if (!$this->isStorageReady()) {
            throw new \RuntimeException('Cancellation reasons storage is unavailable. Apply migration.');
        }
        $codeRaw = strtolower(trim((string) ($input['code'] ?? '')));
        $code = preg_replace('/[^a-z0-9_]/', '_', $codeRaw) ?? '';
        $code = trim($code, '_');
        if ($code === '') {
            throw new \InvalidArgumentException('Reason code is required.');
        }
        if (strlen($code) > 64) {
            $code = substr($code, 0, 64);
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Reason name is required.');
        }
        if (strlen($name) > 120) {
            $name = substr($name, 0, 120);
        }
        $appliesTo = strtolower(trim((string) ($input['applies_to'] ?? 'cancellation')));
        if (!in_array($appliesTo, ['cancellation', 'no_show', 'both'], true)) {
            throw new \InvalidArgumentException('Reason applies_to must be cancellation, no_show, or both.');
        }
        $sortOrder = (int) ($input['sort_order'] ?? 0);
        if ($sortOrder < 0) {
            $sortOrder = 0;
        }
        $isActive = !empty($input['is_active']);
        $orgId = $this->repo->organizationId();
        if ($this->repo->codeExists($orgId, $code, $excludeId)) {
            throw new \InvalidArgumentException('Reason code must be unique in this tenant.');
        }

        return [
            'code' => $code,
            'name' => $name,
            'applies_to' => $appliesTo,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ];
    }

    private function currentUserId(): ?int
    {
        $id = $this->auth->id();

        return $id !== null ? (int) $id : null;
    }
}

