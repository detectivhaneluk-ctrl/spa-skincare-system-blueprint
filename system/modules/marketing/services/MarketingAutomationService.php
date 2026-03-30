<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Marketing\Repositories\MarketingAutomationRepository;

final class MarketingAutomationService
{
    public const EXCEPTION_STORAGE_NOT_READY = 'Marketing automation storage is not ready. Apply migration 099_marketing_automations_foundation.sql.';

    private const KEY_REENGAGEMENT_45_DAY = 'reengagement_45_day';
    private const KEY_BIRTHDAY_SPECIAL = 'birthday_special';
    private const KEY_FIRST_TIME_VISITOR_WELCOME = 'first_time_visitor_welcome';

    public function __construct(
        private MarketingAutomationRepository $automations,
        private BranchContext $branchContext,
        private BranchDirectory $branchDirectory,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            self::KEY_REENGAGEMENT_45_DAY => [
                'title' => 'Re-engagement (45-day)',
                'description' => 'Targets clients without recent completed appointments.',
                'defaults' => ['dormant_days' => 45],
                'rules' => ['dormant_days' => ['min' => 1, 'max' => 365]],
            ],
            self::KEY_BIRTHDAY_SPECIAL => [
                'title' => 'Birthday special',
                'description' => 'Sends a birthday offer before the client birthday date.',
                'defaults' => ['lookahead_days' => 7],
                'rules' => ['lookahead_days' => ['min' => 0, 'max' => 60]],
            ],
            self::KEY_FIRST_TIME_VISITOR_WELCOME => [
                'title' => 'First-time visitor welcome',
                'description' => 'Welcomes new first-time visitors after an initial delay.',
                'defaults' => ['delay_hours' => 24],
                'rules' => ['delay_hours' => ['min' => 0, 'max' => 720]],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function effectiveByBranch(int $branchId): array
    {
        $this->assertStorageReady();
        $this->assertWritableBranch($branchId);
        $rows = $this->automations->listByBranch($branchId);
        $byKey = [];
        foreach ($rows as $r) {
            $key = (string) ($r['automation_key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $r;
            }
        }

        $out = [];
        foreach (self::catalog() as $key => $meta) {
            $row = $byKey[$key] ?? null;
            $cfg = $this->normalizeConfig($key, $this->decodeConfig($row['config_json'] ?? null));
            $out[] = [
                'automation_key' => $key,
                'title' => (string) $meta['title'],
                'description' => (string) $meta['description'],
                'enabled' => $row ? ((int) ($row['enabled'] ?? 0) === 1) : false,
                'config' => $cfg,
                'branch_id' => $branchId,
                'has_persisted_override' => $row !== null,
                'updated_at' => $row['updated_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $rawConfig
     */
    public function upsertSettings(int $branchId, string $automationKey, array $rawConfig, ?bool $enabled = null): void
    {
        $this->assertStorageReady();
        $this->assertWritableBranch($branchId);
        $key = $this->assertAllowedKey($automationKey);
        $existing = $this->automations->findByBranchAndKey($branchId, $key);
        $effectiveEnabled = $enabled ?? ($existing ? ((int) ($existing['enabled'] ?? 0) === 1) : false);
        $cfg = $this->normalizeConfig($key, $rawConfig);
        $this->automations->upsert($branchId, $key, $effectiveEnabled, json_encode($cfg, JSON_THROW_ON_ERROR));
    }

    public function toggle(int $branchId, string $automationKey): bool
    {
        $this->assertStorageReady();
        $this->assertWritableBranch($branchId);
        $key = $this->assertAllowedKey($automationKey);
        $existing = $this->automations->findByBranchAndKey($branchId, $key);
        $cfg = $this->normalizeConfig($key, $this->decodeConfig($existing['config_json'] ?? null));
        $nextEnabled = !($existing ? ((int) ($existing['enabled'] ?? 0) === 1) : false);
        $this->automations->upsert($branchId, $key, $nextEnabled, json_encode($cfg, JSON_THROW_ON_ERROR));

        return $nextEnabled;
    }

    /**
     * @param array<string, mixed> $rawConfig
     * @return array<string, int>
     */
    public function normalizeConfig(string $automationKey, array $rawConfig): array
    {
        $meta = self::catalog()[$this->assertAllowedKey($automationKey)];
        $defaults = is_array($meta['defaults']) ? $meta['defaults'] : [];
        $rules = is_array($meta['rules']) ? $meta['rules'] : [];
        $out = [];
        foreach ($defaults as $field => $default) {
            $rule = is_array($rules[$field] ?? null) ? $rules[$field] : [];
            $min = isset($rule['min']) ? (int) $rule['min'] : PHP_INT_MIN;
            $max = isset($rule['max']) ? (int) $rule['max'] : PHP_INT_MAX;
            $value = array_key_exists($field, $rawConfig) ? $rawConfig[$field] : $default;
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException(sprintf('%s must be an integer.', $field));
            }
            $intVal = (int) $value;
            if ($intVal < $min || $intVal > $max) {
                throw new \InvalidArgumentException(sprintf('%s must be between %d and %d.', $field, $min, $max));
            }
            $out[$field] = $intVal;
        }

        return $out;
    }

    public function currentBranchId(): int
    {
        $branchId = (int) ($this->branchContext->getCurrentBranchId() ?? 0);
        if ($branchId <= 0) {
            throw new \DomainException('Active branch context is required.');
        }

        return $branchId;
    }

    public function isStorageReady(): bool
    {
        return $this->automations->isStorageReady();
    }

    private function assertAllowedKey(string $automationKey): string
    {
        $key = trim($automationKey);
        if (!isset(self::catalog()[$key])) {
            throw new \InvalidArgumentException('Unknown automation key.');
        }

        return $key;
    }

    private function assertWritableBranch(int $branchId): void
    {
        if ($branchId <= 0) {
            throw new \DomainException('Branch is required.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
        if (!$this->branchDirectory->isActiveBranchId($branchId)) {
            throw new \DomainException('Branch must be active.');
        }
    }

    private function assertStorageReady(): void
    {
        if (!$this->automations->isStorageReady()) {
            throw new \DomainException(self::EXCEPTION_STORAGE_NOT_READY);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
