<?php
$title    = !empty($trashView) ? 'Staff — Trash' : 'Staff';
$csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$csrfVal  = htmlspecialchars($csrf ?? '');

// ── View-state from URL ────────────────────────────────────────────────────
$showInactive = empty($trashView) && isset($_GET['active']) && (string) $_GET['active'] === '0';
$sortCol      = isset($_GET['sort']) ? (string) $_GET['sort'] : 'name';
$sortDir      = isset($_GET['dir']) && $_GET['dir'] === 'desc' ? 'desc' : 'asc';
$allowedSorts = ['name', 'job_title', 'email', 'type', 'status'];
if (!in_array($sortCol, $allowedSorts, true)) {
    $sortCol = 'name';
}

// ── URL builder ────────────────────────────────────────────────────────────
function stfIndexUrl(array $overrides = []): string
{
    $base = [
        'status' => null,
        'active' => null,
        'sort'   => null,
        'dir'    => null,
        'page'   => null,
    ];
    $params = array_filter(array_merge($base, $overrides), fn ($v) => $v !== null && $v !== '');
    return '/staff' . ($params ? ('?' . http_build_query($params)) : '');
}

$baseStatus   = !empty($trashView) ? 'trash' : null;
$baseActive   = $showInactive ? '0' : null;
$baseSort     = $sortCol !== 'name' ? $sortCol : null;
$baseDir      = $sortDir !== 'asc' ? $sortDir : null;
$basePage     = $page > 1 ? (string) $page : null;

ob_start();
$teamWorkspaceActiveTab  = 'directory';
$teamWorkspaceShellTitle = !empty($trashView) ? 'Staff — Trash' : 'Team';
require base_path('modules/staff/views/partials/team-workspace-shell.php');

// ── PHP-side sort ──────────────────────────────────────────────────────────
$sortedStaff = $staff;
if ($sortCol !== 'name') {
    usort($sortedStaff, function ($a, $b) use ($sortCol, $sortDir) {
        if ($sortCol === 'job_title') {
            $va = strtolower((string) ($a['job_title'] ?? ''));
            $vb = strtolower((string) ($b['job_title'] ?? ''));
        } elseif ($sortCol === 'email') {
            $va = strtolower((string) ($a['email'] ?? ''));
            $vb = strtolower((string) ($b['email'] ?? ''));
        } elseif ($sortCol === 'type') {
            $va = strtolower((string) ($a['staff_type'] ?? ''));
            $vb = strtolower((string) ($b['staff_type'] ?? ''));
        } elseif ($sortCol === 'status') {
            $va = (int) (!empty($a['is_active']));
            $vb = (int) (!empty($b['is_active']));
            return $sortDir === 'asc' ? ($vb <=> $va) : ($va <=> $vb);
        } else {
            $va = '';
            $vb = '';
        }
        return $sortDir === 'asc' ? strcmp($va, $vb) : strcmp($vb, $va);
    });
}
?>
<?php if ($flash && is_array($flash)): $fk = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($fk) ?>"><?= htmlspecialchars($flash[$fk] ?? '') ?></div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     TEAM WORKSPACE — page toolbar
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="stf-ws-toolbar">

    <!-- Left: search -->
    <div class="stf-ws-toolbar__left">
        <?php if (!$trashView): ?>
        <div class="stf-search-wrap" id="stf-search-wrap">
            <svg class="stf-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="stf-search-input" class="stf-search-input" placeholder="Search staff…" autocomplete="off" aria-label="Search staff">
            <button type="button" id="stf-search-clear" class="stf-search-clear" title="Clear" hidden>✕</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: view tabs + Add Staff CTA -->
    <div class="stf-ws-toolbar__right">
        <!-- Status / active tabs -->
        <div class="stf-status-tabs" role="tablist" aria-label="Staff view">
            <a href="<?= htmlspecialchars(stfIndexUrl()) ?>"
               class="stf-status-tab <?= (!$trashView && !$showInactive) ? 'stf-status-tab--active' : '' ?>"
               role="tab">
                Active <span class="stf-status-count"><?= (int) ($countActive ?? 0) ?></span>
            </a>
            <a href="<?= htmlspecialchars(stfIndexUrl(['active' => '0'])) ?>"
               class="stf-status-tab <?= ($showInactive) ? 'stf-status-tab--active' : '' ?>"
               role="tab">
                All incl. inactive
            </a>
            <a href="<?= htmlspecialchars(stfIndexUrl(['status' => 'trash'])) ?>"
               class="stf-status-tab <?= ($trashView) ? 'stf-status-tab--active' : '' ?>"
               role="tab">
                Trash <span class="stf-status-count"><?= (int) ($countTrash ?? 0) ?></span>
            </a>
        </div>

        <!-- Add Staff CTA — opens drawer -->
        <?php if (!$trashView): ?>
        <a href="/staff/create" data-drawer-url="/staff/create" class="stf-create-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Staff
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($staff)): ?>
<!-- ── Empty state ─────────────────────────────────────────────────────────── -->
<div class="stf-empty">
    <?php if ($trashView): ?>
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
    <p class="stf-empty__title">Trash is empty</p>
    <p class="stf-empty__sub"><a href="/staff">← Back to staff list</a></p>
    <?php elseif ($showInactive): ?>
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
    <p class="stf-empty__title">No staff found</p>
    <p class="stf-empty__sub">No active or inactive staff members yet. <a href="/staff/create">Add your first staff member.</a></p>
    <?php else: ?>
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    <p class="stf-empty__title">No active staff yet</p>
    <p class="stf-empty__sub"><a href="/staff/create">Add your first staff member</a> to get started.</p>
    <?php endif; ?>
