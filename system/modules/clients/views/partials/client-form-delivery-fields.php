<?php
/** @var array $client */
/** @var array $errors */
$err = static function (string $key) use ($errors): string {
    return !empty($errors[$key]) ? (string) $errors[$key] : '';
};
$v = static function (string $key) use ($client): string {
    return htmlspecialchars((string) ($client[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
$sameHome = (int) ($client['delivery_same_as_home'] ?? 0) === 1;
?>
<div class="client-ref-delivery-block client-ref-hig-field client-ref-hig-field--full client-ref-hig-delivery-panel" data-cr-delivery-block>
    <input type="hidden" name="delivery_same_as_home" value="0">
    <p class="client-ref-hig-panel-heading">Delivery address</p>
    <div class="client-ref-delivery-switch-row">
        <span class="client-ref-delivery-switch-label">Same as home address</span>
        <label class="client-ref-ios-switch">
            <input type="checkbox" name="delivery_same_as_home" value="1" data-cr-delivery-toggle<?= $sameHome ? ' checked' : '' ?> aria-checked="<?= $sameHome ? 'true' : 'false' ?>">
            <span class="client-ref-ios-switch-ui" aria-hidden="true"></span>
        </label>
    </div>
    <div class="client-ref-delivery-expand<?= $sameHome ? ' client-ref-delivery-expand--collapsed' : '' ?>" data-cr-delivery-fields>
        <div class="client-ref-delivery-expand-inner">
            <div class="client-ref-hig-panel-grid client-ref-hig-panel-grid--delivery">
                <div class="form-row client-ref-hig-field">
                    <label for="delivery_address_1">Line 1</label>
                    <input type="text" id="delivery_address_1" name="delivery_address_1" maxlength="255" value="<?= $v('delivery_address_1') ?>" autocomplete="shipping address-line1">
                    <?php if ($err('delivery_address_1') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_address_1')) ?></span><?php endif; ?>
                </div>
                <div class="form-row client-ref-hig-field">
                    <label for="delivery_address_2">Line 2</label>
                    <input type="text" id="delivery_address_2" name="delivery_address_2" maxlength="255" value="<?= $v('delivery_address_2') ?>" autocomplete="shipping address-line2">
                    <?php if ($err('delivery_address_2') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_address_2')) ?></span><?php endif; ?>
                </div>
                <div class="form-row client-ref-hig-field">
                    <label for="delivery_city">City</label>
                    <input type="text" id="delivery_city" name="delivery_city" maxlength="120" value="<?= $v('delivery_city') ?>" autocomplete="shipping address-level2">
                    <?php if ($err('delivery_city') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_city')) ?></span><?php endif; ?>
                </div>
                <div class="form-row client-ref-hig-field">
                    <label for="delivery_postal_code">Postal code</label>
                    <input type="text" id="delivery_postal_code" name="delivery_postal_code" maxlength="32" value="<?= $v('delivery_postal_code') ?>" autocomplete="shipping postal-code">
                    <?php if ($err('delivery_postal_code') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_postal_code')) ?></span><?php endif; ?>
                </div>
                <div class="form-row client-ref-hig-field client-ref-hig-field--full">
                    <label for="delivery_country">Country</label>
                    <input type="text" id="delivery_country" name="delivery_country" maxlength="100" value="<?= $v('delivery_country') ?>" autocomplete="shipping country">
                    <?php if ($err('delivery_country') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_country')) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
