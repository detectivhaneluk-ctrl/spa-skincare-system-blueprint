<?php
// Group branches by organisation to detect multi-org context and enable unambiguous option labels.
$branchesByOrg = [];
foreach (($branches ?? []) as $branch) {
    $orgId   = (int) ($branch['organization_id'] ?? 0);
    $orgName = (string) ($branch['organization_name'] ?? 'Unknown');
    if (!isset($branchesByOrg[$orgId])) {
        $branchesByOrg[$orgId] = ['name' => $orgName, 'branches' => []];
    }
    $branchesByOrg[$orgId]['branches'][] = $branch;
}
$isMultiOrg = count($branchesByOrg) > 1;

ob_start();
?>
<div class="auth-card">
    <h1>Select your branch</h1>
    <?php if ($isMultiOrg): ?>
    <p>You have access to branches across multiple organisations. Select one to continue.</p>
    <?php else: ?>
    <p>Choose an active branch to continue.</p>
    <?php endif; ?>
    <form method="post" action="/account/branch-context" class="auth-form">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
        <input type="hidden" name="redirect_to" value="/dashboard">
        <div>
            <label for="branch_id">Branch</label>
            <select id="branch_id" name="branch_id" required>
                <?php if ($isMultiOrg): ?>
                    <?php foreach ($branchesByOrg as $orgData): ?>
                    <optgroup label="<?= htmlspecialchars($orgData['name']) ?>">
                        <?php foreach ($orgData['branches'] as $branch): ?>
                        <option value="<?= (int) ($branch['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($branch['name'] ?? 'Branch')) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach (($branches ?? []) as $branch): ?>
                    <option value="<?= (int) ($branch['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($branch['name'] ?? 'Branch')) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <button type="submit">Continue</button>
    </form>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