</div>
<?php else: ?>

<!-- ── Bulk action bar ─────────────────────────────────────────────────────── -->
<form method="post" action="/staff/bulk-trash" id="stf-bulk-form" class="stf-bulk-bar">
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
    <input type="hidden" name="list_status" value="<?= !empty($trashView) ? 'trash' : '' ?>">
    <input type="hidden" name="list_page" value="<?= (int) $page ?>">
    <input type="hidden" name="list_active" value="<?= $showInactive ? '0' : '1' ?>">
    <select name="bulk_action" id="stf-bulk-action" class="stf-bulk-select" aria-label="Bulk action">
        <option value="">Bulk action…</option>
        <?php if (empty($trashView)): ?>
        <option value="move_to_trash">Move to Trash</option>
        <?php else: ?>
        <option value="restore">Restore</option>
        <option value="delete_permanently">Delete permanently</option>
        <?php endif; ?>
    </select>
    <button type="submit" class="stf-bulk-apply" id="stf-bulk-apply">Apply</button>
    <span class="stf-bulk-count" id="stf-bulk-count" hidden></span>
</form>

<!-- ── Staff table ─────────────────────────────────────────────────────────── -->
<div class="stf-table-wrap">
<table class="stf-table" id="stf-table">
    <thead>
        <tr>
            <th class="stf-th stf-th--check">
                <input type="checkbox" id="stf-check-all" title="Select all" aria-label="Select all visible rows">
            </th>
            <th class="stf-th stf-th--name">
                <?php
                $nameDir    = ($sortCol === 'name' && $sortDir === 'asc') ? 'desc' : 'asc';
                $nameActive = ($sortCol === 'name');
                ?>
                <a href="<?= htmlspecialchars(stfIndexUrl(['status' => $baseStatus, 'active' => $baseActive, 'sort' => 'name', 'dir' => $nameDir, 'page' => $basePage])) ?>"
                   class="stf-sort-link <?= $nameActive ? 'stf-sort-link--active' : '' ?>">
                    Name <span class="stf-sort-icon"><?= $nameActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="stf-th stf-th--job">
                <?php
                $jobDir    = ($sortCol === 'job_title' && $sortDir === 'asc') ? 'desc' : 'asc';
                $jobActive = ($sortCol === 'job_title');
                ?>
                <a href="<?= htmlspecialchars(stfIndexUrl(['status' => $baseStatus, 'active' => $baseActive, 'sort' => 'job_title', 'dir' => $jobDir, 'page' => $basePage])) ?>"
                   class="stf-sort-link <?= $jobActive ? 'stf-sort-link--active' : '' ?>">
                    Job Title <span class="stf-sort-icon"><?= $jobActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="stf-th stf-th--email">
                <?php
                $emailDir    = ($sortCol === 'email' && $sortDir === 'asc') ? 'desc' : 'asc';
                $emailActive = ($sortCol === 'email');
                ?>
                <a href="<?= htmlspecialchars(stfIndexUrl(['status' => $baseStatus, 'active' => $baseActive, 'sort' => 'email', 'dir' => $emailDir, 'page' => $basePage])) ?>"
                   class="stf-sort-link <?= $emailActive ? 'stf-sort-link--active' : '' ?>">
                    Email <span class="stf-sort-icon"><?= $emailActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="stf-th stf-th--phone">Phone</th>
            <th class="stf-th stf-th--type">
                <?php
                $typeDir    = ($sortCol === 'type' && $sortDir === 'asc') ? 'desc' : 'asc';
                $typeActive = ($sortCol === 'type');
                ?>
                <a href="<?= htmlspecialchars(stfIndexUrl(['status' => $baseStatus, 'active' => $baseActive, 'sort' => 'type', 'dir' => $typeDir, 'page' => $basePage])) ?>"
                   class="stf-sort-link <?= $typeActive ? 'stf-sort-link--active' : '' ?>">
                    Type <span class="stf-sort-icon"><?= $typeActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="stf-th stf-th--status">
                <?php
                $statusDir    = ($sortCol === 'status' && $sortDir === 'asc') ? 'desc' : 'asc';
                $statusActive = ($sortCol === 'status');
                ?>
                <a href="<?= htmlspecialchars(stfIndexUrl(['status' => $baseStatus, 'active' => $baseActive, 'sort' => 'status', 'dir' => $statusDir, 'page' => $basePage])) ?>"
                   class="stf-sort-link <?= $statusActive ? 'stf-sort-link--active' : '' ?>">
                    Status <span class="stf-sort-icon"><?= $statusActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="stf-th stf-th--actions">Actions</th>
        </tr>
    </thead>
    <tbody id="stf-table-body">
    <?php foreach ($sortedStaff as $r):
        $active       = !empty($r['is_active']);
        $incomplete   = isset($r['onboarding_step']) && (int) $r['onboarding_step'] < 4;
        $staffType    = match ($r['staff_type'] ?? '') {
            'employee'    => 'Employee',
            'contractor'  => 'Contractor',
            'volunteer'   => 'Volunteer',
            default       => $r['staff_type'] ?? '',
        };
        $phone = $r['mobile_phone'] ?? $r['home_phone'] ?? $r['phone'] ?? '';
    ?>
    <tr class="stf-row"
        data-name="<?= htmlspecialchars(strtolower($r['display_name'] ?? '')) ?>"
        data-job="<?= htmlspecialchars(strtolower($r['job_title'] ?? '')) ?>"
        data-email="<?= htmlspecialchars(strtolower($r['email'] ?? '')) ?>">
        <td class="stf-td stf-td--check">
            <input class="stf-row-check" type="checkbox" name="staff_ids[]" value="<?= (int) $r['id'] ?>" form="stf-bulk-form" aria-label="Select <?= htmlspecialchars($r['display_name'] ?? '') ?>">
        </td>
        <td class="stf-td stf-td--name">
            <div class="stf-name-cell">
                <a href="/staff/<?= (int) $r['id'] ?>" class="stf-name-link"><?= htmlspecialchars($r['display_name'] ?? '') ?></a>
                <?php if ($incomplete && !$trashView): ?>
                <span class="stf-badge stf-badge--incomplete" title="Onboarding not complete (step <?= (int) ($r['onboarding_step'] ?? 1) ?>/4)">Step <?= (int) ($r['onboarding_step'] ?? 1) ?>/4</span>
                <?php endif; ?>
            </div>
        </td>
        <td class="stf-td stf-td--job"><?= htmlspecialchars($r['job_title'] ?? '') ?: '<span class="stf-muted">—</span>' ?></td>
        <td class="stf-td stf-td--email">
            <?php if ($r['email'] ?? null): ?>
            <a href="mailto:<?= htmlspecialchars($r['email']) ?>" class="stf-email-link"><?= htmlspecialchars($r['email']) ?></a>
            <?php else: ?>
            <span class="stf-muted">—</span>
            <?php endif; ?>
        </td>
        <td class="stf-td stf-td--phone"><span class="stf-mono"><?= htmlspecialchars($phone) ?: '<span class="stf-muted">—</span>' ?></span></td>
        <td class="stf-td stf-td--type">
            <?php if ($staffType !== ''): ?>
            <span class="stf-type-badge"><?= htmlspecialchars($staffType) ?></span>
            <?php else: ?>
            <span class="stf-muted">—</span>
            <?php endif; ?>
        </td>
        <td class="stf-td stf-td--status">
            <span class="stf-status <?= $active ? 'stf-status--active' : 'stf-status--inactive' ?>"><?= $active ? 'Active' : 'Inactive' ?></span>
        </td>
        <td class="stf-td stf-td--actions">
            <div class="stf-row-actions">
                <?php if (empty($trashView)): ?>
                <a href="/staff/<?= (int) $r['id'] ?>/edit" class="stf-act stf-act--edit" title="Edit staff member">Edit</a>
                <form method="post" action="/staff/<?= (int) $r['id'] ?>/delete" class="stf-act-form"
                      onsubmit="return confirm('Move «<?= htmlspecialchars(addslashes($r['display_name'] ?? '')) ?>» to Trash?')">
                    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                    <button type="submit" class="stf-act stf-act--trash" title="Move to Trash">Trash</button>
                </form>
                <?php else: ?>
                <form method="post" action="/staff/<?= (int) $r['id'] ?>/restore" class="stf-act-form">
                    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                    <button type="submit" class="stf-act stf-act--restore" title="Restore">Restore</button>
                </form>
                <form method="post" action="/staff/<?= (int) $r['id'] ?>/permanent-delete" class="stf-act-form"
                      onsubmit="return confirm('Permanently delete «<?= htmlspecialchars(addslashes($r['display_name'] ?? '')) ?>»? This cannot be undone.')">
                    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                    <button type="submit" class="stf-act stf-act--delete" title="Permanently delete">Delete</button>
                </form>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- No search results -->
