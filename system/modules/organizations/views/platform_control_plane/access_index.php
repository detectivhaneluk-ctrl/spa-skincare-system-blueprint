<?php
/** @var string $csrf */
/** @var string $title */
/** @var \Modules\Organizations\Services\FounderAccessPresenter $presenter */
/** @var list<array{row:array<string,mixed>,shape:array<string,mixed>}> $enriched */
/** @var list<array<string,mixed>> $orgs */
/** @var bool $canManage */
/** @var array{user_limit:int,source_row_count:int,displayed_row_count:int,shape_filter:string,org_filter_ignored:bool,membership_pivot_present:bool} $tenantAccessMeta */
$flashMsg = flash();
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
$q = isset($_GET['q']) ? htmlspecialchars((string) $_GET['q']) : '';
$orgSel = isset($_GET['org_id']) ? (int) $_GET['org_id'] : 0;
$shapeSel = isset($_GET['shape']) ? htmlspecialchars((string) $_GET['shape']) : '';
$filterOptions = $presenter->accessStatusFilterOptions();
?>
<div class="workspace-shell platform-control-plane">
    <?php if (is_array($flashMsg)): ?>
        <?php if (!empty($flashMsg['success'])): ?>
            <p class="platform-control-plane__recent-lead" role="status"><?= htmlspecialchars((string) $flashMsg['success']) ?></p>
        <?php endif; ?>
        <?php if (!empty($flashMsg['error'])): ?>
            <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars((string) $flashMsg['error']) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php $pagePurposeKey = 'access'; require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php'); ?>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($title) ?></h1>
            <p class="workspace-module-head__sub">Scan and open — this list is for discovery. Repairs, provisioning, and diagnostics run on the user detail page.</p>
        </div>
    </header>

    <p class="platform-control-plane__recent-lead" role="status">
        <strong>List view:</strong> filter rows, then <strong>Open</strong> a user to act.
        <?php if ($canManage): ?>
            <span aria-hidden="true"> · </span>
            <a class="tenant-dash-table__link" href="/platform-admin/access/provision">Provision new users</a>
            <span aria-hidden="true"> · </span>
        <?php endif; ?>
        Showing <strong><?= (int) $tenantAccessMeta['displayed_row_count'] ?></strong> row(s)
        <?php if (($tenantAccessMeta['shape_filter'] ?? '') !== ''): ?>
            for the selected access status
        <?php endif; ?>
        out of <strong><?= (int) $tenantAccessMeta['source_row_count'] ?></strong> loaded (max <?= (int) $tenantAccessMeta['user_limit'] ?> per request).
    </p>

    <?php if (!empty($tenantAccessMeta['org_filter_ignored'])): ?>
        <p class="platform-control-plane__recent-lead" role="status">
            Organization filter is unavailable until the membership table exists (migration 087).
        </p>
    <?php endif; ?>

    <form class="platform-control-plane__filters" method="get" action="/platform-admin/access">
        <label>Email contains <input type="text" name="q" value="<?= $q ?>" maxlength="120" placeholder="search"></label>
        <label>Organization
            <select name="org_id">
                <option value="0">Any</option>
                <?php foreach ($orgs as $o): ?>
                    <option value="<?= (int) ($o['id'] ?? 0) ?>"<?= $orgSel === (int) ($o['id'] ?? 0) ? ' selected' : '' ?>><?= htmlspecialchars((string) ($o['name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Access status
            <select name="shape">
                <?php foreach ($filterOptions as $opt): ?>
                    <option value="<?= htmlspecialchars((string) $opt['value']) ?>"<?= $shapeSel === (string) $opt['value'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Filter</button>
    </form>

    <?php if ($tenantAccessMeta['source_row_count'] === 0): ?>
        <p class="platform-control-plane__recent-lead" role="status">No users matched the current filters.</p>
    <?php elseif ($tenantAccessMeta['displayed_row_count'] === 0): ?>
        <p class="platform-control-plane__recent-lead" role="status">No rows match this access status filter. Choose “Any access status” or pick another value.</p>
    <?php endif; ?>

    <div class="tenant-dash-table-wrap">
        <table class="tenant-dash-table">
            <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th>Role</th>
                <th>Access status</th>
                <th>Organization status</th>
                <th>Branch access</th>
                <th>Expected destination</th>
                <th>Risk / attention</th>
                <th>Repair</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($enriched as $item): ?>
                <?php
                $r = $item['row'];
                $s = $item['shape'];
                $uid = (int) ($r['id'] ?? 0);
                $repHuman = $presenter->humanRepairRecommendations($s);
                $repCell = $repHuman !== [] ? 'Repair recommended' : '—';
                $repTitle = $repHuman !== [] ? implode(' — ', $repHuman) : '';
                ?>
                <tr>
                    <td>
                        <a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>"><?= htmlspecialchars(trim((string) ($r['name'] ?? '')) !== '' ? (string) $r['name'] : ('#' . $uid)) ?></a>
                    </td>
                    <td><?= htmlspecialchars((string) ($r['email'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($presenter->humanizeRoleCodes((string) ($r['role_codes'] ?? ''))) ?></td>
                    <td><?= htmlspecialchars($presenter->humanAccessStatus($s)) ?></td>
                    <td><?= htmlspecialchars($presenter->humanOrganizationStatus($s)) ?></td>
                    <td><?= htmlspecialchars($presenter->humanBranchSummary($s)) ?></td>
                    <td><?= htmlspecialchars($presenter->humanExpectedDestination($s)) ?></td>
                    <td><?= htmlspecialchars($presenter->humanRiskAttention($s)) ?></td>
                    <td<?= $repTitle !== '' ? ' title="' . htmlspecialchars($repTitle) . '"' : '' ?>><?= htmlspecialchars($repCell) ?></td>
                    <td><a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!$canManage): ?>
        <p class="platform-control-plane__recent-lead">Read-only: platform.organizations.manage is required to provision users or change accounts.</p>
    <?php endif; ?>
</div>
