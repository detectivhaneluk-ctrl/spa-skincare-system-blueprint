<?php
declare(strict_types=1);

/**
 * CLIENT FORM COMPOSER
 *
 * @var bool   $canEditClientFields
 * @var list<array<string,mixed>>   $definitions
 * @var array<string,array<string,mixed>> $systemFieldDefinitions
 * @var array<string,string>        $fieldLabels
 * @var array<string,string>        $customFieldLayoutTypes
 * @var list<array<string,mixed>>   $profiles
 * @var string  $selectedProfileKey
 * @var list<array<string,mixed>>   $layoutItems
 * @var list<string>                $availableToAdd
 * @var bool    $layoutStorageReady
 * @var list<string>                $intakeImmutableKeys
 * @var string  $csrf
 *
 * SortableJS 1.15.2 (/assets/js/sortable.min.js) when storage is ready and the user can edit; init on
 * #apl-composer-layout-list. After drag: reindex positions, __aplComposerSyncLayoutFlowList for flow.
 */

$title                  = 'Form Layouts';
$mainClass              = 'clients-workspace-page cf-composer-page';
$clientFieldsHideSubtabs = true;
$csrfTn = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));

// ── Sorted layout items ───────────────────────────────────────────────────────
$layoutItemsSorted = array_values($layoutItems ?? []);
usort($layoutItemsSorted, static fn(array $a, array $b): int =>
    ((int)($a['position'] ?? 0)) <=> ((int)($b['position'] ?? 0))
);

// ── Active profile display label ──────────────────────────────────────────────
$profileDisplayLabel = (string)$selectedProfileKey;
foreach (($profiles ?? []) as $pp) {
    if ((string)($pp['profile_key'] ?? '') === (string)$selectedProfileKey) {
        $profileDisplayLabel = (string)($pp['display_label'] ?? $selectedProfileKey);
        break;
    }
}

// ── Custom field definition lookup (layout-key → definition row) ──────────────
$customFieldsByLayoutKey = [];
foreach ($definitions ?? [] as $d) {
    $customFieldsByLayoutKey['custom:' . (int)$d['id']] = $d;
}

// ── Group available-to-add fields ────────────────────────────────────────────
$fieldGroups = [
    'identity'    => ['label' => 'Identity',    'icon' => 'person',   'fields' => []],
    'contact'     => ['label' => 'Contact',     'icon' => 'phone',    'fields' => []],
    'address'     => ['label' => 'Address',     'icon' => 'location', 'fields' => []],
    'dates'       => ['label' => 'Dates',       'icon' => 'calendar', 'fields' => []],
    'preferences' => ['label' => 'Preferences', 'icon' => 'settings', 'fields' => []],
    'custom'      => ['label' => 'Custom',      'icon' => 'doc',      'fields' => []],
];

foreach ($availableToAdd ?? [] as $fieldKey) {
    $meta     = $systemFieldDefinitions[$fieldKey] ?? null;
    $label    = $fieldLabels[$fieldKey] ?? $fieldKey;
    $isCustom = str_starts_with($fieldKey, 'custom:');

    $group = 'custom';
    if (!$isCustom && $meta !== null) {
        $adminType = (string)($meta['admin_field_type'] ?? '');
        if (in_array($fieldKey, ['first_name', 'last_name', 'gender', 'occupation'], true)) {
            $group = 'identity';
        } elseif (str_contains($adminType, 'phone') || str_contains($adminType, 'email')) {
            $group = 'contact';
        } elseif (str_contains($adminType, 'address')) {
            $group = 'address';
        } elseif ($adminType === 'date') {
            $group = 'dates';
        } else {
            $group = 'preferences';
        }
    }

    $entry = ['key' => $fieldKey, 'label' => $label, 'is_custom' => $isCustom];
    if ($isCustom && isset($customFieldsByLayoutKey[$fieldKey])) {
        $d = $customFieldsByLayoutKey[$fieldKey];
        $entry['definition_id'] = (int)$d['id'];
        $entry['field_type']    = (string)($d['field_type']   ?? 'text');
        $entry['options_json']  = (string)($d['options_json'] ?? '');
        $entry['is_active']     = (int)($d['is_active']       ?? 1);
        $entry['sort_order']    = (int)($d['sort_order']      ?? 0);
    }
    $fieldGroups[$group]['fields'][] = $entry;
}

$hasLibraryFields = false;
foreach ($fieldGroups as $g) {
    if (!empty($g['fields'])) {
        $hasLibraryFields = true;
        break;
    }
}

// ── SVG icon helper (Lucide v0.460.0 stroke set, ISC — https://lucide.dev/icons/) ──
$icon = static function (string $name, int $size = 20): string {
    static $paths = [
        /* user */
        'person' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'location' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'doc' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'xmark' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'chevron' => '<path d="m9 18 6-6-6-6"/>',
        'drag' => '<circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'pencil' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'trash' => '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'arrow-up' => '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
        'arrow-down' => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
        /* type (Lucide "type") */
        'text-t' => '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" x2="15" y1="20" y2="20"/><line x1="12" x2="12" y1="4" y2="20"/>',
        'toggle' => '<rect width="20" height="12" x="2" y="6" rx="6" ry="6"/><circle cx="8" cy="12" r="2"/>',
        'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'numbers' => '<line x1="4" x2="20" y1="9" y2="9"/><line x1="4" x2="20" y1="15" y2="15"/><line x1="10" x2="8" y1="3" y2="21"/><line x1="16" x2="14" y1="3" y2="21"/>',
        'listBulleted' => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
        'checklist' => '<path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/>',
    ];
    $p = $paths[$name] ?? '';
    if ($p === '') {
        return '';
    }

    return sprintf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">%2$s</svg>',
        $size,
        $p
    );
};

$classifyFieldType = static function (string $fk, string $fieldType, array $sysDefs): string {
    if ($fk === 'first_name' || $fk === 'last_name') return 'name';
    if ($fk === 'email') return 'email';
    if ($fk === 'notes') return 'notes';
    $meta = $sysDefs[$fk] ?? null;
    if ($meta !== null) {
        $kind = (string)($meta['kind'] ?? '');
        if ($kind === 'block') {
            $block = (string)($meta['block'] ?? '');
            if ($block === 'phone_contact') return 'phone';
            if (in_array($block, ['home_address', 'delivery'], true)) return 'address';
        }
        $adm = (string)($meta['admin_field_type'] ?? '');
        if ($adm === 'boolean') return 'boolean';
        if ($adm === 'date') return 'date';
        if ($adm === 'textarea') return 'paragraph';
        if ($adm === 'number') return 'number';
        if ($adm === 'select') return 'picklist';
        if ($adm === 'multiselect') return 'multiselect';
        if (str_contains($adm, 'phone')) return 'phone';
        if (str_contains($adm, 'address')) return 'address';
    }
    if ($fieldType === 'boolean') return 'boolean';
    if ($fieldType === 'date') return 'date';
    if ($fieldType === 'phone') return 'phone';
    if ($fieldType === 'address') return 'address';
    if ($fieldType === 'textarea') return 'paragraph';
    if ($fieldType === 'number') return 'number';
    if ($fieldType === 'select') return 'picklist';
    if ($fieldType === 'multiselect') return 'multiselect';
    return 'text';
};

$typeIconMap = [
    'name' => 'person', 'email' => 'mail', 'phone' => 'phone',
    'address' => 'location', 'date' => 'calendar', 'boolean' => 'toggle',
    'notes' => 'doc', 'text' => 'text-t', 'paragraph' => 'doc', 'number' => 'numbers',
    'picklist' => 'listBulleted', 'multiselect' => 'checklist',
];

/**
 * Always full row in layout flow (6 grid units). Only compound or read-only rows that
 * cannot share a row meaningfully; all other system fields honor Form row width.
 * Must match $detailsLayoutForceFullWidthKeys in client-details-layout-render.php and
 * ClientPageLayoutService::customerDetailsLayoutForceFullWidthKeys() (private — keep lists identical).
 *
 * @var list<string>
 */
$customerDetailsLayoutFlowForceFullKeys = [
    'phone_contact_block',
    'summary_primary_phone',
];

/**
 * @param array<string, array<string, mixed>> $customFieldsByLayoutKey
 */
$layoutFlowEffectiveSpan = static function (
    string $fieldKey,
    int $layoutSpan,
    array $forceFullKeys,
    array $customFieldsByLayoutKey,
): int {
    $layoutSpan = max(1, min(3, $layoutSpan));
    if (in_array($fieldKey, $forceFullKeys, true)) {
        return 3;
    }
    if (str_starts_with($fieldKey, 'custom:')) {
        $def = $customFieldsByLayoutKey[$fieldKey] ?? null;
        if ($def !== null) {
            $ft = (string) ($def['field_type'] ?? 'text');
            $cfFull = $ft === 'textarea' || $ft === 'address' || $ft === 'multiselect' || $ft === 'boolean'
                || ($ft === 'select' && !empty($def['options_json']));
            if ($cfFull) {
                return 3;
            }
        }
    }

    return $layoutSpan;
};

/**
 * Stored layout_span tier 2|3 (half|full); legacy 1 is normalized to 2 server-side. Grid uses 6 units per row (two half = 3+3).
 */
$layoutSpanToFlowGridUnits = static function (int $span1to3): int {
    return match (max(1, min(3, $span1to3))) {
        1 => 2,
        2 => 3,
        3 => 6,
        default => 6,
    };
};

$useCustomerDetailsLayoutFlow = ($selectedProfileKey ?? '') === 'customer_details';
$layoutFlowRowUnits = 6;

/** Slide-over panel: field type combobox rows (value must match server field_type). */
$panelFieldTypeTones = [
    'text', 'paragraph', 'number', 'name', 'email', 'phone', 'address', 'date', 'boolean', 'notes',
    'picklist', 'multiselect',
];
$panelFieldTypeRows = [
    ['value' => 'text', 'label' => 'Single line text', 'icon' => 'text-t', 'tone' => 'text'],
    ['value' => 'textarea', 'label' => 'Paragraph text', 'icon' => 'doc', 'tone' => 'paragraph'],
    ['value' => 'number', 'label' => 'Number', 'icon' => 'numbers', 'tone' => 'number'],
    ['value' => 'date', 'label' => 'Date', 'icon' => 'calendar', 'tone' => 'date'],
    ['value' => 'phone', 'label' => 'Phone', 'icon' => 'phone', 'tone' => 'phone'],
    ['value' => 'email', 'label' => 'Email', 'icon' => 'mail', 'tone' => 'email'],
    ['value' => 'select', 'label' => 'Picklist (select one)', 'icon' => 'listBulleted', 'tone' => 'picklist'],
    ['value' => 'multiselect', 'label' => 'Multiselect', 'icon' => 'checklist', 'tone' => 'multiselect'],
    ['value' => 'boolean', 'label' => 'Yes / No toggle', 'icon' => 'toggle', 'tone' => 'boolean'],
    ['value' => 'address', 'label' => 'Address block', 'icon' => 'location', 'tone' => 'address'],
];

$lockedFieldLabels = [];
$lockedFieldCount = 0;
$lockedRows = [];
$editableLayoutRows = [];
foreach ($layoutItemsSorted as $i => $it) {
    $fk = (string)($it['field_key'] ?? '');
    $isLocked = in_array($fk, $intakeImmutableKeys ?? [], true);
    $row = ['idx' => $i, 'item' => $it];
    if ($isLocked) {
        $lockedRows[] = $row;
        $dl = trim((string)($it['display_label'] ?? ''));
        $lockedFieldLabels[] = $dl !== '' ? $dl : ($fieldLabels[$fk] ?? $fk);
        $lockedFieldCount++;
    } else {
        $editableLayoutRows[] = $row;
    }
}
$lockedHintLine = implode(', ', count($lockedFieldLabels) > 5
    ? array_merge(array_slice($lockedFieldLabels, 0, 5), ['…'])
    : $lockedFieldLabels
);

/** @var list<array{grid_span: int, row_start: bool}> */
$editableLayoutFlowMeta = [];
if ($useCustomerDetailsLayoutFlow) {
    $run = 0;
    foreach ($editableLayoutRows as $sr) {
        $it = $sr['item'];
        $fk = (string) ($it['field_key'] ?? '');
        $raw = (int) ($it['layout_span'] ?? 3);
        if ($raw < 1 || $raw > 3) {
            $raw = 3;
        }
        $span = $layoutFlowEffectiveSpan($fk, $raw, $customerDetailsLayoutFlowForceFullKeys, $customFieldsByLayoutKey);
        $gridSpan = $layoutSpanToFlowGridUnits($span);
        if ($run + $gridSpan > $layoutFlowRowUnits) {
            $run = 0;
        }
        $editableLayoutFlowMeta[] = [
            'grid_span' => $gridSpan,
            'row_start' => $run === 0,
        ];
        $run += $gridSpan;
        if ($run >= $layoutFlowRowUnits) {
            $run = 0;
        }
    }
}

/**
 * Right-edge hairline when a row is shorter than 6 units (no second cell — border-left alone cannot draw the divider).
 *
 * @param list<array{grid_span: int, row_start: bool}> $meta
 * @return list<bool>
 */
$flowLayoutTrailRightAt = static function (array $meta, int $rowUnits): array {
    $n = count($meta);
    $trail = array_fill(0, $n, false);
    for ($i = 0; $i < $n; $i++) {
        if ($i + 1 < $n && empty($meta[$i + 1]['row_start'])) {
            continue;
        }
        $rowSum = 0;
        for ($j = $i; $j >= 0; $j--) {
            $rowSum += (int) ($meta[$j]['grid_span'] ?? 0);
            if (!empty($meta[$j]['row_start'])) {
                break;
            }
        }
        if ($rowSum < $rowUnits) {
            $trail[$i] = true;
        }
    }

    return $trail;
};

/** @var list<bool> */
$editableFlowTrailRight = [];
if ($useCustomerDetailsLayoutFlow) {
    $editableFlowTrailRight = $flowLayoutTrailRightAt($editableLayoutFlowMeta, $layoutFlowRowUnits);
}

$composerSortableEnabled = $layoutStorageReady && $canEditClientFields && $editableLayoutRows !== [];

ob_start();
?>
<?php require base_path('modules/clients/views/partials/client-fields-admin-shell.php'); ?>
<style>
/* ==========================================================================
   DESIGN TOKENS — scoped to .apl-composer (light + html[data-app-theme="dark"])
   ========================================================================== */

/* ==========================================================================
   BASE — scoped to composer only to avoid polluting global box-model
   ========================================================================== */
.apl-composer * { box-sizing: border-box; }

/* ==========================================================================
   LAYOUT
   ========================================================================== */

/*
 * Apple font-smoothing: both prefixes are required.
 * -webkit-font-smoothing: antialiased — Chrome, Safari (macOS)
 * -moz-osx-font-smoothing: grayscale — Firefox on macOS
 * Together they produce Apple's characteristically thin, crisp letterforms.
 */
