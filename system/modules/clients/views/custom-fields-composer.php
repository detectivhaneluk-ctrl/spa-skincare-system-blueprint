<?php

declare(strict_types=1);

/** @var bool $canEditClientFields */
/** @var list<array<string, mixed>> $definitions */
/** @var array<string, array<string, mixed>> $systemCatalog */
/** @var array<string, string> $fieldLabels */
/** @var array<string, array<string, mixed>> $systemFieldDefinitions */
/** @var array<string, string> $customFieldLayoutTypes layout key => field_type for custom fields */
/** @var list<array<string, mixed>> $profiles */
/** @var string $selectedProfileKey */
/** @var list<array<string, mixed>> $layoutItems */
/** @var list<string> $availableToAdd */
/** @var bool $layoutStorageReady */
/** @var callable $humanizeFieldType */
/** @var list<string> $intakeImmutableKeys fixed-order keys for customer_details (name, email, phones, notes) */

$intakeImmutableKeys = $intakeImmutableKeys ?? [];

$title = 'Client form composer';
$mainClass = 'clients-workspace-page cf-composer-page';
$clientFieldsHideSubtabs = true;
$csrfTn = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$profileUrl = static function (string $pk): string {
    return '/clients/custom-fields?profile=' . rawurlencode($pk);
};

$layoutItemsSorted = array_values($layoutItems ?? []);
usort($layoutItemsSorted, static fn (array $a, array $b): int => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));

$removableKeys = array_values(array_filter(
    array_map(static fn (array $r) => (string) $r['field_key'], $layoutItems ?? []),
    static fn (string $fk) => $intakeImmutableKeys === [] || !in_array($fk, $intakeImmutableKeys, true)
));

$profileDisplayLabel = (string) $selectedProfileKey;
foreach (($profiles ?? []) as $pp) {
    if ((string) ($pp['profile_key'] ?? '') === (string) $selectedProfileKey) {
        $profileDisplayLabel = (string) ($pp['display_label'] ?? $selectedProfileKey);
        break;
    }
}

/** @return 'text'|'phone'|'address'|'date'|null */
$classifyKeyForAddMenu = static function (string $ak) use ($systemFieldDefinitions, $customFieldLayoutTypes): ?string {
    $meta = $systemFieldDefinitions[$ak] ?? null;
    if ($meta !== null) {
        $kind = (string) ($meta['kind'] ?? '');
        if ($kind === 'block') {
            $block = (string) ($meta['block'] ?? '');
            if ($block === 'phone_contact') {
                return 'phone';
            }
            if (in_array($block, ['home_address', 'delivery'], true)) {
                return 'address';
            }

            return 'text';
        }
        $adm = (string) ($meta['admin_field_type'] ?? '');
        if ($adm === 'date') {
            return 'date';
        }
        if ($adm === 'phone' || str_contains($adm, 'phone')) {
            return 'phone';
        }
        if (str_contains($adm, 'address')) {
            return 'address';
        }

        return 'text';
    }
    $cft = $customFieldLayoutTypes[$ak] ?? 'text';

    return match ($cft) {
        'phone' => 'phone',
        'address' => 'address',
        'date' => 'date',
        default => 'text',
    };
};

$addMenuBuckets = ['text' => [], 'phone' => [], 'address' => [], 'date' => []];
foreach (($availableToAdd ?? []) as $ak) {
    $ak = (string) $ak;
    $cat = $classifyKeyForAddMenu($ak);
    if ($cat !== null) {
        $addMenuBuckets[$cat][] = $ak;
    }
}

$addMenuLabels = [
    'text' => 'Text',
    'phone' => 'Phone',
    'address' => 'Address',
    'date' => 'Date',
];

/** Bucket heading icons — Lucide names: align-left, phone, map-pin, calendar. */
$addMenuIconKeys = [
    'text' => 'alignLeft',
    'phone' => 'phone',
    'address' => 'mapPin',
    'date' => 'calendar',
];

/**
 * Lucide icons — https://lucide.dev/icons/ (ISC License).
 * Paths match Lucide icon specifications; default stroke 2, round caps/joins.
 */
$iconSvg = static function (string $name, string $class = ''): string {
    $c = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
    $wrap = static function (string $inner, string $w = '24', string $h = '24') use ($c): string {
        return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' . $c . '>' . $inner . '</svg>';
    };

    $lucideFileText = '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>';

    return match ($name) {
        'plus' => $wrap('<path d="M5 12h14"/><path d="M12 5v14"/>', '20', '20'),
        'lock' => $wrap('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', '16', '16'),
        'lines' => $wrap('<circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/>', '20', '20'),
        'alignLeft' => $wrap('<path d="M15 12H3"/><path d="M17 18H3"/><path d="M21 6H3"/>', '18', '18'),
        'type' => $wrap('<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>', '18', '18'),
        'mail' => $wrap('<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>', '18', '18'),
        'user' => $wrap('<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>', '18', '18'),
        'fileText' => $wrap($lucideFileText, '18', '18'),
        'envelope' => $wrap('<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>', '20', '16'),
        'house' => $wrap('<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>', '20', '18'),
        'note' => $wrap($lucideFileText, '18', '20'),
        'phone' => $wrap('<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>', '18', '18'),
        'mapPin' => $wrap('<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>', '18', '18'),
        'calendar' => $wrap('<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>', '18', '18'),
        'plusCircleFill' => $wrap('<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/>', '22', '22'),
        'infoCircle' => $wrap('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>', '20', '20'),
        'trash' => $wrap('<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>', '18', '18'),
        'checkmark' => $wrap('<path d="M20 6 9 17l-5-5"/>', '18', '18'),
        'arrowClockwise' => $wrap('<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>', '18', '18'),
        'chevronDown' => $wrap('<path d="m6 9 6 6 6-6"/>', '16', '16'),
        'chevronRight' => $wrap('<path d="m9 18 6-6-6-6"/>', '16', '16'),
        'xMark' => $wrap('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>', '16', '16'),
        'docText' => $wrap($lucideFileText, '18', '18'),
        'checkSquare' => $wrap('<rect width="18" height="18" x="3" y="3" rx="2"/><path d="m9 12 2 2 4-4"/>', '18', '18'),
        'toggleRight' => $wrap('<rect width="20" height="12" x="2" y="6" rx="6"/><circle cx="16" cy="12" r="2.5"/>', '18', '18'),
        default => '',
    };
};

$customDefinitionIdFromLayoutKey = static function (string $fk): ?int {
    if (!str_starts_with($fk, 'custom:')) {
        return null;
    }
    $id = (int) substr($fk, strlen('custom:'));

    return $id > 0 ? $id : null;
};

$sectionHeadingForKey = static function (string $fk, ?array $pmeta): string {
    if ($pmeta !== null && (($pmeta['kind'] ?? '') === 'block')) {
        $label = (string) ($pmeta['label'] ?? $fk);
        if ($fk === 'phone_contact_block') {
            return 'PHONE NUMBERS (HOME, MOBILE, WORK…)';
        }

        return function_exists('mb_strtoupper') ? mb_strtoupper($label, 'UTF-8') : strtoupper($label);
    }

    return '';
};

