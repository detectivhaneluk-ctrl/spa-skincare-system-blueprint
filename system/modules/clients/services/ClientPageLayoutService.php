<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Core\App\Database;
use Core\Organization\OrganizationContext;
use Core\Tenant\TenantOwnedDataScopeGuard;
use Modules\Clients\Repositories\ClientFieldDefinitionRepository;
use Modules\Clients\Repositories\ClientPageLayoutItemRepository;
use Modules\Clients\Repositories\ClientPageLayoutProfileRepository;

final class ClientPageLayoutService
{
    /** User-facing hint when `client_page_layout_*` tables are missing (apply migration 113). */
    public const LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE = 'The client form composer requires migration 113 to be applied before layouts can be edited.';

    private ?bool $layoutTablesPresent = null;

    public function __construct(
        private OrganizationContext $organizationContext,
        private TenantOwnedDataScopeGuard $tenantScopeGuard,
        private Database $db,
        private ClientPageLayoutProfileRepository $profiles,
        private ClientPageLayoutItemRepository $items,
        private ClientFieldCatalogService $catalog,
        private ClientFieldDefinitionRepository $fieldDefinitions,
    ) {
    }

    /**
     * True when both layout tables exist. Uses information_schema only (never queries missing tables).
     */
    public function isLayoutStorageReady(): bool
    {
        if ($this->layoutTablesPresent !== null) {
            return $this->layoutTablesPresent;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN (\'client_page_layout_profiles\', \'client_page_layout_items\')',
                []
            );
            $this->layoutTablesPresent = isset($row['c']) && (int) $row['c'] === 2;
        } catch (\Throwable) {
            $this->layoutTablesPresent = false;
        }

        return $this->layoutTablesPresent;
    }

    public function requireOrganizationId(): int
    {
        $this->tenantScopeGuard->requireResolvedTenantScope();
        $id = $this->organizationContext->getCurrentOrganizationId();

        if ($id === null || $id <= 0) {
            throw new \DomainException('Organization context is required for client page layouts.');
        }

        return $id;
    }

