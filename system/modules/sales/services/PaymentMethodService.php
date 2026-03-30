<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Modules\Sales\Repositories\PaymentMethodRepository;

/**
 * Payment methods from DB (replaces hardcoded list). Used by payment form and validation.
 */
final class PaymentMethodService
{
    /** Code used when redeeming gift cards (not shown on manual payment form). */
    public const CODE_GIFT_CARD = 'gift_card';

    public const CODE_MAX_LENGTH = 30;
    public const NAME_MAX_LENGTH = 100;
    public const TYPE_LABEL_MAX_LENGTH = 50;

    public function __construct(private PaymentMethodRepository $repo)
    {
    }

    /**
     * List methods for the "record payment" form: active, excluding gift_card.
     *
     * @return list<array{id:int, code:string, name:string}>
     */
    public function listForPaymentForm(?int $branchId = null): array
    {
        $rows = $this->repo->listActive($branchId, self::CODE_GIFT_CARD);
        return array_map(fn (array $r) => [
            'id' => $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
        ], $rows);
    }

    /**
     * Whether the code is a valid active payment method (including gift_card when used programmatically).
     */
    public function isValidMethod(string $code, ?int $branchId = null): bool
    {
        return $this->repo->isActiveCode(trim($code), $branchId);
    }

    /**
     * Default method for "record payment" on an invoice: branch-effective allowed list (excludes gift_card),
     * prefers {@see SettingsService}-supplied default when that code is allowed, else first allowed code.
     */
    public function resolveDefaultForRecordedPayment(?int $branchId, string $settingsDefaultMethodCode): ?string
    {
        $rows = $this->repo->listActive($branchId, self::CODE_GIFT_CARD);
        if ($rows === []) {
            return null;
        }
        $codes = array_values(array_unique(array_map(fn (array $r) => (string) $r['code'], $rows)));
        $preferred = trim($settingsDefaultMethodCode);
        if ($preferred !== '' && in_array($preferred, $codes, true)) {
            return $preferred;
        }
        return $codes[0];
    }

    /**
     * Allowed methods for {@see PaymentService::create} (manual invoice payment): active for branch, not gift_card
     * (gift cards must use invoice redemption flow).
     */
    public function isAllowedForRecordedInvoicePayment(string $code, ?int $branchId = null): bool
    {
        $code = trim($code);
        if ($code === '' || strcasecmp($code, self::CODE_GIFT_CARD) === 0) {
            return false;
        }
        return $this->repo->isActiveCode($code, $branchId);
    }

    /**
     * List all payment methods for admin (global branch_id NULL only for now).
     *
     * @return list<array{id:int, code:string, name:string, type_label:string|null, is_active:int, sort_order:int}>
     */
    public function listForAdmin(?int $branchId = null): array
    {
        return $this->repo->listAll($branchId);
    }

    /**
     * Get one payment method by id for admin.
     *
     * @return array{id:int, code:string, name:string, type_label:string|null, is_active:int, sort_order:int}|null
     */
    public function getById(int $id): ?array
    {
        return $this->repo->getById($id);
    }

    /**
     * Create payment method. Code is derived from name (slug, unique). branch_id NULL = global.
     * Only keys name, type_label, is_active, sort_order are read; other input is ignored.
     *
     * @param array{name: string, type_label?: string|null, is_active?: bool, sort_order?: int} $data
     * @return int new id
     * @throws \InvalidArgumentException on validation failure
     */
    public function create(?int $branchId, array $data): int
    {
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required.');
        }
        if (strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException('Name must not exceed ' . self::NAME_MAX_LENGTH . ' characters.');
        }
        $typeLabel = $this->normalizeTypeLabel($data['type_label'] ?? null);
        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : true;
        $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;

        if ($isActive && $this->repo->existsActiveNameForBranch($branchId, $name, null)) {
            throw new \InvalidArgumentException('Another active payment method already has this name.');
        }

        $code = $this->generateUniqueCodeFromName($name, $branchId);

        return $this->repo->create($branchId, $code, $name, $typeLabel, $isActive, $sortOrder);
    }

    /**
     * Update payment method. Code is not changed.
     * Only keys name, type_label, is_active, sort_order are read; other input is ignored.
     *
     * @param array{name: string, type_label?: string|null, is_active?: bool, sort_order?: int} $data
     * @throws \InvalidArgumentException on validation failure
     */
    public function update(int $id, array $data): void
    {
        $existing = $this->repo->getById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Payment method not found.');
        }
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required.');
        }
        if (strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException('Name must not exceed ' . self::NAME_MAX_LENGTH . ' characters.');
        }
        $typeLabel = $this->normalizeTypeLabel($data['type_label'] ?? null);
        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : true;
        $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : $existing['sort_order'];

        if ($isActive && $this->repo->existsActiveNameForBranch($existing['branch_id'], $name, $id)) {
            throw new \InvalidArgumentException('Another active payment method already has this name.');
        }

        $this->repo->update($id, $name, $typeLabel, $isActive, $sortOrder);
    }

    /**
     * Archive payment method by id (non-destructive; sets is_active = 0).
     *
     * @throws \InvalidArgumentException when method does not exist
     */
    public function archive(int $id): void
    {
        $existing = $this->repo->getById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Payment method not found.');
        }
        $this->repo->archive($id);
    }

    /**
     * Generate a unique code from name (slug, max 30 chars). Appends _1, _2... if needed.
     */
    private function generateUniqueCodeFromName(string $name, ?int $branchId): string
    {
        $base = preg_replace('/[^a-z0-9]+/i', '_', trim($name));
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'method';
        }
        $base = substr(strtolower($base), 0, self::CODE_MAX_LENGTH);
        $code = $base;
        $n = 0;
        while ($this->repo->codeExistsForBranch($code, $branchId, null)) {
            $suffix = '_' . (++$n);
            $code = substr($base, 0, self::CODE_MAX_LENGTH - strlen($suffix)) . $suffix;
        }
        return $code;
    }

    private function normalizeTypeLabel(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $typeLabel = trim((string) $value);
        if ($typeLabel === '') {
            return null;
        }
        if (strlen($typeLabel) > self::TYPE_LABEL_MAX_LENGTH) {
            throw new \InvalidArgumentException('Type label must not exceed ' . self::TYPE_LABEL_MAX_LENGTH . ' characters.');
        }
        return $typeLabel;
    }
}
