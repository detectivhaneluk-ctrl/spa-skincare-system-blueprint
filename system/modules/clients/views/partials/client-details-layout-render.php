<?php
/** @var list<array{field_key: string, layout_span?: int}>|null $detailsLayoutRows */
/** @var list<string>|null $detailsLayoutKeys Legacy: used only when $detailsLayoutRows is empty */
/** @var array<string, mixed> $client */
/** @var array<string, mixed> $errors */
/** @var array<string, mixed> $marketing */
/** @var list<array<string, mixed>> $customFieldDefinitions */
/** @var array<int, string|null> $customFieldValues */
/** @var \Modules\Clients\Services\ClientFieldCatalogService $fieldCatalog */
/** @var bool $clientRefDedicatedDetails Optional; when true (edit page), emit visual field-group sections without changing field order. */

$defsById = [];
foreach ($customFieldDefinitions as $def) {
    $defsById[(int) $def['id']] = $def;
}

$err = static function (string $key) use ($errors): string {
    return !empty($errors[$key]) ? (string) $errors[$key] : '';
};

$useDetailsSections = !empty($clientRefDedicatedDetails);
$detailsSectionOpen = false;
$detailsPrevSection = null;
$detailsLayoutGridOpen = false;

$detailsLayoutRows = $detailsLayoutRows ?? [];
if ($detailsLayoutRows === [] && isset($detailsLayoutKeys) && is_array($detailsLayoutKeys)) {
    foreach ($detailsLayoutKeys as $legacyKey) {
        $detailsLayoutRows[] = ['field_key' => (string) $legacyKey, 'layout_span' => 3];
    }
}

/** Must match composer $customerDetailsLayoutFlowForceFullKeys — only true full-width rows. */
$detailsLayoutForceFullWidthKeys = [
    'phone_contact_block',
    'summary_primary_phone',
];

/** layout_span tier → grid units on a 6-column row: 1→2, 2→3, 3→6 (tier 1 legacy; service normalizes 1→ tier 2). */
$detailsLayoutSpanToGridUnits = static function (int $layoutSpan1to3): int {
    return match (max(1, min(3, $layoutSpan1to3))) {
        1 => 2,
        2 => 3,
        3 => 6,
        default => 6,
    };
};

/** @return array<string, string> */
$detailsSectionLabels = static fn (): array => [
    'identity' => 'Identity',
    'contact' => 'Contact',
    'personal' => 'Personal',
    'address' => 'Address',
    'communication' => 'Communication',
    'alerts' => 'Alerts',
    'referral' => 'Referral',
    'emergency' => 'Emergency contact',
    'status_notes' => 'Status & notes',
    'custom_fields' => 'Custom fields',
];

$resolveSystemDetailsSection = static function (string $layoutKey): ?string {
    return match ($layoutKey) {
        'first_name', 'last_name' => 'identity',
        'email', 'phone_contact_block', 'summary_primary_phone' => 'contact',
        'birth_date', 'anniversary', 'gender', 'occupation', 'language' => 'personal',
        'home_address_block', 'delivery_block' => 'address',
        'preferred_contact_method', 'receive_emails', 'receive_sms', 'marketing_opt_in' => 'communication',
        'booking_alert', 'check_in_alert', 'check_out_alert' => 'alerts',
        'referral_information', 'referral_history', 'referred_by', 'customer_origin' => 'referral',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship' => 'emergency',
        'inactive_flag', 'notes' => 'status_notes',
        default => null,
    };
};

$sectionLabels = $detailsSectionLabels();

if (!$useDetailsSections) {
    echo '<div class="client-ref-details-layout-grid">';
    $detailsLayoutGridOpen = true;
}