.apl-composer {
    /* Surfaces: cool off-white page, pure white cards */
    --apl-gray-1: #FFFFFF;
    --apl-gray-2: #eef1f6;
    --apl-gray-3: #e6eaf2;
    --apl-gray-4: #d5dae6;
    --apl-gray-5: #a8b0bd;
    --apl-gray-6: #6b728f;
    --apl-gray-7: #404a5c;
    --apl-gray-8: #111827;
    --apl-accent: #111827;
    --apl-accent-hover: #000000;
    --apl-accent-soft: rgba(17, 24, 39, 0.078);
    --apl-accent-soft-strong: rgba(17, 24, 39, 0.12);
    --apl-blue: #2563eb;
    --apl-blue-hover: #1d4ed8;
    --apl-blue-light: rgba(37, 99, 235, 0.1);
    --apl-green: #34c759;
    --apl-red: #ff3b30;
    --apl-red-light: #ffebea;
    --apl-orange: #ff9500;
    --apl-sys-blue: #007aff;
    --apl-sys-indigo: #5856d6;
    --apl-sys-purple: #af52de;
    --apl-sys-pink: #ff2d55;
    --apl-sys-red: #ff3b30;
    --apl-sys-orange: #ff9500;
    --apl-sys-yellow: #ffcc00;
    --apl-sys-green: #34c759;
    --apl-sys-teal: #5ac8fa;
    --apl-sys-mint: #00c8b3;
    --apl-sys-brown: #a2845e;
    --apl-indigo: #5856d6;
    --apl-system-gray: #8e8e93;
    --apl-font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    --apl-text-xs: 11px;
    --apl-text-sm: 13px;
    --apl-text-base: 15px;
    --apl-text-lg: 17px;
    --apl-text-xl: 20px;
    --apl-text-2xl: 24px;
    --apl-text-3xl: 28px;
    --apl-weight-regular: 400;
    --apl-weight-medium: 500;
    --apl-weight-semibold: 600;
    --apl-weight-bold: 700;
    --apl-line-tight: 1.2;
    --apl-line-normal: 1.4;
    --apl-line-relaxed: 1.6;
    --apl-space-1: 4px;
    --apl-space-2: 8px;
    --apl-space-3: 12px;
    --apl-space-4: 16px;
    --apl-space-5: 20px;
    --apl-space-6: 24px;
    --apl-space-8: 32px;
    --apl-space-10: 40px;
    --apl-space-12: 48px;
    --apl-space-16: 64px;
    --apl-control-sm: 28px;
    --apl-control-md: 36px;
    --apl-control-lg: 44px;
    --apl-radius-sm: 6px;
    --apl-radius-md: 10px;
    --apl-radius-lg: 14px;
    --apl-radius-xl: 18px;
    --apl-radius-full: 9999px;
    --apl-shadow-sm: 0 1px 2px rgba(0,0,0,.032);
    --apl-shadow-md: 0 2px 6px rgba(0,0,0,.042), 0 1px 2px rgba(0,0,0,.028);
    --apl-shadow-lg: 0 4px 14px rgba(0,0,0,.05), 0 2px 4px rgba(0,0,0,.03);
    --apl-shadow-xl: 0 12px 36px rgba(0,0,0,.058), 0 4px 10px rgba(0,0,0,.032);
    --apl-shadow-raised: 0 1px 2px rgba(15, 23, 42, 0.028), 0 6px 20px rgba(15, 23, 42, 0.038);
    --apl-shadow-sheet: -6px 0 36px rgba(0,0,0,.065), -2px 0 10px rgba(0,0,0,.038);
    --apl-shadow-focus: 0 0 0 3px rgba(17, 24, 39, 0.16);
    --apl-shadow-focus-outer: 0 0 0 4px rgba(17, 24, 39, 0.1);
    --apl-spring-out: cubic-bezier(0.32, 0.72, 0, 1);
    --apl-spring-duration: 0.44s;
    --apl-transition-fast: 0.15s ease;
    --apl-transition-base: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    --apl-transition-slow: 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    /* Theme-aware chrome (dark overrides below) */
    --apl-sep: rgba(0, 0, 0, 0.07);
    --apl-sep-faint: rgba(0, 0, 0, 0.05);
    --apl-sep-mid: rgba(0, 0, 0, 0.06);
    --apl-border-panel: rgba(15, 23, 42, 0.08);
    --apl-fill-hover: rgba(0, 0, 0, 0.03);
    --apl-fill-hover-strong: rgba(15, 23, 42, 0.055);
    --apl-input-fill: rgba(15, 23, 42, 0.04);
    --apl-input-fill-strong: rgba(15, 23, 42, 0.085);
    --apl-segmented-track: rgba(15, 23, 42, 0.055);
    --apl-segmented-pill: #ffffff;
    --apl-settings-surface: rgba(15, 23, 42, 0.035);
    --apl-settings-group-bg: rgba(255, 255, 255, 0.65);
    --apl-sortable-ghost-bg: #f4f4f5;
    --apl-sortable-ghost-border: #a1a1aa;
    --apl-segmented-hover: rgba(255, 255, 255, 0.35);
    --apl-segmented-inset: inset 0 1px 0 rgba(255, 255, 255, 0.45);
    --apl-sidebar-nav-hover: rgba(0, 0, 0, 0.04);
    --apl-chip-divider: rgba(0, 0, 0, 0.08);
    --apl-settings-input-bg: rgba(255, 255, 255, 0.92);
    --apl-settings-input-focus: #ffffff;
    --apl-settings-input-border: rgba(15, 23, 42, 0.1);
    --apl-settings-input-border-focus: rgba(15, 23, 42, 0.14);
    --apl-btn-secondary-border: rgba(0, 0, 0, 0.16);
    --apl-focus-ring: rgba(17, 24, 39, 0.22);
    --apl-scrollbar-thumb: rgba(15, 23, 42, 0.22);
    --apl-scrollbar-thumb-hover: rgba(15, 23, 42, 0.4);
    --apl-scrollbar-color: rgba(15, 23, 42, 0.3);
    /* Page canvas — strict alias */
    --apl-workspace-canvas: var(--primary-bg, var(--ds-color-bg-page, #f4f4f5));
    /* Field-type icons — full-strength sapphire */
    --apl-type-icon-muted: var(--accent-sapphire, #0d3b66);
    /* Primary CTA — sapphire black + light neutral label */
    --apl-btn-primary-fill: #092a47;
    --apl-btn-primary-fill-hover: #071f35;
    --apl-btn-primary-text: #f4f4f5;
    /* iOS switch “on” */
    --apl-switch-track-on: #0d3b66;
    color: var(--apl-gray-8);
    display: flex;
    min-height: 100vh;
    /* Let main.cf-composer-page canvas show through — avoids a second full-bleed white sheet */
    background: transparent;
    font-family: var(--apl-font-family);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/*
 * Dark theme — aligns with html[data-app-theme="dark"] + design-tokens.css surfaces.
 */
html[data-app-theme="dark"] .apl-composer {
    color-scheme: dark;
    --apl-gray-1: #141416;
    --apl-gray-2: #1c1c1f;
    --apl-gray-3: #252528;
    --apl-gray-4: #3a3a41;
    --apl-gray-5: #71717a;
    --apl-gray-6: #a1a1aa;
    --apl-gray-7: #d4d4d8;
    --apl-gray-8: #f4f4f5;
    --apl-accent: #3b82f6;
    --apl-accent-hover: #60a5fa;
    --apl-accent-soft: rgba(59, 130, 246, 0.22);
    --apl-accent-soft-strong: rgba(59, 130, 246, 0.32);
    --apl-red-light: rgba(255, 59, 48, 0.16);
    --apl-blue-light: rgba(59, 130, 246, 0.2);
    --apl-shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.35);
    --apl-shadow-md: 0 2px 8px rgba(0, 0, 0, 0.4);
    --apl-shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.45);
    --apl-shadow-xl: 0 12px 36px rgba(0, 0, 0, 0.5);
    --apl-shadow-raised: 0 1px 0 rgba(255, 255, 255, 0.06), 0 8px 28px rgba(0, 0, 0, 0.42);
    --apl-shadow-sheet: -8px 0 40px rgba(0, 0, 0, 0.55);
    --apl-shadow-focus: 0 0 0 3px rgba(59, 130, 246, 0.45);
    --apl-shadow-focus-outer: 0 0 0 4px rgba(59, 130, 246, 0.2);
    --apl-sep: rgba(255, 255, 255, 0.1);
    --apl-sep-faint: rgba(255, 255, 255, 0.06);
    --apl-sep-mid: rgba(255, 255, 255, 0.08);
    --apl-border-panel: rgba(255, 255, 255, 0.12);
    --apl-fill-hover: rgba(255, 255, 255, 0.05);
    --apl-fill-hover-strong: rgba(255, 255, 255, 0.1);
    --apl-input-fill: rgba(255, 255, 255, 0.06);
    --apl-input-fill-strong: rgba(255, 255, 255, 0.11);
    --apl-segmented-track: rgba(255, 255, 255, 0.1);
    --apl-segmented-pill: #252528;
    --apl-settings-surface: rgba(255, 255, 255, 0.06);
    --apl-settings-group-bg: rgba(255, 255, 255, 0.08);
    --apl-btn-primary-text: #f4f4f5;
    --apl-sortable-ghost-bg: rgba(255, 255, 255, 0.08);
    --apl-sortable-ghost-border: rgba(255, 255, 255, 0.22);
    --apl-segmented-hover: rgba(255, 255, 255, 0.09);
    --apl-segmented-inset: inset 0 1px 0 rgba(255, 255, 255, 0.05);
    --apl-sidebar-nav-hover: rgba(255, 255, 255, 0.07);
    --apl-chip-divider: rgba(255, 255, 255, 0.12);
    --apl-settings-input-bg: rgba(255, 255, 255, 0.08);
    --apl-settings-input-focus: rgba(255, 255, 255, 0.12);
    --apl-settings-input-border: rgba(255, 255, 255, 0.14);
    --apl-settings-input-border-focus: rgba(255, 255, 255, 0.24);
    --apl-btn-secondary-border: rgba(255, 255, 255, 0.2);
    --apl-focus-ring: rgba(96, 165, 250, 0.5);
    --apl-workspace-canvas: var(--ds-color-bg-page);
    --apl-type-icon-muted: #7eb8e8;
    --apl-btn-primary-fill: #092a47;
    --apl-btn-primary-fill-hover: #0a3354;
    --apl-switch-track-on: #5b8ec4;
    --apl-scrollbar-thumb: rgba(255, 255, 255, 0.22);
    --apl-scrollbar-thumb-hover: rgba(255, 255, 255, 0.38);
    --apl-scrollbar-color: rgba(255, 255, 255, 0.35);
}

/* Native <select> chevron: light gray on dark surfaces */
html[data-app-theme="dark"] .apl-composer .apl-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='%23a1a1aa'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
}

/* ── Sidebar ─────────────────────────────────────────────────────────────── */
/*
 * Sidebar — opaque fill with --apl-workspace-canvas (matches main.cf-composer-page)
 * so sticky chrome does not show scrolling content through it.
 */
.apl-sidebar {
    width: 224px;
    flex-shrink: 0;
    background: var(--apl-workspace-canvas);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
    border-right: 1px solid var(--apl-border-panel);
    border-top-left-radius: var(--apl-radius-lg);
    display: flex;
    flex-direction: column;
    position: sticky;
    top: var(--ds-app-header-rail-height, 3.5rem);
    height: calc(100vh - var(--ds-app-header-rail-height, 3.5rem));
    overflow: hidden;
    align-self: flex-start;
    z-index: 50;
}

.apl-sidebar__header {
    padding: var(--apl-space-4) var(--apl-space-5);
    border-bottom: 1px solid var(--apl-sep);
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: calc(var(--apl-space-4) + var(--apl-control-lg) + var(--apl-space-4));
}

.apl-sidebar__header .apl-toolbar__eyebrow--layout-spacer {
    margin: 0 0 2px;
    color: transparent;
    user-select: none;
    pointer-events: none;
}

.apl-sidebar__title {
    margin: 0;
    font-size: 22px;
    font-weight: var(--apl-weight-semibold);
    color: var(--apl-gray-8);
    letter-spacing: -0.02em;
    line-height: 1.25;
}

.apl-sidebar__body {
    flex: 1;
    padding: var(--apl-space-3) var(--apl-space-2) var(--apl-space-4);
    overflow-y: auto;
}

/*
 * Hairline scrollbars (composer only) — avoids thick OS-style thumbs in nested panels.
 */
.apl-composer .apl-sidebar__body,
.apl-composer .apl-sidebar__nav,
.apl-composer .apl-type-picker__menu,
.apl-composer .apl-panel__body {
    scrollbar-width: thin;
    scrollbar-color: var(--apl-scrollbar-color) transparent;
}
.apl-composer .apl-sidebar__body::-webkit-scrollbar,
.apl-composer .apl-sidebar__nav::-webkit-scrollbar,
.apl-composer .apl-type-picker__menu::-webkit-scrollbar,
.apl-composer .apl-panel__body::-webkit-scrollbar {
    width: 3px;
    height: 3px;
}
.apl-composer .apl-sidebar__body::-webkit-scrollbar-track,
.apl-composer .apl-sidebar__nav::-webkit-scrollbar-track,
.apl-composer .apl-type-picker__menu::-webkit-scrollbar-track,
.apl-composer .apl-panel__body::-webkit-scrollbar-track {
    background: transparent;
}
.apl-composer .apl-sidebar__body::-webkit-scrollbar-thumb,
.apl-composer .apl-sidebar__nav::-webkit-scrollbar-thumb,
.apl-composer .apl-type-picker__menu::-webkit-scrollbar-thumb,
.apl-composer .apl-panel__body::-webkit-scrollbar-thumb {
    background: var(--apl-scrollbar-thumb);
    border-radius: 999px;
}
.apl-composer .apl-sidebar__body::-webkit-scrollbar-thumb:hover,
.apl-composer .apl-sidebar__nav::-webkit-scrollbar-thumb:hover,
.apl-composer .apl-type-picker__menu::-webkit-scrollbar-thumb:hover,
.apl-composer .apl-panel__body::-webkit-scrollbar-thumb:hover {
    background: var(--apl-scrollbar-thumb-hover);
}

.apl-sidebar__section-label {
    padding: var(--apl-space-2) var(--apl-space-3) var(--apl-space-2);
    margin: 0;
    font-size: var(--apl-text-xs);
    font-weight: var(--apl-weight-semibold);
    color: var(--apl-gray-6);
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.apl-sidebar__nav {
    list-style: none;
    margin: 0;
    padding: 0;
    background: none;
    border: none;
    border-radius: 0;
    box-shadow: none;
}

.apl-sidebar__nav-item { margin-bottom: 2px; }
.apl-sidebar__nav-item:last-child { margin-bottom: 0; }

.apl-sidebar__nav-link {
    display: block;
    min-width: 0;
    padding: var(--apl-space-2) var(--apl-space-3);
    border-radius: 0;
    border: none;
    border-left: 4px solid transparent;
    background: transparent;
    color: var(--text-main, var(--apl-gray-8));
    text-decoration: none;
    font-size: var(--apl-text-sm);
    font-weight: var(--apl-weight-regular);
    line-height: var(--apl-line-normal);
    transition: background var(--apl-transition-fast), color var(--apl-transition-fast), border-color var(--apl-transition-fast);
}

.apl-sidebar__nav-link:hover { background: var(--apl-sidebar-nav-hover); }

.apl-sidebar__nav-link.is-active {
    background: transparent;
    color: var(--accent-sapphire, #0d3b66);
    font-weight: 600;
    border-left: 4px solid var(--accent-sapphire, #0d3b66);
    box-shadow: none;
}

html[data-app-theme="dark"] .apl-sidebar__nav-link.is-active {
    background: transparent;
    color: #a8c8e8;
    border-left-color: #7eb8e8;
}

/* ── Main ────────────────────────────────────────────────────────────────── */
.apl-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    background: var(--apl-workspace-canvas);
}

/* ── Toolbar ─────────────────────────────────────────────────────────────── */
/*
 * Toolbar — same opaque canvas as the page; required for position:sticky so
 * content below does not bleed through on scroll.
 */
.apl-toolbar {
    background: var(--apl-workspace-canvas);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
    /* Same hairline as .apl-sidebar__header so the rules meet at the column split */
    border-bottom: 1px solid var(--apl-sep);
    /* Outer top-right corner — pairs with .apl-sidebar border-top-left-radius */
    border-top-right-radius: var(--apl-radius-lg);
    box-shadow: none;
    padding: var(--apl-space-4) var(--apl-space-6);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--apl-space-6);
    flex-shrink: 0;
    position: sticky;
    top: var(--ds-app-header-rail-height, 3.5rem);
    z-index: 100;
}

/* Slightly fuller corner radius than default 10px — reads closer to SF Symbol / macOS push buttons */
.apl-toolbar .apl-btn {
    border-radius: 12px;
}

.apl-toolbar__eyebrow {
    margin: 0 0 2px;
    font-size: var(--apl-text-xs);
    color: var(--apl-gray-6);
    font-weight: var(--apl-weight-medium);
    letter-spacing: 0.02em;
    text-transform: uppercase;
}

/*
 * Apple: navigation/view titles at 22px semibold (600).
 * 28px (--apl-text-3xl) is reserved for marketing/hero text.
 * Letter-spacing: -0.02em is Apple's standard for display titles ≥ 20px.
 */
.apl-toolbar__title {
    margin: 0;
    font-size: 22px;
    font-weight: var(--apl-weight-semibold);
    color: var(--apl-gray-8);
    letter-spacing: -0.02em;
    line-height: 1.25;
}

.apl-toolbar__actions {
    display: flex;
    gap: var(--apl-space-3);
    align-items: center;
}

/* ── Canvas ──────────────────────────────────────────────────────────────── */
.apl-canvas {
    flex: 1;
    /* Apple: 20px side padding on compact, 24px on regular view */
    padding: var(--apl-space-6) var(--apl-space-6);
}

.apl-canvas__inner {
    /*
     * Cap content width for readability; align start so space sits on the right
     * (not a dead band between sidebar and the form).
     */
    max-width: min(900px, 100%);
    margin: 0;
    margin-inline-end: auto;
}

/* ==========================================================================
   BUTTONS
   ========================================================================== */
/*
 * Apple button rules:
 *  - No translateY lift on hover — buttons are flat in Apple's UI
 *  - No elevation shadow on hover — shadows belong to sheets and popovers
 *  - transition targets only background and opacity — never `all`
 *  - Active state uses opacity(.85) for tactile press feedback
 *  - border-radius 10px for standard 44px buttons (correct Apple radius at this height)
 */
.apl-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--apl-space-2);
    height: var(--apl-control-lg);
    padding: 0 var(--apl-space-5);
    border: 1px solid transparent;
    border-radius: var(--apl-radius-md);
    font-family: var(--apl-font-family);
    font-size: var(--apl-text-base);
    font-weight: var(--apl-weight-medium);
    line-height: 1;
    cursor: pointer;
    transition: background 0.15s ease, opacity 0.12s ease, border-color 0.15s ease;
    user-select: none;
    text-decoration: none;
    white-space: nowrap;
}

.apl-btn:disabled { opacity: 0.38; cursor: not-allowed; pointer-events: none; }
/* Save Layout: full-opacity sapphire black + light label; disabled = same look, no click */
.apl-btn--primary.apl-save-btn,
.apl-btn--primary.apl-save-btn:disabled {
    opacity: 1;
    background: #092a47;
    color: var(--apl-btn-primary-text);
    border: 1px solid #092a47;
}
.apl-btn--primary.apl-save-btn:hover:not(:disabled) {
    background: var(--apl-btn-primary-fill-hover);
    border-color: var(--apl-btn-primary-fill-hover);
    color: var(--apl-btn-primary-text);
}
html[data-app-theme="dark"] .apl-btn--primary.apl-save-btn:disabled,
html[data-app-theme="dark"] .apl-btn--primary.apl-save-btn {
    background: #092a47;
    color: var(--apl-btn-primary-text);
    border-color: #092a47;
}
.apl-btn:active:not(:disabled) { opacity: 0.8; }
.apl-btn--primary.apl-save-btn:active:not(:disabled) { opacity: 1; }

.apl-btn--primary:not(.apl-save-btn) {
    background: var(--apl-btn-primary-fill);
    color: var(--apl-btn-primary-text);
    border-color: var(--apl-btn-primary-fill);
}
.apl-btn--primary:not(.apl-save-btn):hover:not(:disabled) {
    background: var(--apl-btn-primary-fill-hover);
    border-color: var(--apl-btn-primary-fill-hover);
}

.apl-btn--secondary {
    background: var(--apl-gray-1);
    border-color: var(--apl-btn-secondary-border);
    color: var(--apl-gray-8);
}
.apl-btn--secondary:hover:not(:disabled) {
    background: var(--apl-gray-2);
}

.apl-btn--ghost {
    background: transparent;
    color: var(--apl-accent);
}
.apl-btn--ghost:hover:not(:disabled) {
    background: var(--apl-accent-soft);
}

/*
 * Danger (destructive) button:
 * Apple uses the system red at full intensity for text, very light tint for background.
 * On hover: slightly deeper tint, never inverted to red-on-white.
 */
.apl-btn--danger {
    background: rgba(255,59,48,.08);
    color: var(--apl-red);
    border-color: rgba(255,59,48,.16);
}
.apl-btn--danger:hover:not(:disabled) {
    background: rgba(255,59,48,.14);
}

