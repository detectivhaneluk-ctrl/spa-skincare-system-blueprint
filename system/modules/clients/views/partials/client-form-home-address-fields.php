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
<h2 class="client-ref-block-title">Home address</h2>
<div class="form-row">
    <label for="home_address_1">Address line 1</label>
    <input type="text" id="home_address_1" name="home_address_1" maxlength="255" value="<?= $v('home_address_1') ?>">
    <?php if ($err('home_address_1') !== ''): ?><span class="error"><?= htmlspecialchars($err('home_address_1')) ?></span><?php endif; ?>
</div>
<div class="form-row">
    <label for="home_address_2">Address line 2</label>
    <input type="text" id="home_address_2" name="home_address_2" maxlength="255" value="<?= $v('home_address_2') ?>">
    <?php if ($err('home_address_2') !== ''): ?><span class="error"><?= htmlspecialchars($err('home_address_2')) ?></span><?php endif; ?>
</div>
<div class="form-row">
    <label for="home_city">City</label>
    <input type="text" id="home_city" name="home_city" maxlength="120" value="<?= $v('home_city') ?>">
    <?php if ($err('home_city') !== ''): ?><span class="error"><?= htmlspecialchars($err('home_city')) ?></span><?php endif; ?>
</div>
<div class="form-row">
    <label for="home_postal_code">Postal code</label>
    <input type="text" id="home_postal_code" name="home_postal_code" maxlength="32" value="<?= $v('home_postal_code') ?>">
    <?php if ($err('home_postal_code') !== ''): ?><span class="error"><?= htmlspecialchars($err('home_postal_code')) ?></span><?php endif; ?>
</div>
<div class="form-row">
    <label for="home_country">Country</label>
    <input type="text" id="home_country" name="home_country" maxlength="100" value="<?= $v('home_country') ?>">
    <?php if ($err('home_country') !== ''): ?><span class="error"><?= htmlspecialchars($err('home_country')) ?></span><?php endif; ?>
</div>
