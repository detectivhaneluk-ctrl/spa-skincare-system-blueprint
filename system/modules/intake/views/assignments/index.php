<?php
ob_start();
$base = rtrim((string) config('app.url', ''), '/');
?>
<h1>Intake assignments</h1>
<?php if (!empty($flash['success'])): ?><p><?= htmlspecialchars((string) $flash['success']) ?></p><?php endif; ?>
<?php if (!empty($flash['error'])): ?><p><?= htmlspecialchars((string) $flash['error']) ?></p><?php endif; ?>
<?php if (!empty($showTokenOnce)): ?>
    <?php $completionUrl = ($base !== '' ? $base : '') . '/public/intake?token=' . rawurlencode($showTokenOnce); ?>
    <p><strong>Completion URL</strong> (copy now):<br><code><?= htmlspecialchars($completionUrl) ?></code></p>
    <p><strong>Raw token</strong> (copy now):<br><code><?= htmlspecialchars($showTokenOnce) ?></code></p>
<?php endif; ?>
<p><a href="/intake/templates">Templates</a> · <a href="/intake/assign">New assignment</a></p>
<table border="1" cellpadding="6" cellspacing="0">
    <thead><tr><th>ID</th><th>Template</th><th>Client</th><th>Appt</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($assignments as $a): ?>
        <?php $subId = isset($a['submission_id']) && $a['submission_id'] ? (int) $a['submission_id'] : null; ?>
        <tr>
            <td><?= (int) $a['id'] ?></td>
            <td><?= htmlspecialchars((string) ($a['template_name'] ?? '')) ?></td>
            <td>#<?= (int) $a['client_id'] ?> <?= htmlspecialchars(trim((string) ($a['client_first_name'] ?? '') . ' ' . (string) ($a['client_last_name'] ?? ''))) ?></td>
            <td><?= isset($a['appointment_id']) && $a['appointment_id'] ? (int) $a['appointment_id'] : '—' ?></td>
            <td><?= htmlspecialchars((string) ($a['status'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($a['assigned_at'] ?? '')) ?></td>
            <td>
                <?php if ($subId): ?>
                    <a href="/intake/submissions/<?= $subId ?>">View submission</a>
                <?php elseif (($a['status'] ?? '') !== 'cancelled' && ($a['status'] ?? '') !== 'expired'): ?>
                    <form method="post" action="/intake/assignments/<?= (int) $a['id'] ?>/cancel" style="display:inline" onsubmit="return confirm('Cancel this assignment?');">
                        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="reason" value="staff_cancelled">
                        <button type="submit">Cancel</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$content = ob_get_clean();
$title = 'Intake assignments';
require shared_path('layout/base.php');