.apl-btn--icon {
    width: var(--apl-control-lg);
    height: var(--apl-control-lg);
    padding: 0;
    background: transparent;
    border-color: transparent;
    color: var(--apl-gray-6);
    border-radius: 8px;
}
.apl-btn--icon:hover:not(:disabled) {
    background: var(--apl-fill-hover-strong);
    color: var(--apl-gray-8);
}

.apl-btn--sm {
    height: var(--apl-control-md);
    padding: 0 var(--apl-space-4);
    font-size: var(--apl-text-sm);
    border-radius: var(--apl-radius-sm);
}
.apl-btn--icon.apl-btn--sm {
    width: var(--apl-control-md);
    height: var(--apl-control-md);
    padding: 0;
}

/* Save button — no opacity-driven transitions */
.apl-save-btn { width: 100%; transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease; }
.apl-toolbar__actions .apl-save-btn {
    width: auto;
    flex: 0 1 auto;
    min-height: var(--apl-control-lg);
    padding: 0 var(--apl-space-5);
    border-radius: var(--apl-radius-full);
    font-weight: var(--apl-weight-semibold);
}
.apl-toolbar-save-form {
    display: contents;
}
.apl-toolbar__actions--split {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--apl-space-3);
}
/*
 * New Field — same APL secondary surface as the rest of the composer; pill shape pairs with Save Layout.
 */
.apl-toolbar .apl-toolbar__actions .apl-toolbar-new-field-btn {
    border-radius: var(--apl-radius-full);
    font-weight: var(--apl-weight-medium);
    border-color: rgba(13, 59, 102, 0.28);
    color: #0d3b66;
    background: #ffffff;
}
.apl-toolbar .apl-toolbar__actions .apl-toolbar-new-field-btn:hover:not(:disabled) {
    background: var(--ds-color-bg-subtle, #ededed);
    border-color: rgba(13, 59, 102, 0.38);
    color: #0d3b66;
}
.apl-toolbar .apl-toolbar__actions .apl-toolbar-new-field-btn svg {
    flex-shrink: 0;
    stroke: currentColor;
}
/*
 * Apple HIG 2026: buttons never use transform: scale on any state.
 * Dirty pulse — only when transitioning to savable.
 */
.apl-save-btn[data-dirty="true"]:not(:disabled) { animation: apl-pulse 0.35s ease; }

@keyframes apl-pulse {
    0%   { filter: brightness(0.95); }
    60%  { filter: brightness(1.05); }
    100% { filter: none; }
}

/* ==========================================================================
   FIELD LIST — Apple inset-grouped list style
   ========================================================================== */
.apl-field-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
}

/*
 * Customer details: 6-unit row (span 1→2, 2→3, 3→6) so two half-width fields sit one row — no extra chrome.
 */
.apl-field-list--layout-flow {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    /* No column gap — gutters showed parent (white) beside hover fill; hairlines + padding separate cells. */
    column-gap: 0;
    row-gap: 0;
}
.apl-field-list--layout-flow > .apl-field-card {
    min-width: 0;
}
.apl-field-list--layout-flow > .apl-field-card + .apl-field-card {
    border-top: none;
}
/* Full-width row hairline (grid item border-top on half-span cells only drew ~50% width). */
.apl-field-list--layout-flow > .apl-layout-flow-row-rule {
    list-style: none;
    margin: 0;
    padding: 0;
    height: 0;
    border: none;
    border-top: 1px solid var(--apl-sep);
    box-sizing: border-box;
    grid-column: 1 / -1;
    align-self: stretch;
}
/* Same hairline language as row separators — only between columns on one row */
.apl-field-list--layout-flow > .apl-field-card:not(.apl-field-card--layout-row-start) {
    border-left: 1px solid var(--apl-sep);
    padding-left: 14px;
    box-sizing: border-box;
}
.apl-field-list--layout-flow > .apl-field-card.apl-field-card--flow-row-trail {
    border-right: 1px solid var(--apl-sep);
    padding-right: 14px;
    box-sizing: border-box;
}
/*
 * Sortable only moves .apl-field-card nodes; row-rule <li> and column hairlines stay stale until JS sync.
 * While dragging, hide flow chrome so lines do not lag behind the ghost / fallback clone.
 */
.apl-field-list--layout-flow.is-apl-flow-drag > .apl-layout-flow-row-rule {
    visibility: hidden;
    pointer-events: none;
    border-top-color: transparent;
}
.apl-field-list--layout-flow.is-apl-flow-drag > .apl-field-card:not(.apl-field-card--expanded) {
    border-left-color: transparent !important;
    border-right-color: transparent !important;
}
/*
 * Half-width cards leave an empty grid track; expanded settings need the full list width.
 * !important overrides inline grid-column from the collapsed span.
 */
.apl-field-list--layout-flow > .apl-field-card.apl-field-card--expanded {
    grid-column: 1 / -1 !important;
    border-left: none !important;
    border-right: none !important;
    padding-left: 0;
    padding-right: 0;
}

/*
 * Apple HIG: inset-grouped table container.
 * Corner radius = 12px (Catalyst / macOS system default for inset grouped).
 * Border = rgba(0,0,0,.09) — just barely visible on white.
 * Shadow: same raised token as library for visual balance.
 */
.apl-field-groups {
    /* Pure white card — strict token (form grid only) */
    background: var(--card-bg, #ffffff);
    border: 1px solid var(--apl-border-panel);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--apl-shadow-sm);
}

/* ── Fixed fields header row ─────────────────────────────────────────────── */
/*
 * Apple: section header rows in grouped lists have a faint gray background,
 * NOT white. Uses var(--apl-gray-2) = #F5F5F7.
 */
.apl-fixed-row {
    display: flex;
    align-items: center;
    gap: var(--apl-space-3);
    padding: 0 var(--apl-space-5);
    border-bottom: 1px solid var(--apl-sep);
    background: transparent;
    cursor: default;
    user-select: none;
    min-height: 44px;
}
.apl-fixed-row__lock {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    /* Lock is decorative — very light, does not compete with content */
    color: var(--apl-gray-5);
    flex-shrink: 0;
}
.apl-fixed-row__lock svg { width: 13px; height: 13px; }

.apl-fixed-row__label {
    font-size: var(--apl-text-sm);
    /* Apple: section header label = regular weight, NOT semibold */
    font-weight: var(--apl-weight-medium);
    color: var(--ds-color-text, var(--apl-gray-8));
    white-space: nowrap;
    letter-spacing: 0;
}
.apl-fixed-row__count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    /* Apple: tight border-radius (4px) on numeric tags */
    border-radius: 4px;
    font-size: 11px;
    font-weight: var(--apl-weight-bold);
    background: var(--apl-gray-8);
    color: var(--apl-gray-1);
    flex-shrink: 0;
    letter-spacing: 0.02em;
}
.apl-fixed-row__hint {
    flex: 1;
    min-width: 0;
    font-size: var(--apl-text-xs);
    /* Apple: secondary text = --apl-gray-6 (#86868B) */
    color: var(--apl-gray-6);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    letter-spacing: 0;
}
.apl-fixed-row__toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: none;
    border: none;
    cursor: pointer;
    /* Apple: disclosure chevrons = --apl-gray-5 (#B0B0B8) */
    color: var(--apl-gray-5);
    border-radius: 4px;
    /* Apple: no background on chevron buttons — color change only */
    transition: color var(--apl-transition-fast), transform var(--apl-transition-base);
    flex-shrink: 0;
}
.apl-fixed-row__toggle:hover { color: var(--apl-gray-7); }
.apl-fixed-row__toggle[aria-expanded="true"] { transform: rotate(90deg); }

.apl-fixed-detail {
    display: none;
    border-bottom: 1px solid var(--apl-sep);
    background: transparent;
}
.apl-fixed-detail.is-open { display: block; }
.apl-fixed-detail__list {
    list-style: none;
    margin: 0;
    padding: var(--apl-space-2) var(--apl-space-5);
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: var(--apl-space-5);
    row-gap: var(--apl-space-2);
    align-items: start;
}
/* Odd count: last row has one cell — span full width so it does not hug a single column */
.apl-fixed-detail__list .apl-fixed-detail__item:last-child:nth-child(odd) {
    grid-column: 1 / -1;
}
.apl-fixed-detail__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    min-width: 0;
    font-size: var(--apl-text-sm);
    color: var(--ds-color-text, var(--apl-gray-8));
    letter-spacing: 0;
}
.apl-fixed-detail__list .apl-fixed-detail__item > span:last-child {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ── Field row (Apple list cell) ─────────────────────────────────────────── */
/*
 * Apple HIG rules applied here:
 *  - No border/radius per-cell — the container holds the shape
 *  - Separator = rgba(0,0,0,.07), rendered as inset (starts after the left padding)
 *  - Hover = rgba(0,0,0,.03) — barely perceptible, never jarring
 *  - Row height = 44px minimum (44pt = Apple's minimum touch/click target)
 */
.apl-field-card {
    background: transparent;
    border: none;
    border-radius: 0;
    box-shadow: none;
    overflow: visible;
    transition: background 0.2s ease, transform 0.25s cubic-bezier(0.2, 0, 0, 1), box-shadow 0.25s ease;
    position: relative;
    /* scrollIntoView: keep row clear of sticky app header + composer toolbar */
    scroll-margin-top: calc(var(--ds-app-header-rail-height, 3.5rem) + 4rem);
    scroll-margin-bottom: max(1.5rem, env(safe-area-inset-bottom, 0px));
}
.apl-field-card + .apl-field-card {
    /* Apple inset separator: starts at the label column (72px), not edge-to-edge */
    border-top: 1px solid var(--apl-sep);
}
.apl-field-card:hover {
    background: var(--apl-fill-hover);
}

/* Open row: soft fill only — no left rail (borderless list language) */
.apl-field-card--expanded {
    background: var(--apl-accent-soft);
}

.apl-field-card__header {
    display: flex;
    align-items: center;
    gap: var(--apl-space-3);
    /* Apple: 16px horizontal padding is the standard for list rows */
    padding: 0 var(--apl-space-4);
    min-height: 44px;
    width: 100%;
    box-sizing: border-box;
}

.apl-field-card__drag-handle {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--apl-gray-5);
    border-radius: 4px;
    flex-shrink: 0;
    cursor: default;
    /* Grip visible at rest; grab cursor only when Sortable is active (see .apl-composer--sortable-ready). */
    opacity: 0.42;
    transition: opacity 0.2s ease, color 0.2s ease;
}
.apl-field-card:hover .apl-field-card__drag-handle,
.apl-field-card--expanded .apl-field-card__drag-handle {
    opacity: 1;
}
.apl-field-card__drag-handle:hover { color: var(--apl-gray-7); }

.apl-composer.apl-composer--sortable-ready .apl-field-card__drag-handle {
    cursor: grab;
    user-select: none;
    -webkit-user-select: none;
}
.apl-composer.apl-composer--sortable-ready .apl-field-card__drag-handle:active {
    cursor: grabbing;
}

/* No text/image selection highlight while reordering (clone + in-list slot + chosen state). */
.apl-sortable-fallback,
.apl-sortable-drag,
.apl-sortable-chosen {
    user-select: none;
    -webkit-user-select: none;
}

/* Placeholder row in the list (drop target) — dashed outline, muted fill */
.apl-sortable-ghost {
    opacity: 0.35 !important;
    background-color: var(--apl-sortable-ghost-bg) !important;
    border: 1px dashed var(--apl-sortable-ghost-border) !important;
    border-radius: var(--apl-radius-sm, 8px);
    box-shadow: none !important;
}
.apl-sortable-chosen {
    background: var(--apl-gray-2);
}
/* Source row while dragging (fallback mode keeps a clone on body; this stays in-list) */
.apl-sortable-drag {
    opacity: 0.35;
}
/* Clone that follows the cursor — full control vs native HTML5 ghost image */
.apl-sortable-fallback {
    opacity: 1 !important;
    background-color: var(--apl-gray-1, #ffffff) !important;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
    transform: scale(1.03) !important;
    cursor: grabbing !important;
    border-radius: var(--apl-radius-sm, 8px);
    z-index: 99999 !important;
    box-sizing: border-box;
    -webkit-user-drag: none;
}

.apl-field-card__content { flex: 1; min-width: 0; }

/* Entire label row (except drag handle / trailing actions) opens settings */
.apl-field-card__content[data-opens-settings] {
    cursor: pointer;
    border-radius: var(--apl-radius-sm);
}
.apl-field-card__content[data-opens-settings]:focus {
    outline: none;
}

.apl-field-card__title {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: nowrap;
    margin: 0;
}

/*
 * Apple HIG: list cell primary label
 *  - font-size: 15px (--apl-text-base) — SF Pro Text / body
 *  - font-weight: 400 (regular) — NOT medium/semibold; those are for nav titles
 *  - letter-spacing: 0 — SF Pro at body sizes has no manual tracking
 *  - line-height: 1.4 (natural)
 */
.apl-field-card__label {
    flex: 1 1 auto;
    min-width: 0;
    font-size: var(--apl-text-base);
    font-weight: 400;
    letter-spacing: 0;
    line-height: 1.4;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--text-main, #111827);
}

/* Type modifiers keep the same ink — color coding is icon-only */
.apl-field-card__label--address,
.apl-field-card__label--phone,
.apl-field-card__label--date,
.apl-field-card__label--boolean,
.apl-field-card__label--text,
.apl-field-card__label--paragraph,
.apl-field-card__label--number,
.apl-field-card__label--picklist,
.apl-field-card__label--multiselect,
.apl-field-card__label--name,
.apl-field-card__label--email,
.apl-field-card__label--notes {
    color: var(--text-main, #111827);
}

/*
 * Type icon — bare colored icon, NO background pill.
 * Apple uses bare SF Symbols inline next to labels in list cells.
 * Adding a colored background square would make it look like an app icon grid.
 */
.apl-type-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    background: none;
    border-radius: 0;
}
.apl-type-badge svg {
    width: 16px;
    height: 16px;
    display: block;
    fill: none;
    /* Delicate strokes — mineral sapphire via currentColor */
    stroke-width: 1.45;
}

/*
 * Field-type icons — full-strength sapphire inside the form grid only (beats generic .apl-composer rule below).
 */
.apl-composer .apl-field-groups .apl-type-badge,
.apl-composer .apl-field-groups .apl-type-badge svg {
    color: var(--accent-sapphire, #0d3b66);
    opacity: 1;
}
.apl-composer .apl-type-badge {
    color: var(--apl-type-icon-muted);
}

/* Fixed detail uses the same badge */
.apl-fixed-detail__item .apl-type-badge {
    width: 16px; height: 16px;
}
.apl-fixed-detail__item .apl-type-badge svg {
    width: 14px;
    height: 14px;
    stroke-width: 1.65;
}

.apl-field-card__badge {
    display: inline-flex;
    align-items: center;
    padding: 1px 7px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: var(--apl-weight-medium);
    background: var(--apl-gray-3);
    color: var(--apl-gray-7);
    letter-spacing: 0;
    white-space: nowrap;
    flex-shrink: 0;
}
.apl-field-card__badge--required {
    background: var(--apl-accent-soft-strong);
    color: var(--apl-accent);
}

.apl-field-card__meta { display: none; }

/*
 * Action buttons: invisible at rest, revealed on hover/focus/expand.
 * Apple: trailing accessory icons in list rows appear only on hover (macOS).
 */
.apl-field-card__actions {
    display: flex;
    gap: 2px;
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.12s ease;
    margin-left: auto;
}
.apl-field-card:hover .apl-field-card__actions,
.apl-field-card:focus-within .apl-field-card__actions,
.apl-field-card--expanded .apl-field-card__actions {
    opacity: 1;
}

/*
 * Apple: icon buttons inside list rows = 28px × 28px (--apl-control-sm).
 * 36px (--apl-control-md) is correct for standalone toolbar buttons, not
 * inline row controls — those must use the compact control size.
 */
.apl-field-card__actions .apl-btn--icon {
    width: var(--apl-control-sm);
    height: var(--apl-control-sm);
    border-radius: 6px;
}
.apl-field-card__actions .apl-btn--icon svg {
    width: 15px;
    height: 15px;
}

/* Settings expand panel — outer shell stays flush; inner surface reads as nested card */
.apl-field-card__settings {
    display: none;
    padding: 8px 12px 12px;
    border-top: 1px solid var(--apl-sep);
    background: transparent;
}
.apl-field-card--expanded .apl-field-card__settings {
    display: block;
    animation: apl-slide-down 0.22s var(--apl-spring-out);
}

@keyframes apl-slide-down {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
}

.apl-field-card__settings-surface {
    background: var(--apl-settings-surface);
    border-radius: 10px;
    border: 1px solid var(--apl-sep);
    box-shadow: none;
    padding: 10px 12px;
}

.apl-field-card__settings-stack {
    display: flex;
    flex-direction: column;
    gap: 0;
}

/* Custom label — tight label + input + muted hint */
.apl-settings-field {
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--apl-sep);
}
.apl-settings-field__label {
    display: block;
    margin-bottom: 5px;
    font-size: 13px;
    font-weight: 500;
    letter-spacing: -0.01em;
    color: var(--apl-gray-7);
}
.apl-settings-field__input.apl-input {
    padding: 7px 10px;
    font-size: 15px;
    line-height: 1.35;
    border-radius: 8px;
    background: var(--apl-settings-input-bg);
    border: 1px solid var(--apl-settings-input-border);
}
.apl-settings-field__input.apl-input:hover {
    background: var(--apl-settings-input-focus);
    border-color: var(--apl-settings-input-border);
}
.apl-settings-field__input.apl-input:focus,
.apl-settings-field__input.apl-input:focus-visible {
    background: var(--apl-settings-input-focus);
    border-color: var(--apl-settings-input-border-focus);
}
.apl-settings-field__hint {
    margin: 4px 0 0;
    font-size: 11px;
    line-height: 1.35;
    color: var(--apl-gray-6);
}

/* iOS-like grouped rows: hairlines between cells */
.apl-settings-group {
    border-radius: 8px;
    overflow: hidden;
    background: var(--apl-settings-group-bg);
    border: 1px solid var(--apl-sep);
}
.apl-settings-group__cell {
    padding: 4px 8px;
}
.apl-settings-group__cell + .apl-settings-group__cell {
    border-top: 1px solid var(--apl-sep);
}
.apl-settings-group__cell--stack {
    padding: 8px 8px 10px;
}
.apl-settings-seg-head {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 500;
    color: var(--apl-gray-8);
    letter-spacing: -0.01em;
}
.apl-settings-seg-hint {
    margin: 6px 0 0;
    font-size: 11px;
    line-height: 1.35;
    color: var(--apl-gray-6);
}

/* Compact switch row inside settings (label left, control right) */
.apl-switch--settings-tight {
    min-height: 38px;
    padding: 4px 0;
    gap: 10px;
}
.apl-switch--settings-tight .apl-switch__label {
    font-size: 15px;
    font-weight: 400;
    line-height: 1.25;
}
.apl-switch--settings-tight .apl-switch__track {
    width: 48px;
    height: 29px;
}
.apl-switch--settings-tight .apl-switch__thumb {
    width: 25px;
    height: 25px;
}
.apl-switch--settings-tight .apl-switch__input:checked + .apl-switch__track .apl-switch__thumb {
    transform: translateX(19px);
}

/* Segmented control — compact variant for inline settings */
.apl-layout-span-segmented--compact {
    padding: 3px;
    border-radius: 8px;
    background: var(--apl-segmented-track);
    border: 1px solid var(--apl-border-panel);
    box-shadow: var(--apl-segmented-inset);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}
.apl-layout-span-segmented--compact .apl-layout-span-segmented__option {
    border-radius: 6px;
}
.apl-layout-span-segmented--compact .apl-layout-span-segmented__text {
    min-height: 30px;
    padding: 4px 6px;
    gap: 0;
    border-radius: 6px;
}
.apl-layout-span-segmented--compact .apl-layout-span-segmented__title {
    font-size: 12px;
    font-weight: 600;
}
.apl-layout-span-segmented--compact .apl-layout-span-segmented__sub {
    font-size: 9px;
    margin-top: 1px;
}
.apl-layout-span-segmented--compact .apl-layout-span-segmented__input:checked + .apl-layout-span-segmented__text {
    transform: none;
    box-shadow:
        0 1px 4px rgba(15, 23, 42, 0.1),
        0 0 0 1px rgba(15, 23, 42, 0.04);
}

.apl-settings-actions {
    display: flex;
    gap: var(--apl-space-3);
    margin-top: var(--apl-space-6);
    padding-top: var(--apl-space-5);
    border-top: 1px solid var(--apl-sep);
}
.apl-settings-actions--tight {
    margin-top: 10px;
    padding-top: 10px;
    gap: 8px;
    border-top-color: var(--apl-sep);
}

/* ==========================================================================
   FORM CONTROLS
   ========================================================================== */
.apl-form-group { margin-bottom: var(--apl-space-5); }
.apl-form-group:last-child { margin-bottom: 0; }

.apl-form-label {
    display: block;
    margin-bottom: var(--apl-space-2);
    font-size: var(--apl-text-sm);
    font-weight: var(--apl-weight-medium);
    color: var(--apl-gray-8);
}

.apl-form-hint {
    margin-top: var(--apl-space-2);
    font-size: var(--apl-text-sm);
    color: var(--apl-gray-6);
    line-height: var(--apl-line-relaxed);
}

/*
 * Form row width — inset segmented control (macOS / iOS Settings style, 2026 glass track).
 */
.apl-layout-span-segmented {
    display: flex;
    gap: 0;
    padding: 4px;
    border-radius: 14px;
    background: var(--apl-segmented-track);
    border: 1px solid var(--apl-border-panel);
    box-shadow: var(--apl-segmented-inset);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}
.apl-layout-span-segmented__option {
    position: relative;
    flex: 1;
    min-width: 0;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    border-radius: 10px;
}
.apl-layout-span-segmented__input {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
    opacity: 0;
}
.apl-layout-span-segmented__text {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1px;
    min-height: 44px;
    padding: 8px 6px;
    border-radius: 10px;
    text-align: center;
    font-family: var(--apl-font-family);
    transition:
        background 0.22s var(--apl-spring-out),
        color 0.18s ease,
        box-shadow 0.22s var(--apl-spring-out),
        transform 0.18s var(--apl-spring-out);
}
.apl-layout-span-segmented__title {
    font-size: 13px;
    font-weight: var(--apl-weight-semibold);
    letter-spacing: -0.02em;
    color: var(--apl-gray-7);
    line-height: 1.2;
}
.apl-layout-span-segmented__sub {
    font-size: 10px;
    font-weight: var(--apl-weight-medium);
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--apl-gray-5);
    line-height: 1.2;
}
.apl-layout-span-segmented__option:hover .apl-layout-span-segmented__text {
    background: var(--apl-segmented-hover);
}
.apl-layout-span-segmented__input:checked + .apl-layout-span-segmented__text {
    background: var(--apl-segmented-pill);
    color: var(--apl-gray-8);
    box-shadow:
        0 2px 8px rgba(15, 23, 42, 0.08),
        0 1px 2px rgba(15, 23, 42, 0.06),
        inset 0 1px 0 rgba(255, 255, 255, 0.9);
    transform: scale(1.02);
}
.apl-layout-span-segmented__input:checked + .apl-layout-span-segmented__text .apl-layout-span-segmented__title {
    color: var(--apl-gray-8);
}
.apl-layout-span-segmented__input:checked + .apl-layout-span-segmented__text .apl-layout-span-segmented__sub {
    color: var(--apl-gray-6);
}
.apl-layout-span-segmented__input:focus-visible + .apl-layout-span-segmented__text {
    outline: 2px solid var(--apl-accent);
    outline-offset: 2px;
}
/*
 * Borderless fields: no outline ring; focus = slightly darker fill only.
 */
