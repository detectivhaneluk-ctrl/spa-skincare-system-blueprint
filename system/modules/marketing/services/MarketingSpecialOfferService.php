<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Core\Auth\AuthService;
use Core\Branch\BranchContext;
use Core\Errors\AccessDeniedException;
use Modules\Marketing\Repositories\MarketingSpecialOfferRepository;

final class MarketingSpecialOfferService
{
    /**
     * H-006 enforced truth: definitions are catalog-only; activation is blocked in service and repository until a pricing consumer exists.
     */
    public const ADMIN_ONLY_EXECUTION_MESSAGE = 'Special offers are catalog-only: not wired to invoice, booking, or checkout pricing. Activation is disabled in code.';

    public function __construct(
        private MarketingSpecialOfferRepository $repo,
        private BranchContext $branchContext,
        private AuthService $auth
    ) {
    }

    public function isStorageReady(): bool
    {
        return $this->repo->isStorageReady();
    }

    /**
     * @param array{name?:string,code?:string,origin?:string,adjustment_type?:string,offer_option?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function listForCurrentBranch(array $filters): array
    {
        $branchId = $this->requireBranchId();

        return $this->repo->listForBranch($branchId, $filters);
    }

    public function createForCurrentBranch(array $input): int
    {
        $branchId = $this->requireBranchId();
        $payload = $this->validatedPayload($input, $branchId, null);

        return $this->repo->insert([
            'branch_id' => $branchId,
            'name' => $payload['name'],
            'code' => $payload['code'],
            'origin' => $payload['origin'],
            'adjustment_type' => $payload['adjustment_type'],
            'adjustment_value' => $payload['adjustment_value'],
            'offer_option' => $payload['offer_option'],
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'sort_order' => $this->repo->nextSortOrder($branchId),
            'is_active' => 0,
            'created_by' => $this->currentUserId(),
            'updated_by' => $this->currentUserId(),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getForCurrentBranch(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $branchId = $this->requireBranchId();

        return $this->repo->findInBranch($id, $branchId);
    }

    public function updateForCurrentBranch(int $id, array $input): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid special offer id.');
        }
        $branchId = $this->requireBranchId();
        if ($this->repo->findInBranch($id, $branchId) === null) {
            throw new \InvalidArgumentException('Special offer not found.');
        }
        $payload = $this->validatedPayload($input, $branchId, $id);
        $this->repo->updateInBranch($id, $branchId, [
            'name' => $payload['name'],
            'code' => $payload['code'],
            'origin' => $payload['origin'],
            'adjustment_type' => $payload['adjustment_type'],
            'adjustment_value' => $payload['adjustment_value'],
            'offer_option' => $payload['offer_option'],
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'is_active' => 0,
            'updated_by' => $this->currentUserId(),
        ]);
    }

    public function toggleActiveForCurrentBranch(int $id): bool
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid special offer id.');
        }
        $branchId = $this->requireBranchId();
        $row = $this->repo->findInBranch($id, $branchId);
        if ($row === null) {
            throw new \InvalidArgumentException('Special offer not found.');
        }
        $currentlyActive = ((int) ($row['is_active'] ?? 0)) === 1;
        if (!$currentlyActive) {
            throw new \InvalidArgumentException(
                'Cannot activate: special offers are not wired to booking, checkout, or invoice pricing. ' .
                'Definitions are stored only; repository activation is also blocked (H-006).'
            );
        }
        $this->repo->setActiveInBranch($id, $branchId, false, $this->currentUserId());

        return false;
    }

    public function softDeleteForCurrentBranch(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $branchId = $this->requireBranchId();
        $this->repo->softDeleteInBranch($id, $branchId, $this->currentUserId());
    }

    private function requireBranchId(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new AccessDeniedException('Branch context is required for special offers.');
        }

        return (int) $branchId;
    }

    private function currentUserId(): ?int
    {
        $user = $this->auth->user();

        return isset($user['id']) ? (int) $user['id'] : null;
    }

    /**
     * @return array{name:string,code:string,origin:string,adjustment_type:string,adjustment_value:float,offer_option:string,start_date:?string,end_date:?string}
     */
    private function validatedPayload(array $input, int $branchId, ?int $excludeId): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if ($name === '' || $code === '') {
            throw new \InvalidArgumentException('Name and code are required.');
        }
        if ($this->repo->codeExistsInBranch($branchId, $code, $excludeId)) {
            throw new \InvalidArgumentException('Promo code already exists in this branch.');
        }
        $origin = trim((string) ($input['origin'] ?? 'manual'));
        $allowedOrigins = ['manual', 'auto'];
        if (!in_array($origin, $allowedOrigins, true)) {
            throw new \InvalidArgumentException('Invalid origin.');
        }
        $adjType = trim((string) ($input['adjustment_type'] ?? 'percent'));
        if (!in_array($adjType, ['percent', 'fixed'], true)) {
            throw new \InvalidArgumentException('Invalid adjustment type.');
        }
        $adjValue = (float) ($input['adjustment_value'] ?? 0);
        if ($adjValue <= 0) {
            throw new \InvalidArgumentException('Adjustment value must be greater than zero.');
        }
        $offerOption = trim((string) ($input['offer_option'] ?? 'all'));
        $allowedOptions = ['all', 'hide_from_customer', 'internal_only'];
        if (!in_array($offerOption, $allowedOptions, true)) {
            throw new \InvalidArgumentException('Invalid offer option.');
        }
        $startDate = $this->normalizeDate($input['start_date'] ?? null, 'start date');
        $endDate = $this->normalizeDate($input['end_date'] ?? null, 'end date');
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            throw new \InvalidArgumentException('End date cannot be before start date.');
        }

        return [
            'name' => mb_substr($name, 0, 160),
            'code' => mb_substr($code, 0, 60),
            'origin' => $origin,
            'adjustment_type' => $adjType,
            'adjustment_value' => $adjValue,
            'offer_option' => $offerOption,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    private function normalizeDate(mixed $value, string $label): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if (!$dt || $dt->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException('Invalid ' . $label . '. Use YYYY-MM-DD.');
        }

        return $raw;
    }
}

