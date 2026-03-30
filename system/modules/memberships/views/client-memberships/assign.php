<?php
$title = 'Assign Membership to Client';
ob_start();
?>
<h1>Assign Membership to Client</h1>
<p><a href="/memberships/client-memberships">← Client Memberships</a></p>
<?php if (!empty($membershipSettings['terms_text'])): ?>
<div class="form-row">
    <label>Membership terms</label>
    <div><?= nl2br(htmlspecialchars((string) $membershipSettings['terms_text'])) ?></div>
</div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : 'Invalid') ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" action="/memberships/client-memberships/assign" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <?php
    $roundTripBranch = $assignBranchRoundTrip ?? false;
    $listBr = $listBranchId ?? null;
    if ($roundTripBranch && $listBr !== null && $listBr > 0): ?>
    <input type="hidden" name="assign_branch_id" value="<?= (int) $listBr ?>">
    <?php endif; ?>
    <div class="form-row">
        <label for="client_id">Client *</label>
        <select id="client_id" name="client_id" required>
            <option value="">Select client</option>
            <?php foreach ($clients as $c): ?>
            <?php $cid = (int) ($c['id'] ?? 0); $display = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')); ?>
            <option value="<?= $cid ?>" <?= ((string) ($data['client_id'] ?? '') === (string) $cid) ? 'selected' : '' ?>><?= htmlspecialchars($display ?: ('Client #' . $cid)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="membership_definition_id">Membership plan *</label>
        <select id="membership_definition_id" name="membership_definition_id" required>
            <option value="">Select plan</option>
            <?php foreach ($definitions as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= ((string) ($data['membership_definition_id'] ?? '') === (string) $d['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['name']) ?> (<?= (int) $d['duration_days'] ?> days<?= $d['price'] !== null ? ', ' . number_format((float) $d['price'], 2) : '' ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="starts_at">Start date *</label>
        <input type="date" id="starts_at" name="starts_at" required value="<?= htmlspecialchars($data['starts_at'] ?? date('Y-m-d')) ?>">
    </div>
    <div class="form-row">
        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars($data['notes'] ?? '') ?></textarea>
    </div>
    <div class="form-actions"><button type="submit">Assign</button> <a href="/memberships/client-memberships">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
