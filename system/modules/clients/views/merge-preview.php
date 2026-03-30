<?php
$title = 'Merge Clients';
ob_start();
?>
<h1>Merge Clients</h1>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="flash flash-error"><?= htmlspecialchars((string) $error) ?></div>
<?php endif; ?>

<?php if (!empty($queuedMergeJob) && is_array($queuedMergeJob)): ?>
<div class="hint" role="status" style="margin:1rem 0;padding:0.75rem;border:1px solid #ccc;">
    <p><strong>Merge job #<?= (int) ($queuedMergeJob['id'] ?? 0) ?></strong> — status: <code><?= htmlspecialchars((string) ($queuedMergeJob['status'] ?? '')) ?></code></p>
    <?php if (!empty($queuedMergeJob['error_message_public'])): ?>
    <p><?= htmlspecialchars((string) $queuedMergeJob['error_message_public']) ?></p>
    <?php endif; ?>
    <p class="hint">JSON: <a href="/clients/merge/job-status?job_id=<?= (int) ($queuedMergeJob['id'] ?? 0) ?>">/clients/merge/job-status?job_id=<?= (int) ($queuedMergeJob['id'] ?? 0) ?></a></p>
    <?php if (($queuedMergeJob['status'] ?? '') === 'succeeded'): ?>
    <p><a href="/clients/<?= (int) ($queuedMergeJob['primary_client_id'] ?? 0) ?>">Open primary client #<?= (int) ($queuedMergeJob['primary_client_id'] ?? 0) ?></a></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="get" class="search-form">
    <label for="primary_id">Primary Client ID</label>
    <input type="number" min="1" id="primary_id" name="primary_id" value="<?= htmlspecialchars((string) ($_GET['primary_id'] ?? '')) ?>" required>
    <label for="secondary_id">Secondary Client ID</label>
    <input type="number" min="1" id="secondary_id" name="secondary_id" value="<?= htmlspecialchars((string) ($_GET['secondary_id'] ?? '')) ?>" required>
    <button type="submit">Preview Merge</button>
</form>

<?php if (!empty($preview)): ?>
<h2>Preview</h2>
<p><strong>Primary:</strong> <a href="/clients/<?= (int) $preview['primary']['id'] ?>">#<?= (int) $preview['primary']['id'] ?></a> <?= htmlspecialchars(trim((string) $preview['primary']['first_name'] . ' ' . (string) $preview['primary']['last_name'])) ?></p>
<p><strong>Secondary:</strong> <a href="/clients/<?= (int) $preview['secondary']['id'] ?>">#<?= (int) $preview['secondary']['id'] ?></a> <?= htmlspecialchars(trim((string) $preview['secondary']['first_name'] . ' ' . (string) $preview['secondary']['last_name'])) ?></p>

<table class="index-table">
    <thead><tr><th>Linked Table</th><th>Rows to Re-map</th></tr></thead>
    <tbody>
    <?php foreach (($preview['secondary_linked_counts'] ?? []) as $table => $count): ?>
    <tr><td><?= htmlspecialchars((string) $table) ?></td><td><?= (int) $count ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>

<form method="post" action="/clients/merge" class="entity-form" onsubmit="return confirm('Proceed with client merge? Secondary will be soft-closed.')">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="primary_id" value="<?= (int) $preview['primary']['id'] ?>">
    <input type="hidden" name="secondary_id" value="<?= (int) $preview['secondary']['id'] ?>">
    <div class="form-row">
        <label for="merge_notes">Merge Notes</label>
        <textarea id="merge_notes" name="notes" rows="3"></textarea>
    </div>
    <div class="form-actions">
        <button type="submit">Confirm Merge</button>
        <a href="/clients">Cancel</a>
    </div>
</form>
<?php endif; ?>

<p><a href="/clients">← Back to clients</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
