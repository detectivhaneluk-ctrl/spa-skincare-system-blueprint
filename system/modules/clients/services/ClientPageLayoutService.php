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
    public const LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE = 'Client Fields / Page Layouts requires migration 113 to be applied before this feature can be used.';

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
        $tmp = $items[$idx];
        $items[$idx] = $items[$j];
        $items[$j] = $tmp;
        $rows = [];
        $pos = 0;
        foreach ($items as $row) {
            $rows[] = [
                'field_key' => (string) $row['field_key'],
                'position' => $pos++,
                'is_enabled' => (int) ($row['is_enabled'] ?? 1),
            ];
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
            return $this->prependRequiredIdentityKeys($this->defaultDetailsFieldKeys());
        }
        $this->ensureDefaultsForOrganization($organizationId);
        $profile = $this->profiles->findByOrgAndKey($organizationId, 'customer_details');
        if (!$profile) {
            return $this->prependRequiredIdentityKeys($this->defaultDetailsFieldKeys());
        }
        $list = $this->items->listByProfileId((int) $profile['id']);
        $keys = [];
        foreach ($list as $row) {
            if ((int) ($row['is_enabled'] ?? 0) !== 1) {
                continue;
            }
            $keys[] = (string) $row['field_key'];
        }
        $keys = $this->prependRequiredIdentityKeys($keys);

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
                return $this->prependRequiredIdentityKeys($this->defaultDetailsFieldKeys());
            }

            return $this->getEnabledOrderedKeysForDetails((int) $oid);
        } catch (\Throwable) {
            return $this->prependRequiredIdentityKeys($this->defaultDetailsFieldKeys());
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
    private function prependRequiredIdentityKeys(array $keys): array
    {
        $need = ['first_name', 'last_name'];
        $filtered = array_values(array_filter($keys, static fn (string $k) => !in_array($k, $need, true)));

        return array_merge($need, $filtered);
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
