<?php
/** @var array $client */
/** @var array $errors */
$err = static function (string $key) use ($errors): string {
    return !empty($errors[$key]) ? (string) $errors[$key] : '';
};
$v = static function (string $key) use ($client): string {
    return htmlspecialchars((string) ($client[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
$phoneMobileVal = trim((string) ($client['phone_mobile'] ?? ''));
if ($phoneMobileVal === '') {
    $phoneMobileVal = trim((string) ($client['phone'] ?? ''));
}
?>
<p class="client-ref-hig-subsection-label">Phone numbers</p>
<div class="form-row client-ref-hig-field">
    <label for="phone_home">Home</label>
    <input type="text" id="phone_home" name="phone_home" maxlength="50" value="<?= $v('phone_home') ?>" autocomplete="tel">
    <?php if ($err('phone_home') !== ''): ?><span class="error"><?= htmlspecialchars($err('phone_home')) ?></span><?php endif; ?>
</div>
<div class="form-row client-ref-hig-field">
    <label for="phone_mobile">Mobile</label>
    <input type="text" id="phone_mobile" name="phone_mobile" maxlength="50" value="<?= htmlspecialchars($phoneMobileVal, ENT_QUOTES, 'UTF-8') ?>" autocomplete="tel">
    <?php if ($err('phone_mobile') !== ''): ?><span class="error"><?= htmlspecialchars($err('phone_mobile')) ?></span><?php endif; ?>
</div>
<div class="form-row client-ref-hig-field">
    <label for="mobile_operator">Mobile operator</label>
    <input type="text" id="mobile_operator" name="mobile_operator" maxlength="100" value="<?= $v('mobile_operator') ?>">
    <?php if ($err('mobile_operator') !== ''): ?><span class="error"><?= htmlspecialchars($err('mobile_operator')) ?></span><?php endif; ?>
</div>
<div class="form-row client-ref-hig-field">
    <label for="phone_work">Work phone</label>
    <input type="text" id="phone_work" name="phone_work" maxlength="50" value="<?= $v('phone_work') ?>" autocomplete="tel">
    <?php if ($err('phone_work') !== ''): ?><span class="error"><?= htmlspecialchars($err('phone_work')) ?></span><?php endif; ?>
</div>
<div class="form-row client-ref-hig-field">
    <label for="phone_work_ext">Work extension</label>
    <input type="text" id="phone_work_ext" name="phone_work_ext" maxlength="30" value="<?= $v('phone_work_ext') ?>">
    <?php if ($err('phone_work_ext') !== ''): ?><span class="error"><?= htmlspecialchars($err('phone_work_ext')) ?></span><?php endif; ?>
</div>
