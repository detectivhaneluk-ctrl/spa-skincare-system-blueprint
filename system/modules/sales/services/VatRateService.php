<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\Kernel\RequestContextHolder;
use Modules\Sales\Repositories\VatRateRepository;

/**
 * Table-backed VAT catalog (`vat_rates`). Not part of `SettingsService` flat keys.
 *
 * - `listActive` / `listForAdmin(null)` / `listAll(null)`: **global rows only** (`branch_id IS NULL`).
 * - `listActive(B)` / `listForAdmin(B)` / `listAll(B)`: **global ∪ branch B** (service form dropdowns, future branch admin).
 * - `find` / `getById` / `getRatePercentById`: **by primary key** only — no invoice-branch overlay (invoice math uses `rate_percent` from the row the service references).
 * - {@see assertActiveVatRateAssignableToServiceBranch}: **service write-time** guard so `services.vat_rate_id` cannot reference inactive or other-branch rates.
 */
final class VatRateService
{
    public const CODE_MAX_LENGTH = 30;
    public const NAME_MAX_LENGTH = 100;
    /** Maximum allowed rate_percent (reasonable upper bound for VAT). */
    public const RATE_PERCENT_MAX = 100.0;
    /** @var list<string> */
    public const ALLOWED_APPLIES_TO = ['services', 'products', 'memberships', 'add_ons'];

    public function __construct(
        private VatRateRepository $repo,
        private RequestContextHolder $contextHolder,
    ) {
    }

    /**
     * Full row by id (any `branch_id`). Callers that mean “Settings global admin” must filter `branch_id === null` (see `VatRatesController`).
     */
    public function getGlobalCatalogRateForSettingsAdmin(int $id): ?array
    {
        return $this->repo->findGlobalCatalogRateInResolvedTenantById($id);
    }

    /**
     * Active rates: `branchId === null` ⇒ global only; else global ∪ that branch (ordered).
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, rate_percent:float, is_flexible:int, price_includes_tax:int, applies_to_json:list<string>, is_active:int, sort_order:int}>
     */
    public function listActive(?int $branchId = null): array
    {
        return $this->repo->listActive($branchId);
    }

    /**
     * Admin list: same branch rule as {@see listActive} but includes inactive rows.
     * `VatRatesController` passes `null` (global catalog only).
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, rate_percent:float, is_flexible:int, price_includes_tax:int, applies_to_json:list<string>, is_active:int, sort_order:int}>
     */
    public function listForAdmin(?int $branchId = null): array
    {
        return $this->repo->listAll($branchId);
    }

    /**
     * `rate_percent` for a `vat_rates.id` (invoice service-line canonicalization). No invoice-branch filter.
     */
    public function getRatePercentById(int $vatRateId): ?float
    {
        if ($vatRateId <= 0) {
            return null;
        }
        $rate = $this->repo->findTenantVisibleRateById($vatRateId);
        return $rate !== null ? $rate['rate_percent'] : null;
    }

    /**
     * Write-time guard for `services.vat_rate_id`: must be **active** and in the same catalog slice as
     * {@see listActive} for the service's `branch_id` (global service ⇒ global rates only).
     *
     * @throws \DomainException when a positive id is missing, inactive, or scoped to another branch
     */
    public function assertActiveVatRateAssignableToServiceBranch(?int $vatRateId, ?int $serviceBranchId): void
    {
        if ($vatRateId === null || $vatRateId <= 0) {
            return;
        }
        if (!$this->repo->isActiveIdInServiceBranchCatalog($vatRateId, $serviceBranchId)) {
            throw new \DomainException(
                'Selected VAT rate is not valid for this service branch. Choose an active rate from the global catalog or the same branch.'
            );
        }
    }

    /**
     * Find active rate by code for branch.
     */
    public function findByCode(string $code, ?int $branchId = null): ?array
    {
        return $this->repo->findByCode($code, $branchId);
    }