$renderComposerBlockBody = static function (array $prow, bool $interactivePreview = false, ?string $composerLabelOverride = null, string $previewLayout = 'default') use ($fieldLabels, $systemFieldDefinitions, $customFieldLayoutTypes, $iconSvg, $sectionHeadingForKey): void {
    $fk = (string) $prow['field_key'];
    $plabel = ($composerLabelOverride !== null && $composerLabelOverride !== '') ? $composerLabelOverride : ($fieldLabels[$fk] ?? $fk);
    $pmeta = $systemFieldDefinitions[$fk] ?? null;
    $kind = $pmeta['kind'] ?? '';

    if ($kind === 'block') {
        $block = (string) ($pmeta['block'] ?? '');
        $head = $sectionHeadingForKey($fk, $pmeta);

        if ($block === 'delivery') {
            echo '<div class="cf-composer__block-stack cf-composer__block-stack--delivery" data-cf-delivery-block>';
            if ($head !== '') {
                echo '<p class="cf-composer__section-cap">' . htmlspecialchars($head) . '</p>';
            }
            echo '<div class="cf-composer__switch-row" data-cf-delivery-toggle-row>';
            echo '<span class="cf-composer__switch-label">Delivery same as Home Address</span>';
            if ($interactivePreview) {
                echo '<label class="cf-composer__ios-switch">';
                echo '<input type="checkbox" role="switch" checked aria-checked="true" data-cf-delivery-same>';
                echo '<span class="cf-composer__ios-switch-ui" aria-hidden="true"></span>';
                echo '</label>';
            } else {
                echo '<span class="cf-composer__ios-switch cf-composer__ios-switch--readonly" aria-hidden="true"><span class="cf-composer__ios-switch-ui is-on"></span></span>';
            }
            echo '</div>';
            $expandClass = 'cf-composer__delivery-expand cf-composer__delivery-expand--collapsed';
            if (!$interactivePreview) {
                $expandClass .= ' cf-composer__delivery-expand--static';
            }
            echo '<div class="' . $expandClass . '" data-cf-delivery-fields>';
            echo '<div class="cf-composer__delivery-expand-inner cf-composer__field-row cf-composer__field-row--split">';
            echo '<div class="cf-composer__field-cell"><div class="cf-composer__ctrl-field"><input class="cf-composer__ctrl cf-composer__ctrl--line" type="text" value="" placeholder="Line 1" ' . ($interactivePreview ? '' : 'disabled aria-disabled="true"') . '></div></div>';
            echo '<div class="cf-composer__field-cell"><div class="cf-composer__ctrl-field"><input class="cf-composer__ctrl cf-composer__ctrl--line" type="text" value="" placeholder="Line 2" ' . ($interactivePreview ? '' : 'disabled aria-disabled="true"') . '></div></div>';
            echo '</div></div>';
            echo '</div>';

            return;
        }

        echo '<div class="cf-composer__block-stack">';
        if ($head !== '') {
            echo '<p class="cf-composer__section-cap">' . htmlspecialchars($head) . '</p>';
        }
        echo '<div class="cf-composer__block-row-head">';
        if ($block === 'home_address') {
            echo '<span class="cf-composer__block-icon" aria-hidden="true">' . $iconSvg('house') . '</span>';
        } elseif ($block === 'phone_contact') {
            echo '<span class="cf-composer__block-icon" aria-hidden="true">' . $iconSvg('phone') . '</span>';
        }
        echo '<span class="cf-composer__block-title">' . htmlspecialchars($plabel) . '</span>';
        echo '</div>';
        if ($block === 'phone_contact') {
            echo '<div class="cf-composer__field-row cf-composer__field-row--split">';
            echo '<div class="cf-composer__field-cell"><div class="cf-composer__ctrl-field"><input class="cf-composer__ctrl cf-composer__ctrl--phone" type="text" disabled value="" placeholder="Home" aria-disabled="true"></div></div>';
            echo '<div class="cf-composer__field-cell"><div class="cf-composer__ctrl-field"><input class="cf-composer__ctrl cf-composer__ctrl--phone" type="text" disabled value="" placeholder="Mobile" aria-disabled="true"></div></div>';
            echo '</div>';
        } else {
            echo '<div class="cf-composer__field-row cf-composer__field-row--split">';
            echo '<div class="cf-composer__field-cell"><div class="cf-composer__ctrl-field"><input class="cf-composer__ctrl cf-composer__ctrl--line" type="text" disabled value="" placeholder="Line 1" aria-disabled="true"></div></div>';
            echo '<div class="cf-composer__field-cell"><div class="cf-composer__ctrl-field"><input class="cf-composer__ctrl cf-composer__ctrl--line" type="text" disabled value="" placeholder="Line 2" aria-disabled="true"></div></div>';
            echo '</div>';
        }
        echo '</div>';

        return;
    }

    if ($fk === 'first_name' || $fk === 'last_name') {
        $ph = $fk === 'first_name' ? 'First name' : 'Last name';
        if ($previewLayout === 'name-pair-col') {
            echo '<div class="cf-composer__labeled-field cf-composer__labeled-field--grow">';
            echo '<span class="cf-composer__input-cap">' . htmlspecialchars($plabel) . '</span>';
            echo '<input class="cf-composer__ctrl cf-composer__ctrl--name" type="text" disabled value="" placeholder="' . htmlspecialchars($ph) . '" aria-disabled="true">';
            echo '</div>';

            return;
        }
        echo '<div class="cf-composer__ctrl-field cf-composer__ctrl-field--solo">';
        echo '<input class="cf-composer__ctrl cf-composer__ctrl--name" type="text" disabled value="" placeholder="' . htmlspecialchars($ph) . '" aria-disabled="true">';
        echo '</div>';

        return;
    }

    if ($pmeta === null) {
        $cft = $customFieldLayoutTypes[$fk] ?? 'text';
        $cPara = $cft === 'textarea' || $cft === 'address';
        $cBool = $cft === 'boolean';
        echo '<div class="cf-composer__block-row-head"><span class="cf-composer__block-title cf-composer__block-title--muted">' . htmlspecialchars($plabel) . '</span></div>';
        if ($cBool) {
            echo '<label class="cf-composer__ctrl-check"><input type="checkbox" disabled aria-disabled="true"> <span>' . htmlspecialchars($plabel) . '</span></label>';
        } elseif ($cPara) {
            echo '<div class="cf-composer__ctrl-field"><textarea class="cf-composer__ctrl cf-composer__ctrl--area" disabled rows="' . ($cft === 'address' ? '2' : '3') . '" aria-disabled="true" placeholder="' . htmlspecialchars($plabel) . '"></textarea></div>';
        } else {
            echo '<div class="cf-composer__ctrl-field"><input class="cf-composer__ctrl" type="text" disabled value="" placeholder="' . htmlspecialchars($plabel) . '" aria-disabled="true"></div>';
        }

        return;
    }

    $adm = (string) ($pmeta['admin_field_type'] ?? '');
    $isPara = str_contains($adm, 'paragraph') || str_contains($adm, 'text_area');
    $isBool = $adm === 'boolean';

    if ($fk === 'email') {
        echo '<div class="cf-composer__block-row-head">';
        echo '<span class="cf-composer__block-icon" aria-hidden="true">' . $iconSvg('envelope') . '</span>';
        echo '<span class="cf-composer__field-cap">' . htmlspecialchars($plabel) . '</span>';
        echo '</div>';
        echo '<div class="cf-composer__labeled-field cf-composer__labeled-field--full">';
        echo '<div class="cf-composer__ctrl-field"><input class="cf-composer__ctrl cf-composer__ctrl--email" type="email" disabled value="" placeholder="name@example.com" aria-disabled="true"></div>';
        echo '</div>';

        return;
    }

    if ($fk === 'notes') {
        echo '<div class="cf-composer__block-row-head">';
        echo '<span class="cf-composer__block-icon" aria-hidden="true">' . $iconSvg('note') . '</span>';
        echo '<span class="cf-composer__field-cap">' . htmlspecialchars($plabel) . '</span>';
        echo '</div>';
        if ($interactivePreview) {
            echo '<div class="cf-composer__ctrl-field"><textarea class="cf-composer__ctrl cf-composer__ctrl--area cf-composer__ctrl--autosize" rows="1" placeholder="Notes" data-cf-autosize></textarea></div>';
        } else {
            echo '<div class="cf-composer__ctrl-field"><textarea class="cf-composer__ctrl cf-composer__ctrl--area" disabled rows="3" aria-disabled="true" placeholder="Notes"></textarea></div>';
        }

        return;
    }

    $genericIcon = $isBool ? 'checkSquare' : ($isPara ? 'note' : 'docText');
    echo '<div class="cf-composer__block-row-head"><span class="cf-composer__block-icon" aria-hidden="true">' . $iconSvg($genericIcon) . '</span><span class="cf-composer__field-cap">' . htmlspecialchars($plabel) . '</span></div>';
    if ($isBool) {
        echo '<label class="cf-composer__ctrl-check"><input type="checkbox" disabled aria-disabled="true"> <span>' . htmlspecialchars($plabel) . '</span></label>';
    } elseif ($isPara) {
        echo '<div class="cf-composer__ctrl-field"><textarea class="cf-composer__ctrl cf-composer__ctrl--area" disabled rows="3" aria-disabled="true" placeholder="' . htmlspecialchars($plabel) . '"></textarea></div>';
    } else {
        echo '<div class="cf-composer__ctrl-field"><input class="cf-composer__ctrl" type="text" disabled value="" placeholder="' . htmlspecialchars($plabel) . '" aria-disabled="true"></div>';
    }
};

/** @return array<string, mixed> View-model for one layout row (composer list card). */
$composerRowView = static function (array $row) use ($intakeImmutableKeys, $removableKeys, $definitions, $fieldLabels, $customDefinitionIdFromLayoutKey): array {
    $fk = (string) $row['field_key'];
    $rowLocked = $intakeImmutableKeys !== [] && in_array($fk, $intakeImmutableKeys, true);
    $rowEnabled = ((int) ($row['is_enabled'] ?? 1)) === 1;
    $customDefId = $customDefinitionIdFromLayoutKey($fk);
    $settingsId = 'cf-field-settings-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $fk);
    $catalogLabel = $fieldLabels[$fk] ?? $fk;
    $storedLabel = isset($row['display_label']) && $row['display_label'] !== null ? trim((string) $row['display_label']) : '';
    $effectiveDisplayLabel = $storedLabel !== '' ? $storedLabel : $catalogLabel;
    $catalogRequired = false;
    if ($customDefId !== null) {
        foreach (($definitions ?? []) as $d) {
            if ((int) ($d['id'] ?? 0) === $customDefId) {
                $catalogRequired = (int) ($d['is_required'] ?? 0) === 1;
                break;
            }
        }
    }
    $layoutRequiredVal = null;
    if (array_key_exists('is_required', $row) && $row['is_required'] !== null && $row['is_required'] !== '') {
        $layoutRequiredVal = (int) $row['is_required'] ? 1 : 0;
    }
    $effectiveRequired = $layoutRequiredVal !== null ? ($layoutRequiredVal === 1) : $catalogRequired;

    return [
        'row' => $row,
        'fk' => $fk,
        'rowLocked' => $rowLocked,
        'rowEnabled' => $rowEnabled,
        'customDefId' => $customDefId,
        'settingsId' => $settingsId,
        'catalogLabel' => $catalogLabel,
        'storedLabel' => $storedLabel,
        'effectiveDisplayLabel' => $effectiveDisplayLabel,
        'catalogRequired' => $catalogRequired,
        'layoutRequiredVal' => $layoutRequiredVal,
        'effectiveRequired' => $effectiveRequired,
        'removable' => !$rowLocked && in_array($fk, $removableKeys, true),
    ];
};

$renderComposerSettingsExpand = static function (array $v) use ($csrfTn, $csrf, $selectedProfileKey): void {
    $fk = $v['fk'];
    $settingsId = $v['settingsId'];
    $rowLocked = $v['rowLocked'];
    if ($rowLocked) {
        return;
    }
    $effectiveDisplayLabel = $v['effectiveDisplayLabel'];
    $layoutRequiredVal = $v['layoutRequiredVal'];
    $effectiveRequired = $v['effectiveRequired'];
    $rowEnabled = $v['rowEnabled'];
    echo '<div class="cf-composer__field-settings-expand" id="' . htmlspecialchars($settingsId, ENT_QUOTES, 'UTF-8') . '" data-cf-field-settings-panel data-cf-settings-owner="' . htmlspecialchars($fk, ENT_QUOTES, 'UTF-8') . '" data-cf-settings-readonly="0" role="region" aria-label="Field configuration" aria-hidden="true">';
    echo '<div class="cf-composer__field-settings-inner">';
    echo '<div class="cf-composer__settings-field">';
    echo '<label class="cf-composer__settings-input-label" for="' . htmlspecialchars($settingsId, ENT_QUOTES, 'UTF-8') . '-label">Label</label>';
    echo '<input type="text" class="cf-composer__settings-text-input" form="cf-form-layout-save" id="' . htmlspecialchars($settingsId, ENT_QUOTES, 'UTF-8') . '-label" name="items[' . htmlspecialchars($fk) . '][display_label]" value="' . htmlspecialchars($effectiveDisplayLabel) . '" autocomplete="off" maxlength="150" aria-describedby="' . htmlspecialchars($settingsId, ENT_QUOTES, 'UTF-8') . '-label-hint">';
    echo '<p class="cf-composer__settings-hint" id="' . htmlspecialchars($settingsId, ENT_QUOTES, 'UTF-8') . '-label-hint">Shown on the client form. Clear and save to reset to default.</p>';
    echo '</div>';
    echo '<div class="cf-composer__settings-switch-row">';
    echo '<span class="cf-composer__settings-switch-label" id="' . htmlspecialchars($settingsId, ENT_QUOTES, 'UTF-8') . '-req-label">Required</span>';
    echo '<input type="hidden" form="cf-form-layout-save" name="items[' . htmlspecialchars($fk) . '][is_required]" value="0">';
    echo '<label class="cf-composer__ios-switch">';
    echo '<input type="checkbox" role="switch" form="cf-form-layout-save" name="items[' . htmlspecialchars($fk) . '][is_required]" value="1"' . ($effectiveRequired ? ' checked' : '') . ' aria-labelledby="' . htmlspecialchars($settingsId, ENT_QUOTES, 'UTF-8') . '-req-label">';
    echo '<span class="cf-composer__ios-switch-ui" aria-hidden="true"></span>';
    echo '</label></div>';
    echo '<div class="cf-composer__settings-switch-row">';
    echo '<span class="cf-composer__settings-switch-label" id="' . htmlspecialchars($settingsId, ENT_QUOTES, 'UTF-8') . '-vis-label">Show on form</span>';
    echo '<label class="cf-composer__ios-switch">';
    echo '<input type="checkbox" role="switch" form="cf-form-layout-save" name="items[' . htmlspecialchars($fk) . '][is_enabled]" value="1"' . ($rowEnabled ? ' checked' : '') . ' aria-labelledby="' . htmlspecialchars($settingsId, ENT_QUOTES, 'UTF-8') . '-vis-label">';
    echo '<span class="cf-composer__ios-switch-ui" aria-hidden="true"></span>';
    echo '</label></div>';
    echo '</div></div>';
};

