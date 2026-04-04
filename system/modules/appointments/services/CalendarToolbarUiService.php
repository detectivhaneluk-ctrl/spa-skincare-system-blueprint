<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Modules\Appointments\Repositories\CalendarSavedViewsRepository;
use Modules\Appointments\Repositories\CalendarUserPreferencesRepository;

/**
 * Validates and persists day-calendar toolbar UI state (per org + user + branch).
 */
final class CalendarToolbarUiService
{
    public const MAX_VIEWS_PER_USER = 20;

    public const MAX_VIEW_NAME_LEN = 120;

    public const MIN_COLUMN_WIDTH = 96;

    public const MAX_COLUMN_WIDTH = 420;

    public const MIN_TIME_ZOOM_PERCENT = 8;

    /** Minimum zoom applied when reading from DB / serving to client — prevents unusably compressed calendars. */
    public const AUTO_FIT_MIN_TIME_ZOOM_PERCENT = 25;

    public const MAX_TIME_ZOOM_PERCENT = 200;

    public const MAX_CONFIG_JSON_BYTES = 12000;

    public function __construct(
        private CalendarUserPreferencesRepository $prefsRepo,
        private CalendarSavedViewsRepository $viewsRepo,
    ) {
    }

    /**
     * @return array{
     *   column_width_px:int,
     *   time_zoom_percent:int,
     *   show_in_progress:bool,
     *   hidden_staff_ids:list<string>
     * }
     */
    public function getPreferencesOrDefaults(int $organizationId, int $userId, int $branchId): array
    {
        $row = $this->prefsRepo->find($organizationId, $userId, $branchId);
        if ($row !== null) {
            return $row;
        }

        return [
            'column_width_px' => 160,
            'time_zoom_percent' => 100,
            'show_in_progress' => true,
            'hidden_staff_ids' => [],
        ];
    }

    public function preferencesRowExists(int $organizationId, int $userId, int $branchId): bool
    {
        return $this->prefsRepo->exists($organizationId, $userId, $branchId);
    }

    /**
     * @param array<string, mixed> $patch
     * @return array{ok:true,data:array}|array{ok:false,error:string,code:string}
     */
    public function patchPreferences(int $organizationId, int $userId, int $branchId, array $patch): array
    {
        $current = $this->getPreferencesOrDefaults($organizationId, $userId, $branchId);
        $col = $current['column_width_px'];
        $zoom = $current['time_zoom_percent'];
        $sip = $current['show_in_progress'];
        $hidden = $current['hidden_staff_ids'];

        if (array_key_exists('column_width_px', $patch)) {
            $col = (int) $patch['column_width_px'];
            if ($col < self::MIN_COLUMN_WIDTH || $col > self::MAX_COLUMN_WIDTH) {
                return ['ok' => false, 'error' => 'column_width_px out of range', 'code' => 'VALIDATION_FAILED'];
            }
        }
        if (array_key_exists('time_zoom_percent', $patch)) {
            $zoom = (int) $patch['time_zoom_percent'];
            if ($zoom < self::MIN_TIME_ZOOM_PERCENT || $zoom > self::MAX_TIME_ZOOM_PERCENT) {
                return ['ok' => false, 'error' => 'time_zoom_percent out of range', 'code' => 'VALIDATION_FAILED'];
            }
        }
        if (array_key_exists('show_in_progress', $patch)) {
            $sip = !empty($patch['show_in_progress']);
        }
        if (array_key_exists('hidden_staff_ids', $patch)) {
            $raw = $patch['hidden_staff_ids'];
            if (!is_array($raw)) {
                return ['ok' => false, 'error' => 'hidden_staff_ids must be array', 'code' => 'VALIDATION_FAILED'];
            }
            $hidden = [];
            foreach ($raw as $id) {
                $hidden[] = (string) $id;
            }
            if (count($hidden) > 200) {
                return ['ok' => false, 'error' => 'too many hidden_staff_ids', 'code' => 'VALIDATION_FAILED'];
            }
        }

        $this->prefsRepo->upsert($organizationId, $userId, $branchId, $col, $zoom, $sip, $hidden);
        $data = $this->getPreferencesOrDefaults($organizationId, $userId, $branchId);

        return ['ok' => true, 'data' => $data];
    }

    /**
     * @return array{ok:true,views:list<array<string,mixed>>}|array{ok:false,error:string,code:string}
     */
    public function listViews(int $organizationId, int $userId): array
    {
        return ['ok' => true, 'views' => $this->viewsRepo->listForUser($organizationId, $userId)];
    }