.apl-input,
.apl-select,
.apl-textarea {
    width: 100%;
    padding: var(--apl-space-3) var(--apl-space-4);
    border: none;
    border-radius: var(--apl-radius-md);
    font-family: var(--apl-font-family);
    font-size: var(--apl-text-base);
    color: var(--apl-gray-8);
    background: var(--apl-input-fill);
    box-shadow: none;
    transition: background 0.18s ease;
    appearance: none;
    -webkit-appearance: none;
}
.apl-select {
    background-color: var(--apl-input-fill);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='%236b728f'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
}
.apl-input:hover,
.apl-select:hover,
.apl-textarea:hover {
    background-color: var(--apl-input-fill-strong);
}
.apl-input:focus,
.apl-input:focus-visible,
.apl-select:focus,
.apl-select:focus-visible,
.apl-textarea:focus,
.apl-textarea:focus-visible {
    outline: none;
    border: none;
    box-shadow: none;
    background-color: var(--apl-input-fill-strong);
}
.apl-textarea { min-height: 80px; resize: vertical; }

/* iOS-style switch — Settings row: title left, control right (like iOS grouped lists). */
.apl-switch {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    gap: var(--apl-space-4);
    min-height: 44px;
    cursor: pointer;
    box-sizing: border-box;
}
.apl-switch__input {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
    opacity: 0;
}
.apl-switch__track {
    position: relative;
    width: 51px;
    height: 31px;
    background: var(--apl-gray-4);
    border-radius: var(--apl-radius-full);
    transition: background var(--apl-transition-base);
    flex-shrink: 0;
}
.apl-switch__thumb {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 27px;
    height: 27px;
    background: #FFFFFF;
    border-radius: 50%;
    /* Soft two-layer thumb — same language as page elevation, slightly lighter */
    box-shadow: 0 1px 3px rgba(0,0,0,.10), 0 1px 1px rgba(0,0,0,.06);
    transition: transform 0.25s cubic-bezier(0.4,0,0.2,1);
}
.apl-switch__input:checked + .apl-switch__track { background: var(--apl-switch-track-on); }
.apl-switch__input:checked + .apl-switch__track .apl-switch__thumb { transform: translateX(20px); }
.apl-switch__input:focus-visible + .apl-switch__track {
    outline: 2px solid var(--apl-switch-track-on);
    outline-offset: 3px;
    border-radius: var(--apl-radius-full);
}

.apl-form-group--switch-pair {
    display: flex;
    flex-wrap: wrap;
    gap: var(--apl-space-5);
    align-items: stretch;
}
.apl-form-group--switch-pair .apl-switch {
    flex: 1;
    min-width: min(200px, 100%);
}
.apl-switch__label {
    flex: 1;
    min-width: 0;
    font-size: var(--apl-text-base);
    color: var(--apl-gray-8);
    user-select: none;
    text-align: start;
    line-height: var(--apl-line-normal);
}

/* ==========================================================================
   INLINE DESTRUCTIVE CONFIRMATION
   ========================================================================== */
/*
 * Inline destructive confirm — Apple style:
 *  - Background: rgba(255,59,48,.08) — very faint tint, not an opaque red block
 *  - Border: rgba(255,59,48,.2) — slightly more visible than background tint
 *  - border-radius: 8px (tight rounded rect, not pill, not full modal rounding)
 *  - Text: full red (FF3B30), medium weight — clear but not alarming
 */
.apl-inline-confirm {
    display: none;
    align-items: center;
    gap: var(--apl-space-2);
    padding: var(--apl-space-2) var(--apl-space-3);
    background: rgba(255,59,48,.08);
    border: 1px solid rgba(255,59,48,.2);
    border-radius: 8px;
    font-size: var(--apl-text-sm);
    animation: apl-slide-down 0.15s ease;
}
.apl-inline-confirm.is-visible { display: flex; }

.apl-inline-confirm__text {
    flex: 1;
    color: var(--apl-red);
    font-weight: var(--apl-weight-medium);
}

.apl-inline-confirm__countdown {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: conic-gradient(var(--apl-red) calc(var(--pct, 100) * 1%), rgba(255,59,48,.12) 0);
    flex-shrink: 0;
}

/* ==========================================================================
   FIELD LIBRARY
   ========================================================================== */
.apl-library {
    margin-top: var(--apl-space-6);
    background: var(--apl-gray-1);
    border-radius: 12px;
    border: 1px solid var(--apl-border-panel);
    overflow: hidden;
    box-shadow: var(--apl-shadow-sm);
}

.apl-library__content { padding: var(--apl-space-5); }

/* Search */
.apl-library__search {
    display: flex;
    align-items: center;
    gap: var(--apl-space-2);
    padding: var(--apl-space-3) var(--apl-space-4);
    margin-bottom: var(--apl-space-5);
    border: none;
    border-radius: var(--apl-radius-md);
    background: var(--apl-input-fill);
    box-shadow: none;
    transition: background 0.18s ease;
}
.apl-library__search:focus-within {
    background: var(--apl-input-fill-strong);
    box-shadow: none;
}
.apl-library__search-icon { color: var(--apl-gray-5); flex-shrink: 0; }
.apl-library__search-input {
    flex: 1;
    border: none;
    background: transparent;
    font-family: var(--apl-font-family);
    font-size: var(--apl-text-base);
    color: var(--apl-gray-8);
    outline: none;
}
.apl-library__search-input::placeholder { color: var(--apl-gray-5); }

.apl-library__group {
    margin-bottom: var(--apl-space-5);
}
.apl-library__group:last-child { margin-bottom: 0; }
.apl-library__group[hidden] { display: none; }

.apl-library__group-title {
    display: flex;
    align-items: center;
    gap: var(--apl-space-2);
    margin-bottom: var(--apl-space-3);
    font-size: var(--apl-text-sm);
    font-weight: var(--apl-weight-semibold);
    color: var(--apl-gray-7);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.apl-library__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--apl-space-2);
}

/* ── Chip wrapper: chip button + optional icon actions ───────────────────── */
.apl-chip-wrapper {
    display: inline-flex;
    align-items: center;
    border: 1px solid var(--apl-border-panel);
    border-radius: var(--apl-radius-full);
    overflow: hidden;
    transition: border-color 0.12s ease;
    background: var(--apl-gray-1);
}
.apl-chip-wrapper:hover {
    border-color: var(--apl-accent);
}
.apl-chip-wrapper[data-hidden="true"] { display: none; }

.apl-library__chip {
    padding: var(--apl-space-2) var(--apl-space-4);
    border: none;
    background: transparent;
    font-family: var(--apl-font-family);
    font-size: var(--apl-text-sm);
    font-weight: var(--apl-weight-medium);
    color: var(--apl-gray-8);
    cursor: pointer;
    transition: background var(--apl-transition-fast), color var(--apl-transition-fast);
    white-space: nowrap;
}
.apl-library__chip:hover { background: var(--apl-accent-soft); color: var(--apl-accent); }

.apl-chip-actions {
    display: flex;
    border-left: 1px solid var(--apl-chip-divider);
}
.apl-chip-action {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    background: transparent;
    cursor: pointer;
    color: var(--apl-gray-5);
    transition: background 0.12s ease, color 0.12s ease;
}
.apl-chip-action:hover { background: var(--apl-fill-hover-strong); color: var(--apl-gray-8); }
.apl-chip-action--danger:hover { background: rgba(255,59,48,.08); color: var(--apl-red); }
.apl-chip-action + .apl-chip-action { border-left: 1px solid var(--apl-chip-divider); }

/* ── Chip delete confirm row ─────────────────────────────────────────────── */
.apl-chip-confirm {
    display: none;
    align-items: center;
    gap: var(--apl-space-2);
    padding: var(--apl-space-2) var(--apl-space-3);
    background: rgba(255,59,48,.08);
    border: 1px solid rgba(255,59,48,.2);
    border-radius: var(--apl-radius-full);
    font-size: var(--apl-text-sm);
    animation: apl-slide-down 0.15s ease;
}
.apl-chip-confirm.is-visible { display: flex; }
.apl-chip-confirm__text { color: var(--apl-red); font-weight: var(--apl-weight-medium); white-space: nowrap; }

/* ==========================================================================
   SLIDE-OVER PANEL (New / Edit field definition)
   ========================================================================== */
/*
 * Apple modal overlay (macOS Sequoia / 2026):
 *  - backdrop-filter blur(8px) — content behind sheet is softly blurred
 *  - background rgba(0,0,0,.3) — lower opacity because blur carries the depth
 *  - Together these match the macOS Sequoia sheet dimming exactly
 */
.apl-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.26);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 999;
    animation: apl-fade-in 0.18s ease;
}
.apl-overlay.is-open { display: block; }
@keyframes apl-fade-in { from { opacity: 0; } to { opacity: 1; } }

/*
 * Apple sheet / slide-over panel (macOS Sequoia / 2026):
 *  - Spring transition: cubic-bezier(0.32, 0.72, 0, 1) 0.38s — matches iOS 18 sheet
 *  - Two-layer diffuse shadow: large soft spread, low opacity
 *  - Panel bg = solid white — backdrop blur lives on the overlay, not the panel
 */
.apl-panel {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 480px;
    max-width: 100%;
    background: var(--apl-gray-1);
    box-shadow: var(--apl-shadow-sheet);
    display: flex;
    flex-direction: column;
    z-index: 1000;
    transform: translateX(100%);
    transition: transform 0.38s cubic-bezier(0.32, 0.72, 0, 1);
}
.apl-panel.is-open { transform: translateX(0); }

.apl-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--apl-space-5) var(--apl-space-6);
    border-bottom: 1px solid var(--apl-border-panel);
    flex-shrink: 0;
}

.apl-panel__title {
    margin: 0;
    font-size: var(--apl-text-xl);
    font-weight: var(--apl-weight-semibold);
    color: var(--apl-gray-8);
    letter-spacing: -0.02em;
}

.apl-panel__body {
    flex: 1;
    overflow-y: auto;
    padding: var(--apl-space-6);
}

.apl-panel__footer {
    display: flex;
    gap: var(--apl-space-3);
    padding: var(--apl-space-5) var(--apl-space-6);
    border-top: 1px solid var(--apl-border-panel);
    flex-shrink: 0;
}

.apl-panel__footer .apl-btn { flex: 1; }

/* Options / choices — only visible for select/multiselect */
.apl-options-group { display: none; }
.apl-options-group.is-visible { display: block; }

.apl-choices-editor__list {
    list-style: none;
    margin: 0 0 var(--apl-space-3);
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--apl-space-2);
}
.apl-choices-editor__row {
    display: flex;
    align-items: center;
    gap: var(--apl-space-2);
}
.apl-choices-editor__row .apl-choices-editor__input {
    flex: 1;
    min-width: 0;
}
.apl-choices-editor__rm {
    flex-shrink: 0;
    width: var(--apl-control-md);
    height: var(--apl-control-md);
    padding: 0;
    border: none;
    border-radius: var(--apl-radius-sm);
    background: transparent;
    color: var(--apl-gray-6);
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    transition: background 0.12s ease, color 0.12s ease;
}
.apl-choices-editor__rm:hover {
    background: var(--apl-input-fill);
    color: var(--apl-gray-8);
}
.apl-choices-editor__rm:focus-visible {
    outline: 2px solid var(--apl-accent);
    outline-offset: 2px;
}

/* Field type combobox (panel) — soft surface, icons, spring motion */
.apl-form-group--type-picker {
    position: relative;
    z-index: 0;
}

/*
 * Open menu is position:absolute and overlaps following form rows (Required / Active).
 * Those siblings paint later in DOM order and would cover the list unless this group
 * stacks above them while the menu is open (.is-menu-open toggled in JS).
 */
.apl-form-group--type-picker.is-menu-open {
    z-index: 200;
}