/** Returns ['label' => string, 'class' => string, 'icon' => string, 'glyph' => string] for a field key's type badge. */
$fieldTypeBadge = static function (string $fk, ?array $pmeta) use ($classifyKeyForAddMenu, $customFieldLayoutTypes): array {
    if ($fk === 'first_name' || $fk === 'last_name') {
        return ['label' => 'Name', 'class' => 'name', 'icon' => 'user', 'glyph' => ''];
    }
    if ($fk === 'email') {
        return ['label' => 'Email', 'class' => 'email', 'icon' => 'mail', 'glyph' => ''];
    }
    if ($fk === 'notes') {
        return ['label' => 'Notes', 'class' => 'notes', 'icon' => 'fileText', 'glyph' => ''];
    }
    if ($pmeta !== null && ($pmeta['admin_field_type'] ?? '') === 'boolean') {
        return ['label' => 'Toggle', 'class' => 'boolean', 'icon' => 'toggleRight', 'glyph' => ''];
    }
    $cft = $customFieldLayoutTypes[$fk] ?? null;
    if ($cft === 'boolean') {
        return ['label' => 'Toggle', 'class' => 'boolean', 'icon' => 'toggleRight', 'glyph' => ''];
    }
    $cat = $classifyKeyForAddMenu($fk);
    $map = [
        'phone'   => ['label' => 'Phone', 'class' => 'phone', 'icon' => 'phone', 'glyph' => ''],
        'address' => ['label' => 'Address', 'class' => 'address', 'icon' => 'mapPin', 'glyph' => ''],
        'date'    => ['label' => 'Date', 'class' => 'date', 'icon' => 'calendar', 'glyph' => ''],
        'text'    => ['label' => 'Text', 'class' => 'text', 'icon' => 'type', 'glyph' => ''],
    ];

    return $map[$cat ?? 'text'] ?? ['label' => 'Text', 'class' => 'text', 'icon' => 'type', 'glyph' => ''];
};

$renderFieldTypeBadge = static function (array $badge) use ($iconSvg): void {
    $label = (string) ($badge['label'] ?? 'Field');
    $class = (string) ($badge['class'] ?? 'text');
    $icon = (string) ($badge['icon'] ?? '');
    $glyph = (string) ($badge['glyph'] ?? '');
    echo '<span class="cfe-type-pill cfe-type-pill--' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
    if ($icon !== '') {
        echo '<span class="cfe-type-pill__icon" aria-hidden="true">' . $iconSvg($icon) . '</span>';
    } else {
        echo '<span class="cfe-type-pill__glyph" aria-hidden="true">' . htmlspecialchars($glyph !== '' ? $glyph : strtoupper(substr($label, 0, 1)), ENT_QUOTES, 'UTF-8') . '</span>';
    }
    echo '</span>';
};

ob_start();
?>
<?php require base_path('modules/clients/views/partials/client-fields-admin-shell.php'); ?>
<?php
$anyAdd = array_sum(array_map('count', $addMenuBuckets)) > 0;
$showToolbarActions = $canEditClientFields && ($layoutStorageReady ?? true) && !empty($layoutItemsSorted);
$showSidebarSave = $canEditClientFields && ($layoutStorageReady ?? true);
$catalogStatusText = $anyAdd ? 'Catalog fields are still available for this profile.' : 'All catalog fields are on this profile.';
$lockedStackLabels = [];
if ($canEditClientFields && $intakeImmutableKeys !== [] && !empty($layoutItemsSorted)) {
    $scanRows = array_values($layoutItemsSorted);
    $scanN = count($scanRows);
    for ($si = 0; $si < $scanN; $si++) {
        $sfk = (string) ($scanRows[$si]['field_key'] ?? '');
        $snext = ($si + 1 < $scanN) ? (string) ($scanRows[$si + 1]['field_key'] ?? '') : '';
        if ($sfk === 'first_name' && $snext === 'last_name') {
            $sva = $composerRowView($scanRows[$si]);
            $svb = $composerRowView($scanRows[$si + 1]);
            if ($sva['rowLocked'] && $svb['rowLocked']) {
                $lockedStackLabels[] = $sva['effectiveDisplayLabel'] . ' · ' . $svb['effectiveDisplayLabel'];
                $si++;

                continue;
            }
        }
        $sv = $composerRowView($scanRows[$si]);
        if ($sv['rowLocked']) {
            $lockedStackLabels[] = $sv['effectiveDisplayLabel'];
        }
    }
}
$lockedStackCount = count($lockedStackLabels);
/** Fixed fields stay collapsed until the operator explicitly expands them. */
$cfeFixedExpandedDefault = false;
?>

<!-- ════════════════════════════════════════════════════════════
     CLIENT FORM COMPOSER — FOUNDATION SHELL
     Regions: page-head · tools-bar · editor-canvas
     Future mounts: data-cfe-mount-add-panel · data-cfe-mount-inspector · data-cfe-mount-library
     ════════════════════════════════════════════════════════════ -->
