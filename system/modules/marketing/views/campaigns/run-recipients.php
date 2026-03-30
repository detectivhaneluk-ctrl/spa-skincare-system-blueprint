<?php
$title = 'Campaign run recipients';
ob_start();
?>
<h1>Run #<?= (int) ($run['id'] ?? 0) ?> — <?= htmlspecialchars((string) ($campaign['name'] ?? '')) ?></h1>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars((string) $t) ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></div>
<?php endif; ?>

<p>Run status: <strong><?= htmlspecialchars((string) ($run['status'] ?? '')) ?></strong></p>

<?php if (($run['status'] ?? '') === 'frozen'): ?>
<form method="post" action="/marketing/campaigns/runs/<?= (int) ($run['id'] ?? 0) ?>/dispatch" style="display:inline">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <button type="submit">Enqueue outbound sends</button>
</form>
<form method="post" action="/marketing/campaigns/runs/<?= (int) ($run['id'] ?? 0) ?>/cancel" style="display:inline;margin-left:1em">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <button type="submit">Cancel run</button>
</form>
<?php endif; ?>

<table class="index-table">
    <thead>
    <tr>
        <th>Client</th>
        <th>Email (snapshot)</th>
        <th>Recipient status</th>
        <th>Outbound</th>
        <th>Notes</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($recipients as $rec): ?>
    <tr>
        <td>#<?= (int) ($rec['client_id'] ?? 0) ?></td>
        <td><?= htmlspecialchars((string) ($rec['email_snapshot'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string) ($rec['delivery_status'] ?? '')) ?></td>
        <td><?php
            $os = (string) ($rec['outbound_status'] ?? '');
            if ($os !== '') {
                echo htmlspecialchars($os);
                if (!empty($rec['outbound_sent_at'])) {
                    $tsLabel = match ($os) {
                        'captured_locally' => 'logged_at',
                        'handoff_accepted', 'sent' => 'mta_handoff_at',
                        default => 'dispatch_completed_at',
                    };
                    echo ' / ' . htmlspecialchars($tsLabel) . ' ' . htmlspecialchars((string) $rec['outbound_sent_at']);
                }
                if (!empty($rec['outbound_failed_at'])) {
                    echo ' / failed ' . htmlspecialchars((string) $rec['outbound_failed_at']);
                }
            } elseif (!empty($rec['outbound_message_id'])) {
                echo '#' . (int) $rec['outbound_message_id'];
            } else {
                echo '—';
            }
        ?></td>
        <td><?= htmlspecialchars((string) ($rec['skip_reason'] ?? ($rec['outbound_error_summary'] ?? ($rec['outbound_skip_reason'] ?? '')))) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($recipients === []): ?><p>No recipients.</p><?php endif; ?>

<p><a href="/marketing/campaigns/<?= (int) ($campaign['id'] ?? 0) ?>">Back to campaign</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