.apl-type-picker {
    position: relative;
}
.apl-type-picker.is-open {
    z-index: 1;
}
.apl-type-picker__trigger {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 7px;
    min-height: 44px;
    padding: 0 var(--apl-space-4);
    border: none;
    border-radius: var(--apl-radius-md);
    background: var(--apl-input-fill);
    cursor: pointer;
    font-family: inherit;
    font-size: var(--apl-text-base);
    font-weight: 400;
    line-height: 1.4;
    letter-spacing: 0;
    color: var(--apl-gray-8);
    text-align: left;
    transition: background 0.18s ease;
}
.apl-type-picker__trigger:hover {
    background: var(--apl-input-fill-strong);
}
.apl-type-picker__trigger:focus {
    outline: none;
}
.apl-type-picker__trigger:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px var(--apl-focus-ring);
    background: var(--apl-input-fill-strong);
}
.apl-type-picker__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    /* Icon color from .apl-composer .apl-type-badge (muted) */
}
.apl-type-picker__icon svg {
    display: block;
    width: 16px;
    height: 16px;
    fill: none;
    stroke-width: 1.75;
}
.apl-type-picker__value {
    flex: 1;
    min-width: 0;
    line-height: 1.4;
}
.apl-type-picker__caret {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    color: var(--apl-gray-5);
    flex-shrink: 0;
    transform: rotate(90deg);
    transition: transform 0.26s var(--apl-spring-out);
}
.apl-type-picker__caret svg {
    width: 16px;
    height: 16px;
    display: block;
    fill: none;
    stroke-width: 1.75;
}
.apl-type-picker.is-open .apl-type-picker__caret {
    transform: rotate(-90deg);
}
.apl-type-picker__menu {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 6px);
    z-index: 2;
    padding: 0;
    margin: 0;
    list-style: none;
    /* Solid surface so nothing from scroll content shows through during opacity animation */
    background: var(--apl-gray-1);
    border: none;
    border-radius: var(--apl-radius-md);
    box-shadow: var(--apl-shadow-xl);
    isolation: isolate;
    /* ~9 rows at 44px — matches field list row height */
    max-height: min(396px, 60vh);
    overflow-x: hidden;
    overflow-y: auto;
    opacity: 0;
    transform: translateY(-8px) scale(0.97);
    pointer-events: none;
    transition:
        opacity 0.24s var(--apl-spring-out),
        transform 0.3s var(--apl-spring-out);
}
.apl-type-picker.is-open .apl-type-picker__menu {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: auto;
}
.apl-type-picker__option {
    display: flex;
    align-items: center;
    gap: 7px;
    width: 100%;
    min-height: 44px;
    padding: 0 var(--apl-space-4);
    margin: 0;
    border: none;
    border-radius: 0;
    box-sizing: border-box;
    background: transparent;
    cursor: pointer;
    font-family: inherit;
    font-size: var(--apl-text-base);
    font-weight: 400;
    line-height: 1.4;
    letter-spacing: 0;
    color: var(--ds-color-text, var(--apl-gray-8));
    text-align: left;
    transition: background 0.1s ease;
}
.apl-type-picker__option + .apl-type-picker__option {
    border-top: 1px solid var(--apl-sep);
}
.apl-type-picker__option:hover {
    background: var(--apl-fill-hover);
}
.apl-type-picker__option[aria-selected="true"] {
    background: var(--apl-fill-hover-strong);
}
.apl-type-picker__option:focus {
    outline: none;
}
.apl-type-picker__option:focus-visible {
    outline: none;
    box-shadow: none;
    background: var(--apl-input-fill);
}
.apl-type-picker__opt-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}
.apl-type-picker__opt-icon svg {
    display: block;
    width: 16px;
    height: 16px;
    fill: none;
    stroke-width: 1.75;
}
.apl-type-picker__opt-label {
    flex: 1 1 auto;
    min-width: 0;
    text-align: left;
}
@media (prefers-reduced-motion: reduce) {
    .apl-type-picker__menu,
    .apl-type-picker__caret {
        transition: none !important;
    }
    .apl-type-picker__menu {
        transform: none !important;
    }
    .apl-type-picker.is-open .apl-type-picker__menu {
        transform: none !important;
    }
}

/* ==========================================================================
   EMPTY STATE
   ========================================================================== */
.apl-empty {
    text-align: center;
    padding: var(--apl-space-16) var(--apl-space-8);
}
.apl-empty__icon { width: 56px; height: 56px; margin: 0 auto var(--apl-space-4); color: var(--apl-gray-4); }
.apl-empty__title {
    margin: 0 0 var(--apl-space-2);
    font-size: var(--apl-text-xl);
    font-weight: var(--apl-weight-semibold);
    color: var(--apl-gray-8);
}
.apl-empty__message {
    margin: 0 0 var(--apl-space-5);
    font-size: var(--apl-text-base);
    color: var(--apl-gray-6);
    line-height: var(--apl-line-relaxed);
    max-width: 380px;
    margin-left: auto;
    margin-right: auto;
}

/* ==========================================================================
   RESPONSIVE
   ========================================================================== */
   
/* Tablet landscape */
@media (max-width: 1024px) {
    .apl-sidebar { width: 200px; }
    .apl-canvas  { padding: var(--apl-space-6); }
    .apl-toolbar { padding: var(--apl-space-5) var(--apl-space-6); }
    .apl-sidebar__header {
        padding: var(--apl-space-5) var(--apl-space-5);
        min-height: calc(var(--apl-space-5) + var(--apl-control-lg) + var(--apl-space-5));
    }
}

/* Tablet portrait */
@media (max-width: 768px) {
    .apl-composer { flex-direction: column; }

    .apl-sidebar  {
        width: 100%;
        border-right: none;
        border-bottom: 1px solid var(--apl-border-panel);
        border-top-right-radius: var(--apl-radius-lg);
        position: sticky;
        top: 0;
        height: auto;
        max-height: 200px;
        z-index: 12;
    }

    .apl-sidebar__header {
        padding: var(--apl-space-4);
        min-height: calc(var(--apl-space-4) + var(--apl-control-lg) + var(--apl-space-4));
    }
    .apl-sidebar__title { font-size: var(--apl-text-xl); }

    .apl-sidebar__body {
        padding: var(--apl-space-2) var(--apl-space-2);
        overflow-y: auto;
    }

    .apl-sidebar__nav {
        display: flex;
        gap: var(--apl-space-2);
        padding: var(--apl-space-1) var(--apl-space-2);
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        background: none;
        border: none;
        box-shadow: none;
        border-radius: 0;
    }

    .apl-sidebar__nav-item { margin-bottom: 0; white-space: nowrap; }

    .apl-toolbar {
        padding: var(--apl-space-4);
        gap: var(--apl-space-3);
        border-top-right-radius: 0;
    }
    
    .apl-toolbar__eyebrow { font-size: var(--apl-text-xs); }
    .apl-toolbar__title { font-size: var(--apl-text-xl); }
    
    .apl-toolbar__actions { 
        display: flex;
        gap: var(--apl-space-2);
    }
    
    .apl-canvas { padding: var(--apl-space-4); }
    .apl-canvas__inner { max-width: 100%; }
    
    .apl-field-card__header { padding: var(--apl-space-3); gap: var(--apl-space-2); }
    .apl-field-card__label { font-size: var(--apl-text-sm); }
    .apl-field-card__settings { padding: 6px 10px 10px; }
    .apl-fixed-row { padding: var(--apl-space-3) var(--apl-space-4); gap: var(--apl-space-2); }
    .apl-fixed-row__hint { font-size: var(--apl-text-xs); }
    
    .apl-library__content { padding: var(--apl-space-4); }
    
    .apl-panel { width: 100%; }

    .apl-field-list--layout-flow {
        display: flex;
        flex-direction: column;
        column-gap: normal;
    }
    .apl-field-list--layout-flow > .apl-field-card {
        grid-column: unset !important;
    }
    .apl-field-list--layout-flow > .apl-layout-flow-row-rule {
        display: none;
    }
    .apl-field-list--layout-flow > .apl-field-card.apl-field-card--layout-row-start {
        border-top: none;
    }
    .apl-field-list--layout-flow > .apl-field-card + .apl-field-card {
        border-top: 1px solid var(--apl-sep);
    }
    .apl-field-list--layout-flow > .apl-field-card:not(.apl-field-card--layout-row-start) {
        border-left: none;
        padding-left: 0;
    }

    .apl-field-list--layout-flow > .apl-field-card.apl-field-card--flow-row-trail {
        border-right: none;
        padding-right: 0;
    }
}

/* Mobile */
@media (max-width: 480px) {
    .apl-fixed-detail__list {
        grid-template-columns: 1fr;
    }
    .apl-fixed-detail__list .apl-fixed-detail__item:last-child:nth-child(odd) {
        grid-column: auto;
    }

    .apl-sidebar { max-height: 160px; }

    .apl-sidebar__header {
        padding: var(--apl-space-3);
        min-height: calc(var(--apl-space-3) + var(--apl-control-lg) + var(--apl-space-3));
    }

    .apl-toolbar { 
        padding: var(--apl-space-3);
        flex-wrap: wrap;
    }
    
    .apl-toolbar__title { font-size: var(--apl-text-lg); }
    
    .apl-toolbar__actions { 
        width: 100%;
        justify-content: stretch;
    }
    
    .apl-toolbar__actions .apl-save-btn,
    .apl-toolbar__actions .apl-toolbar-new-field-btn {
        flex: 1;
        font-size: var(--apl-text-sm);
        min-height: var(--apl-control-md);
    }
    
    .apl-canvas { padding: var(--apl-space-3); }
    
    .apl-field-card__header { padding: var(--apl-space-2) var(--apl-space-3); gap: var(--apl-space-2); min-height: 44px; }
    .apl-field-card__drag-handle { width: 24px; height: 24px; }
    .apl-field-card__label { font-size: var(--apl-text-sm); }
    .apl-type-badge { width: 20px; height: 20px; }
    .apl-type-badge svg { width: 12px; height: 12px; stroke-width: 1.5; }
    /* On mobile: always show actions — no hover available */
    .apl-field-card__actions { opacity: 1; }
    .apl-field-card__drag-handle { opacity: 1; }
    
    .apl-type-badge { width: 16px; height: 16px; }
    .apl-type-badge svg { width: 14px; height: 14px; stroke-width: 1.6; }
    
    .apl-btn--icon.apl-btn--sm {
        width: 32px;
        height: 32px;
    }
    
    .apl-library__chips { gap: var(--apl-space-1); }
    .apl-library__chip { font-size: var(--apl-text-xs); padding: var(--apl-space-1) var(--apl-space-3); }
    
    .apl-form-group { margin-bottom: var(--apl-space-4); }
    
    .apl-panel__header { padding: var(--apl-space-4); }
    .apl-panel__body { padding: var(--apl-space-4); }
    .apl-panel__footer { padding: var(--apl-space-4); flex-direction: column; }
    .apl-panel__footer .apl-btn { width: 100%; }
}

/* ==========================================================================
   ACCESSIBILITY
   ========================================================================== */
.apl-sr-only {
    position: absolute; width: 1px; height: 1px;
    padding: 0; margin: -1px; overflow: hidden;
    clip: rect(0,0,0,0); white-space: nowrap; border-width: 0;
}

/*
 * Apple keyboard focus ring — scoped to composer to avoid overriding
 * the global design system focus rings on nav, modals, and other modules.
 *  - outline: 2px solid ink + 2px offset
 */
.apl-composer *:focus-visible {
    outline: 2px solid var(--apl-accent);
    outline-offset: 2px;
    box-shadow: var(--apl-shadow-focus-outer);
}

/* Borderless fields use background only — suppress the composer-wide focus ring */
.apl-composer .apl-input:focus-visible,
.apl-composer .apl-select:focus-visible,
.apl-composer .apl-textarea:focus-visible,
.apl-composer .apl-library__search-input:focus-visible {
    outline: none;
    box-shadow: none;
}

/* Type picker: override composer * ring; keep soft trigger ring, tint-only options */
.apl-composer .apl-type-picker__trigger:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px var(--apl-focus-ring);
    background: var(--apl-input-fill-strong);
}
.apl-composer .apl-type-picker__option:focus-visible {
    outline: none;
    box-shadow: none;
    background: var(--apl-input-fill-strong);
}

