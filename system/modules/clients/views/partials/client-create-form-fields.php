<?php
declare(strict_types=1);

/** @var array<string, mixed> $client */
/** @var array<string, mixed> $errors */
/** @var array<string, mixed> $marketing */
/** @var list<array<string, mixed>> $customFieldDefinitions */
/** @var array<int, string|null> $customFieldValues */
$err = static function (string $key) use ($errors): string {
    return !empty($errors[$key]) ? (string) $errors[$key] : '';
};

$v = static function (string $key) use ($client): string {
    return htmlspecialchars((string) ($client[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};

$isRedisplay = !empty($errors);
$receiveEmailsChecked = $isRedisplay ? ((int) ($client['receive_emails'] ?? 0) === 1) : true;
$receiveSmsChecked = $isRedisplay ? ((int) ($client['receive_sms'] ?? 0) === 1) : true;
$marketingOptInChecked = (int) ($client['marketing_opt_in'] ?? 0) === 1;
$needsDeliveryChecked = $isRedisplay && (int) ($client['needs_delivery'] ?? 0) === 1;
$isDrawer = !empty($isDrawer);
?>

<?php if (!$isDrawer): ?>
<div class="client-create-shell">
<?php endif; ?>
<div class="staff-create-section<?= $isDrawer ? '' : ' client-create-section' ?>" role="region" aria-labelledby="client-create-essential-heading">
    <h3 id="client-create-essential-heading" class="staff-create-section__title">Essential details</h3>
    <p class="staff-create-hint<?= $isDrawer ? '' : ' client-create-lead' ?>" id="client-create-essential-hint">Start with name and mobile; everything else is optional.</p>
    <div class="staff-create-row-2">
        <div class="staff-create-field <?= $err('first_name') !== '' ? 'staff-create-field--error' : '' ?>">
            <label for="first_name" class="staff-create-label staff-create-label--required">First name</label>
            <input type="text" id="first_name" name="first_name" class="staff-create-input" required maxlength="100" autocomplete="given-name" value="<?= $v('first_name') ?>" aria-required="true"<?= $err('first_name') !== '' ? ' aria-invalid="true" aria-describedby="err_first_name"' : '' ?>>
            <?php if ($err('first_name') !== ''): ?><span class="form-field-error" id="err_first_name"><?= htmlspecialchars($err('first_name'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        </div>
        <div class="staff-create-field <?= $err('last_name') !== '' ? 'staff-create-field--error' : '' ?>">
            <label for="last_name" class="staff-create-label">Last name</label>
            <input type="text" id="last_name" name="last_name" class="staff-create-input" maxlength="100" autocomplete="family-name" value="<?= $v('last_name') ?>"<?= $err('last_name') !== '' ? ' aria-invalid="true" aria-describedby="err_last_name"' : '' ?>>
            <?php if ($err('last_name') !== ''): ?><span class="form-field-error" id="err_last_name"><?= htmlspecialchars($err('last_name'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        </div>
    </div>
    <div class="staff-create-field client-create-phone-field <?= $err('phone_mobile') !== '' ? 'staff-create-field--error' : '' ?>">
        <label for="phone_mobile" class="staff-create-label staff-create-label--required">Mobile phone</label>
        <input
            type="tel"
            id="phone_mobile"
            name="phone_mobile"
            class="staff-create-input js-client-phone-dedupe-input"
            required
            maxlength="50"
            autocomplete="tel"
            inputmode="tel"
            value="<?= $v('phone_mobile') ?>"
            aria-required="true"
            data-phone-check-url="/clients/phone-exists"
            <?= $err('phone_mobile') !== '' ? 'aria-invalid="true" aria-describedby="err_phone_mobile"' : '' ?>
        >
        <div class="client-create-phone-dedupe-hint" id="client-phone-dedupe-hint" hidden aria-live="polite"></div>
        <?php if ($err('phone_mobile') !== ''): ?><span class="form-field-error" id="err_phone_mobile"><?= htmlspecialchars($err('phone_mobile'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
    </div>

    <div class="client-create-delivery-block">
        <input type="hidden" name="add_delivery" value="0">
        <div class="client-create-delivery-toggle-row">
            <label class="staff-create-checkbox client-create-delivery-toggle">
                <input
                    type="checkbox"
                    name="add_delivery"
                    value="1"
                    class="js-client-create-add-delivery"
                    id="add_delivery"
                    <?= $needsDeliveryChecked ? 'checked' : '' ?>
                    aria-controls="client-create-delivery-region"
                    aria-expanded="<?= $needsDeliveryChecked ? 'true' : 'false' ?>"
                >
                <span class="client-create-delivery-toggle-label">Add delivery address</span>
            </label>
            <span class="client-create-delivery-hint" id="client-create-delivery-hint">Shop / product shipping</span>
        </div>
        <div
            class="client-create-delivery-reveal<?= $needsDeliveryChecked ? ' client-create-delivery-reveal--open' : '' ?>"
            id="client-create-delivery-reveal"
        >
            <div class="client-create-delivery-reveal__measure">
                <div
                    class="client-create-delivery-reveal__inner"
                    id="client-create-delivery-region"
                    role="region"
                    aria-labelledby="client-create-delivery-heading"
                    <?= $needsDeliveryChecked ? '' : ' inert' ?>
                >
                    <h4 class="client-create-delivery-heading" id="client-create-delivery-heading">Delivery details</h4>
                    <div class="staff-create-field <?= $err('delivery_address_1') !== '' ? 'staff-create-field--error' : '' ?>">
                        <label for="delivery_address_1" class="staff-create-label staff-create-label--required">Delivery address line 1</label>
                        <input
                            type="text"
                            id="delivery_address_1"
                            name="delivery_address_1"
                            class="staff-create-input"
                            maxlength="255"
                            autocomplete="shipping address-line1"
                            data-client-delivery-field
                            value="<?= $v('delivery_address_1') ?>"
                            <?= $needsDeliveryChecked ? 'required' : '' ?>
                            <?= $err('delivery_address_1') !== '' ? ' aria-invalid="true" aria-describedby="err_delivery_address_1"' : '' ?>
                        >
                        <?php if ($err('delivery_address_1') !== ''): ?><span class="form-field-error" id="err_delivery_address_1"><?= htmlspecialchars($err('delivery_address_1'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    </div>
                    <div class="staff-create-field <?= $err('delivery_address_2') !== '' ? 'staff-create-field--error' : '' ?>">
                        <label for="delivery_address_2" class="staff-create-label">Delivery address line 2 <span class="client-create-optional">(optional)</span></label>
                        <input
                            type="text"
                            id="delivery_address_2"
                            name="delivery_address_2"
                            class="staff-create-input"
                            maxlength="255"
                            autocomplete="shipping address-line2"
                            data-client-delivery-field
                            value="<?= $v('delivery_address_2') ?>"
                            <?= $err('delivery_address_2') !== '' ? ' aria-invalid="true" aria-describedby="err_delivery_address_2"' : '' ?>
                        >
                        <?php if ($err('delivery_address_2') !== ''): ?><span class="form-field-error" id="err_delivery_address_2"><?= htmlspecialchars($err('delivery_address_2'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    </div>
                    <div class="staff-create-row-2">
                        <div class="staff-create-field <?= $err('delivery_city') !== '' ? 'staff-create-field--error' : '' ?>">
                            <label for="delivery_city" class="staff-create-label">City</label>
                            <input
                                type="text"
                                id="delivery_city"
                                name="delivery_city"
                                class="staff-create-input"
                                maxlength="120"
                                autocomplete="address-level2"
                                data-client-delivery-field
                                value="<?= $v('delivery_city') ?>"
                                <?= $err('delivery_city') !== '' ? ' aria-invalid="true" aria-describedby="err_delivery_city"' : '' ?>
                            >
                            <?php if ($err('delivery_city') !== ''): ?><span class="form-field-error" id="err_delivery_city"><?= htmlspecialchars($err('delivery_city'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="staff-create-field <?= $err('delivery_postal_code') !== '' ? 'staff-create-field--error' : '' ?>">
                            <label for="delivery_postal_code" class="staff-create-label">Postal code</label>
                            <input
                                type="text"
                                id="delivery_postal_code"
                                name="delivery_postal_code"
                                class="staff-create-input"
                                maxlength="32"
                                autocomplete="postal-code"
                                data-client-delivery-field
                                value="<?= $v('delivery_postal_code') ?>"
                                <?= $err('delivery_postal_code') !== '' ? ' aria-invalid="true" aria-describedby="err_delivery_postal_code"' : '' ?>
                            >
                            <?php if ($err('delivery_postal_code') !== ''): ?><span class="form-field-error" id="err_delivery_postal_code"><?= htmlspecialchars($err('delivery_postal_code'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="staff-create-section<?= $isDrawer ? '' : ' client-create-section' ?>" role="region" aria-labelledby="client-create-personal-heading">
    <h3 id="client-create-personal-heading" class="staff-create-section__title">Personal &amp; marketing</h3>
    <div class="staff-create-row-2">
        <div class="staff-create-field <?= $err('email') !== '' ? 'staff-create-field--error' : '' ?>">
            <label for="email" class="staff-create-label">Email</label>
            <input type="email" id="email" name="email" class="staff-create-input" maxlength="255" autocomplete="email" value="<?= $v('email') ?>"<?= $err('email') !== '' ? ' aria-invalid="true" aria-describedby="err_email"' : '' ?>>
            <?php if ($err('email') !== ''): ?><span class="form-field-error" id="err_email"><?= htmlspecialchars($err('email'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        </div>
        <div class="staff-create-field <?= $err('birth_date') !== '' ? 'staff-create-field--error' : '' ?>">
            <label for="birth_date" class="staff-create-label">Birth date</label>
            <input type="date" id="birth_date" name="birth_date" class="staff-create-input" autocomplete="bday" value="<?= $v('birth_date') ?>"<?= $err('birth_date') !== '' ? ' aria-invalid="true" aria-describedby="err_birth_date"' : '' ?>>
            <?php if ($err('birth_date') !== ''): ?><span class="form-field-error" id="err_birth_date"><?= htmlspecialchars($err('birth_date'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        </div>
    </div>
    <div class="staff-create-row-2">
        <div class="staff-create-field <?= $err('gender') !== '' ? 'staff-create-field--error' : '' ?>">
            <label for="gender" class="staff-create-label">Gender</label>
            <select id="gender" name="gender" class="staff-create-select"<?= $err('gender') !== '' ? ' aria-invalid="true" aria-describedby="err_gender"' : '' ?>>
                <option value="">Prefer not to say</option>
                <option value="male" <?= ($client['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= ($client['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="other" <?= ($client['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
            <?php if ($err('gender') !== ''): ?><span class="form-field-error" id="err_gender"><?= htmlspecialchars($err('gender'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        </div>
        <div class="staff-create-field <?= $err('language') !== '' ? 'staff-create-field--error' : '' ?>">
            <label for="language" class="staff-create-label">Language</label>
            <input type="text" id="language" name="language" class="staff-create-input" maxlength="50" autocomplete="language" value="<?= $v('language') ?>" placeholder="e.g. English, Français"<?= $err('language') !== '' ? ' aria-invalid="true" aria-describedby="err_language"' : '' ?>>
            <?php if ($err('language') !== ''): ?><span class="form-field-error" id="err_language"><?= htmlspecialchars($err('language'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        </div>
    </div>
</div>

<div class="staff-create-section<?= $isDrawer ? '' : ' client-create-section' ?>" role="region" aria-labelledby="client-create-prefs-heading">
    <h3 id="client-create-prefs-heading" class="staff-create-section__title">Notes &amp; preferences</h3>
    <div class="staff-create-field <?= $err('notes') !== '' ? 'staff-create-field--error' : '' ?>">
        <label for="notes" class="staff-create-label">Client notes</label>
        <textarea id="notes" name="notes" class="staff-create-textarea staff-create-textarea--notes" rows="5" placeholder="Allergies, preferences, reminders for bookings…"<?= $err('notes') !== '' ? ' aria-invalid="true" aria-describedby="err_notes"' : '' ?>><?= $v('notes') ?></textarea>
        <?php if ($err('notes') !== ''): ?><span class="form-field-error" id="err_notes"><?= htmlspecialchars($err('notes'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
    </div>
    <div class="staff-create-field <?= $err('referred_by') !== '' ? 'staff-create-field--error' : '' ?>">
        <label for="referred_by" class="staff-create-label">Referred by</label>
        <input type="text" id="referred_by" name="referred_by" class="staff-create-input" maxlength="200" autocomplete="off" value="<?= $v('referred_by') ?>" placeholder="Name or source"<?= $err('referred_by') !== '' ? ' aria-invalid="true" aria-describedby="err_referred_by"' : '' ?>>
        <?php if ($err('referred_by') !== ''): ?><span class="form-field-error" id="err_referred_by"><?= htmlspecialchars($err('referred_by'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
    </div>
    <?php if ($isDrawer): ?>
    <div class="staff-create-field" role="group" aria-labelledby="client-create-comm-prefs-label">
        <div id="client-create-comm-prefs-label" class="staff-create-label">Communication preferences</div>
        <input type="hidden" name="receive_emails" value="0">
        <label class="staff-create-checkbox">
            <input type="checkbox" name="receive_emails" value="1" <?= $receiveEmailsChecked ? 'checked' : '' ?>>
            <span>Receive emails (transactional)</span>
        </label>
        <input type="hidden" name="receive_sms" value="0">
        <label class="staff-create-checkbox">
            <input type="checkbox" name="receive_sms" value="1" <?= $receiveSmsChecked ? 'checked' : '' ?>>
            <span>Receive SMS</span>
        </label>
        <input type="hidden" name="marketing_opt_in" value="0">
        <label class="staff-create-checkbox">
            <input type="checkbox" name="marketing_opt_in" value="1" <?= $marketingOptInChecked ? 'checked' : '' ?>>
            <span><?= htmlspecialchars((string) ($marketing['consent_label'] ?? 'Marketing communications'), ENT_QUOTES, 'UTF-8') ?></span>
        </label>
    </div>
    <?php else: ?>
    <div class="client-create-comm-card" role="group" aria-labelledby="client-create-comm-prefs-label">
        <div id="client-create-comm-prefs-label" class="client-create-comm-card__title">Communication</div>
        <input type="hidden" name="receive_emails" value="0">
        <label class="staff-create-checkbox client-create-comm-row">
            <input type="checkbox" name="receive_emails" value="1" <?= $receiveEmailsChecked ? 'checked' : '' ?>>
            <span>Receive emails (transactional)</span>
        </label>
        <input type="hidden" name="receive_sms" value="0">
        <label class="staff-create-checkbox client-create-comm-row">
            <input type="checkbox" name="receive_sms" value="1" <?= $receiveSmsChecked ? 'checked' : '' ?>>
            <span>Receive SMS</span>
        </label>
        <input type="hidden" name="marketing_opt_in" value="0">
        <label class="staff-create-checkbox client-create-comm-row">
            <input type="checkbox" name="marketing_opt_in" value="1" <?= $marketingOptInChecked ? 'checked' : '' ?>>
            <span><?= htmlspecialchars((string) ($marketing['consent_label'] ?? 'Marketing communications'), ENT_QUOTES, 'UTF-8') ?></span>
        </label>
    </div>
    <?php endif; ?>
    <?php
    if ($customFieldDefinitions !== []) {
        if ($isDrawer) {
            echo '<h3 class="staff-create-section__title client-create-custom-fields-drawer" id="client-create-custom-heading">Custom fields</h3>';
        } else {
            echo '<h4 class="client-create-custom-heading" id="client-create-custom-heading">Custom fields</h4>';
        }
    }
    foreach ($customFieldDefinitions as $def) {
        $fid = (int) $def['id'];
        $fkey = 'custom_fields[' . $fid . ']';
        $fval = $customFieldValues[$fid] ?? $customFieldValues[(string) $fid] ?? '';
        $ft = (string) ($def['field_type'] ?? 'text');
        $req = (int) ($def['is_required'] ?? 0) === 1;
        $ek = 'custom_field_' . $fid;
        $hasCfErr = !empty($errors[$ek]);
        ?>
        <div class="staff-create-field <?= $hasCfErr ? 'staff-create-field--error' : '' ?>">
            <label for="cf_<?= $fid ?>" class="staff-create-label<?= $req ? ' staff-create-label--required' : '' ?>"><?= htmlspecialchars((string) $def['label'], ENT_QUOTES, 'UTF-8') ?></label>
            <?php if ($ft === 'textarea' || $ft === 'address'): ?>
            <textarea id="cf_<?= $fid ?>" name="<?= htmlspecialchars($fkey, ENT_QUOTES, 'UTF-8') ?>" class="staff-create-textarea" rows="3"<?= $req ? ' required' : '' ?><?= $hasCfErr ? ' aria-invalid="true"' : '' ?>><?= htmlspecialchars((string) $fval, ENT_QUOTES, 'UTF-8') ?></textarea>
            <?php elseif ($ft === 'boolean'): ?>
            <input type="hidden" name="<?= htmlspecialchars($fkey, ENT_QUOTES, 'UTF-8') ?>" value="0">
            <label class="staff-create-checkbox"><input type="checkbox" name="<?= htmlspecialchars($fkey, ENT_QUOTES, 'UTF-8') ?>" value="1" <?= ((string) $fval === '1' || $fval === true || (int) $fval === 1) ? 'checked' : '' ?>> Yes</label>
            <?php elseif ($ft === 'select' && !empty($def['options_json'])): ?>
            <?php
            $opts = json_decode((string) $def['options_json'], true);
            $opts = is_array($opts) ? $opts : [];
            ?>
            <select id="cf_<?= $fid ?>" name="<?= htmlspecialchars($fkey, ENT_QUOTES, 'UTF-8') ?>" class="staff-create-select"<?= $req ? ' required' : '' ?><?= $hasCfErr ? ' aria-invalid="true"' : '' ?>>
                <option value="">—</option>
                <?php foreach ($opts as $opt): ?>
                <?php $os = is_scalar($opt) ? (string) $opt : ''; ?>
                <option value="<?= htmlspecialchars($os, ENT_QUOTES, 'UTF-8') ?>" <?= ((string) $fval === $os) ? 'selected' : '' ?>><?= htmlspecialchars($os, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <?php elseif ($ft === 'multiselect'): ?>
            <textarea id="cf_<?= $fid ?>" name="<?= htmlspecialchars($fkey, ENT_QUOTES, 'UTF-8') ?>" class="staff-create-textarea" rows="2" placeholder="One value per line"<?= $req ? ' required' : '' ?><?= $hasCfErr ? ' aria-invalid="true"' : '' ?>><?= htmlspecialchars((string) $fval, ENT_QUOTES, 'UTF-8') ?></textarea>
            <?php else: ?>
            <input id="cf_<?= $fid ?>" class="staff-create-input" type="<?= $ft === 'date' ? 'date' : ($ft === 'number' ? 'number' : ($ft === 'email' ? 'email' : 'text')) ?>" name="<?= htmlspecialchars($fkey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $fval, ENT_QUOTES, 'UTF-8') ?>"<?= $req ? ' required' : '' ?><?= $hasCfErr ? ' aria-invalid="true"' : '' ?>>
            <?php endif; ?>
            <?php if ($hasCfErr): ?><span class="form-field-error"><?= htmlspecialchars((string) $errors[$ek], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        </div>
        <?php
    }
    ?>
</div>
<?php if (!$isDrawer): ?>
</div>
<?php endif; ?>
