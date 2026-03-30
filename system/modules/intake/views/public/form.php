<?php
ob_start();
$key = static function (string $k): string {
    return htmlspecialchars($k, ENT_QUOTES, 'UTF-8');
};
$val = static function (mixed $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?>
<h1><?= $val($title ?? 'Intake') ?></h1>
<?php if (!empty($errors['_token'])): ?><p><?= $val($errors['_token']) ?></p><?php endif; ?>
<form method="post" action="/public/intake/submit">
    <input type="hidden" name="token" value="<?= $val($tokenValue ?? '') ?>">
    <?php foreach ($fields as $f):
        $fk = (string) ($f['field_key'] ?? '');
        $label = (string) ($f['label'] ?? $fk);
        $type = (string) ($f['field_type'] ?? 'text');
        $req = !empty($f['required']);
        $err = $errors[$fk] ?? null;
        $ov = $old[$fk] ?? null;
        ?>
        <div style="margin-bottom:1rem">
            <?php if ($type === 'checkbox'): ?>
                <label>
                    <input type="hidden" name="<?= $key($fk) ?>" value="0">
                    <input type="checkbox" name="<?= $key($fk) ?>" value="1" <?= (($ov === '1' || $ov === 1 || $ov === true || $ov === 'on') ? 'checked' : '') ?><?= $req ? ' required' : '' ?>>
                    <?= $val($label) ?><?= $req ? ' *' : '' ?>
                </label>
            <?php elseif ($type === 'textarea'): ?>
                <label><?= $val($label) ?><?= $req ? ' *' : '' ?><br>
                    <textarea name="<?= $key($fk) ?>" rows="4" cols="50"<?= $req ? ' required' : '' ?>><?= $val($ov ?? '') ?></textarea>
                </label>
            <?php elseif ($type === 'select'):
                $opts = is_array($f['options'] ?? null) ? $f['options'] : [];
                ?>
                <label><?= $val($label) ?><?= $req ? ' *' : '' ?><br>
                    <select name="<?= $key($fk) ?>"<?= $req ? ' required' : '' ?>>
                        <option value="">—</option>
                        <?php foreach ($opts as $opt): ?>
                            <option value="<?= $val($opt) ?>"<?= (string) $ov === (string) $opt ? ' selected' : '' ?>><?= $val($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else:
                $inputType = match ($type) {
                    'email' => 'email',
                    'phone' => 'tel',
                    'number' => 'text',
                    'date' => 'date',
                    default => 'text',
                };
                ?>
                <label><?= $val($label) ?><?= $req ? ' *' : '' ?><br>
                    <input type="<?= $key($inputType) ?>" name="<?= $key($fk) ?>" value="<?= $val($ov ?? '') ?>"<?= $req ? ' required' : '' ?>>
                </label>
            <?php endif; ?>
            <?php if ($err): ?><div><small><?= $val($err) ?></small></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <p><button type="submit">Submit</button></p>
</form>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
