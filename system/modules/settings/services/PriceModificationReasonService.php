<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use Core\Audit\AuditService;
use Core\Auth\SessionAuth;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;
use Modules\Settings\Repositories\PriceModificationReasonRepository;

final class PriceModificationReasonService
{
    public function __construct(
        private PriceModificationReasonRepository $repo,
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
     * Stable helper for future dropdown consumption.
     *
     * @return list<array{id:int,code:string,name:string}>
     */
    public function listActiveForPicker(): array
    {
        $rows = $this->listForCurrentOrganization(true);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
            ];
        }

        return $out;
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
            'code' => $row['code'],
            'name' => $row['name'],
            'description' => $row['description'],
            'sort_order' => $row['sort_order'],
            'is_active' => $row['is_active'] ? 1 : 0,
        ]);
        $this->audit->log('price_modification_reason_created', 'settings', $id, $this->currentUserId(), null, [
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
            throw new \RuntimeException('Price modification reason not found.');
        }
        $row = $this->validateAndNormalize($input, $id);
        $this->repo->update($orgId, $id, [
            'code' => $row['code'],
            'name' => $row['name'],
            'description' => $row['description'],
            'sort_order' => $row['sort_order'],
            'is_active' => $row['is_active'] ? 1 : 0,
        ]);
        $event = ((int) ($existing['is_active'] ?? 1) === 1 && !$row['is_active'])
            ? 'price_modification_reason_deactivated'
            : 'price_modification_reason_updated';
        $this->audit->log($event, 'settings', $id, $this->currentUserId(), null, [
            'organization_id' => $orgId,
            'code' => $row['code'],
        ]);
    }

    public function findForCurrentOrganization(int $id): ?array
    {
        if (!$this->isStorageReady()) {
            return null;
        }
        if ($id <= 0) {
            return null;
        }

        return $this->repo->findById($this->repo->organizationId(), $id);
    }

    private function validateAndNormalize(array $input, ?int $excludeId): array
    {
        if (!$this->isStorageReady()) {
            throw new \RuntimeException('Price modification reasons storage is unavailable. Apply migration.');
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
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') {
            $description = null;
        } elseif (strlen($description) > 500) {
            $description = substr($description, 0, 500);
        }
        $sortOrder = (int) ($input['sort_order'] ?? 0);
        if ($sortOrder < 0) {
            $sortOrder = 0;
        }
        $isActive = !empty($input['is_active']);
        $orgId = $this->repo->organizationId();
        if ($this->repo->codeExists($orgId, $code, $excludeId)) {
            throw new \InvalidArgumentException('Reason code must be unique in this organization.');
        }

        return [
            'code' => $code,
            'name' => $name,
            'description' => $description,
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