    /**
     * Create VAT rate. Code is derived from name (slug, unique). branch_id NULL = global.
     *
     * @param array{name: string, rate_percent: float|string, is_flexible?: bool, price_includes_tax?: bool, applies_to_json?: mixed, is_active?: bool, sort_order?: int} $data
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
        $ratePercent = $this->parseRatePercent($data['rate_percent'] ?? null);
        $isFlexible = isset($data['is_flexible']) ? (bool) $data['is_flexible'] : false;
        $priceIncludesTax = isset($data['price_includes_tax']) ? (bool) $data['price_includes_tax'] : false;
        $appliesTo = $this->normalizeAppliesToTokens($data['applies_to_json'] ?? []);
        $appliesToJson = $appliesTo === [] ? null : (string) json_encode($appliesTo);
        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : true;
        $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;

        if ($isActive && $this->repo->existsActiveNameForBranch($branchId, $name, null)) {
            throw new \InvalidArgumentException('Another active VAT rate already has this name.');
        }

        $code = $this->generateUniqueCodeFromName($name, $branchId);

        return $this->repo->create($branchId, $code, $name, $ratePercent, $isFlexible, $priceIncludesTax, $appliesToJson, $isActive, $sortOrder);
    }

    /**
     * Update VAT rate. Code is not changed.
     *
     * @param array{name: string, rate_percent: float|string, is_flexible?: bool, price_includes_tax?: bool, applies_to_json?: mixed, is_active?: bool, sort_order?: int} $data
     * @throws \InvalidArgumentException on validation failure
     */
    public function updateGlobalCatalogRateForSettingsAdmin(int $id, array $data): void
    {
        $existing = $this->repo->findGlobalCatalogRateInResolvedTenantById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('VAT rate not found.');
        }
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required.');
        }
        if (strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException('Name must not exceed ' . self::NAME_MAX_LENGTH . ' characters.');
        }
        $ratePercent = $this->parseRatePercent($data['rate_percent'] ?? null);
        $isFlexible = isset($data['is_flexible']) ? (bool) $data['is_flexible'] : false;
        $priceIncludesTax = isset($data['price_includes_tax']) ? (bool) $data['price_includes_tax'] : false;
        $appliesTo = $this->normalizeAppliesToTokens($data['applies_to_json'] ?? []);
        $appliesToJson = $appliesTo === [] ? null : (string) json_encode($appliesTo);
        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : true;
        $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : $existing['sort_order'];

        if ($isActive && $this->repo->existsActiveNameForBranch($existing['branch_id'], $name, $id)) {
            throw new \InvalidArgumentException('Another active VAT rate already has this name.');
        }

        $this->repo->updateGlobalCatalogRateInResolvedTenantById($id, $name, $ratePercent, $isFlexible, $priceIncludesTax, $appliesToJson, $isActive, $sortOrder);
    }

    /**
     * Archive VAT rate by id (non-destructive; sets is_active = 0).
     *
     * @throws \InvalidArgumentException when rate does not exist
     */
    public function archiveGlobalCatalogRateForSettingsAdmin(int $id): void
    {
        $existing = $this->repo->findGlobalCatalogRateInResolvedTenantById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('VAT rate not found.');
        }
        $this->repo->archiveGlobalCatalogRateInResolvedTenantById($id);
    }

    /**
     * Settings-owned matrix write path backed by vat_rates.applies_to_json.
     *
     * @param array<int|string, mixed> $matrixInput map vat_rate_id => list<token>
     * @param list<string> $domains allowed matrix domains for this surface
     * @return array{updated_count:int}
     */
    public function bulkUpdateGlobalApplicabilityMatrix(array $matrixInput, array $domains): array
    {
        if ($domains === []) {
            throw new \InvalidArgumentException('No applicability domains configured.');
        }
        $allowedDomains = [];
        foreach ($domains as $domain) {
            $domain = trim((string) $domain);
            if ($domain === '' || !in_array($domain, self::ALLOWED_APPLIES_TO, true)) {
                throw new \InvalidArgumentException('Unknown applicability domain configured: ' . $domain);
            }
            $allowedDomains[] = $domain;
        }
        $allowedDomains = array_values(array_unique($allowedDomains));

        $activeGlobalRates = $this->repo->listActive(null);
        $ids = [];
        foreach ($activeGlobalRates as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $payload = [];
        foreach ($ids as $id) {
            $raw = $matrixInput[(string) $id] ?? $matrixInput[$id] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }
            $tokens = [];
            foreach ($raw as $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }
                if (!in_array($token, $allowedDomains, true)) {
                    throw new \InvalidArgumentException('Unknown applicability token for VAT rate #' . $id . ': ' . $token);
                }
                $tokens[] = $token;
            }
            $tokens = array_values(array_unique($tokens));
            sort($tokens);
            $payload[$id] = $tokens;
        }

        $this->repo->bulkUpdateGlobalActiveApplicability($payload);

        return ['updated_count' => count($payload)];
    }

    private function parseRatePercent(mixed $value): float
    {
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException('VAT rate (percentage) is required.');
        }
        $rate = is_numeric($value) ? (float) $value : null;
        if ($rate === null) {
            throw new \InvalidArgumentException('VAT rate must be a number.');
        }
        if ($rate < 0 || $rate > self::RATE_PERCENT_MAX) {
            throw new \InvalidArgumentException('VAT rate must be between 0 and ' . (int) self::RATE_PERCENT_MAX . '.');
        }
        return round($rate, 2);
    }

    private function generateUniqueCodeFromName(string $name, ?int $branchId): string
    {
        $base = preg_replace('/[^a-z0-9]+/i', '_', trim($name));
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'vat';
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

    /**
     * @return list<string>
     */
    private function normalizeAppliesToTokens(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $tokens = [];
        foreach ($value as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            if (!in_array($token, self::ALLOWED_APPLIES_TO, true)) {
                throw new \InvalidArgumentException('Applied to contains an unknown value: ' . $token);
            }
            $tokens[] = $token;
        }
        return array_values(array_unique($tokens));
    }
}