<div class="cfe-root<?= $cfeFixedExpandedDefault ? ' cfe-readonly-details-expanded' : '' ?>" data-cf-composer-root>

    <?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); $tm = (string) ($flash[$t] ?? ''); ?>
    <?php if ($tm !== ''): ?>
    <div class="flash flash-<?= htmlspecialchars((string) $t) ?>" role="status" aria-live="polite"><?= htmlspecialchars($tm) ?></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($canEditClientFields): ?>
    <form id="cf-form-layout-save" method="post" action="/clients/custom-fields/layouts/save" class="cf-composer__hidden-form">
        <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey ?? '') ?>">
    </form>
    <?php endif; ?>

    <div class="cfe-layout">
        <aside class="cfe-sidebar" aria-label="Layout profiles">
            <p class="cfe-sidebar__cap">Profile</p>
            <nav class="cfe-sidebar__nav">
                <?php foreach (($profiles ?? []) as $p): ?>
                <?php $pk = (string) ($p['profile_key'] ?? ''); $active = $pk === ($selectedProfileKey ?? ''); ?>
                <a href="<?= htmlspecialchars($profileUrl($pk)) ?>" class="cfe-sidebar__link<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <?= htmlspecialchars((string) ($p['display_label'] ?? $pk)) ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($showSidebarSave): ?>
            <button type="submit" form="cf-form-layout-save" class="cfe-sidebar__save">Save layout</button>
            <span class="cfe-save-state" data-cf-save-state aria-live="polite" aria-atomic="true"></span>
            <?php endif; ?>
        </aside>

        <section class="cfe-main">
            <nav class="cfe-main__profiles ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb" aria-label="Layout profiles" data-ds-segmented-thumb>
                <span class="ds-segmented__thumb" aria-hidden="true"></span>
                <?php foreach (($profiles ?? []) as $p): ?>
                <?php $pk = (string) ($p['profile_key'] ?? ''); $active = $pk === ($selectedProfileKey ?? ''); ?>
                <a href="<?= htmlspecialchars($profileUrl($pk)) ?>" class="ds-segmented__link<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= htmlspecialchars((string) ($p['display_label'] ?? $pk)) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if (!$canEditClientFields): ?>
            <p class="cfe-read-only-banner" role="status">You can review how client forms are structured. Ask an administrator for <strong>clients.edit</strong> to change layouts or custom fields.</p>
            <?php endif; ?>

            <?php if (($layoutStorageReady ?? true) === false): ?>
            <div class="cfe-warn-panel" role="alert">
                <strong>Layout storage is not available.</strong>
                <?= htmlspecialchars(\Modules\Clients\Services\ClientPageLayoutService::LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE) ?>
                <p class="hint" style="margin:0.5rem 0 0">From the <code>system/</code> directory run <code>php scripts/migrate.php</code> to apply pending migrations.</p>
            </div>

            <?php else: ?>

            <div class="cfe-canvas" data-cfe-mount-inspector>

        <?php if ($canEditClientFields): ?>
            <?php if (!empty($layoutItemsSorted)): ?>
            <ul class="cfe-field-list" data-cf-field-sortable>
                <?php if ($lockedStackCount > 0): ?>
                <?php
                $lockedHintParts = $lockedStackLabels;
                if (count($lockedHintParts) > 5) {
                    $lockedHintParts = array_merge(array_slice($lockedHintParts, 0, 5), ['…']);
                }
                $lockedHintLine = implode(', ', $lockedHintParts);
                ?>
                <li class="cfe-field-item cfe-field-summary-locked cf-composer__group-row"
                    data-cf-non-sortable="1"
                    data-cf-locked-summary="1">
                    <div class="cf-composer__field-card-wrap">
                        <div class="cfe-field-row cfe-locked-summary-row__inner">
                            <span class="cfe-grip cfe-grip--locked" aria-hidden="true"><?= $iconSvg('lock') ?></span>
                            <div class="cfe-field-center cfe-locked-summary-row__body">
                                <div class="cfe-field-identity cfe-field-identity--summary">
                                    <span class="cfe-field-name">Fixed fields</span>
                                    <span class="cfe-lock-tag"><?= (int) $lockedStackCount ?></span>
                                    <span class="cfe-locked-summary-row__inline-hint" id="cf-readonly-details-hint"><?= htmlspecialchars($lockedHintLine) ?></span>
                                </div>
                            </div>
                            <div class="cfe-field-actions cfe-locked-summary-row__actions">
                                <button type="button"
                                    class="cfe-readonly-details-toggle"
                                    data-cf-readonly-details-toggle
                                    aria-label="<?= $cfeFixedExpandedDefault ? 'Hide fixed fields' : 'Show fixed fields' ?>"
                                    aria-expanded="<?= $cfeFixedExpandedDefault ? 'true' : 'false' ?>"
                                    aria-describedby="cf-readonly-details-hint">
                                    <span class="wr-pro__visually-hidden cfe-readonly-details-toggle__label"><?= $cfeFixedExpandedDefault ? 'Hide fixed fields' : 'Show fixed fields' ?></span>
                                    <span class="cfe-readonly-details-toggle__chev" aria-hidden="true"><?= $iconSvg('chevronDown') ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </li>
                <?php endif; ?>
                <?php
                $layoutRows = array_values($layoutItemsSorted);
                $layoutRowCount = count($layoutRows);
                for ($li = 0; $li < $layoutRowCount; $li++) {
                    $row = $layoutRows[$li];
                    $fk = (string) $row['field_key'];
                    $nextFk = ($li + 1 < $layoutRowCount) ? (string) $layoutRows[$li + 1]['field_key'] : '';

                    // ── Name pair ──
                    if ($fk === 'first_name' && $nextFk === 'last_name') {
                        $va = $composerRowView($row);
                        $vb = $composerRowView($layoutRows[$li + 1]);
                        $pairBadge = ['label' => 'Name', 'class' => 'name', 'icon' => '', 'glyph' => 'N'];
                        $pairGripLocked = $va['rowLocked'] || $vb['rowLocked'];
                        $pairBothLocked = $va['rowLocked'] && $vb['rowLocked'];
                        $li++;
                        ?>
                <li class="cfe-field-item cfe-field-item--pair cf-composer__group-row<?= $pairBothLocked ? ' cfe-field-item--locked cfe-locked-stack-item' : '' ?>"
                    data-cf-field-key="<?= htmlspecialchars($va['fk'], ENT_QUOTES) ?>"
                    data-cf-field-pair="1"
                    data-cf-field-locked="<?= $pairGripLocked ? '1' : '0' ?>">
                    <div class="cf-composer__field-card-wrap">
                        <div class="cfe-field-row">
                            <?php if (!$pairGripLocked): ?>
                            <span class="cfe-grip cf-composer__field-grip" draggable="true" tabindex="0" role="button" aria-label="Drag to reorder name fields"><?= $iconSvg('lines') ?></span>
                            <?php else: ?>
                            <span class="cfe-grip cfe-grip--locked" aria-hidden="true"><?= $iconSvg('lock') ?></span>
                            <?php endif; ?>
                            <div class="cfe-field-center cf-composer__group-row-body cf-composer__group-row-body--pair">
                                <div class="cfe-field-identity">
                                    <span class="cfe-field-name"><?= htmlspecialchars($va['effectiveDisplayLabel']) ?> · <?= htmlspecialchars($vb['effectiveDisplayLabel']) ?></span>
                                    <?php $renderFieldTypeBadge($pairBadge); ?>
                                </div>
                                <div class="cfe-field-preview">
                                    <div class="cf-composer__preview-cols">
                                        <?php $renderComposerBlockBody($va['row'], true, $va['effectiveDisplayLabel'], 'name-pair-col'); ?>
                                        <?php $renderComposerBlockBody($vb['row'], true, $vb['effectiveDisplayLabel'], 'name-pair-col'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="cfe-field-actions">
                                <?php foreach ([$va, $vb] as $vx): ?>
                                <?php if ($vx['removable']): ?>
                                <form method="post" action="/clients/custom-fields/layouts/remove-item" class="cfe-remove-form cf-composer__inline-remove" data-cf-confirm-remove>
                                    <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey ?? '') ?>">
                                    <input type="hidden" name="field_key" value="<?= htmlspecialchars($vx['fk']) ?>">
                                    <button type="button" class="cfe-action-btn cfe-action-btn--remove cfe-action-btn--confirm-trigger" aria-label="Remove <?= htmlspecialchars($vx['catalogLabel']) ?> from layout"><?= $iconSvg('trash') ?></button>
                                    <button type="submit" class="cfe-action-btn cfe-action-btn--remove cfe-action-btn--confirm-ok" aria-label="Confirm remove <?= htmlspecialchars($vx['catalogLabel']) ?>" hidden><?= $iconSvg('checkmark') ?><span class="cfe-confirm-ok-label">Remove</span></button>
                                </form>
                                <?php endif; ?>
                                <?php if (!$vx['rowLocked']): ?>
                                <button type="button"
                                    class="cfe-action-btn cfe-action-btn--done cf-composer__field-close-btn"
                                    data-cf-close-key="<?= htmlspecialchars($vx['fk'], ENT_QUOTES) ?>"
                                    aria-label="Done editing <?= htmlspecialchars($vx['catalogLabel']) ?>"><?= $iconSvg('checkmark') ?></button>
                                <?php endif; ?>
                                <?php if (!$vx['rowLocked']): ?>
                                <button type="button"
                                    class="cfe-action-btn cfe-action-btn--toggle cf-composer__field-edit-btn"
                                    data-cf-settings-key="<?= htmlspecialchars($vx['fk'], ENT_QUOTES) ?>"
                                    aria-label="Configure <?= htmlspecialchars($vx['catalogLabel']) ?>"
                                    aria-expanded="false"
                                    aria-controls="<?= htmlspecialchars($vx['settingsId'], ENT_QUOTES) ?>"><span class="cfe-action-btn__chev" aria-hidden="true"><?= $iconSvg('chevronDown') ?></span></button>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php $renderComposerSettingsExpand($va); ?>
                        <?php $renderComposerSettingsExpand($vb); ?>
                    </div>
                    <?php foreach ([$va, $vb] as $vx): ?>
                    <label class="wr-pro__visually-hidden" for="cf-pos-<?= htmlspecialchars($vx['fk']) ?>">Position</label>
                    <input type="hidden" form="cf-form-layout-save" id="cf-pos-<?= htmlspecialchars($vx['fk']) ?>" name="items[<?= htmlspecialchars($vx['fk']) ?>][position]" value="<?= (int) ($vx['row']['position'] ?? 0) ?>">
                    <input type="hidden" form="cf-form-layout-save" name="items[<?= htmlspecialchars($vx['fk']) ?>][field_key]" value="<?= htmlspecialchars($vx['fk']) ?>">
                    <?php if ($vx['rowLocked']): ?>
                    <input type="hidden" form="cf-form-layout-save" name="items[<?= htmlspecialchars($vx['fk']) ?>][is_enabled]" value="1">
                    <input type="hidden" form="cf-form-layout-save" name="items[<?= htmlspecialchars($vx['fk']) ?>][display_label]" value="<?= htmlspecialchars($vx['storedLabel']) ?>">
                    <?php if ($vx['layoutRequiredVal'] !== null): ?>
                    <input type="hidden" form="cf-form-layout-save" name="items[<?= htmlspecialchars($vx['fk']) ?>][is_required]" value="<?= $vx['layoutRequiredVal'] === 1 ? '1' : '0' ?>">
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </li>
                        <?php
                        continue;
                    }

                    // ── Regular row ──
                    $v = $composerRowView($row);
                    $badge = $fieldTypeBadge($v['fk'], $systemFieldDefinitions[$v['fk']] ?? null);
                    ?>
                <li class="cfe-field-item cf-composer__group-row<?= $v['rowLocked'] ? ' cfe-field-item--locked cfe-locked-stack-item' : '' ?>"
                    data-cf-field-key="<?= htmlspecialchars($v['fk'], ENT_QUOTES) ?>"
                    data-cf-field-locked="<?= $v['rowLocked'] ? '1' : '0' ?>">
                    <div class="cf-composer__field-card-wrap">
                        <div class="cfe-field-row">
                            <?php if (!$v['rowLocked']): ?>
                            <span class="cfe-grip cf-composer__field-grip" draggable="true" tabindex="0" role="button" aria-label="Drag to reorder field"><?= $iconSvg('lines') ?></span>
                            <?php else: ?>
                            <span class="cfe-grip cfe-grip--locked" aria-hidden="true"><?= $iconSvg('lock') ?></span>
                            <?php endif; ?>
                            <div class="cfe-field-center cf-composer__group-row-body">
                                <div class="cfe-field-identity">
                                    <span class="cfe-field-name"><?= htmlspecialchars($v['effectiveDisplayLabel']) ?></span>
                                <?php $renderFieldTypeBadge($badge); ?>
                                </div>
                                <div class="cfe-field-preview">
                                    <?php $renderComposerBlockBody($row, true, $v['effectiveDisplayLabel']); ?>
                                </div>
                            </div>
                            <div class="cfe-field-actions">
                                <?php if ($v['removable']): ?>
                                <form method="post" action="/clients/custom-fields/layouts/remove-item" class="cfe-remove-form cf-composer__inline-remove" data-cf-confirm-remove>
                                    <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey ?? '') ?>">
                                    <input type="hidden" name="field_key" value="<?= htmlspecialchars($v['fk']) ?>">
                                    <button type="button" class="cfe-action-btn cfe-action-btn--remove cfe-action-btn--confirm-trigger" aria-label="Remove <?= htmlspecialchars($v['catalogLabel']) ?> from layout"><?= $iconSvg('trash') ?></button>
                                    <button type="submit" class="cfe-action-btn cfe-action-btn--remove cfe-action-btn--confirm-ok" aria-label="Confirm remove <?= htmlspecialchars($v['catalogLabel']) ?>" hidden><?= $iconSvg('checkmark') ?><span class="cfe-confirm-ok-label">Remove</span></button>
                                </form>
                                <?php endif; ?>
                                <?php if (!$v['rowLocked']): ?>
                                <button type="button"
                                    class="cfe-action-btn cfe-action-btn--done cf-composer__field-close-btn"
                                    data-cf-close-key="<?= htmlspecialchars($v['fk'], ENT_QUOTES) ?>"
                                    aria-label="Done editing <?= htmlspecialchars($v['catalogLabel']) ?>"><?= $iconSvg('checkmark') ?></button>
                                <?php endif; ?>
                                <?php if (!$v['rowLocked']): ?>
                                <button type="button"
                                    class="cfe-action-btn cfe-action-btn--toggle cf-composer__field-edit-btn"
                                    data-cf-settings-key="<?= htmlspecialchars($v['fk'], ENT_QUOTES) ?>"
                                    aria-label="Configure <?= htmlspecialchars($v['catalogLabel']) ?>"
                                    aria-expanded="false"
                                    aria-controls="<?= htmlspecialchars($v['settingsId'], ENT_QUOTES) ?>"><span class="cfe-action-btn__chev" aria-hidden="true"><?= $iconSvg('chevronDown') ?></span></button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php $renderComposerSettingsExpand($v); ?>
                    </div>
                    <label class="wr-pro__visually-hidden" for="cf-pos-<?= htmlspecialchars($v['fk']) ?>">Position</label>
                    <input type="hidden" form="cf-form-layout-save" id="cf-pos-<?= htmlspecialchars($v['fk']) ?>" name="items[<?= htmlspecialchars($v['fk']) ?>][position]" value="<?= (int) ($row['position'] ?? 0) ?>">
                    <input type="hidden" form="cf-form-layout-save" name="items[<?= htmlspecialchars($v['fk']) ?>][field_key]" value="<?= htmlspecialchars($v['fk']) ?>">
                    <?php if ($v['rowLocked']): ?>
                    <input type="hidden" form="cf-form-layout-save" name="items[<?= htmlspecialchars($v['fk']) ?>][is_enabled]" value="1">
                    <input type="hidden" form="cf-form-layout-save" name="items[<?= htmlspecialchars($v['fk']) ?>][display_label]" value="<?= htmlspecialchars($v['storedLabel']) ?>">
                    <?php if ($v['layoutRequiredVal'] !== null): ?>
                    <input type="hidden" form="cf-form-layout-save" name="items[<?= htmlspecialchars($v['fk']) ?>][is_required]" value="<?= $v['layoutRequiredVal'] === 1 ? '1' : '0' ?>">
                    <?php endif; ?>
                    <?php endif; ?>
                </li>
                <?php
                }
                ?>
            </ul>

            <?php else: ?>
            <div class="cfe-empty-state">
                <p class="cfe-empty-state__title">No fields on this profile yet.</p>
                <p class="cfe-empty-state__hint">Open the <strong>Field library</strong> below to add fields, or <a href="/clients/custom-fields/create" class="cfe-empty-state__link">create a custom field</a>.</p>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Read-only layout -->
            <?php if (!empty($layoutItemsSorted)): ?>
            <ul class="cfe-field-list cf-composer__grouped-list--readonly">
                <?php
                $roRows = array_values($layoutItemsSorted);
                $roN = count($roRows);
                $roPairBadge = ['label' => 'Name', 'class' => 'name', 'icon' => '', 'glyph' => 'N'];
                for ($ri = 0; $ri < $roN; $ri++) {
                    $rrow = $roRows[$ri];
                    $rfk = (string) $rrow['field_key'];
                    $rnext = ($ri + 1 < $roN) ? (string) $roRows[$ri + 1]['field_key'] : '';
                    if ($rfk === 'first_name' && $rnext === 'last_name') {
                        $ri++;
                        $va = $composerRowView($rrow);
                        $vb = $composerRowView($roRows[$ri]);
                        ?>
                <li class="cfe-field-item cfe-field-item--pair cf-composer__group-row" data-cf-field-key="<?= htmlspecialchars($va['fk'], ENT_QUOTES) ?>" data-cf-field-pair="1">
                    <div class="cfe-field-row">
                        <span class="cfe-grip cfe-grip--locked" aria-hidden="true"><?= $iconSvg('lock') ?></span>
                        <div class="cfe-field-center cf-composer__group-row-body cf-composer__group-row-body--pair">
                            <div class="cfe-field-identity">
                                <span class="cfe-field-name"><?= htmlspecialchars($va['effectiveDisplayLabel']) ?> · <?= htmlspecialchars($vb['effectiveDisplayLabel']) ?></span>
                                <?php $renderFieldTypeBadge($roPairBadge); ?>
                            </div>
                            <div class="cfe-field-preview">
                                <div class="cf-composer__preview-cols">
                                    <?php $renderComposerBlockBody($rrow, false, $va['effectiveDisplayLabel'], 'name-pair-col'); ?>
                                    <?php $renderComposerBlockBody($roRows[$ri], false, $vb['effectiveDisplayLabel'], 'name-pair-col'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="cfe-field-actions"></div>
                    </div>
                </li>
                        <?php
                        continue;
                    }
                    $roView = $composerRowView($rrow);
                    $roBadge = $fieldTypeBadge($rfk, $systemFieldDefinitions[$rfk] ?? null);
                    ?>
                <li class="cfe-field-item cf-composer__group-row" data-cf-field-key="<?= htmlspecialchars($rfk, ENT_QUOTES) ?>">
                    <div class="cfe-field-row">
                        <span class="cfe-grip cfe-grip--locked" aria-hidden="true"><?= $iconSvg('lock') ?></span>
                        <div class="cfe-field-center cf-composer__group-row-body">
                            <div class="cfe-field-identity">
                                <span class="cfe-field-name"><?= htmlspecialchars($roView['effectiveDisplayLabel']) ?></span>
                                <?php $renderFieldTypeBadge($roBadge); ?>
                            </div>
                            <div class="cfe-field-preview">
                                <?php $renderComposerBlockBody($rrow, false); ?>
                            </div>
                        </div>
                        <div class="cfe-field-actions"></div>
                    </div>
                </li>
                <?php
                }
                ?>
            </ul>
            <?php else: ?>
            <div class="cfe-empty-state"><p class="cfe-empty-state__title">No layout rows for this profile.</p></div>
            <?php endif; ?>

        <?php endif; ?>

                <?php if ($canEditClientFields): ?>
                <div class="cfe-card-footer">
                    <details class="cfe-footer-library" id="cf-composer-library" data-cf-add-disclosure>
                        <summary class="cfe-footer-library__summary">
                            <?= $iconSvg('plusCircleFill', 'cfe-footer-library__summary-ic') ?>
                            <span>Field library</span>
                            <span class="cfe-footer-library__summary-chev" aria-hidden="true"><?= $iconSvg('chevronDown') ?></span>
                        </summary>
                        <div class="cfe-footer-library__panel">
                            <?php if ($anyAdd): ?>
                            <div class="cfe-footer-library__section">
                                <p class="cfe-footer-library__cap">Add field</p>
                                <label class="cfe-tool-search-wrap" for="cfe-add-search">
                                    <span class="wr-pro__visually-hidden">Search fields to add</span>
                                    <input id="cfe-add-search" type="search" class="cfe-tool-search" placeholder="Search available fields" autocomplete="off" data-cf-add-search>
                                </label>
                                <div class="cfe-palette">
                                    <?php foreach (['text', 'phone', 'address', 'date'] as $bucketKey): ?>
                                    <?php $bKeys = $addMenuBuckets[$bucketKey] ?? []; ?>
                                    <div class="cfe-palette-group" data-cf-add-group data-cf-group-label="<?= htmlspecialchars(strtolower($addMenuLabels[$bucketKey] ?? $bucketKey), ENT_QUOTES, 'UTF-8') ?>">
                                        <p class="cfe-palette-cap">
                                            <?= $iconSvg($addMenuIconKeys[$bucketKey] ?? 'alignLeft') ?>
                                            <span><?= htmlspecialchars($addMenuLabels[$bucketKey] ?? $bucketKey) ?></span>
                                        </p>
                                        <?php if ($bKeys === []): ?>
                                        <p class="cfe-palette-empty">All added</p>
                                        <?php else: ?>
                                        <div class="cfe-palette-chips">
                                            <?php foreach ($bKeys as $ak): ?>
                                            <form method="post" action="/clients/custom-fields/layouts/add-item" class="cfe-palette-chip-form" data-cf-add-item data-cf-add-label="<?= htmlspecialchars(strtolower((string) ($fieldLabels[$ak] ?? $ak)), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey ?? '') ?>">
                                                <input type="hidden" name="field_key" value="<?= htmlspecialchars($ak) ?>">
                                                <button type="submit" class="cfe-palette-chip"><?= htmlspecialchars($fieldLabels[$ak] ?? $ak) ?></button>
                                            </form>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="cfe-footer-library__section">
                                <?php if ($canEditClientFields): ?>
                                <a href="/clients/custom-fields/create" class="cfe-create-btn">
                                    <?= $iconSvg('plus') ?><span>Create custom field</span>
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($definitions)): ?>
                                <div class="cfe-lib-list">
                                    <?php foreach ($definitions as $d): ?>
                                    <div class="cfe-lib-item">
                                        <span class="cfe-lib-name"><?= htmlspecialchars((string) $d['label']) ?></span>
                                        <span class="cfe-lib-type"><?= htmlspecialchars($humanizeFieldType((string) ($d['field_type'] ?? ''))) ?></span>
                                        <?php if ($canEditClientFields): ?>
                                        <div class="cfe-lib-controls">
                                            <form method="post" action="/clients/custom-fields/<?= (int) $d['id'] ?>" class="cfe-lib-toggle-form">
                                                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="label" value="<?= htmlspecialchars((string) $d['label']) ?>">
                                                <input type="hidden" name="field_type" value="<?= htmlspecialchars((string) ($d['field_type'] ?? '')) ?>">
                                                <input type="hidden" name="sort_order" value="<?= (int) ($d['sort_order'] ?? 0) ?>">
                                                <?php if ((int) ($d['is_required'] ?? 0) === 1): ?><input type="hidden" name="is_required" value="1"><?php endif; ?>
                                                <?php $isActive = (int) ($d['is_active'] ?? 0) === 1; ?>
                                                <?php if (!$isActive): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
                                                <button type="submit" class="cfe-lib-toggle cfe-lib-toggle--<?= $isActive ? 'active' : 'inactive' ?>" aria-label="<?= $isActive ? 'Deactivate' : 'Activate' ?> <?= htmlspecialchars((string) $d['label']) ?>">
                                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                                </button>
                                            </form>
                                            <form method="post" action="/clients/custom-fields/<?= (int) $d['id'] ?>/delete" class="cfe-lib-delete-form" data-cf-confirm-remove>
                                                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                                <button type="button" class="cfe-lib-delete cfe-action-btn--confirm-trigger" aria-label="Delete <?= htmlspecialchars((string) $d['label']) ?>"><?= $iconSvg('trash') ?></button>
                                                <button type="submit" class="cfe-lib-delete cfe-lib-delete--confirm cfe-action-btn--confirm-ok" aria-label="Confirm delete <?= htmlspecialchars((string) $d['label']) ?>" hidden><?= $iconSvg('checkmark') ?><span class="cfe-confirm-ok-label">Delete</span></button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <p class="cfe-lib-empty">No custom fields yet.</p>
                                <?php endif; ?>

                                <?php if (!empty($systemCatalog)): ?>
                                <details class="cfe-sub-disclosure">
                                    <summary class="cfe-sub-cap">Built-in profile fields</summary>
                                    <div class="cfe-lib-list">
                                        <?php foreach (($systemCatalog ?? []) as $skey => $smeta): ?>
                                        <div class="cfe-lib-item">
                                            <span class="cfe-lib-name"><?= htmlspecialchars((string) ($smeta['label'] ?? $skey)) ?></span>
                                            <span class="cfe-lib-type"><?= htmlspecialchars($humanizeFieldType((string) ($smeta['admin_field_type'] ?? ''))) ?></span>
                                            <span class="cfe-lib-lock"><?= $iconSvg('lock') ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    </details>
                    <p class="cfe-card-footer__status"><?= htmlspecialchars($catalogStatusText) ?></p>
                </div>
                <?php endif; ?>
            </div><!-- /.cfe-canvas -->

            <?php endif; ?>
        </section>
    </div>

