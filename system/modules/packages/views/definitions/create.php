<?php
$title = 'New Package Plan';
ob_start();
$pkgWorkspaceActiveTab = 'plans';
require base_path('modules/packages/views/partials/packages-workspace-shell.php');
?>
<h2>New Package Plan</h2>
<p class="hint" style="margin-top:0;">Create a plan template (sessions, price, branch scope). Client-held records are managed in Clients.</p>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : 'Invalid') ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" action="/packages" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row"><label for="name">Name *</label><input type="text" id="name" name="name" required value="<?= htmlspecialchars((string) ($package['name'] ?? '')) ?>"></div>
    <div class="form-row"><label for="description">Description</label><textarea id="description" name="description" rows="3"><?= htmlspecialchars((string) ($package['description'] ?? '')) ?></textarea></div>
    <div class="form-row"><label for="status">Status *</label>
        <select id="status" name="status">
            <?php foreach (\Modules\Packages\Services\PackageService::PACKAGE_STATUSES as $status): ?>
            <option value="<?= htmlspecialchars($status) ?>" <?= (($package['status'] ?? 'active') === $status) ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label for="total_sessions">Total Sessions *</label><input type="number" min="1" step="1" id="total_sessions" name="total_sessions" required value="<?= htmlspecialchars((string) ($package['total_sessions'] ?? 1)) ?>"></div>
    <div class="form-row"><label for="validity_days">Validity Days</label><input type="number" min="1" step="1" id="validity_days" name="validity_days" value="<?= htmlspecialchars((string) ($package['validity_days'] ?? '')) ?>"></div>
    <div class="form-row"><label for="price">Price</label><input type="number" min="0" step="0.01" id="price" name="price" value="<?= htmlspecialchars((string) ($package['price'] ?? '')) ?>"></div>
    <div class="form-row">
        <label>
            <input type="hidden" name="public_online_eligible" value="0">
            <input type="checkbox" name="public_online_eligible" value="1" <?= !empty($package['public_online_eligible']) ? 'checked' : '' ?>> Eligible for public online purchase (when commerce settings allow)
        </label>
    </div>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">Organisation-wide</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= ((string) ($package['branch_id'] ?? '') === (string) $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions"><button type="submit">Create plan</button> <a href="/packages">← Package plans</a></div>
</form>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
