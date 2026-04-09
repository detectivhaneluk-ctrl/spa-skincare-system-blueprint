<?php
$title = $title ?? 'Branches';
$rows = $rows ?? [];
$csrfName = config('app.csrf_token_name', 'csrf_token');
$canManageBranches = \Core\App\Application::container()->get(\Core\Permissions\PermissionService::class)
    ->has((int) (\Core\App\Application::container()->get(\Core\Auth\AuthService::class)->user()['id'] ?? 0), 'branches.manage');
ob_start();
require base_path('modules/branches/views/partials/branches-workspace-shell.php');
?>
<h2>Branches</h2>
<?php if (!empty($canManageBranches)): ?><p><a class="btn" href="/branches/create">Add branch</a></p><?php endif; ?>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<table class="data-table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Code</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></td>
            <td><code><?= htmlspecialchars((string) ($r['code'] ?? '')) ?></code></td>
            <td><?= !empty($r['deleted_at']) ? 'Inactive' : 'Active' ?></td>
            <td>
                <?php if (!empty($canManageBranches)): ?>
                <a href="/branches/<?= (int) ($r['id'] ?? 0) ?>/edit">Edit</a>
                <?php if (empty($r['deleted_at'])): ?>
                <form method="post" action="/branches/<?= (int) ($r['id'] ?? 0) ?>/delete" style="display:inline" onsubmit="return confirm('Deactivate this branch? It will be hidden from selectors; historical data stays linked.');">
                    <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                    <button type="submit">Deactivate</button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                —
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr><td colspan="4">No branches.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
<p class="text-muted">Restoring a deactivated branch is not available in this release; create a new branch or clear <code>deleted_at</code> via DBA if required.</p>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
