<?php
$title = 'Create Membership Definition';
ob_start();
?>
<h1>Create Membership Definition</h1>
<p><a href="/memberships">← Membership Definitions</a></p>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : 'Invalid') ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" action="/memberships" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="name">Name *</label>
        <input type="text" id="name" name="name" required value="<?= htmlspecialchars($definition['name'] ?? '') ?>">
    </div>
    <div class="form-row">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="2"><?= htmlspecialchars($definition['description'] ?? '') ?></textarea>
    </div>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">Global</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= ((string) ($definition['branch_id'] ?? '') === (string) $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="duration_days">Duration (days) *</label>
        <input type="number" id="duration_days" name="duration_days" min="1" required value="<?= (int) ($definition['duration_days'] ?? 30) ?>">
    </div>
    <div class="form-row">
        <label for="price">Price</label>
        <input type="number" id="price" name="price" min="0" step="0.01" value="<?= ($definition['price'] ?? null) !== null ? htmlspecialchars((string) $definition['price']) : '' ?>">
    </div>
    <div class="form-row">
        <label>
            <input type="hidden" name="billing_enabled" value="0">
            <input type="checkbox" name="billing_enabled" value="1" <?= !empty($definition['billing_enabled']) ? 'checked' : '' ?>> Enable subscription billing (renewals)
        </label>
    </div>
    <div class="form-row">
        <label for="billing_interval_count">Bill every</label>
        <input type="number" id="billing_interval_count" name="billing_interval_count" min="1" value="<?= (int) ($definition['billing_interval_count'] ?? 1) ?>">
        <select id="billing_interval_unit" name="billing_interval_unit">
            <?php foreach (\Modules\Memberships\Services\MembershipService::DEFINITION_BILLING_INTERVAL_UNITS as $u): ?>
            <option value="<?= htmlspecialchars($u) ?>" <?= (($definition['billing_interval_unit'] ?? 'month') === $u) ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="renewal_price">Renewal price</label>
        <input type="number" id="renewal_price" name="renewal_price" min="0" step="0.01" value="<?= isset($definition['renewal_price']) && $definition['renewal_price'] !== null && $definition['renewal_price'] !== '' ? htmlspecialchars((string) $definition['renewal_price']) : '' ?>">
    </div>
    <div class="form-row">
        <label for="renewal_invoice_due_days">Renewal invoice due (days before period end)</label>
        <input type="number" id="renewal_invoice_due_days" name="renewal_invoice_due_days" min="0" max="3660" value="<?= (int) ($definition['renewal_invoice_due_days'] ?? 14) ?>">
    </div>
    <div class="form-row">
        <label>
            <input type="hidden" name="billing_auto_renew_enabled" value="0">
            <input type="checkbox" name="billing_auto_renew_enabled" value="1" <?= !empty($definition['billing_auto_renew_enabled']) ? 'checked' : '' ?>> Auto-renew by default
        </label>
    </div>
    <div class="form-row">
        <label>
            <input type="hidden" name="public_online_eligible" value="0">
            <input type="checkbox" name="public_online_eligible" value="1" <?= !empty($definition['public_online_eligible']) ? 'checked' : '' ?>> Eligible for public online purchase (when commerce settings allow)
        </label>
    </div>
    <div class="form-row">
        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach (\Modules\Memberships\Services\MembershipService::DEFINITION_STATUSES as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>" <?= ($definition['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions"><button type="submit">Create</button> <a href="/memberships">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
