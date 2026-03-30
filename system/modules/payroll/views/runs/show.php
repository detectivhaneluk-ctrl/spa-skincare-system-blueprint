<?php
$title = $title ?? 'Payroll run';
ob_start();
?>
<h1>Payroll run #<?= (int) ($run['id'] ?? 0) ?></h1>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars((string) $t) ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></div>
<?php endif; ?>

<p>
    Branch #<?= (int) ($run['branch_id'] ?? 0) ?> |
    Period <?= htmlspecialchars((string) ($run['period_start'] ?? '')) ?> – <?= htmlspecialchars((string) ($run['period_end'] ?? '')) ?> |
    Status: <strong><?= htmlspecialchars((string) ($run['status'] ?? '')) ?></strong>
    <?php if (!empty($run['settled_at'])): ?>
    | Settled at <?= htmlspecialchars((string) $run['settled_at']) ?>
    <?php endif; ?>
</p>
<?php if (!empty($run['notes'])): ?>
<p>Notes: <?= nl2br(htmlspecialchars((string) $run['notes'])) ?></p>
<?php endif; ?>

<?php if (!empty($canManage)): ?>
<p>
    <?php if (($run['status'] ?? '') === 'draft'): ?>
    <form method="post" action="/payroll/runs/<?= (int) $run['id'] ?>/calculate" style="display:inline">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn">Calculate</button>
    </form>
    <form method="post" action="/payroll/runs/<?= (int) $run['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete this draft run?');">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn">Delete draft</button>
    </form>
    <?php endif; ?>
    <?php if (($run['status'] ?? '') === 'calculated'): ?>
    <form method="post" action="/payroll/runs/<?= (int) $run['id'] ?>/lock" style="display:inline">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn">Lock</button>
    </form>
    <form method="post" action="/payroll/runs/<?= (int) $run['id'] ?>/reopen" style="display:inline">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn">Reopen to draft</button>
    </form>
    <?php endif; ?>
    <?php if (($run['status'] ?? '') === 'locked'): ?>
    <form method="post" action="/payroll/runs/<?= (int) $run['id'] ?>/settle" style="display:inline">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn">Mark settled</button>
    </form>
    <?php endif; ?>
</p>
<p><a href="/payroll/rules">Rules</a> · <a href="/payroll/runs">All runs</a></p>
<?php else: ?>
<p><a href="/payroll/runs">All runs</a></p>
<?php endif; ?>

<h2>Lines</h2>
<table class="index-table">
    <thead>
    <tr>
        <th>Staff</th>
        <th>Source</th>
        <th>Base</th>
        <th>Currency</th>
        <th>Rate %</th>
        <th>Calculated</th>
        <th>Derivation</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($lineRows as $line): ?>
    <tr>
        <td><?= htmlspecialchars(trim((string) (($line['staff_first_name'] ?? '') . ' ' . ($line['staff_last_name'] ?? '')))) ?> (#<?= (int) ($line['staff_id'] ?? 0) ?>)</td>
        <td><code><?= htmlspecialchars((string) ($line['source_kind'] ?? '')) ?></code> #<?= (int) ($line['source_ref'] ?? 0) ?>
            <?php if (!empty($line['invoice_id'])): ?><br>inv <?= (int) $line['invoice_id'] ?><?php endif; ?>
            <?php if (!empty($line['appointment_id'])): ?> appt <?= (int) $line['appointment_id'] ?><?php endif; ?>
        </td>
        <td><?= htmlspecialchars((string) ($line['base_amount'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string) ($line['currency'] ?? '')) ?></td>
        <td><?= $line['rate_percent'] !== null && $line['rate_percent'] !== '' ? htmlspecialchars((string) $line['rate_percent']) : '—' ?></td>
        <td><?= htmlspecialchars((string) ($line['calculated_amount'] ?? '')) ?></td>
        <td><pre style="white-space:pre-wrap;max-width:28rem;font-size:11px;"><?php
            $dj = $line['derivation_json'] ?? null;
            if (is_string($dj)) {
                $dec = json_decode($dj, true);
                echo htmlspecialchars($dec !== null ? json_encode($dec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $dj);
            } else {
                echo htmlspecialchars(json_encode($dj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        ?></pre></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($lineRows === []): ?>
<p>No lines on this run.</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
