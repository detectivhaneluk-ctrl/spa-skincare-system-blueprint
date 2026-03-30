<?php
/** @var array $client */
/** @var array $errors */
$err = static function (string $key) use ($errors): string {
    return !empty($errors[$key]) ? (string) $errors[$key] : '';
};
$v = static function (string $key) use ($client): string {
    return htmlspecialchars((string) ($client[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
<h2 class="client-ref-block-title">Delivery address</h2>
<div class="form-row">
    <input type="hidden" name="delivery_same_as_home" value="0">
    <label>
        <input type="checkbox" name="delivery_same_as_home" value="1" <?= (int) ($client['delivery_same_as_home'] ?? 0) === 1 ? 'checked' : '' ?>>
        Same as home address
    </label>
</div>
<div class="form-row">
    <label for="delivery_address_1">Delivery address line 1</label>
    <input type="text" id="delivery_address_1" name="delivery_address_1" maxlength="255" value="<?= $v('delivery_address_1') ?>">
    <?php if ($err('delivery_address_1') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_address_1')) ?></span><?php endif; ?>
</div>
<div class="form-row">
    <label for="delivery_address_2">Delivery address line 2</label>
    <input type="text" id="delivery_address_2" name="delivery_address_2" maxlength="255" value="<?= $v('delivery_address_2') ?>">
    <?php if ($err('delivery_address_2') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_address_2')) ?></span><?php endif; ?>
</div>
<div class="form-row">
    <label for="delivery_city">Delivery city</label>
    <input type="text" id="delivery_city" name="delivery_city" maxlength="120" value="<?= $v('delivery_city') ?>">
    <?php if ($err('delivery_city') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_city')) ?></span><?php endif; ?>
</div>
<div class="form-row">
    <label for="delivery_postal_code">Delivery postal code</label>
    <input type="text" id="delivery_postal_code" name="delivery_postal_code" maxlength="32" value="<?= $v('delivery_postal_code') ?>">
    <?php if ($err('delivery_postal_code') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_postal_code')) ?></span><?php endif; ?>
</div>
<div class="form-row">
    <label for="delivery_country">Delivery country</label>
    <input type="text" id="delivery_country" name="delivery_country" maxlength="100" value="<?= $v('delivery_country') ?>">
    <?php if ($err('delivery_country') !== ''): ?><span class="error"><?= htmlspecialchars($err('delivery_country')) ?></span><?php endif; ?>
</div>