    public function ensureDefaultsForOrganization(int $organizationId): void
    {
        if (!$this->isLayoutStorageReady()) {
            return;
        }
        $defaults = [
            [
                'key' => 'customer_details',
                'label' => 'Customer details (edit form)',
                'consumed' => true,
                'items' => $this->defaultDetailsFieldKeys(),
            ],
            [
                'key' => 'customer_sidebar',
                'label' => 'Customer sidebar (résumé)',
                'consumed' => true,
                'items' => $this->defaultSidebarFieldKeys(),
            ],
            [
                'key' => 'future_intake',
                'label' => 'Future intake (stored only)',
                'consumed' => false,
                'items' => [],
            ],
        ];
        foreach ($defaults as $def) {
            $existing = $this->profiles->findByOrgAndKey($organizationId, $def['key']);
            if ($existing) {
                continue;
            }
            $pid = $this->profiles->create($organizationId, $def['key'], $def['label'], $def['consumed']);
            $rows = [];
            $pos = 0;
            foreach ($def['items'] as $fk) {
                $rows[] = ['field_key' => $fk, 'position' => $pos++, 'is_enabled' => 1];
            }
            if ($rows !== []) {
                $this->items->insertRows($pid, $rows);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function defaultDetailsFieldKeys(): array
    {
        $out = [];
        foreach ($this->catalog->systemFieldDefinitions() as $key => $meta) {
            if (!empty($meta['details_profile_default'])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function defaultSidebarFieldKeys(): array
    {
        $out = [];
        foreach ($this->catalog->systemFieldDefinitions() as $key => $meta) {
            if (!empty($meta['sidebar_profile_default'])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listProfilesForAdmin(int $organizationId): array
    {
        if (!$this->isLayoutStorageReady()) {
            return [];
        }
        $this->ensureDefaultsForOrganization($organizationId);

        return $this->profiles->listByOrganization($organizationId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLayoutItems(int $organizationId, string $profileKey): array
    {
        if (!$this->isLayoutStorageReady()) {
            return [];
        }
        $this->ensureDefaultsForOrganization($organizationId);
        $profile = $this->profiles->findByOrgAndKey($organizationId, $profileKey);
        if (!$profile) {
            return [];
        }

        return $this->items->listByProfileId((int) $profile['id']);
    }

    /**
     * Composer entry: returns layout rows, persisting a corrected intake prefix for customer_details when allowed.
     *
     * @return list<array<string, mixed>>
     */
    public function listLayoutItemsForComposer(int $organizationId, string $profileKey, bool $persistIntakeRepair): array
    {
        $items = $this->listLayoutItems($organizationId, $profileKey);
        if ($profileKey !== 'customer_details' || $items === [] || !$persistIntakeRepair) {
            return $items;
        }
        $normalized = $this->normalizeCustomerDetailsRowsFromDbItems($items);
        if (!$this->customerDetailsLayoutListsDiffer($items, $normalized)) {
            return $items;
        }
        $this->saveLayout($organizationId, $profileKey, $normalized);
        $profile = $this->profiles->findByOrgAndKey($organizationId, $profileKey);
        if (!$profile) {
            return $items;
        }

        return $this->items->listByProfileId((int) $profile['id']);
    }

    /**
     * @param list<array{field_key:string, position:int, is_enabled:int}> $rows
     */
    public function shiftItemPosition(int $organizationId, string $profileKey, string $fieldKey, string $direction): void
    {
        if (!$this->isLayoutStorageReady()) {
            return;
        }
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new \InvalidArgumentException('Invalid shift direction.');
        }
        $items = $this->listLayoutItems($organizationId, $profileKey);
        if ($items === []) {
            return;
        }
        usort($items, static fn (array $a, array $b) => ((int) $a['position']) <=> ((int) $b['position']));
        $idx = null;
        foreach ($items as $i => $row) {
            if ((string) $row['field_key'] === $fieldKey) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return;
        }
        $j = $direction === 'up' ? $idx - 1 : $idx + 1;
        if ($j < 0 || $j >= count($items)) {
            return;
        }
        if ($profileKey === 'customer_details') {
            $immutable = $this->catalog->customerDetailsImmutablePrefixKeys();
            if (in_array((string) $items[$idx]['field_key'], $immutable, true)
                || in_array((string) $items[$j]['field_key'], $immutable, true)) {
                return;
            }
        }
        $tmp = $items[$idx];
        $items[$idx] = $items[$j];
        $items[$j] = $tmp;
        $rows = [];
        $pos = 0;
        foreach ($items as $row) {
            $rows[] = $this->layoutRowFromDatabaseRow($row, $pos++);
        }
        $this->saveLayout($organizationId, $profileKey, $rows);
    }

    public function saveLayout(int $organizationId, string $profileKey, array $rows): void
    {
        if (!$this->isLayoutStorageReady()) {
            throw new \DomainException(self::LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE);
        }
        $this->tenantScopeGuard->requireResolvedTenantScope();
        $profile = $this->profiles->findByOrgAndKey($organizationId, $profileKey);
        if (!$profile) {
            throw new \InvalidArgumentException('Unknown layout profile.');
        }
        if ($profileKey === 'customer_details') {
            $rows = $this->normalizeCustomerDetailsPostedRows($rows);
        } else {
            $rows = $this->sanitizeGenericPostedLayoutRows($rows);
        }
        foreach ($rows as $r) {
            $fk = trim((string) ($r['field_key'] ?? ''));
            if ($fk === '') {
                throw new \InvalidArgumentException('field_key is required.');
            }
            $this->assertFieldKeyAllowed($fk);
        }
        $pdo = $this->db->connection();
        $pdo->beginTransaction();
        try {
            $pid = (int) $profile['id'];
            $this->items->deleteByProfileId($pid);
            $this->items->insertRows($pid, $rows);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Enabled ordered keys for runtime (details form); injects required name fields if missing.
     *
     * @return list<string>
     */
    public function getEnabledOrderedKeysForDetails(int $organizationId): array
    {
        if (!$this->isLayoutStorageReady()) {
            return $this->prependIntakeCoreKeys($this->defaultDetailsFieldKeys());
        }
        $this->ensureDefaultsForOrganization($organizationId);
        $profile = $this->profiles->findByOrgAndKey($organizationId, 'customer_details');
        if (!$profile) {
            return $this->prependIntakeCoreKeys($this->defaultDetailsFieldKeys());
        }
        $list = $this->items->listByProfileId((int) $profile['id']);
        $keys = [];
        foreach ($list as $row) {
            if ((int) ($row['is_enabled'] ?? 0) !== 1) {
                continue;
            }
            $keys[] = (string) $row['field_key'];
        }
        $keys = $this->prependIntakeCoreKeys($keys);

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    public function getEnabledOrderedKeysForSidebar(int $organizationId): array
    {
        if (!$this->isLayoutStorageReady()) {
            return $this->defaultSidebarFieldKeys();
        }
        $this->ensureDefaultsForOrganization($organizationId);
        $profile = $this->profiles->findByOrgAndKey($organizationId, 'customer_sidebar');
        if (!$profile) {
            return $this->defaultSidebarFieldKeys();
        }
        $list = $this->items->listByProfileId((int) $profile['id']);
        $keys = [];
        foreach ($list as $row) {
            if ((int) ($row['is_enabled'] ?? 0) !== 1) {
                continue;
            }
            $keys[] = (string) $row['field_key'];
        }

        return $keys === [] ? $this->defaultSidebarFieldKeys() : $keys;
    }

    /**
     * When org/layout tables are unavailable, fall back to catalog defaults (no DB reads).
     *
     * @return list<string>
     */
    public function tryDetailsLayoutKeys(): array
    {
        try {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $oid = $this->organizationContext->getCurrentOrganizationId();
            if ($oid === null || $oid <= 0) {
                return $this->prependIntakeCoreKeys($this->defaultDetailsFieldKeys());
            }

            return $this->getEnabledOrderedKeysForDetails((int) $oid);
        } catch (\Throwable) {
            return $this->prependIntakeCoreKeys($this->defaultDetailsFieldKeys());
        }
    }

    /**
     * @return list<string>
     */
    public function trySidebarLayoutKeys(): array
    {
        try {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $oid = $this->organizationContext->getCurrentOrganizationId();
            if ($oid === null || $oid <= 0) {
                return $this->defaultSidebarFieldKeys();
            }

            return $this->getEnabledOrderedKeysForSidebar((int) $oid);
        } catch (\Throwable) {
            return $this->defaultSidebarFieldKeys();
        }
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function prependIntakeCoreKeys(array $keys): array
    {
        $need = $this->catalog->customerDetailsImmutablePrefixKeys();
        $filtered = array_values(array_filter($keys, static fn (string $k) => !in_array($k, $need, true)));

        return array_merge($need, $filtered);
    }

    /**
     * @param list<array<string, mixed>> $dbItems
     * @return list<array{field_key: string, position: int, is_enabled: int, display_label: ?string, is_required: ?int}>
     */
    private function normalizeCustomerDetailsRowsFromDbItems(array $dbItems): array
    {
        $rows = [];
        foreach ($dbItems as $row) {
            $rows[] = $this->layoutRowFromDatabaseRow($row, (int) ($row['position'] ?? 0));
        }
        usort($rows, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $this->finalizeCustomerDetailsNormalizedRows($rows);
    }

    /**
     * @param array<string, mixed> $row DB or in-memory layout item
     * @return array{field_key: string, position: int, is_enabled: int, display_label: ?string, is_required: ?int}
     */
    public function layoutRowFromStoredItem(array $row, int $position): array
    {
        return $this->layoutRowFromDatabaseRow($row, $position);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{field_key: string, position: int, is_enabled: int, display_label: ?string, is_required: ?int}
     */
    private function layoutRowFromDatabaseRow(array $row, int $position): array
    {
        $dl = null;
        if (array_key_exists('display_label', $row) && $row['display_label'] !== null) {
            $t = trim((string) $row['display_label']);
            $dl = $t !== '' ? $t : null;
        }
        $req = null;
        if (array_key_exists('is_required', $row) && $row['is_required'] !== null && $row['is_required'] !== '') {
            $req = (int) $row['is_required'] ? 1 : 0;
        }

        return [
            'field_key' => (string) $row['field_key'],
            'position' => $position,
            'is_enabled' => (int) ($row['is_enabled'] ?? 1) ? 1 : 0,
            'display_label' => $dl,
            'is_required' => $req,
        ];
    }

    /**
     * @param list<array{field_key: string, position: int, is_enabled: int}> $sortedRows
     * @return list<array{field_key: string, position: int, is_enabled: int, display_label: ?string, is_required: ?int}>
     */
    private function finalizeCustomerDetailsNormalizedRows(array $sortedRows): array
    {
        $immutable = $this->catalog->customerDetailsImmutablePrefixKeys();
        $byKey = [];
        foreach ($sortedRows as $r) {
            $byKey[(string) $r['field_key']] = $r;
        }
        $keys = array_map(static fn (array $r): string => (string) $r['field_key'], $sortedRows);
        $tail = [];
        $seenTail = [];
        foreach ($keys as $k) {
            if (in_array($k, $immutable, true)) {
                continue;
            }
            if (isset($seenTail[$k])) {
                continue;
            }
            $seenTail[$k] = true;
            $tail[] = $k;
        }
        $mergedKeys = array_merge($immutable, $tail);
        $out = [];
        $pos = 0;
        foreach ($mergedKeys as $fk) {
            $prev = $byKey[$fk] ?? null;
            $en = $prev !== null ? ((int) ($prev['is_enabled'] ?? 1) ? 1 : 0) : 1;
            if (in_array($fk, $immutable, true)) {
                $en = 1;
            }
            $dl = null;
            $req = null;
            if ($prev !== null) {
                if (isset($prev['display_label'])) {
                    $t = trim((string) $prev['display_label']);
                    $dl = $t !== '' ? $t : null;
                }
                if (array_key_exists('is_required', $prev)) {
                    $rv = $prev['is_required'];
                    $req = ($rv === null || $rv === '') ? null : (((int) $rv) ? 1 : 0);
                }
            }
            $out[] = [
                'field_key' => $fk,
                'position' => $pos++,
                'is_enabled' => $en,
                'display_label' => $dl,
                'is_required' => $req,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{field_key: string, position: int, is_enabled: int}>
     */
    private function normalizeCustomerDetailsPostedRows(array $rows): array
    {
        $clean = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $fk = trim((string) ($r['field_key'] ?? ''));
            if ($fk === '') {
                continue;
            }
            $entry = [
                'field_key' => $fk,
                'position' => (int) ($r['position'] ?? 0),
                'is_enabled' => !empty($r['is_enabled']) ? 1 : 0,
                'display_label' => ($dl = trim((string) ($r['display_label'] ?? ''))) !== '' ? $dl : null,
            ];
            $entry['is_required'] = array_key_exists('is_required', $r)
                ? (!empty($r['is_required']) ? 1 : 0)
                : null;
            $clean[] = $entry;
        }
        usort($clean, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $this->finalizeCustomerDetailsNormalizedRows($clean);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{field_key: string, position: int, is_enabled: int, display_label: ?string, is_required: ?int}>
     */
    private function sanitizeGenericPostedLayoutRows(array $rows): array
    {
        $clean = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $fk = trim((string) ($r['field_key'] ?? ''));
            if ($fk === '') {
                continue;
            }
            $clean[] = [
                'field_key' => $fk,
                'position' => (int) ($r['position'] ?? 0),
                'is_enabled' => !empty($r['is_enabled']) ? 1 : 0,
                'display_label' => ($dl = trim((string) ($r['display_label'] ?? ''))) !== '' ? $dl : null,
                'is_required' => array_key_exists('is_required', $r)
                    ? (!empty($r['is_required']) ? 1 : 0)
                    : null,
            ];
        }
        usort($clean, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $clean;
    }

    /**
     * @param list<array<string, mixed>> $dbItems
     * @param list<array{field_key: string, position: int, is_enabled: int}> $normalized
     */
    private function customerDetailsLayoutListsDiffer(array $dbItems, array $normalized): bool
    {
        usort($dbItems, static fn (array $a, array $b): int => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));
        $aKeys = array_map(static fn (array $r): string => (string) $r['field_key'], $dbItems);
        $bKeys = array_map(static fn (array $r): string => (string) $r['field_key'], $normalized);

        return $aKeys !== $bKeys;
    }

    private function assertFieldKeyAllowed(string $fieldKey): void
    {
        if ($this->catalog->isSystemFieldKey($fieldKey)) {
            return;
        }
        $cid = $this->catalog->parseCustomFieldId($fieldKey);
        if ($cid === null) {
            throw new \InvalidArgumentException('Invalid field key: ' . $fieldKey);
        }
        $def = $this->fieldDefinitions->find($cid);
        if (!$def) {
            throw new \InvalidArgumentException('Custom field not found: ' . $fieldKey);
        }
    }
}