foreach ($detailsLayoutRows as $rowEntry) {
    $layoutKey = trim((string) ($rowEntry['field_key'] ?? ''));
    if ($layoutKey === '') {
        continue;
    }
    $layoutSpan = (int) ($rowEntry['layout_span'] ?? 3);
    if ($layoutSpan < 1 || $layoutSpan > 3) {
        $layoutSpan = 3;
    }

    $customId = $fieldCatalog->parseCustomFieldId($layoutKey);
    $nextSection = null;
    if ($useDetailsSections) {
        if ($customId !== null) {
            $defProbe = $defsById[$customId] ?? null;
            if ($defProbe === null) {
                continue;
            }
            $nextSection = 'custom_fields';
        } else {
            $nextSection = $resolveSystemDetailsSection($layoutKey);
        }
    }

    if ($useDetailsSections && $nextSection !== null && $nextSection !== $detailsPrevSection) {
        if ($detailsLayoutGridOpen) {
            echo '</div>';
            $detailsLayoutGridOpen = false;
        }
        if ($detailsSectionOpen) {
            echo '</div>';
            $detailsSectionOpen = false;
        }
        $sl = $sectionLabels[$nextSection] ?? $nextSection;
        echo '<div class="client-ref-details-field-group" data-section="' . htmlspecialchars($nextSection) . '">';
        echo '<h3 class="client-ref-details-field-group-title">' . htmlspecialchars($sl) . '</h3>';
        echo '<div class="client-ref-details-layout-grid">';
        $detailsLayoutGridOpen = true;
        $detailsSectionOpen = true;
        $detailsPrevSection = $nextSection;
    }

    if ($useDetailsSections && !$detailsLayoutGridOpen) {
        echo '<div class="client-ref-details-layout-grid">';
        $detailsLayoutGridOpen = true;
    }

    if ($customId !== null) {
        $def = $defsById[$customId] ?? null;
        if ($def === null) {
            continue;
        }
        $fid = (int) $def['id'];
        $fkey = 'custom_fields[' . $fid . ']';
        $fval = $customFieldValues[$fid] ?? '';
        $ft = (string) ($def['field_type'] ?? 'text');
        $cfFull = $ft === 'textarea' || $ft === 'address' || $ft === 'multiselect' || $ft === 'boolean'
            || ($ft === 'select' && !empty($def['options_json']));
        $cellSpan = $cfFull ? 6 : $detailsLayoutSpanToGridUnits($layoutSpan);
        echo '<div class="client-ref-hig-cell" style="grid-column: span ' . (int) $cellSpan . '">';
        ?>
        <div class="form-row client-ref-hig-field<?= $cfFull ? ' client-ref-hig-field--full' : '' ?>">
            <label for="cf_<?= $fid ?>"><?= htmlspecialchars((string) $def['label']) ?><?= (int) ($def['is_required'] ?? 0) === 1 ? ' *' : '' ?></label>
            <?php if ($ft === 'textarea' || $ft === 'address'): ?>
            <textarea id="cf_<?= $fid ?>" name="<?= htmlspecialchars($fkey) ?>" rows="3"><?= htmlspecialchars((string) $fval) ?></textarea>
            <?php elseif ($ft === 'boolean'): ?>
            <input type="hidden" name="<?= htmlspecialchars($fkey) ?>" value="0">
            <label><input type="checkbox" name="<?= htmlspecialchars($fkey) ?>" value="1" <?= ((string) $fval === '1' || $fval === true || (int) $fval === 1) ? 'checked' : '' ?>> Yes</label>
            <?php elseif ($ft === 'select' && !empty($def['options_json'])): ?>
            <?php
            $opts = json_decode((string) $def['options_json'], true);
            $opts = is_array($opts) ? $opts : [];
            ?>
            <select id="cf_<?= $fid ?>" name="<?= htmlspecialchars($fkey) ?>">
                <option value="">—</option>
                <?php foreach ($opts as $opt): ?>
                <?php $os = is_scalar($opt) ? (string) $opt : ''; ?>
                <option value="<?= htmlspecialchars($os) ?>" <?= ((string) $fval === $os) ? 'selected' : '' ?>><?= htmlspecialchars($os) ?></option>
                <?php endforeach; ?>
            </select>
            <?php elseif ($ft === 'multiselect'): ?>
            <textarea id="cf_<?= $fid ?>" name="<?= htmlspecialchars($fkey) ?>" rows="2" placeholder="One value per line"><?= htmlspecialchars((string) $fval) ?></textarea>
            <?php else: ?>
            <input id="cf_<?= $fid ?>" type="<?= $ft === 'date' ? 'date' : ($ft === 'number' ? 'number' : ($ft === 'email' ? 'email' : 'text')) ?>" name="<?= htmlspecialchars($fkey) ?>" value="<?= htmlspecialchars((string) $fval) ?>">
            <?php endif; ?>
            <?php if (!empty($errors['custom_field_' . $fid])): ?><span class="error"><?= htmlspecialchars((string) $errors['custom_field_' . $fid]) ?></span><?php endif; ?>
        </div>
        <?php
        echo '</div>';
        continue;
    }

    $systemCellSpan = in_array($layoutKey, $detailsLayoutForceFullWidthKeys, true)
        ? 6
        : $detailsLayoutSpanToGridUnits($layoutSpan);
    echo '<div class="client-ref-hig-cell" style="grid-column: span ' . (int) $systemCellSpan . '">';

    switch ($layoutKey) {
        case 'phone_contact_block':
            require base_path('modules/clients/views/partials/client-form-phone-fields.php');
            break;
        case 'home_address_block':
            require base_path('modules/clients/views/partials/client-form-home-address-fields.php');
            break;
        case 'delivery_block':
            require base_path('modules/clients/views/partials/client-form-delivery-fields.php');
            break;
        case 'first_name':
            ?>
            <div class="form-row client-ref-hig-field">
                <label for="first_name">First name *</label>
                <input type="text" id="first_name" name="first_name" required maxlength="100" value="<?= htmlspecialchars((string) ($client['first_name'] ?? '')) ?>" autocomplete="given-name">
                <?php if ($err('first_name') !== ''): ?><span class="error"><?= htmlspecialchars($err('first_name')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'last_name':
            ?>
            <div class="form-row client-ref-hig-field">
                <label for="last_name">Last name</label>
                <input type="text" id="last_name" name="last_name" maxlength="100" value="<?= htmlspecialchars((string) ($client['last_name'] ?? '')) ?>" autocomplete="family-name">
                <?php if ($err('last_name') !== ''): ?><span class="error"><?= htmlspecialchars($err('last_name')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'email':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="255" value="<?= htmlspecialchars((string) ($client['email'] ?? '')) ?>" autocomplete="email">
            </div>
            <?php
            break;
        case 'birth_date':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="birth_date">Birth date</label>
                <input type="date" id="birth_date" name="birth_date" value="<?= htmlspecialchars((string) ($client['birth_date'] ?? '')) ?>">
            </div>
            <?php
            break;
        case 'anniversary':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="anniversary">Important date / anniversary</label>
                <input type="date" id="anniversary" name="anniversary" value="<?= htmlspecialchars((string) ($client['anniversary'] ?? '')) ?>">
            </div>
            <?php
            break;
        case 'occupation':
            ?>
            <div class="form-row client-ref-hig-field">
                <label for="occupation">Occupation</label>
                <input type="text" id="occupation" name="occupation" maxlength="200" value="<?= htmlspecialchars((string) ($client['occupation'] ?? '')) ?>">
                <?php if ($err('occupation') !== ''): ?><span class="error"><?= htmlspecialchars($err('occupation')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'gender':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="gender">Gender</label>
                <select id="gender" name="gender">
                    <option value="">—</option>
                    <option value="male" <?= ($client['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                    <option value="female" <?= ($client['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                    <option value="other" <?= ($client['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
            <?php
            break;
        case 'language':
            ?>
            <div class="form-row client-ref-hig-field">
                <label for="language">Language</label>
                <input type="text" id="language" name="language" maxlength="50" value="<?= htmlspecialchars((string) ($client['language'] ?? '')) ?>">
                <?php if ($err('language') !== ''): ?><span class="error"><?= htmlspecialchars($err('language')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'preferred_contact_method':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="preferred_contact_method">Preferred contact method</label>
                <select id="preferred_contact_method" name="preferred_contact_method">
                    <option value="">—</option>
                    <option value="phone" <?= ($client['preferred_contact_method'] ?? '') === 'phone' ? 'selected' : '' ?>>Phone</option>
                    <option value="email" <?= ($client['preferred_contact_method'] ?? '') === 'email' ? 'selected' : '' ?>>Email</option>
                    <option value="sms" <?= ($client['preferred_contact_method'] ?? '') === 'sms' ? 'selected' : '' ?>>SMS</option>
                </select>
                <?php if ($err('preferred_contact_method') !== ''): ?><span class="error"><?= htmlspecialchars($err('preferred_contact_method')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'receive_emails':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full client-ref-hig-checkbox-row">
                <input type="hidden" name="receive_emails" value="0">
                <label class="client-ref-hig-checkbox-label"><input type="checkbox" name="receive_emails" value="1" <?= (int) ($client['receive_emails'] ?? 0) === 1 ? 'checked' : '' ?>> Receive emails (transactional)</label>
            </div>
            <?php
            break;
        case 'receive_sms':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full client-ref-hig-checkbox-row">
                <input type="hidden" name="receive_sms" value="0">
                <label class="client-ref-hig-checkbox-label"><input type="checkbox" name="receive_sms" value="1" <?= (int) ($client['receive_sms'] ?? 0) === 1 ? 'checked' : '' ?>> Receive SMS</label>
            </div>
            <?php
            break;
        case 'marketing_opt_in':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full client-ref-hig-checkbox-row">
                <input type="hidden" name="marketing_opt_in" value="0">
                <label class="client-ref-hig-checkbox-label">
                    <input type="checkbox" name="marketing_opt_in" value="1" <?= (int) ($client['marketing_opt_in'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <?= htmlspecialchars($marketing['consent_label'] ?? 'Marketing communications') ?>
                </label>
            </div>
            <?php
            break;
        case 'booking_alert':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="booking_alert">Booking alert</label>
                <textarea id="booking_alert" name="booking_alert" rows="2" maxlength="500"><?= htmlspecialchars((string) ($client['booking_alert'] ?? '')) ?></textarea>
                <?php if ($err('booking_alert') !== ''): ?><span class="error"><?= htmlspecialchars($err('booking_alert')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'check_in_alert':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="check_in_alert">Check-in alert</label>
                <textarea id="check_in_alert" name="check_in_alert" rows="2" maxlength="500"><?= htmlspecialchars((string) ($client['check_in_alert'] ?? '')) ?></textarea>
                <?php if ($err('check_in_alert') !== ''): ?><span class="error"><?= htmlspecialchars($err('check_in_alert')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'check_out_alert':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="check_out_alert">Check-out alert</label>
                <textarea id="check_out_alert" name="check_out_alert" rows="2" maxlength="500"><?= htmlspecialchars((string) ($client['check_out_alert'] ?? '')) ?></textarea>
                <?php if ($err('check_out_alert') !== ''): ?><span class="error"><?= htmlspecialchars($err('check_out_alert')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'referral_information':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="referral_information">Referral information</label>
                <textarea id="referral_information" name="referral_information" rows="3"><?= htmlspecialchars((string) ($client['referral_information'] ?? '')) ?></textarea>
            </div>
            <?php
            break;
        case 'referral_history':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="referral_history">Referral history</label>
                <textarea id="referral_history" name="referral_history" rows="3"><?= htmlspecialchars((string) ($client['referral_history'] ?? '')) ?></textarea>
            </div>
            <?php
            break;
        case 'referred_by':
            ?>
            <div class="form-row client-ref-hig-field">
                <label for="referred_by">Referred by</label>
                <input type="text" id="referred_by" name="referred_by" maxlength="200" value="<?= htmlspecialchars((string) ($client['referred_by'] ?? '')) ?>">
                <?php if ($err('referred_by') !== ''): ?><span class="error"><?= htmlspecialchars($err('referred_by')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'customer_origin':
            ?>
            <div class="form-row client-ref-hig-field">
                <label for="customer_origin">Customer origin</label>
                <input type="text" id="customer_origin" name="customer_origin" maxlength="120" value="<?= htmlspecialchars((string) ($client['customer_origin'] ?? '')) ?>">
                <?php if ($err('customer_origin') !== ''): ?><span class="error"><?= htmlspecialchars($err('customer_origin')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'emergency_contact_name':
            ?>
            <div class="form-row client-ref-hig-field">
                <label for="emergency_contact_name">Emergency contact name</label>
                <input type="text" id="emergency_contact_name" name="emergency_contact_name" maxlength="200" value="<?= htmlspecialchars((string) ($client['emergency_contact_name'] ?? '')) ?>">
                <?php if ($err('emergency_contact_name') !== ''): ?><span class="error"><?= htmlspecialchars($err('emergency_contact_name')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'emergency_contact_phone':
            ?>
            <div class="form-row client-ref-hig-field">
                <label for="emergency_contact_phone">Emergency contact phone</label>
                <input type="text" id="emergency_contact_phone" name="emergency_contact_phone" maxlength="50" value="<?= htmlspecialchars((string) ($client['emergency_contact_phone'] ?? '')) ?>">
                <?php if ($err('emergency_contact_phone') !== ''): ?><span class="error"><?= htmlspecialchars($err('emergency_contact_phone')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'emergency_contact_relationship':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="emergency_contact_relationship">Emergency contact relationship</label>
                <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" maxlength="120" value="<?= htmlspecialchars((string) ($client['emergency_contact_relationship'] ?? '')) ?>">
                <?php if ($err('emergency_contact_relationship') !== ''): ?><span class="error"><?= htmlspecialchars($err('emergency_contact_relationship')) ?></span><?php endif; ?>
            </div>
            <?php
            break;
        case 'inactive_flag':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full client-ref-hig-checkbox-row">
                <input type="hidden" name="inactive_flag" value="0">
                <label class="client-ref-hig-checkbox-label"><input type="checkbox" name="inactive_flag" value="1" <?= (int) ($client['inactive_flag'] ?? 0) === 1 ? 'checked' : '' ?>> Inactive</label>
            </div>
            <?php
            break;
        case 'notes':
            ?>
            <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                <label for="notes">Notes</label>
                <textarea id="notes" class="client-ref-hig-autosize" name="notes" rows="1"><?= htmlspecialchars((string) ($client['notes'] ?? '')) ?></textarea>
            </div>
            <?php
            break;
        case 'summary_primary_phone':
            ?>
            <p class="hint client-ref-hig-field client-ref-hig-field--full">Primary phone is derived from mobile, home, work, and legacy phone fields (read-only).</p>
            <?php
            break;
        default:
            break;
    }

    echo '</div>';
}

if (!$useDetailsSections && $detailsLayoutGridOpen) {
    echo '</div>';
    $detailsLayoutGridOpen = false;
}

if ($useDetailsSections) {
    if ($detailsLayoutGridOpen) {
        echo '</div>';
        $detailsLayoutGridOpen = false;
    }
    if ($detailsSectionOpen) {
        echo '</div>';
        $detailsSectionOpen = false;
    }
}
