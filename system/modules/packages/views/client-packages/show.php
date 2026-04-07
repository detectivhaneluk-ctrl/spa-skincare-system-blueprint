<?php
$title = 'Client-held package';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = '';
$salesWorkspaceShellTitle = 'Client packages';
$salesWorkspaceShellSub = 'Client-held package records (Clients). Plan templates: Catalog. Checkout can sell an assignment: Sales — not where definitions live.';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Client-held package #<?= (int) $clientPackage['id'] ?></h2>
<?php
$cpClientId = (int) ($clientPackage['client_id'] ?? 0);
$cpClientProfileHref = $cpClientId > 0 ? '/clients/' . $cpClientId : '/clients';
?>
<p class="hint" style="margin-top:0;"><strong>Clients-owned record</strong> — sessions remaining, usage, and expiry for this client. The <strong>package plan</strong> (template) is maintained in <strong>Catalog</strong> (<a href="/packages">Package plans</a>). Open the <a href="<?= htmlspecialchars($cpClientProfileHref, ENT_QUOTES, 'UTF-8') ?>">client profile</a> for the full Clients workspace.</p>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<p><strong>Client:</strong> <?= htmlspecialchars($clientPackage['client_display']) ?></p>
<p><strong>Plan (template name):</strong> <?= htmlspecialchars($clientPackage['package_name']) ?></p>
<p><strong>Status:</strong> <span class="badge badge-muted"><?= htmlspecialchars($clientPackage['status']) ?></span></p>
<p><strong>Assigned:</strong> <?= (int) $clientPackage['assigned_sessions'] ?> | <strong>Remaining:</strong> <span class="badge <?= $currentRemaining <= 0 ? 'badge-warn' : 'badge-success' ?>"><?= (int) $currentRemaining ?></span></p>
<p><strong>Assigned At:</strong> <?= htmlspecialchars($clientPackage['assigned_at']) ?> | <strong>Expires At:</strong> <?= htmlspecialchars($clientPackage['expires_at'] ?? '—') ?></p>
<p><strong>Branch:</strong> <?= $clientPackage['branch_id'] ? ('#' . (int) $clientPackage['branch_id']) : 'Organisation-wide' ?></p>

<p>
    <a class="btn" href="/packages/client-packages/<?= (int) $clientPackage['id'] ?>/use">Use Session</a>
    <a class="btn" href="/packages/client-packages/<?= (int) $clientPackage['id'] ?>/adjust">Adjust Sessions</a>
    <a class="btn" href="/packages/client-packages">← Client-held packages</a>
</p>

<h2>Reverse Usage</h2>
<form method="post" action="/packages/client-packages/<?= (int) $clientPackage['id'] ?>/reverse" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row"><label for="usage_id">Usage ID *</label><input type="number" min="1" step="1" id="usage_id" name="usage_id" required></div>
    <div class="form-row"><label for="reverse_notes">Notes</label><textarea id="reverse_notes" name="notes" rows="2"></textarea></div>
    <div class="form-actions"><button type="submit">Reverse</button></div>
</form>

<h2>Cancel Client Package</h2>
<form method="post" action="/packages/client-packages/<?= (int) $clientPackage['id'] ?>/cancel" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row"><label for="cancel_notes">Notes</label><textarea id="cancel_notes" name="notes" rows="2"></textarea></div>
    <div class="form-actions"><button type="submit">Cancel Package</button></div>
</form>

<h2>Usage History</h2>
<table class="index-table">
    <thead>
    <tr>
        <th>ID</th>
        <th>Type</th>
        <th>Qty</th>
        <th>Remaining After</th>
        <th>Ref</th>
        <th>Created</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($usageHistory as $u): ?>
    <tr>
        <td><?= (int) $u['id'] ?></td>
        <td><span class="badge badge-muted"><?= htmlspecialchars($u['usage_type']) ?></span></td>
        <td><?= (int) $u['quantity'] ?></td>
        <td><?= (int) $u['remaining_after'] ?></td>
        <td><?= htmlspecialchars(($u['reference_type'] ?? '—') . (($u['reference_id'] ?? null) ? ('#' . (int) $u['reference_id']) : '')) ?></td>
        <td><?= htmlspecialchars($u['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
