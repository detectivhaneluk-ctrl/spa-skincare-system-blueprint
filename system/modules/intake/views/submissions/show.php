<?php
ob_start();
$labels = [];
foreach ($fields as $f) {
    $labels[(string) ($f['field_key'] ?? '')] = (string) ($f['label'] ?? '');
}
?>
<h1>Intake submission #<?= (int) ($submission['id'] ?? 0) ?></h1>
<p><a href="/intake/assignments">Assignments</a></p>
<p>Client #<?= (int) ($submission['client_id'] ?? 0) ?>
    <?php if (!empty($submission['appointment_id'])): ?> · Appointment #<?= (int) $submission['appointment_id'] ?><?php endif; ?>
    · <?= htmlspecialchars((string) ($submission['submitted_from'] ?? '')) ?>
    · <?= htmlspecialchars((string) ($submission['created_at'] ?? '')) ?>
</p>
<table border="1" cellpadding="6" cellspacing="0">
    <thead><tr><th>Field</th><th>Value</th></tr></thead>
    <tbody>
    <?php foreach ($fields as $f):
        $fk = (string) ($f['field_key'] ?? '');
        $raw = $valueByKey[$fk] ?? '';
        if (($f['field_type'] ?? '') === 'checkbox') {
            $display = ($raw === '1' || $raw === 'true') ? 'Yes' : 'No';
        } else {
            $display = (string) $raw;
        }
        ?>
        <tr>
            <td><?= htmlspecialchars($labels[$fk] ?? $fk) ?></td>
            <td><?= nl2br(htmlspecialchars($display)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$content = ob_get_clean();
$title = 'Intake submission';
require shared_path('layout/base.php');
