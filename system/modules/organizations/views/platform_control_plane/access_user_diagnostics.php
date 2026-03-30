<?php
/** @var string $csrf */
/** @var string $title */
/** @var array<string,mixed> $row */
/** @var array<string,mixed> $shape */
/** @var \Modules\Organizations\Services\FounderAccessPresenter $presenter */
$uid = (int) ($row['id'] ?? 0);
$shapeJson = json_encode($shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($shapeJson === false) {
    $shapeJson = '{}';
}
?>
<div class="workspace-shell platform-control-plane">
    <?php
    $pagePurposeKey = 'access_diagnostics';
    $pagePurpose = \Core\App\Application::container()->get(\Modules\Organizations\Services\FounderPagePurposePresenter::class)->forPage('access_diagnostics');
    array_unshift($pagePurpose['next_best'], ['label' => 'User access detail', 'href' => '/platform-admin/access/' . $uid]);
    require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php');
    ?>
    <p class="platform-control-plane__recent-lead">
        <a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>">← User access</a>
        · <a class="tenant-dash-table__link" href="/platform-admin/access">Access list</a>
    </p>

    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($title) ?></h1>
            <p class="workspace-module-head__sub">Authoritative engine output for support and incident response. Prefer the Access summary for day-to-day operations.</p>
        </div>
    </header>

    <section class="platform-control-plane__actions" aria-label="Account row">
        <h2 class="dashboard-quicklinks__heading">User row (read)</h2>
        <div class="tenant-dash-table-wrap">
            <table class="tenant-dash-table">
                <tbody>
                <tr><th scope="row">ID</th><td><?= $uid ?></td></tr>
                <tr><th scope="row">Email</th><td><?= htmlspecialchars((string) ($row['email'] ?? '')) ?></td></tr>
                <tr><th scope="row">Name</th><td><?= htmlspecialchars((string) ($row['name'] ?? '')) ?></td></tr>
                <tr><th scope="row">Branch pin (users.branch_id)</th><td><?= isset($row['branch_id']) && $row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : 'NULL' ?></td></tr>
                <tr><th scope="row">Deleted at</th><td><?= htmlspecialchars((string) ($row['deleted_at'] ?? '')) ?: '—' ?></td></tr>
                <tr><th scope="row">Role codes (raw)</th><td><code><?= htmlspecialchars((string) ($row['role_codes'] ?? '')) ?></code></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="platform-control-plane__actions" aria-label="Shape payload">
        <h2 class="dashboard-quicklinks__heading">Access shape (full JSON)</h2>
        <p class="platform-control-plane__recent-lead">Includes canonical_state, expected_home_path, tenant_entry_resolution, contradictions, suggested_repairs, memberships, and usable_branch_ids.</p>
        <pre class="platform-diagnostics-json"><?= htmlspecialchars($shapeJson) ?></pre>
    </section>
</div>