/* Legacy toolbar class — unused when Kylie CTA is active; kept for reference */
.apl-composer .ds-btn.ds-btn--toolbar:focus-visible {
    outline: 2px solid var(--ds-color-focus-ring-ui, var(--ds-color-focus-ring));
    outline-offset: 2px;
    box-shadow: var(--ds-shadow-toolbar-cta);
}
.apl-composer .apl-toolbar__actions .apl-save-btn:focus-visible {
    outline: 2px solid var(--apl-gray-8);
    outline-offset: 2px;
    box-shadow: var(--apl-shadow-focus-outer);
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

@media (prefers-contrast: high) {
    .apl-field-card, .apl-btn, .apl-toolbar-new-field-btn { border-width: 2px; }
}

@media print {
    .apl-sidebar, .apl-toolbar__actions, .apl-field-card__drag-handle,
    .apl-field-card__actions, .apl-library, .apl-overlay, .apl-panel { display: none !important; }
    .apl-main { width: 100%; }
    .apl-fixed-row__toggle { display: none !important; }
    .apl-fixed-detail { display: block !important; }
}

</style>

<?php /* =====================================================================
   HTML — two-column composer
   ===================================================================== */ ?>

<div class="apl-composer" id="apl-composer-root">

    <aside class="apl-sidebar" aria-label="Layout profiles">
        <div class="apl-sidebar__header">
            <p class="apl-toolbar__eyebrow apl-toolbar__eyebrow--layout-spacer" aria-hidden="true">Form Composer</p>
            <h1 class="apl-sidebar__title">Form Layouts</h1>
        </div>

        <div class="apl-sidebar__body">
            <p class="apl-sidebar__section-label">Profiles</p>
            <ul class="apl-sidebar__nav">
                <?php foreach ($profiles ?? [] as $profile): ?>
                    <?php
                    $pk     = (string)($profile['profile_key'] ?? '');
                    $active = $pk === $selectedProfileKey;
                    ?>
                    <li class="apl-sidebar__nav-item">
                        <a href="/clients/custom-fields?profile=<?= rawurlencode($pk) ?>"
                           class="apl-sidebar__nav-link <?= $active ? 'is-active' : '' ?>"
                           data-nav-link
                           <?= $active ? 'aria-current="page"' : '' ?>>
                            <?= htmlspecialchars((string)($profile['display_label'] ?? $pk)) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

    </aside>

    <main class="apl-main" aria-label="Form layout editor">

        <?php /* Session flash → app-toast (shared/layout/partials/app-toast-bootstrap.php); no inline banner */ ?>

        <?php /* Toolbar */ ?>
        <div class="apl-toolbar">
            <div>
                <p class="apl-toolbar__eyebrow">Form Composer</p>
                <h2 class="apl-toolbar__title"><?= htmlspecialchars($profileDisplayLabel) ?></h2>
            </div>

            <?php if ($canEditClientFields): ?>
            <div class="apl-toolbar__actions apl-toolbar__actions--split">
                <button type="button"
                        class="apl-btn apl-btn--secondary apl-toolbar-new-field-btn"
                        id="btn-new-field"
                        aria-haspopup="dialog"
                        aria-controls="panel-field">
                    <?= $icon('plus', 16) ?>
                    <span>New Field</span>
                </button>
                <?php if ($layoutStorageReady): ?>
                <form id="form-save-layout"
                      class="apl-toolbar-save-form"
                      method="post"
                      action="/clients/custom-fields/layouts/save">
                    <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey) ?>">
                    <button type="submit"
                            class="apl-btn apl-btn--primary apl-save-btn"
                            id="btn-save-layout"
                            data-dirty="false"
                            disabled>
                        Save Layout
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php /* Canvas */ ?>
        <div class="apl-canvas">
            <div class="apl-canvas__inner">

                <?php if (!$layoutStorageReady): ?>
                    <div class="apl-empty">
                        <div class="apl-empty__icon"><?= $icon('settings', 56) ?></div>
                        <h3 class="apl-empty__title">Setup Required</h3>
                        <p class="apl-empty__message">
                            <?= htmlspecialchars(\Modules\Clients\Services\ClientPageLayoutService::LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE) ?>
                        </p>
                        <p class="apl-empty__message">
                            Run <code style="font-family:monospace;padding:2px 7px;background:var(--apl-gray-3);border-radius:5px">php scripts/migrate.php</code>
                            from the <code style="font-family:monospace;padding:2px 7px;background:var(--apl-gray-3);border-radius:5px">system/</code> directory.
                        </p>
                    </div>

                <?php elseif (empty($layoutItemsSorted)): ?>
                    <div class="apl-empty">
                        <div class="apl-empty__icon"><?= $icon('doc', 56) ?></div>
                        <h3 class="apl-empty__title">No Fields Yet</h3>
                        <p class="apl-empty__message">
                            <?php if ($hasLibraryFields): ?>
                                Add fields from the library below, or use <strong>New Field</strong> in the toolbar.
                            <?php elseif ($canEditClientFields): ?>
                                There are no catalog fields left to add here. Use <strong>New Field</strong> in the toolbar to create a custom field.
                            <?php else: ?>
                                This layout does not include any fields yet.
                            <?php endif; ?>
                        </p>
                    </div>

                <?php else: ?>
                    <div class="apl-field-groups" id="apl-field-groups">

                        <?php if ($lockedFieldCount > 0): ?>
                        <div class="apl-fixed-row" data-fixed-summary>
                            <span class="apl-fixed-row__lock" aria-hidden="true"><?= $icon('lock', 16) ?></span>
                            <span class="apl-fixed-row__label">Fixed fields</span>
                            <span class="apl-fixed-row__count"><?= $lockedFieldCount ?></span>
                            <span class="apl-fixed-row__hint"><?= htmlspecialchars($lockedHintLine) ?></span>
                            <button type="button"
                                    class="apl-fixed-row__toggle"
                                    data-action="toggle-fixed"
                                    aria-expanded="false"
                                    aria-label="Show fixed fields">
                                <?= $icon('chevron', 16) ?>
                            </button>
                        </div>
                        <div class="apl-fixed-detail" id="apl-fixed-detail" data-fixed-detail>
                            <?php /* Fixed-field preview: always a simple 2-column grid (not the editable layout-flow grid). */ ?>
                            <ul class="apl-fixed-detail__list">
                                <?php foreach ($lockedRows as $lfi => $lrow):
                                    $lItem = $lrow['item'];
                                    $lIdx = (int)$lrow['idx'];
                                    $lFk = (string)($lItem['field_key'] ?? '');
                                    $lDl = trim((string)($lItem['display_label'] ?? ''));
                                    $lLabel = $lDl !== '' ? $lDl : ($fieldLabels[$lFk] ?? $lFk);
                                    $lFt = $customFieldLayoutTypes[$lFk] ?? ($systemFieldDefinitions[$lFk]['admin_field_type'] ?? 'text');
                                    $lCat = $classifyFieldType($lFk, $lFt, $systemFieldDefinitions);
                                    $lBadgeIcon = $typeIconMap[$lCat] ?? 'text-t';
                                ?>
                                <li class="apl-fixed-detail__item">
                                    <span class="apl-type-badge apl-type-badge--<?= htmlspecialchars($lCat) ?>"><?= $icon($lBadgeIcon, 14) ?></span>
                                    <span><?= htmlspecialchars($lLabel) ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php foreach ($lockedRows as $lrow):
                                $lItem = $lrow['item'];
                                $lIdx = (int)$lrow['idx'];
                                $lFk = (string)($lItem['field_key'] ?? '');
                                $lDl = trim((string)($lItem['display_label'] ?? ''));
                                $lReq = (int)($lItem['is_required'] ?? 0) === 1;
                            ?>
                            <input type="hidden" name="items[<?= htmlspecialchars($lFk) ?>][field_key]" form="form-save-layout" value="<?= htmlspecialchars($lFk) ?>">
                            <input type="hidden" name="items[<?= htmlspecialchars($lFk) ?>][position]" form="form-save-layout" value="<?= $lIdx ?>" data-position-input>
                            <input type="hidden" name="items[<?= htmlspecialchars($lFk) ?>][is_enabled]" form="form-save-layout" value="1">
                            <input type="hidden" name="items[<?= htmlspecialchars($lFk) ?>][display_label]" form="form-save-layout" value="<?= htmlspecialchars($lDl) ?>">
                            <?php if ($lReq): ?>
                            <input type="hidden" name="items[<?= htmlspecialchars($lFk) ?>][is_required]" form="form-save-layout" value="1">
                            <?php endif; ?>
                            <?php
                            $lSpan = (int)($lItem['layout_span'] ?? 3);
                            if ($lSpan < 1 || $lSpan > 3) {
                                $lSpan = 3;
                            }
                            ?>
                            <input type="hidden" name="items[<?= htmlspecialchars($lFk) ?>][layout_span]" form="form-save-layout" value="<?= $lSpan ?>">
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <ul id="apl-composer-layout-list"
                            class="apl-field-list<?= $useCustomerDetailsLayoutFlow ? ' apl-field-list--layout-flow' : '' ?>"
                            aria-label="Form fields">
                        <?php foreach ($editableLayoutRows as $sfi => $row): ?>
                            <?php
                            $idx = (int)$row['idx'];
                            $item = $row['item'];
                            $fieldKey       = (string)($item['field_key']      ?? '');
                            $isEnabled      = (int)($item['is_enabled']        ?? 1) === 1;
                            $isRequired     = (int)($item['is_required']       ?? 0) === 1;
                            $layoutSpan     = (int)($item['layout_span']        ?? 3);
                            if ($layoutSpan < 1 || $layoutSpan > 3) {
                                $layoutSpan = 3;
                            }
                            $sfm = $editableLayoutFlowMeta[$sfi] ?? ['grid_span' => 6, 'row_start' => true];
                            $displayLabel   = trim((string)($item['display_label'] ?? ''));
                            $effectiveLabel = $displayLabel !== ''
                                ? $displayLabel
                                : ($fieldLabels[$fieldKey] ?? $fieldKey);
                            $fieldType = $customFieldLayoutTypes[$fieldKey]
                                ?? ($systemFieldDefinitions[$fieldKey]['admin_field_type'] ?? 'text');
                            $typeCat = $classifyFieldType($fieldKey, $fieldType, $systemFieldDefinitions);
                            $badgeIcon = $typeIconMap[$typeCat] ?? 'text-t';
                            $sfTrail = $useCustomerDetailsLayoutFlow && !empty($editableFlowTrailRight[$sfi]);
                            $flowAlwaysFullAttr = '';
                            if ($useCustomerDetailsLayoutFlow) {
                                $flowAlwaysFull = false;
                                if (in_array($fieldKey, $customerDetailsLayoutFlowForceFullKeys, true)) {
                                    $flowAlwaysFull = true;
                                } elseif (str_starts_with($fieldKey, 'custom:')) {
                                    $defFlow = $customFieldsByLayoutKey[$fieldKey] ?? null;
                                    if ($defFlow !== null) {
                                        $ftFlow = (string) ($defFlow['field_type'] ?? 'text');
                                        $cfFullFlow = $ftFlow === 'textarea' || $ftFlow === 'address' || $ftFlow === 'multiselect' || $ftFlow === 'boolean'
                                            || ($ftFlow === 'select' && !empty($defFlow['options_json']));
                                        $flowAlwaysFull = $cfFullFlow;
                                    }
                                }
                                if ($flowAlwaysFull) {
                                    $flowAlwaysFullAttr = ' data-flow-always-full="1"';
                                }
                            }
                            ?>
                            <?php if ($useCustomerDetailsLayoutFlow && !empty($sfm['row_start']) && $sfi > 0): ?>
                            <li class="apl-layout-flow-row-rule" aria-hidden="true"></li>
                            <?php endif; ?>
                            <li class="apl-field-card<?= ($useCustomerDetailsLayoutFlow && !empty($sfm['row_start'])) ? ' apl-field-card--layout-row-start' : '' ?><?= $sfTrail ? ' apl-field-card--flow-row-trail' : '' ?>"
                                data-field-key="<?= htmlspecialchars($fieldKey) ?>"
                                data-field-index="<?= $idx ?>"
                                <?php if ($useCustomerDetailsLayoutFlow): ?>data-flow-grid-span="<?= (int) $sfm['grid_span'] ?>" style="grid-column: span <?= (int) $sfm['grid_span'] ?>"<?= $flowAlwaysFullAttr ?><?php endif; ?>>
                                <?php /* Hidden inputs outside the flex header so trailing actions stay flush right */ ?>
                                <input type="hidden"
                                       name="items[<?= htmlspecialchars($fieldKey) ?>][field_key]"
                                       form="form-save-layout"
                                       value="<?= htmlspecialchars($fieldKey) ?>">
                                <input type="hidden"
                                       name="items[<?= htmlspecialchars($fieldKey) ?>][position]"
                                       form="form-save-layout"
                                       value="<?= $idx ?>"
                                       data-position-input>
                                <?php if (($selectedProfileKey ?? '') !== 'customer_details'): ?>
                                <input type="hidden"
                                       name="items[<?= htmlspecialchars($fieldKey) ?>][layout_span]"
                                       form="form-save-layout"
                                       value="3">
                                <?php endif; ?>

                                <div class="apl-field-card__header">
                                    <div class="apl-field-card__drag-handle"
                                         data-apl-sortable-handle
                                         tabindex="-1"
                                         aria-hidden="true">
                                        <?= $icon('drag', 18) ?>
                                    </div>

                                    <div class="apl-field-card__content"<?php if ($canEditClientFields): ?>
                                         data-opens-settings
                                         tabindex="0"
                                         role="button"
                                         aria-expanded="false"
                                         aria-label="<?= htmlspecialchars('Configure ' . $effectiveLabel) ?>"
                                    <?php endif; ?>>
                                        <div class="apl-field-card__title">
                                            <span class="apl-type-badge apl-type-badge--<?= htmlspecialchars($typeCat) ?>"><?= $icon($badgeIcon, 14) ?></span>
                                            <span class="apl-field-card__label apl-field-card__label--<?= htmlspecialchars($typeCat) ?>">
                                                <?= htmlspecialchars($effectiveLabel) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($canEditClientFields): ?>
                                    <div class="apl-field-card__actions">
                                        <button type="button"
                                                class="apl-btn apl-btn--icon apl-btn--sm"
                                                data-action="remove-init"
                                                aria-label="Remove <?= htmlspecialchars($effectiveLabel) ?> from layout"
                                                title="Remove from layout">
                                            <?= $icon('trash', 16) ?>
                                        </button>
                                        <form method="post"
                                              action="/clients/custom-fields/layouts/remove-item"
                                              data-remove-form
                                              style="display:none">
                                            <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey) ?>">
                                            <input type="hidden" name="field_key" value="<?= htmlspecialchars($fieldKey) ?>">
                                        </form>

                                        <button type="button"
                                                class="apl-btn apl-btn--icon apl-btn--sm"
                                                data-action="toggle-settings"
                                                aria-expanded="false"
                                                aria-label="Configure <?= htmlspecialchars($effectiveLabel) ?>">
                                            <?= $icon('chevron', 16) ?>
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($canEditClientFields): ?>
                                <div class="apl-inline-confirm" data-remove-confirm role="alert" aria-live="assertive">
                                    <span class="apl-inline-confirm__text">Remove "<?= htmlspecialchars($effectiveLabel) ?>"?</span>
                                    <span class="apl-inline-confirm__countdown" style="--pct:100"></span>
                                    <button type="button"
                                            class="apl-btn apl-btn--danger apl-btn--sm"
                                            data-action="remove-confirm"
                                            aria-label="Confirm remove">
                                        Remove
                                    </button>
                                    <button type="button"
                                            class="apl-btn apl-btn--ghost apl-btn--sm"
                                            data-action="remove-cancel">
                                        Cancel
                                    </button>
                                </div>

                                <div class="apl-field-card__settings" aria-hidden="true">
                                    <div class="apl-field-card__settings-surface">
                                        <div class="apl-field-card__settings-stack">
                                            <div class="apl-settings-field">
                                                <label class="apl-settings-field__label"
                                                       for="label-<?= htmlspecialchars($fieldKey) ?>">
                                                    Custom label
                                                </label>
                                                <input type="text"
                                                       id="label-<?= htmlspecialchars($fieldKey) ?>"
                                                       name="items[<?= htmlspecialchars($fieldKey) ?>][display_label]"
                                                       form="form-save-layout"
                                                       class="apl-input apl-settings-field__input"
                                                       value="<?= htmlspecialchars($displayLabel) ?>"
                                                       placeholder="<?= htmlspecialchars($fieldLabels[$fieldKey] ?? $fieldKey) ?>">
                                                <p class="apl-settings-field__hint">Blank = use default label</p>
                                            </div>

                                            <div class="apl-settings-group" role="group" aria-label="Field options">
                                                <?php if (($selectedProfileKey ?? '') === 'customer_details'):
                                                    $layoutSpanSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $fieldKey);
                                                    $layoutSpanSlug = trim((string) $layoutSpanSlug, '-') ?: 'field';
                                                    $layoutSpanLblId = 'layout-span-lbl-' . $layoutSpanSlug;
                                                    $layoutSpanHintId = 'layout-span-hint-' . $layoutSpanSlug;
                                                    ?>
                                                <div class="apl-settings-group__cell apl-settings-group__cell--stack">
                                                    <div class="apl-settings-seg-head" id="<?= htmlspecialchars($layoutSpanLblId) ?>">Form row width</div>
                                                    <div class="apl-layout-span-segmented apl-layout-span-segmented--compact"
                                                         role="radiogroup"
                                                         aria-labelledby="<?= htmlspecialchars($layoutSpanLblId) ?>">
                                                        <label class="apl-layout-span-segmented__option"
                                                               title="Half width — 2 fields per row">
                                                            <input type="radio"
                                                                   class="apl-layout-span-segmented__input"
                                                                   name="items[<?= htmlspecialchars($fieldKey) ?>][layout_span]"
                                                                   form="form-save-layout"
                                                                   value="2"
                                                                   <?= $layoutSpan === 2 ? 'checked' : '' ?>
                                                                   aria-describedby="<?= htmlspecialchars($layoutSpanHintId) ?>">
                                                            <span class="apl-layout-span-segmented__text">
                                                                <span class="apl-layout-span-segmented__title">Half</span>
                                                                <span class="apl-layout-span-segmented__sub">2 across</span>
                                                            </span>
                                                        </label>
                                                        <label class="apl-layout-span-segmented__option"
                                                               title="Full width — one field per row">
                                                            <input type="radio"
                                                                   class="apl-layout-span-segmented__input"
                                                                   name="items[<?= htmlspecialchars($fieldKey) ?>][layout_span]"
                                                                   form="form-save-layout"
                                                                   value="3"
                                                                   <?= $layoutSpan === 3 ? 'checked' : '' ?>
                                                                   aria-describedby="<?= htmlspecialchars($layoutSpanHintId) ?>">
                                                            <span class="apl-layout-span-segmented__text">
                                                                <span class="apl-layout-span-segmented__title">Full</span>
                                                                <span class="apl-layout-span-segmented__sub">1 across</span>
                                                            </span>
                                                        </label>
                                                    </div>
                                                    <p class="apl-settings-seg-hint" id="<?= htmlspecialchars($layoutSpanHintId) ?>">Uses the same column rules as the client details form. The list above shows that layout (soft spacing only — no extra borders).</p>
                                                </div>
                                                <?php endif; ?>

                                                <div class="apl-settings-group__cell">
                                                    <label class="apl-switch apl-switch--settings-tight">
                                                        <span class="apl-switch__label">Required</span>
                                                        <input type="checkbox"
                                                               class="apl-switch__input"
                                                               name="items[<?= htmlspecialchars($fieldKey) ?>][is_required]"
                                                               form="form-save-layout"
                                                               value="1"
                                                               <?= $isRequired ? 'checked' : '' ?>>
                                                        <span class="apl-switch__track">
                                                            <span class="apl-switch__thumb"></span>
                                                        </span>
                                                    </label>
                                                </div>

                                                <div class="apl-settings-group__cell">
                                                    <label class="apl-switch apl-switch--settings-tight">
                                                        <span class="apl-switch__label">Visible on form</span>
                                                        <input type="checkbox"
                                                               class="apl-switch__input"
                                                               name="items[<?= htmlspecialchars($fieldKey) ?>][is_enabled]"
                                                               form="form-save-layout"
                                                               value="1"
                                                               <?= $isEnabled ? 'checked' : '' ?>>
                                                        <span class="apl-switch__track">
                                                            <span class="apl-switch__thumb"></span>
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="apl-settings-actions apl-settings-actions--tight">
                                                <button type="submit"
                                                        form="form-save-layout"
                                                        class="apl-btn apl-btn--primary apl-btn--sm">
                                                    Apply
                                                </button>
                                                <button type="button"
                                                        class="apl-btn apl-btn--ghost apl-btn--sm"
                                                        data-action="close-settings">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>

                    </div>
                <?php endif; ?>

                <?php /* ── Field Library (only when there are fields left to add) */ ?>
                <?php if ($canEditClientFields && $layoutStorageReady && $hasLibraryFields): ?>
                <div class="apl-library" id="field-library">
                    <div class="apl-library__content">
                            <label class="apl-library__search">
                                <span class="apl-library__search-icon"><?= $icon('search', 16) ?></span>
                                <input type="text"
                                       id="library-search"
                                       class="apl-library__search-input"
                                       placeholder="Filter fields…"
                                       autocomplete="off"
                                       aria-label="Filter available fields">
                            </label>

                            <?php foreach ($fieldGroups as $groupKey => $group): ?>
                                <?php if (empty($group['fields'])) continue; ?>
                                <div class="apl-library__group" data-group-key="<?= htmlspecialchars($groupKey) ?>">
                                    <h3 class="apl-library__group-title">
                                        <?= $icon($group['icon'], 14) ?>
                                        <?= htmlspecialchars($group['label']) ?>
                                    </h3>
                                    <div class="apl-library__chips">
                                        <?php foreach ($group['fields'] as $field): ?>
                                            <div class="apl-chip-wrapper"
                                                 data-chip-label="<?= htmlspecialchars(strtolower($field['label'])) ?>">

                                                <?php /* Add-to-layout chip */ ?>
                                                <form method="post"
                                                      action="/clients/custom-fields/layouts/add-item"
                                                      style="display:inline">
                                                    <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey) ?>">
                                                    <input type="hidden" name="field_key" value="<?= htmlspecialchars($field['key']) ?>">
                                                    <button type="submit"
                                                            class="apl-library__chip"
                                                            title="Add <?= htmlspecialchars($field['label']) ?> to layout">
                                                        <?= htmlspecialchars($field['label']) ?>
                                                    </button>
                                                </form>

                                                <?php /* Edit + delete actions only for custom fields */ ?>
                                                <?php if (!empty($field['is_custom']) && isset($field['definition_id'])): ?>
                                                <div class="apl-chip-actions">
                                                    <button type="button"
                                                            class="apl-chip-action"
                                                            data-action="edit-field"
                                                            data-definition-id="<?= (int)$field['definition_id'] ?>"
                                                            data-field-label="<?= htmlspecialchars($field['label']) ?>"
                                                            data-field-type="<?= htmlspecialchars($field['field_type'] ?? 'text') ?>"
                                                            data-options-json="<?= htmlspecialchars($field['options_json'] ?? '') ?>"
                                                            data-is-active="<?= (int)($field['is_active'] ?? 1) ?>"
                                                            data-sort-order="<?= (int)($field['sort_order'] ?? 0) ?>"
                                                            aria-label="Edit <?= htmlspecialchars($field['label']) ?>"
                                                            title="Edit field definition">
                                                        <?= $icon('pencil', 13) ?>
                                                    </button>
                                                    <button type="button"
                                                            class="apl-chip-action apl-chip-action--danger"
                                                            data-action="delete-field-init"
                                                            data-definition-id="<?= (int)$field['definition_id'] ?>"
                                                            data-field-label="<?= htmlspecialchars($field['label']) ?>"
                                                            aria-label="Delete <?= htmlspecialchars($field['label']) ?>"
                                                            title="Permanently delete this field">
                                                        <?= $icon('trash', 13) ?>
                                                    </button>
                                                    <?php /* Hidden delete form */ ?>
                                                    <form method="post"
                                                          action="/clients/custom-fields/<?= (int)$field['definition_id'] ?>/delete"
                                                          data-delete-form
                                                          style="display:none">
                                                        <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                                                        <input type="hidden" name="_redirect_to" value="/clients/custom-fields?profile=<?= rawurlencode($selectedProfileKey) ?>">
                                                    </form>
                                                </div>
                                                <?php endif; ?>
                                            </div>

                                            <?php /* Delete confirmation pill (per-chip, hidden initially) */ ?>
                                            <?php if (!empty($field['is_custom']) && isset($field['definition_id'])): ?>
                                            <div class="apl-chip-confirm"
                                                 id="chip-confirm-<?= (int)$field['definition_id'] ?>"
                                                 role="alert" aria-live="assertive">
                                                <span class="apl-chip-confirm__text">
                                                    Delete "<?= htmlspecialchars($field['label']) ?>" permanently?
                                                </span>
                                                <button type="button"
                                                        class="apl-btn apl-btn--danger apl-btn--sm"
                                                        data-action="delete-field-confirm"
                                                        data-definition-id="<?= (int)$field['definition_id'] ?>">
                                                    Delete
                                                </button>
                                                <button type="button"
                                                        class="apl-btn apl-btn--ghost apl-btn--sm"
                                                        data-action="delete-field-cancel"
                                                        data-definition-id="<?= (int)$field['definition_id'] ?>">
                                                    Cancel
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<?php /* ===================================================================
   SLIDE-OVER PANEL — Create / Edit field definition
   =================================================================== */ ?>
