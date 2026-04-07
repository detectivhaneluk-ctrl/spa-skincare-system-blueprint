<?php
$title = 'Assign package to client';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = '';
$salesWorkspaceShellTitle = 'Client packages';
$salesWorkspaceShellSub = 'Client-held package records (Clients). Plan templates: Catalog. Checkout can sell an assignment: Sales — not where definitions live.';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Assign package to client</h2>
<p class="hint" style="margin-top:0;">Creates a <strong>client-held</strong> record from a <strong>Catalog plan</strong> you pick below (administrative assignment). Selling through <strong>New sale</strong> in Sales does the same commercially; neither screen replaces Catalog plan definitions.</p>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : 'Invalid') ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" action="/packages/client-packages/assign" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">Organisation-wide</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= ((string) ($assignment['branch_id'] ?? '') === (string) $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="package_id">Package plan (Catalog) *</label>
        <select id="package_id" name="package_id" required>
            <option value="">Select plan</option>
            <?php foreach ($packageDefs as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= ((string) ($assignment['package_id'] ?? '') === (string) $p['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['name']) ?> (<?= (int) $p['total_sessions'] ?> sessions, <?= $p['branch_id'] ? ('branch #' . (int) $p['branch_id']) : 'organisation-wide' ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="client_id">Client *</label>
        <select id="client_id" name="client_id" required>
            <option value="">Select client</option>
            <?php foreach ($clientOptions as $c): ?>
            <?php $cid = (int) ($c['id'] ?? 0); $display = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')); ?>
            <option value="<?= $cid ?>" <?= ((string) ($assignment['client_id'] ?? '') === (string) $cid) ? 'selected' : '' ?>><?= htmlspecialchars($display ?: ('Client #' . $cid)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label for="assigned_sessions">Assigned Sessions *</label><input type="number" min="1" step="1" id="assigned_sessions" name="assigned_sessions" required value="<?= htmlspecialchars((string) ($assignment['assigned_sessions'] ?? 1)) ?>"></div>
    <div class="form-row"><label for="assigned_at">Assigned At *</label><input type="datetime-local" id="assigned_at" name="assigned_at" required value="<?= htmlspecialchars((string) ($assignment['assigned_at'] ?? date('Y-m-d\TH:i'))) ?>"></div>
    <div class="form-row"><label for="starts_at">Starts At</label><input type="datetime-local" id="starts_at" name="starts_at" value="<?= htmlspecialchars((string) ($assignment['starts_at'] ?? '')) ?>"></div>
    <div class="form-row"><label for="expires_at">Expires At</label><input type="datetime-local" id="expires_at" name="expires_at" value="<?= htmlspecialchars((string) ($assignment['expires_at'] ?? '')) ?>"></div>
    <div class="form-row"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"><?= htmlspecialchars((string) ($assignment['notes'] ?? '')) ?></textarea></div>
    <div class="form-actions"><button type="submit">Assign</button> <a href="/packages/client-packages">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