</div><!-- /.cfe-root -->
<script>
(function () {
    'use strict';
    var root = document.querySelector('[data-cf-composer-root]');
    if (!root) return;

    var activeEditFieldId = null;
    var saveForm = document.getElementById('cf-form-layout-save');
    var saveState = root.querySelector('[data-cf-save-state]');
    var isDirty = false;

    function setDirty(next) {
        if (!saveState) return;
        isDirty = !!next;
        saveState.textContent = isDirty ? 'Unsaved changes' : '';
        saveState.classList.toggle('is-dirty', isDirty);
    }

    if (saveForm) {
        root.querySelectorAll('[form="cf-form-layout-save"]').forEach(function (el) {
            el.addEventListener('input', function () { setDirty(true); });
            el.addEventListener('change', function () { setDirty(true); });
        });
        saveForm.addEventListener('submit', function () {
            setDirty(false);
            if (saveState) {
                saveState.textContent = 'Saving…';
            }
        });
        setDirty(false);
        /* Show "Saved" briefly on fresh page load after a successful save */
        var successFlash = root.querySelector('.flash-success');
        if (successFlash && saveState) {
            saveState.textContent = 'Saved';
            window.setTimeout(function () {
                if (!isDirty) saveState.textContent = '';
            }, 3000);
        }
    }

    (function initAddFieldFilter() {
        var disclosure = root.querySelector('[data-cf-add-disclosure]');
        var searchInput = root.querySelector('[data-cf-add-search]');
        if (!disclosure || !searchInput) return;

        function applyFilter() {
            var q = (searchInput.value || '').toLowerCase().trim();
            var groups = disclosure.querySelectorAll('[data-cf-add-group]');
            groups.forEach(function (group) {
                var groupLabel = (group.getAttribute('data-cf-group-label') || '').toLowerCase();
                var chips = group.querySelectorAll('[data-cf-add-item]');
                var visibleInGroup = 0;
                chips.forEach(function (chip) {
                    var itemLabel = (chip.getAttribute('data-cf-add-label') || '').toLowerCase();
                    var match = q === '' || itemLabel.indexOf(q) !== -1 || groupLabel.indexOf(q) !== -1;
                    chip.hidden = !match;
                    if (match) visibleInGroup++;
                });
                group.hidden = visibleInGroup === 0 && q !== '';
            });
        }

        searchInput.addEventListener('input', applyFilter);
        disclosure.addEventListener('toggle', function () {
            if (disclosure.open) {
                window.requestAnimationFrame(function () {
                    searchInput.focus();
                    searchInput.select();
                });
            }
        });
        applyFilter();
    })();

    function syncReadonlyMasterToggle() {
        var btn = root.querySelector('[data-cf-readonly-details-toggle]');
        if (!btn) return;
        var on = root.classList.contains('cfe-readonly-details-expanded');
        btn.setAttribute('aria-expanded', on ? 'true' : 'false');
        btn.setAttribute('aria-label', on ? 'Hide fixed fields' : 'Show fixed fields');
        var lab = btn.querySelector('.cfe-readonly-details-toggle__label');
        if (lab) lab.textContent = on ? 'Hide fixed fields' : 'Show fixed fields';
    }

    function applyComposerEditState() {
        root.querySelectorAll('[data-cf-field-settings-panel]').forEach(function (panel) {
            var owner = panel.getAttribute('data-cf-settings-owner');
            var open = owner !== null && activeEditFieldId !== null && owner === activeEditFieldId;
            panel.classList.toggle('is-open', open);
            panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        });
        root.querySelectorAll('.cf-composer__field-card-wrap').forEach(function (wrap) {
            wrap.classList.toggle('cf-composer__field-card-wrap--edit-open', !!wrap.querySelector('[data-cf-field-settings-panel].is-open'));
        });
        root.querySelectorAll('.cfe-field-item').forEach(function (item) {
            var fid = item.getAttribute('data-cf-field-key');
            item.classList.toggle('cfe-field-item--settings-open', fid !== null && fid === activeEditFieldId);
        });
        root.querySelectorAll('.cf-composer__field-edit-btn').forEach(function (btn) {
            var sk = btn.getAttribute('data-cf-settings-key');
            var li = btn.closest('.cfe-field-item, .cf-composer__field-item');
            var id = sk || (li ? li.getAttribute('data-cf-field-key') : '') || '';
            var active = activeEditFieldId !== null && id === activeEditFieldId;
            btn.setAttribute('aria-expanded', active ? 'true' : 'false');
            btn.classList.toggle('is-active', active);
        });
    }

    function setActiveEditFieldId(id) {
        if (id !== null) {
            var li = root.querySelector('.cfe-field-item[data-cf-field-key="' + id + '"]');
            if (!li || li.getAttribute('data-cf-field-locked') !== '1') {
                root.classList.remove('cfe-readonly-details-expanded');
                syncReadonlyMasterToggle();
            }
        }
        activeEditFieldId = id;
        applyComposerEditState();
    }

    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) return;
        if (e.target.closest('.cf-composer__field-edit-btn, [data-cf-field-settings-panel], .cfe-field-actions, .cfe-palette-chip-form, .cfe-palette-chip, [data-cf-locked-summary], [data-cf-readonly-details-toggle]')) return;
        if (e.target.closest('.cfe-field-item, .cf-composer__field-item')) return;
        root.classList.remove('cfe-readonly-details-expanded');
        syncReadonlyMasterToggle();
        setActiveEditFieldId(null);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        root.classList.remove('cfe-readonly-details-expanded');
        syncReadonlyMasterToggle();
        if (activeEditFieldId !== null) setActiveEditFieldId(null);
        else applyComposerEditState();
    });

    var sortableList = root.querySelector('[data-cf-field-sortable]');
    if (sortableList) {
        sortableList.addEventListener('click', function (e) {
            var doneBtn = e.target.closest('.cf-composer__field-edit-done');
            if (doneBtn) {
                e.preventDefault();
                root.classList.remove('cfe-readonly-details-expanded');
                syncReadonlyMasterToggle();
                setActiveEditFieldId(null);
                return;
            }

            var closeBtn = e.target.closest('.cf-composer__field-close-btn');
            if (closeBtn) {
                e.preventDefault();
                e.stopPropagation();
                setActiveEditFieldId(null);
                return;
            }

            var editBtn = e.target.closest('.cf-composer__field-edit-btn');
            if (editBtn) {
                e.preventDefault();
                e.stopPropagation();
                var sk = editBtn.getAttribute('data-cf-settings-key');
                var li = editBtn.closest('.cfe-field-item, .cf-composer__field-item');
                var fid = sk || (li ? li.getAttribute('data-cf-field-key') : null);
                if (!fid) return;
                setActiveEditFieldId(activeEditFieldId === fid ? null : fid);
                return;
            }

            var li = e.target.closest('.cfe-field-item, .cf-composer__field-item');
            if (!li || !sortableList.contains(li)) return;
            if (e.target.closest('a, button, input, textarea, select, label, .cfe-field-actions, [data-cf-field-settings-panel], [data-cf-delivery-block], .cf-composer__ios-switch, .cfe-remove-form')) return;
            if (li.getAttribute('data-cf-locked-summary') === '1') return;
            if (li.classList.contains('cfe-field-item--locked')) return;
            var fid = li.getAttribute('data-cf-field-key');
            if (!fid) return;
            setActiveEditFieldId(activeEditFieldId === fid ? null : fid);
        });
    }

    root.querySelectorAll('[data-cf-field-settings-panel] .cf-composer__ios-switch input[role="switch"]').forEach(function (inp) {
        function sync() { inp.setAttribute('aria-checked', inp.checked ? 'true' : 'false'); }
        inp.addEventListener('change', sync);
        sync();
    });

    applyComposerEditState();
    syncReadonlyMasterToggle();

    /* Locked rows: one control expands all read-only previews + core-field notes */
    root.addEventListener('click', function (e) {
        var mt = e.target.closest('[data-cf-readonly-details-toggle]');
        if (!mt || !root.contains(mt)) return;
        e.preventDefault();
        e.stopPropagation();
        root.classList.toggle('cfe-readonly-details-expanded');
        syncReadonlyMasterToggle();
        setActiveEditFieldId(null);
    });

    /* ── Drag-and-drop sort — placeholder-based, stable geometry ── */
    (function initFieldSort() {
        var list = root.querySelector('[data-cf-field-sortable]');
        if (!list) return;

        var ds = null;
        var pending = null;
        var rafId = null;
        var lastX = 0;
        var lastY = 0;
        var suppressClicksUntil = 0;
        var DRAG_START_SLOP = 6;
        var DROP_HYSTERESIS = 10;
        var AUTO_SCROLL_ZONE = 96;
        var AUTO_SCROLL_MAX = 12;

        list.addEventListener('dragstart', function (e) { e.preventDefault(); });

        function reducedMotion() {
            try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (_) { return false; }
        }

        function isSortable(el) {
            return el != null
                && el.nodeType === 1
                && (el.classList.contains('cfe-field-item') || el.classList.contains('cf-composer__group-row'))
                && el.getAttribute('data-cf-non-sortable') !== '1'
                && !el.classList.contains('cfe-dnd-placeholder')
                && !el.classList.contains('cfe-field-item--is-placeholder');
        }

        function sortableItems() {
            return Array.prototype.filter.call(list.children, isSortable);
        }

        function lockedCount(items) {
            var count = 0;
            for (var i = 0; i < items.length; i++) {
                if (items[i].getAttribute('data-cf-field-locked') === '1') {
                    count++;
                    continue;
                }
                break;
            }
            return count;
        }

        function syncPositions() {
            var pos = 0;
            Array.prototype.forEach.call(list.children, function (el) {
                if (!isSortable(el)) return;
                el.querySelectorAll('input[name*="[position]"]').forEach(function (inp) {
                    inp.value = String(pos++);
                });
            });
        }

        function settleDroppedItem(item) {
            if (reducedMotion() || !item) return;
            item.classList.remove('cfe-field-item--drop-settle');
            void item.offsetWidth;
            item.classList.add('cfe-field-item--drop-settle');
            window.setTimeout(function () {
                item.classList.remove('cfe-field-item--drop-settle');
            }, 650);
        }

        function flipSettle(allItems, firstRects, dropped) {
            if (reducedMotion()) {
                settleDroppedItem(dropped);
                return;
            }
            var inverts = new Map();
            allItems.forEach(function (el) {
                if (el === dropped || !firstRects.has(el)) return;
                var a = firstRects.get(el);
                var b = el.getBoundingClientRect();
                var dy = a.top - b.top;
                var dx = a.left - b.left;
                if (Math.abs(dy) < 0.5 && Math.abs(dx) < 0.5) return;
                inverts.set(el, { dx: dx, dy: dy });
            });

            var DUR = 280;
            var EASE = 'cubic-bezier(0.32,0.72,0,1)';
            if (inverts.size) {
                inverts.forEach(function (inv, el) {
                    el.style.willChange = 'transform';
                    el.style.transition = 'none';
                    el.style.transform = 'translate3d(' + inv.dx + 'px,' + inv.dy + 'px,0)';
                });
                window.requestAnimationFrame(function () {
                    window.requestAnimationFrame(function () {
                        inverts.forEach(function (inv, el) {
                            el.style.transition = 'transform ' + DUR + 'ms ' + EASE;
                            el.style.transform = 'translate3d(0,0,0)';
                        });
                        window.setTimeout(function () {
                            inverts.forEach(function (inv, el) {
                                el.style.transition = '';
                                el.style.transform = '';
                                el.style.willChange = '';
                            });
                        }, DUR + 16);
                    });
                });
            }

            settleDroppedItem(dropped);
        }

        function createGhost(item, rect) {
            var ghost = item.cloneNode(true);
            ghost.classList.add('cfe-field-item--drag-active');
            /* Strip transient state classes that would look wrong on a floating clone */
            ghost.classList.remove(
                'cfe-field-item--settings-open',
                'cfe-field-item--is-placeholder',
                'cfe-field-item--drop-settle'
            );
            ghost.style.width = rect.width + 'px';
            ghost.style.minWidth = rect.width + 'px';
            ghost.style.left = '0';
            ghost.style.top = '0';
            /* Start at scale(1) — lift spring applied on next frame */
            ghost.style.transform = 'translate3d(' + Math.round(rect.left) + 'px,' + Math.round(rect.top) + 'px,0) scale(1)';
            ghost.style.pointerEvents = 'none';
            ghost.setAttribute('aria-hidden', 'true');
            ghost.setAttribute('inert', '');
            /* Hide interactive UI elements that belong to the list, not the ghost */
            var actions = ghost.querySelector('.cfe-field-actions');
            if (actions) { actions.style.cssText = 'opacity:0!important;pointer-events:none!important'; }
            var grip = ghost.querySelector('.cfe-grip');
            if (grip) { grip.style.cssText = 'opacity:0!important'; }
            var settingsPanel = ghost.querySelector('[data-cf-field-settings-panel]');
            if (settingsPanel) { settingsPanel.style.cssText = 'display:none!important'; }
            document.body.appendChild(ghost);
            return ghost;
        }

        function styleAsPlaceholder(item) {
            item.classList.add('cfe-field-item--is-placeholder');
        }

        function restoreItem(item) {
            item.classList.remove('cfe-field-item--is-placeholder');
        }

        function movableItems() {
            return sortableItems().filter(function (el) {
                return !ds || el !== ds.item;
            });
        }

        function normalizedTop(rect, candidate) {
            if (!ds || !ds.item || !ds.item.parentNode) {
                return rect.top;
            }
            if (!(ds.item.compareDocumentPosition(candidate) & Node.DOCUMENT_POSITION_FOLLOWING)) {
                return rect.top;
            }
            var placeholderRect = ds.item.getBoundingClientRect();
            var placeholderHeight = placeholderRect.height > 0
                ? placeholderRect.height
                : 68;
            return rect.top - placeholderHeight;
        }

        function midpointForRect(rect, item) {
            var midpoint = normalizedTop(rect, item) + rect.height * 0.5;
            if (!ds || ds.lastTarget !== item) {
                return midpoint;
            }
            if (ds.dragDirection > 0) {
                return midpoint + DROP_HYSTERESIS;
            }
            if (ds.dragDirection < 0) {
                return midpoint - DROP_HYSTERESIS;
            }
            return midpoint;
        }

        function resolveInsertBefore(clientY) {
            var items = movableItems();
            var minIdx = lockedCount(items);
            for (var i = minIdx; i < items.length; i++) {
                var candidate = items[i];
                var rect = candidate.getBoundingClientRect();
                if (rect.height < 0.5) continue;
                if (clientY < midpointForRect(rect, candidate)) {
                    return candidate;
                }
            }
            return null;
        }

        function movePlaceholder(insertBefore) {
            if (!ds || !ds.item) return;
            if (insertBefore === ds.lastTarget) return;
            ds.lastTarget = insertBefore;
            if (insertBefore && insertBefore.parentNode === list) {
                list.insertBefore(ds.item, insertBefore);
                return;
            }
            list.appendChild(ds.item);
        }

        function updateDraggedPosition() {
            if (!ds) return;
            var x = Math.round(lastX - ds.offsetX);
            var y = Math.round(lastY - ds.offsetY);
            ds.ghost.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0) scale(1.03)';
        }

        function autoScroll() {
            if (!ds) return;
            var viewportHeight = window.innerHeight;
            if (lastY < AUTO_SCROLL_ZONE) {
                window.scrollBy(0, -Math.min(AUTO_SCROLL_MAX, AUTO_SCROLL_MAX * Math.pow(1 - lastY / AUTO_SCROLL_ZONE, 1.6)));
            } else if (lastY > viewportHeight - AUTO_SCROLL_ZONE) {
                window.scrollBy(0, Math.min(AUTO_SCROLL_MAX, AUTO_SCROLL_MAX * Math.pow((lastY - viewportHeight + AUTO_SCROLL_ZONE) / AUTO_SCROLL_ZONE, 1.6)));
            }
        }

        function tick() {
            if (!ds) {
                rafId = null;
                return;
            }
            updateDraggedPosition();
            movePlaceholder(resolveInsertBefore(lastY));
            autoScroll();
            rafId = window.requestAnimationFrame(tick);
        }

        function stopTick() {
            if (rafId) {
                window.cancelAnimationFrame(rafId);
                rafId = null;
            }
        }

        function cleanupPointerListeners() {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            document.removeEventListener('pointercancel', onCancel);
        }

        function clearPending() {
            pending = null;
        }

        function isInteractiveDragBlocker(target) {
            if (!target) return false;
            return !!target.closest('a, button, input, textarea, select, label, .cfe-field-actions, [data-cf-field-settings-panel], [data-cf-delivery-block], .cf-composer__ios-switch, .cfe-remove-form');
        }

        function startDrag(e, item, anchorX, anchorY) {
            var rect = item.getBoundingClientRect();
            var gripAnchorX = anchorX == null ? e.clientX : anchorX;
            var gripAnchorY = anchorY == null ? e.clientY : anchorY;

            lastX = e.clientX;
            lastY = e.clientY;

            var ghost = createGhost(item, rect);
            styleAsPlaceholder(item);

            ds = {
                item: item,
                ghost: ghost,
                originalNextSibling: item.nextSibling,
                pointerId: e.pointerId,
                offsetX: gripAnchorX - rect.left,
                offsetY: gripAnchorY - rect.top,
                dragDirection: 0,
                lastTarget: null
            };

            clearPending();
            if (activeEditFieldId !== null) {
                setActiveEditFieldId(null);
            }

            list.classList.add('cfe-field-list--is-dragging');
            root.classList.add('cfe-dnd-active');

            /* Lift spring: next frame transitions ghost from scale(1) → scale(1.03) */
            window.requestAnimationFrame(function () {
                if (!ds || ds.ghost !== ghost) return;
                ghost.style.transition = 'transform 160ms cubic-bezier(0.34,1.56,0.64,1)';
                ghost.style.transform = 'translate3d(' +
                    Math.round(lastX - ds.offsetX) + 'px,' +
                    Math.round(lastY - ds.offsetY) + 'px,0) scale(1.03)';
                /* After lift completes, hand back to the rAF tick loop */
                window.setTimeout(function () {
                    if (ds && ds.ghost === ghost) {
                        ghost.style.transition = '';
                    }
                }, 180);
            });

            /* Note: NO premature movePlaceholder() call here — item is already
               at its correct original position. The tick loop handles reordering. */
            rafId = window.requestAnimationFrame(tick);

            document.addEventListener('pointermove', onMove, { passive: false });
            document.addEventListener('pointerup', onUp);
            document.addEventListener('pointercancel', onCancel);
        }

        function finishDrag(shouldCommit) {
            if (!ds) return;

            var session = ds;
            ds = null;
            stopTick();
            cleanupPointerListeners();

            /* Return cursor to normal immediately — don't wait for snap animation */
            root.classList.remove('cfe-dnd-active');
            /* Suppress accidental post-drop clicks immediately */
            suppressClicksUntil = Date.now() + 220;

            function cleanup() {
                if (session.ghost && session.ghost.parentNode) {
                    session.ghost.parentNode.removeChild(session.ghost);
                }
                restoreItem(session.item);
                list.classList.remove('cfe-field-list--is-dragging');
            }

            if (!shouldCommit) {
                /* Restore item to its original position */
                if (session.originalNextSibling && session.originalNextSibling.parentNode === list) {
                    list.insertBefore(session.item, session.originalNextSibling);
                } else if (!session.originalNextSibling) {
                    list.appendChild(session.item);
                }
                cleanup();
                return;
            }

            if (!reducedMotion()) {
                var placeholderRect = session.item.getBoundingClientRect();
                var tx = Math.round(placeholderRect.left);
                var ty = Math.round(placeholderRect.top);
                var done = false;

                function onSnapped() {
                    if (done) return;
                    done = true;
                    session.ghost.removeEventListener('transitionend', onSnapped);
                    cleanup();
                    syncPositions();
                    setDirty(true);
                    settleDroppedItem(session.item);
                }

                session.ghost.style.transition =
                    'transform 380ms cubic-bezier(0.2,1,0.2,1)';
                session.ghost.style.transform =
                    'translate3d(' + tx + 'px,' + ty + 'px,0) scale(1)';
                session.ghost.addEventListener('transitionend', onSnapped);
                window.setTimeout(onSnapped, 500);
            } else {
                cleanup();
                syncPositions();
                setDirty(true);
                settleDroppedItem(session.item);
            }
        }

        function onDown(e) {
            if (ds) return;
            var target = e.target;
            var grip = target.closest('.cf-composer__field-grip');
            var item = target.closest('.cfe-field-item, .cf-composer__group-row');
            if (!item || !list.contains(item)) return;
            if (item.getAttribute('data-cf-field-locked') === '1') return;
            if (item.getAttribute('data-cf-non-sortable') === '1') return;
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            if (!grip && isInteractiveDragBlocker(target)) return;

            if (grip) {
                e.preventDefault();
                e.stopPropagation();
                startDrag(e, item);
                return;
            }

            pending = {
                item: item,
                pointerId: e.pointerId,
                startX: e.clientX,
                startY: e.clientY
            };

            document.addEventListener('pointermove', onMove, { passive: false });
            document.addEventListener('pointerup', onUp);
            document.addEventListener('pointercancel', onCancel);
        }

        function onMove(e) {
            if (ds) {
                if (e.pointerId !== ds.pointerId) return;
                e.preventDefault();
                ds.dragDirection = e.clientY > lastY ? 1 : (e.clientY < lastY ? -1 : ds.dragDirection);
                lastX = e.clientX;
                lastY = e.clientY;
                return;
            }
            if (!pending || e.pointerId !== pending.pointerId) return;

            var dx = e.clientX - pending.startX;
            var dy = e.clientY - pending.startY;
            if (Math.sqrt(dx * dx + dy * dy) < DRAG_START_SLOP) {
                return;
            }

            e.preventDefault();
            startDrag(e, pending.item, pending.startX, pending.startY);
        }

        function onUp(e) {
            if (ds) {
                if (e.pointerId !== ds.pointerId) return;
                finishDrag(true);
                return;
            }
            if (!pending || e.pointerId !== pending.pointerId) return;
            clearPending();
            cleanupPointerListeners();
        }

        function onCancel(e) {
            if (ds) {
                if (!e || e.pointerId === undefined || e.pointerId === ds.pointerId) {
                    finishDrag(false);
                }
                return;
            }
            if (!pending || (e && e.pointerId !== undefined && e.pointerId !== pending.pointerId)) return;
            clearPending();
            cleanupPointerListeners();
        }

        list.addEventListener('pointerdown', onDown);

        root.addEventListener('click', function (e) {
            if (Date.now() < suppressClicksUntil && root.contains(e.target)) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);

        list.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
            var grip = e.target.closest('.cf-composer__field-grip');
            if (!grip) return;
            var item = grip.closest('.cfe-field-item, .cf-composer__group-row');
            if (!item || !list.contains(item)) return;
            if (item.getAttribute('data-cf-field-locked') === '1') return;

            e.preventDefault();

            var all = sortableItems();
            var idx = all.indexOf(item);
            if (idx === -1) return;

            var others = all.filter(function (el) { return el !== item; });
            var minIdx = lockedCount(others);
            var target = e.key === 'ArrowUp'
                ? Math.max(minIdx, idx - 1)
                : Math.min(all.length - 1, idx + 1);

            if (target === idx) return;

            var firstRects = new Map();
            if (!reducedMotion()) {
                all.forEach(function (el) {
                    var rect = el.getBoundingClientRect();
                    if (rect.height > 0.5) {
                        firstRects.set(el, { top: rect.top, left: rect.left });
                    }
                });
            }

            if (target < idx) {
                list.insertBefore(item, all[target]);
            } else {
                var after = all[target + 1];
                if (after) {
                    list.insertBefore(item, after);
                } else {
                    list.appendChild(item);
                }
            }

            syncPositions();
            setDirty(true);
            flipSettle(all, firstRects, item);
            grip.focus();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && ds) {
                finishDrag(false);
            }
        });
    })();

    /* ── Delivery address toggle ── */
    root.querySelectorAll('[data-cf-delivery-block]').forEach(function (block) {
        var input = block.querySelector('[data-cf-delivery-same]');
        var fields = block.querySelector('[data-cf-delivery-fields]');
        if (!input || !fields) return;
        function syncDelivery() {
            var on = input.checked;
            input.setAttribute('aria-checked', on ? 'true' : 'false');
            fields.classList.toggle('cf-composer__delivery-expand--collapsed', on);
        }
        input.addEventListener('change', syncDelivery);
        syncDelivery();
    });

    /* ── Textarea auto-size ── */
    function cfAutosize(ta) { ta.style.height = 'auto'; ta.style.height = Math.max(ta.scrollHeight, 44) + 'px'; }
    root.querySelectorAll('textarea[data-cf-autosize]').forEach(function (ta) {
        ta.addEventListener('input', function () { cfAutosize(ta); });
        window.requestAnimationFrame(function () { cfAutosize(ta); });
    });

    /* ── Inline destructive confirmation (replaces native confirm()) ── */
    (function initInlineConfirm() {
        var PENDING_CLASS = 'cfe-confirm-pending';
        var pendingForm = null;
        var pendingTimer = null;
        var pendingBarTimer = null;
        var TIMEOUT_MS = 4000;

        function resetConfirm(form) {
            if (!form) return;
            form.classList.remove(PENDING_CLASS);
            var ok = form.querySelector('.cfe-action-btn--confirm-ok, .cfe-lib-delete.cfe-action-btn--confirm-ok, .cfe-lib-delete--confirm');
            var trigger = form.querySelector('.cfe-action-btn--confirm-trigger, .cfe-lib-delete.cfe-action-btn--confirm-trigger');
            if (ok) { ok.hidden = true; ok.style.setProperty('--cfe-confirm-progress', '100%'); }
            if (trigger) trigger.hidden = false;
        }

        function animateConfirmBar(ok) {
            if (!ok) return;
            ok.style.setProperty('--cfe-confirm-progress', '100%');
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    ok.style.setProperty('--cfe-confirm-progress', '0%');
                    ok.style.setProperty('--cfe-confirm-progress-duration', TIMEOUT_MS + 'ms');
                });
            });
        }

        function resetAllExcept(except) {
            document.querySelectorAll('[data-cf-confirm-remove]').forEach(function (f) {
                if (f !== except) resetConfirm(f);
            });
        }

        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('.cfe-action-btn--confirm-trigger');
            if (trigger) {
                var form = trigger.closest('[data-cf-confirm-remove]');
                if (!form) return;
                e.preventDefault();
                e.stopPropagation();
                if (pendingTimer) clearTimeout(pendingTimer);
                if (pendingBarTimer) clearTimeout(pendingBarTimer);
                resetAllExcept(form);
                form.classList.add(PENDING_CLASS);
                var ok = form.querySelector('.cfe-action-btn--confirm-ok, .cfe-lib-delete--confirm');
                if (ok) { ok.hidden = false; animateConfirmBar(ok); }
                trigger.hidden = true;
                pendingForm = form;
                pendingTimer = setTimeout(function () {
                    resetConfirm(form);
                    pendingForm = null;
                }, TIMEOUT_MS);
                return;
            }

            if (pendingForm && !e.target.closest('[data-cf-confirm-remove]')) {
                if (pendingTimer) clearTimeout(pendingTimer);
                resetConfirm(pendingForm);
                pendingForm = null;
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && pendingForm) {
                if (pendingTimer) clearTimeout(pendingTimer);
                resetConfirm(pendingForm);
                pendingForm = null;
            }
        });
    })();

    /* ── Palette chip: save scroll position across add-field POST/redirect ── */
    (function initPaletteChipScroll() {
        var SCROLL_KEY = 'cfe_scroll_restore';
        var disclosure = root.querySelector('[data-cf-add-disclosure]');
        if (!disclosure) return;
        var scrollY = sessionStorage.getItem(SCROLL_KEY);
        if (scrollY !== null) {
            sessionStorage.removeItem(SCROLL_KEY);
            window.requestAnimationFrame(function () {
                window.scrollTo(0, parseInt(scrollY, 10) || 0);
            });
        }
        disclosure.querySelectorAll('.cfe-palette-chip-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                sessionStorage.setItem(SCROLL_KEY, String(window.scrollY || 0));
            });
        });
    })();

    /* ── Header: quick new-field popover ── */
    (function () {
        var det = root.querySelector('[data-cf-new-field-details]');
        if (!det) return;
        var labelIn = det.querySelector('#cf-new-field-label');
        var form = det.querySelector('.cfe-new-field-form');

        function closeNewField() {
            det.removeAttribute('open');
            if (form) form.reset();
        }

        det.querySelectorAll('[data-cf-new-field-close]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                closeNewField();
            });
        });

        det.addEventListener('toggle', function () {
            if (det.open && labelIn) {
                window.requestAnimationFrame(function () { labelIn.focus(); });
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && det.open) {
                closeNewField();
            }
        });
    })();
})();
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