    /**
     * @return array{ok:true,view:array<string,mixed>}|array{ok:false,error:string,code:string}
     */
    public function getView(int $organizationId, int $userId, int $id): array
    {
        $v = $this->viewsRepo->find($organizationId, $userId, $id);
        if ($v === null) {
            return ['ok' => false, 'error' => 'View not found', 'code' => 'NOT_FOUND'];
        }
        $cfg = $this->decodeConfig($v['config_json']);
        if ($cfg === null) {
            return ['ok' => false, 'error' => 'Invalid config_json', 'code' => 'SERVER_ERROR'];
        }
        $v['config'] = $cfg;
        unset($v['config_json']);

        return ['ok' => true, 'view' => $v];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{ok:true,id:int}|array{ok:false,error:string,code:string}
     */
    public function createView(int $organizationId, int $userId, string $name, array $config, bool $setAsDefault): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_VIEW_NAME_LEN) {
            return ['ok' => false, 'error' => 'Invalid view name', 'code' => 'VALIDATION_FAILED'];
        }
        if ($this->viewsRepo->countForUser($organizationId, $userId) >= self::MAX_VIEWS_PER_USER) {
            return ['ok' => false, 'error' => 'Maximum saved views reached', 'code' => 'LIMIT_EXCEEDED'];
        }
        $norm = $this->normalizeViewConfig($config);
        if ($norm === null) {
            return ['ok' => false, 'error' => 'Invalid view configuration', 'code' => 'VALIDATION_FAILED'];
        }
        try {
            $json = json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'Invalid JSON', 'code' => 'VALIDATION_FAILED'];
        }
        if (strlen($json) > self::MAX_CONFIG_JSON_BYTES) {
            return ['ok' => false, 'error' => 'Configuration too large', 'code' => 'VALIDATION_FAILED'];
        }
        if ($setAsDefault) {
            $this->viewsRepo->clearDefaultForUser($organizationId, $userId);
        }
        $id = $this->viewsRepo->insert($organizationId, $userId, $name, $norm, $setAsDefault);

        return ['ok' => true, 'id' => $id];
    }

    public function deleteView(int $organizationId, int $userId, int $id): array
    {
        $existing = $this->viewsRepo->find($organizationId, $userId, $id);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'View not found', 'code' => 'NOT_FOUND'];
        }
        $this->viewsRepo->delete($organizationId, $userId, $id);

        return ['ok' => true];
    }

    public function setDefaultView(int $organizationId, int $userId, int $id): array
    {
        $existing = $this->viewsRepo->find($organizationId, $userId, $id);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'View not found', 'code' => 'NOT_FOUND'];
        }
        $this->viewsRepo->setDefault($organizationId, $userId, $id);

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDefaultViewConfig(int $organizationId, int $userId): ?array
    {
        $row = $this->viewsRepo->findDefault($organizationId, $userId);
        if ($row === null) {
            return null;
        }

        return $this->decodeConfig($row['config_json']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeConfig(string $json): ?array
    {
        try {
            $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($d) ? $d : null;
    }

    /**
     * Whitelist view config keys for persistence.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private function normalizeViewConfig(array $config): ?array
    {
        $out = [];
        if (isset($config['branch_id'])) {
            $bid = (int) $config['branch_id'];
            if ($bid > 0) {
                $out['branch_id'] = $bid;
            }
        }
        if (isset($config['column_width_px'])) {
            $c = (int) $config['column_width_px'];
            if ($c >= self::MIN_COLUMN_WIDTH && $c <= self::MAX_COLUMN_WIDTH) {
                $out['column_width_px'] = $c;
            }
        }
        if (isset($config['time_zoom_percent'])) {
            $z = (int) $config['time_zoom_percent'];
            if ($z >= self::MIN_TIME_ZOOM_PERCENT && $z <= self::MAX_TIME_ZOOM_PERCENT) {
                $out['time_zoom_percent'] = $z;
            }
        }
        if (array_key_exists('show_in_progress', $config)) {
            $out['show_in_progress'] = !empty($config['show_in_progress']);
        }
        if (isset($config['hidden_staff_ids']) && is_array($config['hidden_staff_ids'])) {
            $h = [];
            foreach ($config['hidden_staff_ids'] as $id) {
                $h[] = (string) $id;
            }
            $out['hidden_staff_ids'] = array_slice($h, 0, 200);
        }

        return $out;
    }
}