<?php if ($canEditClientFields): ?>
<div class="apl-overlay" id="panel-overlay" aria-hidden="true"></div>
<div class="apl-panel"
     id="panel-field"
     role="dialog"
     aria-modal="true"
     aria-labelledby="panel-title">

    <div class="apl-panel__header">
        <h3 class="apl-panel__title" id="panel-title">New Field</h3>
        <button type="button"
                class="apl-btn apl-btn--icon"
                id="btn-panel-close"
                aria-label="Close panel">
            <?= $icon('xmark', 20) ?>
        </button>
    </div>

    <div class="apl-panel__body">
        <form id="form-field-definition"
              method="post"
              action="/clients/custom-fields">
            <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" id="panel-redirect" name="_redirect_to"
                   value="/clients/custom-fields?profile=<?= rawurlencode($selectedProfileKey) ?>">

            <div class="apl-form-group">
                <label class="apl-form-label" for="panel-label">
                    Label <span aria-hidden="true" style="color:var(--apl-red)">*</span>
                </label>
                <input type="text"
                       id="panel-label"
                       name="label"
                       class="apl-input"
                       placeholder="e.g. Loyalty Tier"
                       required
                       autocomplete="off">
            </div>

            <input type="hidden" id="panel-field-key" name="field_key" value="">

            <div class="apl-form-group apl-form-group--type-picker">
                <label class="apl-form-label" id="panel-type-label" for="panel-type-picker-trigger">Type</label>
                <div class="apl-type-picker" id="apl-type-picker">
                    <input type="hidden"
                           id="panel-field-type"
                           name="field_type"
                           value="text">
                    <button type="button"
                            class="apl-type-picker__trigger"
                            id="panel-type-picker-trigger"
                            aria-haspopup="listbox"
                            aria-expanded="false"
                            aria-controls="panel-type-menu">
                        <span class="apl-type-picker__icon apl-type-badge apl-type-badge--text" aria-hidden="true"><?= $icon('text-t', 16) ?></span>
                        <span class="apl-type-picker__value" id="panel-type-picker-value">Single line text</span>
                        <span class="apl-type-picker__caret" aria-hidden="true"><?= $icon('chevron', 16) ?></span>
                    </button>
                    <div class="apl-type-picker__menu"
                         role="listbox"
                         id="panel-type-menu"
                         aria-labelledby="panel-type-label"
                         hidden>
                        <?php foreach ($panelFieldTypeRows as $row):
                            $tv = (string) $row['value'];
                            $sel = $tv === 'text' ? 'true' : 'false';
                            $tone = (string) ($row['tone'] ?? 'text');
                            if (!in_array($tone, $panelFieldTypeTones, true)) {
                                $tone = 'text';
                            }
                        ?>
                        <button type="button"
                                role="option"
                                class="apl-type-picker__option"
                                id="panel-type-opt-<?= htmlspecialchars($tv, ENT_QUOTES, 'UTF-8') ?>"
                                data-value="<?= htmlspecialchars($tv, ENT_QUOTES, 'UTF-8') ?>"
                                aria-selected="<?= $sel ?>">
                            <span class="apl-type-picker__opt-icon apl-type-badge apl-type-badge--<?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"><?= $icon((string) $row['icon'], 16) ?></span>
                            <span class="apl-type-picker__opt-label"><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if ($useCustomerDetailsLayoutFlow): ?>
            <div class="apl-form-group" id="panel-layout-span-group">
                <div class="apl-form-label" id="panel-layout-span-lbl">Form row width</div>
                <div class="apl-layout-span-segmented apl-layout-span-segmented--compact"
                     role="radiogroup"
                     aria-labelledby="panel-layout-span-lbl">
                    <label class="apl-layout-span-segmented__option"
                           title="Half width — 2 fields per row">
                        <input type="radio"
                               class="apl-layout-span-segmented__input"
                               name="composer_initial_layout_span"
                               value="2"
                               aria-describedby="panel-layout-span-hint">
                        <span class="apl-layout-span-segmented__text">
                            <span class="apl-layout-span-segmented__title">Half</span>
                            <span class="apl-layout-span-segmented__sub">2 across</span>
                        </span>
                    </label>
                    <label class="apl-layout-span-segmented__option"
                           title="Full width — one field per row">
                        <input type="radio"
                               class="apl-layout-span-segmented__input"
                               name="composer_initial_layout_span"
                               value="3"
                               checked
                               aria-describedby="panel-layout-span-hint">
                        <span class="apl-layout-span-segmented__text">
                            <span class="apl-layout-span-segmented__title">Full</span>
                            <span class="apl-layout-span-segmented__sub">1 across</span>
                        </span>
                    </label>
                </div>
                <p class="apl-settings-seg-hint" id="panel-layout-span-hint">The new field is placed on the Customer details list with this width. You can change it later in each field&rsquo;s settings.</p>
            </div>
            <?php endif; ?>

            <div class="apl-form-group apl-options-group" id="panel-options-group">
                <span class="apl-form-label" id="panel-choices-label">Choices</span>
                <div class="apl-choices-editor"
                     id="panel-choices-editor"
                     role="group"
                     aria-labelledby="panel-choices-label">
                    <ul class="apl-choices-editor__list" id="panel-choices-list"></ul>
                    <button type="button"
                            class="apl-btn apl-btn--secondary apl-btn--sm"
                            id="panel-choices-add">
                        Add choice
                    </button>
                </div>
                <input type="hidden" name="options_json" id="panel-options-json" value="">
            </div>

            <div class="apl-form-group apl-form-group--switch-pair">
                <label class="apl-switch">
                    <span class="apl-switch__label">Required</span>
                    <input type="checkbox"
                           id="panel-is-required"
                           name="is_required"
                           class="apl-switch__input"
                           value="1">
                    <span class="apl-switch__track"><span class="apl-switch__thumb"></span></span>
                </label>

                <label class="apl-switch">
                    <span class="apl-switch__label">Active</span>
                    <input type="checkbox"
                           id="panel-is-active"
                           name="is_active"
                           class="apl-switch__input"
                           value="1"
                           checked>
                    <span class="apl-switch__track"><span class="apl-switch__thumb"></span></span>
                </label>
            </div>

            <input type="hidden" id="panel-sort-order" name="sort_order" value="0">
        </form>
    </div>

    <div class="apl-panel__footer">
        <button type="button"
                class="apl-btn apl-btn--secondary"
                id="btn-panel-cancel">
            Cancel
        </button>
        <button type="submit"
                form="form-field-definition"
                class="apl-btn apl-btn--primary"
                id="btn-panel-save">
            Create Field
        </button>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($composerSortableEnabled)): ?>