<p id="stf-no-results" class="stf-no-results" hidden>No staff match your search.</p>

<?php endif; ?>

<!-- ── Pagination ──────────────────────────────────────────────────────────── -->
<?php if (!empty($total) && $total > count($staff)): ?>
<?php
$pgBase = stfIndexUrl(['status' => $baseStatus, 'active' => $baseActive, 'sort' => $baseSort, 'dir' => $baseDir]);
$pgSep  = str_contains($pgBase, '?') ? '&' : '?';
?>
<div class="stf-pagination">
    <span class="stf-pagination__info">Page <?= (int) $page ?> of <?= (int) ceil((int) $total / 20) ?> &middot; <?= (int) $total ?> total</span>
    <div class="stf-pagination__nav">
        <?php if ($page > 1): ?>
        <a href="<?= htmlspecialchars($pgBase . $pgSep . 'page=' . ((int) $page - 1)) ?>" class="stf-page-btn">← Previous</a>
        <?php endif; ?>
        <?php if ($page * 20 < (int) $total): ?>
        <a href="<?= htmlspecialchars($pgBase . $pgSep . 'page=' . ((int) $page + 1)) ?>" class="stf-page-btn">Next →</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    // ── Bulk form ────────────────────────────────────────────────────────────
    var bulkForm  = document.getElementById('stf-bulk-form');
    var bulkSel   = document.getElementById('stf-bulk-action');
    var checkAll  = document.getElementById('stf-check-all');
    var bulkCount = document.getElementById('stf-bulk-count');

    function updateBulkCount() {
        var n = document.querySelectorAll('.stf-row-check:checked').length;
        if (bulkCount) {
            if (n > 0) { bulkCount.hidden = false; bulkCount.textContent = n + ' selected'; }
            else        { bulkCount.hidden = true; }
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.stf-row-check').forEach(function (c) { c.checked = checkAll.checked; });
            updateBulkCount();
        });
    }

    document.querySelectorAll('.stf-row-check').forEach(function (c) {
        c.addEventListener('change', updateBulkCount);
    });

    if (bulkForm && bulkSel) {
        bulkForm.addEventListener('submit', function (e) {
            var act = bulkSel.value;
            if (!act) { e.preventDefault(); return false; }
            var checked = document.querySelectorAll('.stf-row-check:checked');
            if (checked.length === 0) { e.preventDefault(); alert('Select at least one staff member.'); return false; }
            var n = checked.length;
            var msg = act === 'move_to_trash'
                ? 'Move ' + n + ' staff member(s) to Trash?'
                : act === 'restore'
                ? 'Restore ' + n + ' staff member(s)?'
                : 'Permanently delete ' + n + ' staff member(s)? This cannot be undone.';
            if (!confirm(msg)) { e.preventDefault(); return false; }
            if (act === 'move_to_trash')      bulkForm.action = '/staff/bulk-trash';
            else if (act === 'restore')        bulkForm.action = '/staff/bulk-restore';
            else if (act === 'delete_permanently') bulkForm.action = '/staff/bulk-permanent-delete';
            else { e.preventDefault(); return false; }
        });
    }

    // ── Client search ────────────────────────────────────────────────────────
    var searchInput = document.getElementById('stf-search-input');
    var searchClear = document.getElementById('stf-search-clear');
    var noResults   = document.getElementById('stf-no-results');

    function applySearch() {
        if (!searchInput) return;
        var q = searchInput.value.toLowerCase().trim();
        if (searchClear) searchClear.hidden = q === '';
        var rows = document.querySelectorAll('#stf-table-body .stf-row');
        var vis = 0;
        rows.forEach(function (r) {
            var match = q === ''
                || (r.dataset.name  || '').includes(q)
                || (r.dataset.job   || '').includes(q)
                || (r.dataset.email || '').includes(q);
            r.style.display = match ? '' : 'none';
            if (match) vis++;
        });
        if (noResults) noResults.hidden = vis > 0 || q === '';
        rows.forEach(function (r) {
            if (r.style.display === 'none') {
                var cb = r.querySelector('.stf-row-check');
                if (cb) cb.checked = false;
            }
        });
        updateBulkCount();
    }

    if (searchInput) {
        searchInput.addEventListener('input', applySearch);
        if (searchClear) searchClear.addEventListener('click', function () {
            searchInput.value = '';
            applySearch();
            searchInput.focus();
        });
    }

    // Keyboard: focus search with /
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/') return;
        var tag = document.activeElement ? document.activeElement.tagName : '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        e.preventDefault();
        if (searchInput) { searchInput.focus(); searchInput.select(); }
    });

})();
</script>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