<script src="/assets/js/sortable.min.js"></script>
<?php endif; ?>
<script>
(function () {
    'use strict';

    var sortableFeatureOn = <?= !empty($composerSortableEnabled) ? 'true' : 'false' ?>;

<?php if (!empty($useCustomerDetailsLayoutFlow)): ?>
    window.__aplComposerFlowForceFullKeys = <?= json_encode(array_values($customerDetailsLayoutFlowForceFullKeys), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
<?php else: ?>
    window.__aplComposerFlowForceFullKeys = [];
<?php endif; ?>

    // =========================================================================
    // 1. DIRTY TRACKING — Save button state + navigation guard
    // =========================================================================
    var isDirty  = false;
    var saveBtn  = document.getElementById('btn-save-layout');
    var saveForm = document.getElementById('form-save-layout');
    var composerRoot = document.getElementById('apl-composer-root');

    function isSaveLayoutControl(el) {
        if (!el || typeof el.getAttribute !== 'function') return false;
        if (el.getAttribute('form') === 'form-save-layout') return true;
        return !!(saveForm && saveForm.contains(el));
    }

    function markDirty() {
        if (isDirty) return;
        isDirty = true;
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.setAttribute('data-dirty', 'true');
        }
    }

    function markClean() {
        isDirty = false;
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.setAttribute('data-dirty', 'false');
        }
    }

    if (composerRoot) {
        composerRoot.addEventListener('change', function (e) {
            if (isSaveLayoutControl(e.target)) markDirty();
        });
        composerRoot.addEventListener('input', function (e) {
            if (isSaveLayoutControl(e.target)) markDirty();
        });
    }
    if (saveForm) {
        saveForm.addEventListener('submit', markClean);
    }

    document.querySelectorAll('[data-nav-link]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (!isDirty) return;
            var ok = window.confirm('You have unsaved layout changes. Leave without saving?');
            if (!ok) e.preventDefault();
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (isDirty) { e.preventDefault(); e.returnValue = ''; }
    });

    // =========================================================================
    // 2. FIELD CARD — Settings accordion (single registration)
    // =========================================================================
    function scrollExpandedFieldCardIntoView(card) {
        if (!card || !card.classList.contains('apl-field-card--expanded')) {
            return;
        }
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function run() {
            card.scrollIntoView({
                behavior: reduceMotion ? 'auto' : 'smooth',
                block: 'center',
                inline: 'nearest',
            });
        }
        /* Two frames: let .apl-field-card__settings become visible so layout height is final */
        requestAnimationFrame(function () {
            requestAnimationFrame(run);
        });
    }

    function syncSettingsRowAria(card, expanded) {
        var rowOpen = card.querySelector('.apl-field-card__content[data-opens-settings]');
        if (rowOpen) {
            rowOpen.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
    }

    function toggleFieldCardSettings(card) {
        var btn = card.querySelector('[data-action="toggle-settings"]');
        if (!btn) return;
        var isExpanded = card.classList.contains('apl-field-card--expanded');
        var opening    = !isExpanded;

        document.querySelectorAll('.apl-field-card--expanded').forEach(function (other) {
            if (other !== card) {
                other.classList.remove('apl-field-card--expanded');
                var otherBtn = other.querySelector('[data-action="toggle-settings"]');
                if (otherBtn) {
                    otherBtn.setAttribute('aria-expanded', 'false');
                    var otherIco = otherBtn.querySelector('svg');
                    if (otherIco) otherIco.style.transform = 'rotate(0deg)';
                }
                var otherSettings = other.querySelector('.apl-field-card__settings');
                if (otherSettings) otherSettings.setAttribute('aria-hidden', 'true');
                syncSettingsRowAria(other, false);
            }
        });

        card.classList.toggle('apl-field-card--expanded', !isExpanded);
        btn.setAttribute('aria-expanded', String(!isExpanded));
        syncSettingsRowAria(card, !isExpanded);

        var ico = btn.querySelector('svg');
        if (ico) {
            ico.style.transition = 'transform 0.2s cubic-bezier(0.4,0,0.2,1)';
            ico.style.transform  = isExpanded ? 'rotate(0deg)' : 'rotate(90deg)';
        }
        var settings = card.querySelector('.apl-field-card__settings');
        if (settings) settings.setAttribute('aria-hidden', String(isExpanded));

        if (opening) {
            scrollExpandedFieldCardIntoView(card);
        }
    }

    document.querySelectorAll('[data-action="toggle-settings"]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var card = btn.closest('.apl-field-card');
            if (!card) return;
            toggleFieldCardSettings(card);
        });
    });

    var fieldGroupsRoot = document.getElementById('apl-field-groups');
    if (fieldGroupsRoot) {
        fieldGroupsRoot.addEventListener('click', function (e) {
            var zone = e.target.closest('.apl-field-card__content[data-opens-settings]');
            if (!zone) return;
            if (e.target.closest('a, button, input, textarea, select')) return;
            var card = zone.closest('.apl-field-card');
            if (!card) return;
            toggleFieldCardSettings(card);
        });
        fieldGroupsRoot.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var zone = e.target.closest('.apl-field-card__content[data-opens-settings]');
            if (!zone || e.target !== zone) return;
            e.preventDefault();
            var card = zone.closest('.apl-field-card');
            if (card) toggleFieldCardSettings(card);
        });
    }

    // =========================================================================
    // 2b. CLOSE SETTINGS — Cancel button inside expanded panel
    // =========================================================================
    function collapseCard(card) {
        card.classList.remove('apl-field-card--expanded');
        var toggle = card.querySelector('[data-action="toggle-settings"]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
            var ico = toggle.querySelector('svg');
            if (ico) { ico.style.transform = 'rotate(0deg)'; }
        }
        var settings = card.querySelector('.apl-field-card__settings');
        if (settings) settings.setAttribute('aria-hidden', 'true');
        syncSettingsRowAria(card, false);
    }

    document.querySelectorAll('[data-action="close-settings"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var card = btn.closest('.apl-field-card');
            if (card) collapseCard(card);
        });
    });

    // =========================================================================
    // 3. INLINE REMOVE CONFIRMATION (replaces confirm())
    // =========================================================================
    function bindRemoveConfirmation(card) {
        var initBtn    = card.querySelector('[data-action="remove-init"]');
        var confirmRow = card.querySelector('[data-remove-confirm]');
        var confirmBtn = card.querySelector('[data-action="remove-confirm"]');
        var cancelBtn  = card.querySelector('[data-action="remove-cancel"]');
        var removeForm = card.querySelector('[data-remove-form]');
        if (!initBtn || !confirmRow || !confirmBtn || !cancelBtn || !removeForm) return;

        var countdownEl = confirmRow.querySelector('.apl-inline-confirm__countdown');
        var countdownTimer = null;
        var countdownStart = null;
        var DURATION = 5000;

        function showConfirm() {
            confirmRow.classList.add('is-visible');
            initBtn.style.display = 'none';
            startCountdown();
        }

        function hideConfirm() {
            confirmRow.classList.remove('is-visible');
            initBtn.style.display = '';
            stopCountdown();
        }

        function startCountdown() {
            countdownStart = Date.now();
            tick();
        }

        function stopCountdown() {
            if (countdownTimer) { cancelAnimationFrame(countdownTimer); countdownTimer = null; }
            if (countdownEl) countdownEl.style.setProperty('--pct', '100');
        }

        function tick() {
            var elapsed = Date.now() - countdownStart;
            var remaining = Math.max(0, DURATION - elapsed);
            var pct = (remaining / DURATION) * 100;
            if (countdownEl) countdownEl.style.setProperty('--pct', pct.toFixed(1));
            if (remaining > 0) {
                countdownTimer = requestAnimationFrame(tick);
            } else {
                hideConfirm();
            }
        }

        initBtn.addEventListener('click', showConfirm);
        cancelBtn.addEventListener('click', hideConfirm);
        confirmBtn.addEventListener('click', function () {
            stopCountdown();
            removeForm.submit();
        });
    }

    document.querySelectorAll('.apl-field-card').forEach(bindRemoveConfirmation);

    // =========================================================================
    // 4. FIXED FIELDS — toggle expand/collapse
    // =========================================================================
    document.querySelectorAll('[data-action="toggle-fixed"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var detail = document.querySelector('[data-fixed-detail]');
            if (!detail) return;
            var isOpen = detail.classList.contains('is-open');
            detail.classList.toggle('is-open', !isOpen);
            btn.setAttribute('aria-expanded', String(!isOpen));
            btn.setAttribute('aria-label', isOpen ? 'Show fixed fields' : 'Hide fixed fields');
        });
    });

    // =========================================================================
    // 5. LAYOUT FLOW — row hairlines / grid span when Half·Full changes
    //    After Sortable onEnd (customer_details): window.__aplComposerSyncLayoutFlowList(list).
    //    RAF id shared with Sortable onEnd (must live outside if (groupsRoot)).
    // =========================================================================
    var aplFlowListSyncRaf = null;
    var groupsRoot = document.getElementById('apl-field-groups');
    if (groupsRoot) {
        var flowUnits = 6;
        var flowForce = window.__aplComposerFlowForceFullKeys || [];

        function effFlowUnits(card, fk, raw) {
            if (card && card.getAttribute('data-flow-always-full') === '1') return flowUnits;
            if (flowForce.indexOf(fk || '') >= 0) return flowUnits;
            var s = parseInt(raw, 10);
            if (s === 1) return 2;
            if (s === 2) return 3;
            return flowUnits;
        }

        function syncFlowList(list) {
            if (!list || !list.classList.contains('apl-field-list--layout-flow')) return;
            list.querySelectorAll('.apl-layout-flow-row-rule').forEach(function (n) { n.remove(); });
            var cards = Array.prototype.slice.call(list.querySelectorAll('.apl-field-card')).filter(function (c) {
                return !c.classList.contains('apl-sortable-ghost');
            });
            cards.forEach(function (c) {
                c.classList.remove('apl-field-card--flow-row-trail', 'apl-field-card--layout-row-start');
            });
            var run = 0;
            cards.forEach(function (card, index) {
                var span = parseInt(card.getAttribute('data-flow-grid-span'), 10);
                if (!span || span < 1 || span > flowUnits) span = flowUnits;
                if (run + span > flowUnits) run = 0;
                var rowStart = run === 0;
                if (rowStart && index > 0) {
                    var rule = document.createElement('li');
                    rule.className = 'apl-layout-flow-row-rule';
                    rule.setAttribute('aria-hidden', 'true');
                    list.insertBefore(rule, card);
                }
                if (rowStart) card.classList.add('apl-field-card--layout-row-start');
                run += span;
                if (run >= flowUnits) run = 0;
            });
            var ci;
            for (ci = 0; ci < cards.length; ci++) {
                if (ci + 1 < cards.length && !cards[ci + 1].classList.contains('apl-field-card--layout-row-start')) continue;
                var rowSum = 0;
                var j;
                for (j = ci; j >= 0; j--) {
                    rowSum += parseInt(cards[j].getAttribute('data-flow-grid-span'), 10) || flowUnits;
                    if (cards[j].classList.contains('apl-field-card--layout-row-start')) break;
                }
                if (rowSum < flowUnits) cards[ci].classList.add('apl-field-card--flow-row-trail');
            }
        }

        window.__aplComposerSyncLayoutFlowList = syncFlowList;

        window.__aplComposerScheduleFlowListSync = function (list) {
            if (!list || !list.classList.contains('apl-field-list--layout-flow')) return;
            if (aplFlowListSyncRaf != null) {
                cancelAnimationFrame(aplFlowListSyncRaf);
            }
            aplFlowListSyncRaf = requestAnimationFrame(function () {
                aplFlowListSyncRaf = null;
                syncFlowList(list);
            });
        };

        groupsRoot.addEventListener('change', function (e) {
            var t = e.target;
            if (!t) return;
            var nm = t.getAttribute('name') || '';
            if (nm.indexOf('[layout_span]') === -1) return;
            if (t.tagName === 'INPUT' && t.type === 'radio' && !t.checked) return;
            if (t.tagName !== 'SELECT' && !(t.tagName === 'INPUT' && t.type === 'radio')) return;
            var card = t.closest('.apl-field-card');
            if (!card) return;
            var list = card.closest('.apl-field-list--layout-flow');
            if (!list) return;
            var fk = card.getAttribute('data-field-key') || '';
            var u = effFlowUnits(card, fk, t.value);
            card.setAttribute('data-flow-grid-span', String(u));
            card.style.gridColumn = 'span ' + u;
            syncFlowList(list);
        });
    }

    // =========================================================================
    // 5b. SORTABLE — reorder layout fields (Save Layout persists positions)
    // =========================================================================
    function reindexLayoutPositions() {
        var root = document.getElementById('apl-field-groups');
        if (!root) return;
        var inputs = root.querySelectorAll('input[data-position-input]');
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].value = String(i);
        }
    }

    function aplComposerActivateSortableChrome() {
        var cr = document.getElementById('apl-composer-root');
        if (cr) {
            cr.classList.add('apl-composer--sortable-ready');
        }
        document.querySelectorAll('#apl-composer-layout-list [data-apl-sortable-handle]').forEach(function (h) {
            h.setAttribute('aria-hidden', 'false');
            h.setAttribute('aria-label', 'Drag to reorder');
            h.setAttribute('role', 'button');
            h.setAttribute('tabindex', '0');
        });
    }

    var layoutListForSort = document.getElementById('apl-composer-layout-list');
    var aplFlowPostSortTimer = null;
    if (
        sortableFeatureOn
        && layoutListForSort
        && typeof Sortable !== 'undefined'
        && layoutListForSort.querySelectorAll('.apl-field-card').length > 0
    ) {
        aplComposerActivateSortableChrome();
        var aplSortableVibrate = function () {
            if (typeof navigator.vibrate === 'function') {
                navigator.vibrate(15);
            }
        };
        var aplSortableAnimMs = 250;
        var aplSortableOptions = {
            draggable: '.apl-field-card',
            handle: '[data-apl-sortable-handle]',
            animation: aplSortableAnimMs,
            easing: 'cubic-bezier(1, 0, 0, 1)',
            delay: 150,
            delayOnTouchOnly: true,
            scroll: true,
            scrollSensitivity: 40,
            scrollSpeed: 15,
            forceFallback: true,
            fallbackOnBody: true,
            fallbackClass: 'apl-sortable-fallback',
            ghostClass: 'apl-sortable-ghost',
            chosenClass: 'apl-sortable-chosen',
            dragClass: 'apl-sortable-drag',
            onStart: function (evt) {
                var card = evt.item;
                if (card && card.classList.contains('apl-field-card--expanded')) {
                    collapseCard(card);
                }
                aplSortableVibrate();
                if (layoutListForSort.classList.contains('apl-field-list--layout-flow')) {
                    layoutListForSort.classList.add('is-apl-flow-drag');
                    if (aplFlowPostSortTimer) {
                        clearTimeout(aplFlowPostSortTimer);
                        aplFlowPostSortTimer = null;
                    }
                }
            },
            onChange: function (evt) {
                var toList = evt.to;
                if (toList && typeof window.__aplComposerScheduleFlowListSync === 'function') {
                    window.__aplComposerScheduleFlowListSync(toList);
                }
            },
            onEnd: function (evt) {
                aplSortableVibrate();
                if (aplFlowListSyncRaf != null && typeof cancelAnimationFrame === 'function') {
                    cancelAnimationFrame(aplFlowListSyncRaf);
                    aplFlowListSyncRaf = null;
                }
                if (layoutListForSort.classList.contains('apl-field-list--layout-flow')) {
                    layoutListForSort.classList.remove('is-apl-flow-drag');
                }
                if (evt.oldIndex !== evt.newIndex) {
                    reindexLayoutPositions();
                    markDirty();
                }
                if (typeof window.__aplComposerSyncLayoutFlowList === 'function') {
                    window.__aplComposerSyncLayoutFlowList(layoutListForSort);
                }
                if (aplFlowPostSortTimer) {
                    clearTimeout(aplFlowPostSortTimer);
                }
                aplFlowPostSortTimer = setTimeout(function () {
                    aplFlowPostSortTimer = null;
                    if (typeof window.__aplComposerSyncLayoutFlowList === 'function') {
                        window.__aplComposerSyncLayoutFlowList(layoutListForSort);
                    }
                }, aplSortableAnimMs + 40);
            },
        };
        /* Vertical axis lock matches Apple list feel; grid flow needs horizontal freedom for half-width rows. */
        if (!layoutListForSort.classList.contains('apl-field-list--layout-flow')) {
            aplSortableOptions.axis = 'y';
        }
        Sortable.create(layoutListForSort, aplSortableOptions);
    }

    // =========================================================================
    // 6. FIELD LIBRARY — search filter
    // =========================================================================
    var searchInput = document.getElementById('library-search');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var q = searchInput.value.trim().toLowerCase();
            document.querySelectorAll('.apl-chip-wrapper').forEach(function (chip) {
                var label = (chip.getAttribute('data-chip-label') || '').toLowerCase();
                chip.setAttribute('data-hidden', q !== '' && !label.includes(q) ? 'true' : 'false');
            });
            document.querySelectorAll('.apl-library__group').forEach(function (group) {
                var visible = group.querySelectorAll('.apl-chip-wrapper:not([data-hidden="true"])').length > 0;
                group.hidden = !visible;
            });
        });
    }

    // =========================================================================
    // 7. LIBRARY — inline delete confirmation for custom field definitions
    // =========================================================================
    document.querySelectorAll('[data-action="delete-field-init"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id      = btn.getAttribute('data-definition-id');
            var confirm = document.getElementById('chip-confirm-' + id);
            if (confirm) confirm.classList.add('is-visible');
        });
    });

    document.querySelectorAll('[data-action="delete-field-cancel"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id      = btn.getAttribute('data-definition-id');
            var confirm = document.getElementById('chip-confirm-' + id);
            if (confirm) confirm.classList.remove('is-visible');
        });
    });

    document.querySelectorAll('[data-action="delete-field-confirm"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id   = btn.getAttribute('data-definition-id');
            var chip = btn.closest('.apl-library__chips, .apl-library__group');

            // Find the delete form inside the chip-wrapper for this definition
            var wrapper = document.querySelector(
                '.apl-chip-wrapper [data-action="delete-field-init"][data-definition-id="' + id + '"]'
            );
            if (!wrapper) return;
            var form = wrapper.closest('.apl-chip-wrapper')
                               .querySelector('[data-delete-form]');
            if (form) form.submit();
        });
    });

    // =========================================================================
    // 8. SLIDE-OVER PANEL — New / Edit field definition
    // =========================================================================
    var overlay   = document.getElementById('panel-overlay');
    var panel     = document.getElementById('panel-field');
    var panelForm = document.getElementById('form-field-definition');
    if (!panel || !overlay || !panelForm) return;

    var panelTitle     = document.getElementById('panel-title');
    var btnSave        = document.getElementById('btn-panel-save');
    var panelLabel     = document.getElementById('panel-label');
    var panelKey       = document.getElementById('panel-field-key');
    var panelType      = document.getElementById('panel-field-type');
    var panelOptionsJson = document.getElementById('panel-options-json');
    var panelChoicesList = document.getElementById('panel-choices-list');
    var panelChoicesAdd  = document.getElementById('panel-choices-add');
    var panelOptionsGrp= document.getElementById('panel-options-group');
    var panelRequired  = document.getElementById('panel-is-required');
    var panelActive    = document.getElementById('panel-is-active');
    var panelSortOrder = document.getElementById('panel-sort-order');
    var panelRedirect  = document.getElementById('panel-redirect');
    var panelLayoutSpanGroup = document.getElementById('panel-layout-span-group');

    var typePicker     = document.getElementById('apl-type-picker');
    var typeTrigger    = document.getElementById('panel-type-picker-trigger');
    var typeMenu       = document.getElementById('panel-type-menu');
    var typeIconSlot   = typeTrigger ? typeTrigger.querySelector('.apl-type-picker__icon') : null;
    var typeLabelSlot  = document.getElementById('panel-type-picker-value');

    function setTypePickerOpen(open) {
        if (!typePicker || !typeTrigger || !typeMenu) return;
        typePicker.classList.toggle('is-open', open);
        typeTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        var typeGroup = typePicker.closest('.apl-form-group--type-picker');
        if (typeGroup) {
            typeGroup.classList.toggle('is-menu-open', open);
        }
        if (open) {
            typeMenu.removeAttribute('hidden');
        } else {
            typeMenu.setAttribute('hidden', 'hidden');
        }
    }

    function syncTypePickerVisual(val) {
        if (!panelType || !typeMenu || !typeIconSlot || !typeLabelSlot) return;
        var opt = typeMenu.querySelector('[data-value="' + val + '"]');
        if (!opt) {
            opt = typeMenu.querySelector('[data-value="text"]');
            val = 'text';
        }
        panelType.value = val;
        var iconWrap = opt.querySelector('.apl-type-picker__opt-icon');
        var lbl      = opt.querySelector('.apl-type-picker__opt-label');
        if (iconWrap) {
            typeIconSlot.innerHTML = iconWrap.innerHTML;
            typeIconSlot.className = iconWrap.className
                .replace(/\bapl-type-picker__opt-icon\b/g, 'apl-type-picker__icon')
                .replace(/\s+/g, ' ')
                .trim();
        }
        if (lbl) typeLabelSlot.textContent = lbl.textContent;
        typeMenu.querySelectorAll('[role="option"]').forEach(function (o) {
            o.setAttribute('aria-selected', o.getAttribute('data-value') === val ? 'true' : 'false');
        });
    }

    function setFieldTypeValue(val) {
        if (!panelType) return;
        syncTypePickerVisual(val);
        panelType.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function parseOptionsToStrings(raw) {
        if (raw == null || typeof raw !== 'string') return [];
        var t = raw.trim();
        if (t === '') return [];
        try {
            var d = JSON.parse(t);
            if (!Array.isArray(d)) return [];
            return d.map(function (x) {
                return typeof x === 'string' ? x : String(x);
            });
        } catch (e) {
            return [];
        }
    }

    function choicesRowsToArray() {
        if (!panelChoicesList) return [];
        var out = [];
        panelChoicesList.querySelectorAll('.apl-choices-editor__input').forEach(function (inp) {
            var v = inp.value.trim();
            if (v !== '') out.push(v);
        });
        return out;
    }

    function syncChoicesToHidden() {
        if (!panelOptionsJson || !panelType) return;
        if (panelType.value !== 'select' && panelType.value !== 'multiselect') {
            panelOptionsJson.value = '';
            return;
        }
        panelOptionsJson.value = JSON.stringify(choicesRowsToArray());
    }

    function renumberChoiceAriaLabels() {
        if (!panelChoicesList) return;
        var rows = panelChoicesList.querySelectorAll('.apl-choices-editor__row');
        rows.forEach(function (row, i) {
            var inp = row.querySelector('.apl-choices-editor__input');
            if (inp) inp.setAttribute('aria-label', 'Choice ' + (i + 1));
        });
    }

    function clearChoicesList() {
        if (panelChoicesList) panelChoicesList.innerHTML = '';
    }

    function addChoiceRow(value) {
        if (!panelChoicesList) return;
        value = value == null ? '' : String(value);
        var li = document.createElement('li');
        li.className = 'apl-choices-editor__row';
        li.innerHTML =
            '<input type="text" class="apl-input apl-choices-editor__input" maxlength="120" placeholder="Option label" value="">' +
            '<button type="button" class="apl-choices-editor__rm" aria-label="Remove choice">&times;</button>';
        li.querySelector('.apl-choices-editor__input').value = value;
        panelChoicesList.appendChild(li);
        renumberChoiceAriaLabels();
    }

    function loadChoicesFromJson(raw) {
        clearChoicesList();
        var arr = parseOptionsToStrings(raw);
        if (arr.length === 0) {
            addChoiceRow('');
        } else {
            arr.forEach(function (s) { addChoiceRow(s); });
        }
        syncChoicesToHidden();
    }

    function ensureAtLeastOneChoiceRow() {
        if (!panelChoicesList || !panelType) return;
        var show = panelType.value === 'select' || panelType.value === 'multiselect';
        if (!show) return;
        if (panelChoicesList.querySelectorAll('.apl-choices-editor__row').length === 0) {
            addChoiceRow('');
        }
    }

    function slugFromLabel(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 100);
    }

    function isFieldPanelCreateMode() {
        if (!panelForm) return true;
        var a = panelForm.getAttribute('action') || '';
        return !/\/clients\/custom-fields\/\d+(\?|$)/.test(a);
    }

    function setPanelLayoutSpanRadiosForMode(mode) {
        if (!panelForm) return;
        var radios = panelForm.querySelectorAll('input[name="composer_initial_layout_span"]');
        radios.forEach(function (r) {
            r.disabled = mode === 'edit';
            if (mode === 'create') {
                r.checked = String(r.value) === '3';
            }
        });
        if (panelLayoutSpanGroup) {
            panelLayoutSpanGroup.style.display = mode === 'edit' ? 'none' : '';
        }
    }

    function openPanel(mode, data) {
        data = data || {};

        if (mode === 'edit') {
            panelTitle.textContent          = 'Edit Field';
            btnSave.textContent             = 'Save Changes';
            panelForm.action                = '/clients/custom-fields/' + data.definitionId;
            if (panelKey) panelKey.value    = '';
            panelLabel.value                = data.label     || '';
            setFieldTypeValue(data.fieldType || 'text');
            loadChoicesFromJson(data.optionsJson || '');
            if (panelRequired) panelRequired.checked = false;
            if (panelActive)   panelActive.checked   = (data.isActive !== '0');
            if (panelSortOrder) panelSortOrder.value = data.sortOrder || 0;
        } else {
            panelTitle.textContent          = 'New Field';
            btnSave.textContent             = 'Create Field';
            panelForm.action                = '/clients/custom-fields';
            if (panelKey) panelKey.value    = '';
            panelLabel.value                = '';
            setFieldTypeValue('text');
            loadChoicesFromJson('');
            if (panelRequired) panelRequired.checked = false;
            if (panelActive)   panelActive.checked   = true;
            if (panelSortOrder) panelSortOrder.value = 0;
        }

        setPanelLayoutSpanRadiosForMode(mode === 'edit' ? 'edit' : 'create');

        setTypePickerOpen(false);

        panel.classList.add('is-open');
        overlay.classList.add('is-open');
        overlay.removeAttribute('aria-hidden');
        panel.removeAttribute('aria-hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { panelLabel.focus(); }, 50);
    }

    function closePanel() {
        setTypePickerOpen(false);
        panel.classList.remove('is-open');
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        panel.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function toggleOptionsGroup() {
        if (!panelType || !panelOptionsGrp) return;
        var show = panelType.value === 'select' || panelType.value === 'multiselect';
        panelOptionsGrp.classList.toggle('is-visible', show);
        ensureAtLeastOneChoiceRow();
        syncChoicesToHidden();
    }

    panelForm.addEventListener('submit', function () {
        if (isFieldPanelCreateMode() && panelKey && panelLabel) {
            panelKey.value = slugFromLabel(panelLabel.value);
        }
        syncChoicesToHidden();
    });

    if (panelChoicesAdd) {
        panelChoicesAdd.addEventListener('click', function () {
            addChoiceRow('');
        });
    }

    if (panelChoicesList) {
        panelChoicesList.addEventListener('click', function (e) {
            var rm = e.target.closest('.apl-choices-editor__rm');
            if (!rm || !panelChoicesList.contains(rm)) return;
            var row = rm.closest('.apl-choices-editor__row');
            if (row) row.remove();
            renumberChoiceAriaLabels();
            ensureAtLeastOneChoiceRow();
            syncChoicesToHidden();
        });
        panelChoicesList.addEventListener('input', function () {
            syncChoicesToHidden();
        });
    }

    if (panelLabel && panelKey) {
        panelLabel.addEventListener('input', function () {
            if (!isFieldPanelCreateMode()) return;
            panelKey.value = slugFromLabel(panelLabel.value);
        });
    }

    if (panelType) panelType.addEventListener('change', toggleOptionsGroup);

    if (typePicker && typeTrigger && typeMenu) {
        typeTrigger.addEventListener('click', function (e) {
            e.stopPropagation();
            setTypePickerOpen(!typePicker.classList.contains('is-open'));
        });
        typeMenu.querySelectorAll('.apl-type-picker__option').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var v = btn.getAttribute('data-value');
                if (v) setFieldTypeValue(v);
                setTypePickerOpen(false);
                typeTrigger.focus();
            });
        });
        document.addEventListener('click', function () {
            if (typePicker.classList.contains('is-open')) setTypePickerOpen(false);
        });
        typePicker.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    }

    if (panelType && typeMenu) {
        syncTypePickerVisual(panelType.value || 'text');
        toggleOptionsGroup();
    }

    document.getElementById('btn-new-field').addEventListener('click', function () {
        openPanel('create');
    });

    document.getElementById('btn-panel-close').addEventListener('click', closePanel);
    document.getElementById('btn-panel-cancel').addEventListener('click', closePanel);
    overlay.addEventListener('click', closePanel);

    document.querySelectorAll('[data-action="edit-field"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openPanel('edit', {
                definitionId: btn.getAttribute('data-definition-id'),
                label:        btn.getAttribute('data-field-label'),
                fieldType:    btn.getAttribute('data-field-type'),
                optionsJson:  btn.getAttribute('data-options-json'),
                isActive:     btn.getAttribute('data-is-active'),
                sortOrder:    btn.getAttribute('data-sort-order'),
            });
        });
    });

    // Escape: close type menu first, then panel
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !panel.classList.contains('is-open')) return;
        if (typePicker && typePicker.classList.contains('is-open')) {
            setTypePickerOpen(false);
            e.preventDefault();
            return;
        }
        closePanel();
    });

})();
</script>

<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
