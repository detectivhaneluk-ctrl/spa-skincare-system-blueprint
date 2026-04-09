<?php
declare(strict_types=1);

$title = 'Packages';
$totalPages = max(1, (int) ceil($total / $perPage));
$csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$csrfVal = htmlspecialchars($csrf ?? '');
ob_start();
$pkgWorkspaceActiveTab = 'plans';
require base_path('modules/packages/views/partials/packages-workspace-shell.php');
?>
<h2 class="stf-pkg-plans-heading">Package plans</h2>
<p class="hint stf-pkg-plans-lead">Plan definitions (sessions, validity, price). Client-held rows are on the <a href="/packages/client-packages" class="stf-email-link">Client-held</a> tab.</p>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<?php
$pkgPlansBuildQuery = static function (array $base, array $overrides = []): string {
    $merged = array_merge($base, $overrides);
    $out = [];
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $out[$k] = $v;
    }

    return $out === [] ? '' : '?' . http_build_query($out);
};

$listQueryBase = [];
if ($search !== '') {
    $listQueryBase['search'] = $search;
}
if ($status !== '') {
    $listQueryBase['status'] = $status;
}
if ($branchRaw !== '') {
    $listQueryBase['branch_id'] = $branchRaw;
}

$rowStart = $total > 0 ? (($page - 1) * $perPage + 1) : 0;
$rowEnd = $total > 0 ? min($page * $perPage, $total) : 0;

$statusBadgeClass = static function (string $raw): string {
    $s = strtolower(trim($raw));
    if ($s === 'active') {
        return 'badge badge-pkg-active';
    }
    if ($s === 'inactive' || $s === 'archived') {
        return 'badge badge-muted';
    }

    return 'badge badge-muted';
};
?>

<div class="stf-ws-toolbar">
    <div class="stf-ws-toolbar__left">
        <form method="get" action="/packages" class="stf-pkg-held-filter-form" id="pkg-plans-filter-form">
            <div class="stf-search-wrap" id="pkg-plans-search-wrap">
                <svg class="stf-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" name="search" id="pkg_plans_search" class="stf-search-input" value="<?= htmlspecialchars($search) ?>" placeholder="Search package name…" autocomplete="off" aria-label="Search package plans">
                <button type="button" id="pkg-plans-search-clear" class="stf-search-clear" title="Clear" <?= $search === '' ? 'hidden' : '' ?>>✕</button>
            </div>
            <select name="status" class="stf-toolbar-select" aria-label="Status filter">
                <option value="">All statuses</option>
                <?php foreach (\Modules\Packages\Services\PackageService::PACKAGE_STATUSES as $st): ?>
                <option value="<?= htmlspecialchars($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="branch_id" class="stf-toolbar-select" aria-label="Branch filter">
                <option value="">All branches</option>
                <option value="global" <?= $branchRaw === 'global' ? 'selected' : '' ?>>Organisation-wide only</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?= (int) $b['id'] ?>" <?= ($branchRaw !== 'global' && $branchRaw !== '' && (int) $branchRaw === (int) $b['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($b['name'] ?? '')) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="stf-toolbar-filter-btn">Filter</button>
        </form>
    </div>
    <div class="stf-ws-toolbar__right">
        <p class="stf-clients-toolbar-meta">
            <?php if ($total === 0): ?>
            <strong>0</strong> results
            <?php else: ?>
            <strong><?= (int) $total ?></strong> plan<?= $total === 1 ? '' : 's' ?> · showing <?= (int) $rowStart ?>–<?= (int) $rowEnd ?>
            <?php endif; ?>
        </p>
        <?php if (!empty($canCreatePlans)): ?>
        <a href="/packages/create" class="stf-create-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New package plan
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($packageDefs === []): ?>
<div class="stf-empty">
    <p class="stf-empty__title">No package plans yet</p>
    <p class="stf-empty__sub"><?php if (!empty($canCreatePlans)): ?><a href="/packages/create">Create your first package plan</a><?php else: ?>Adjust filters or ask an administrator to create plans.<?php endif; ?></p>
</div>
<?php else: ?>

<?php if (!empty($canBulkRemovePlans)): ?>
<form method="post" action="/packages/bulk-soft-delete" id="pkg-plans-bulk-form" class="stf-bulk-bar stf-bulk-bar--pkg-plans">
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
    <input type="hidden" name="list_search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="list_status" value="<?= htmlspecialchars($status) ?>">
    <input type="hidden" name="list_branch_id" value="<?= htmlspecialchars($branchRaw) ?>">
    <input type="hidden" name="list_page" value="<?= (int) $page ?>">
    <select name="bulk_action" id="pkg-plans-bulk-action" class="stf-bulk-select" aria-label="Bulk action">
        <option value="">Bulk action…</option>
        <option value="remove">Remove from catalog</option>
    </select>
    <button type="submit" class="stf-bulk-apply" id="pkg-plans-bulk-apply">Apply</button>
    <span class="stf-bulk-count" id="pkg-plans-bulk-count" hidden></span>
</form>
<?php endif; ?>

<div class="stf-table-wrap">
<table class="stf-table stf-table--pkg-plans" id="pkg-plans-table">
    <thead>
        <tr>
            <?php if (!empty($canBulkRemovePlans)): ?>
            <th class="stf-th stf-th--check">
                <input type="checkbox" id="pkg-plans-check-all" title="Select all on this page" aria-label="Select all plans on this page">
            </th>
            <?php endif; ?>
            <th class="stf-th stf-th--pkg-name">Name</th>
            <th class="stf-th">Status</th>
            <th class="stf-th stf-th--num">Total sessions</th>
            <th class="stf-th stf-th--num">Validity (days)</th>
            <th class="stf-th stf-th--num">Price</th>
            <th class="stf-th">Branch</th>
            <th class="stf-th stf-th--actions">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($packageDefs as $p): ?>
        <tr class="stf-row">
            <?php if (!empty($canBulkRemovePlans)): ?>
            <td class="stf-td stf-td--check">
                <input class="pkg-plans-row-check" type="checkbox" name="package_ids[]" value="<?= (int) $p['id'] ?>" form="pkg-plans-bulk-form" aria-label="Select plan <?= htmlspecialchars((string) ($p['name'] ?? '')) ?>">
            </td>
            <?php endif; ?>
            <td class="stf-td stf-td--pkg-name"><?= htmlspecialchars((string) ($p['name'] ?? '')) ?></td>
            <td class="stf-td"><span class="<?= htmlspecialchars($statusBadgeClass((string) ($p['status'] ?? ''))) ?>"><?= htmlspecialchars((string) ($p['status'] ?? '')) ?></span></td>
            <td class="stf-td stf-td--num"><?= (int) $p['total_sessions'] ?></td>
            <td class="stf-td stf-td--num"><?= $p['validity_days'] !== null ? (int) $p['validity_days'] : '—' ?></td>
            <td class="stf-td stf-td--num"><?= $p['price'] !== null ? htmlspecialchars(number_format((float) $p['price'], 2)) : '—' ?></td>
            <td class="stf-td"><?= !empty($p['branch_id']) ? ('#' . (int) $p['branch_id']) : 'Organisation-wide' ?></td>
            <td class="stf-td stf-td--actions">
                <div class="stf-row-actions">
                    <?php if (!empty($canEditPlans)): ?>
                    <a href="/packages/<?= (int) $p['id'] ?>/edit" class="stf-act stf-act--edit" title="Edit plan">Edit</a>
                    <form method="post" action="/packages/<?= (int) $p['id'] ?>/delete" class="stf-act-form"
                          onsubmit="return confirm('Remove this package plan from the catalog? It will no longer appear in the list.');">
                        <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                        <button type="submit" class="stf-act stf-act--trash" title="Remove from catalog">Delete</button>
                    </form>
                    <?php else: ?>
                    <span class="stf-muted">—</span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php endif; ?>

<?php if ($totalPages > 1): ?>
<?php
$pgBase = '/packages' . $pkgPlansBuildQuery($listQueryBase);
$pgSep = str_contains($pgBase, '?') ? '&' : '?';
?>
<div class="stf-pagination">
    <span class="stf-pagination__info">Page <?= (int) $page ?> of <?= (int) $totalPages ?> · <?= (int) $total ?> total</span>
    <div class="stf-pagination__nav">
        <?php if ($page > 1): ?>
        <a href="<?= htmlspecialchars($pgBase . $pgSep . 'page=' . ((int) $page - 1)) ?>" class="stf-page-btn">← Previous</a>
        <?php endif; ?>
        <?php if ($page * $perPage < (int) $total): ?>
        <a href="<?= htmlspecialchars($pgBase . $pgSep . 'page=' . ((int) $page + 1)) ?>" class="stf-page-btn">Next →</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<p class="hint">Plans scoped to a branch are only available at that branch. Organisation-wide plans are available across all branches. This list is scoped to your current branch context; the branch filter preserves your query for later use. <strong>Remove from catalog</strong> soft-deletes plans (they no longer appear in the list).</p>

<script>
(function () {
    'use strict';
    var input = document.getElementById('pkg_plans_search');
    var clearBtn = document.getElementById('pkg-plans-search-clear');

    function syncClear() {
        if (!clearBtn || !input) return;
        clearBtn.hidden = (input.value || '').trim() === '';
    }

    if (input) {
        input.addEventListener('input', syncClear);
        syncClear();
    }
    if (clearBtn && input) {
        clearBtn.addEventListener('click', function () {
            var form = document.getElementById('pkg-plans-filter-form');
            if (form) {
                input.value = '';
                form.submit();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== '/') return;
        var tag = document.activeElement ? document.activeElement.tagName : '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        e.preventDefault();
        if (input) { input.focus(); input.select(); }
    });

    var bulkForm = document.getElementById('pkg-plans-bulk-form');
    var bulkSel = document.getElementById('pkg-plans-bulk-action');
    var checkAll = document.getElementById('pkg-plans-check-all');
    var bulkCount = document.getElementById('pkg-plans-bulk-count');

    function updateBulkCount() {
        if (!bulkCount) return;
        var n = document.querySelectorAll('.pkg-plans-row-check:checked').length;
        if (n > 0) {
            bulkCount.hidden = false;
            bulkCount.textContent = n + ' selected';
        } else {
            bulkCount.hidden = true;
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            checkAll.indeterminate = false;
            document.querySelectorAll('.pkg-plans-row-check').forEach(function (c) { c.checked = checkAll.checked; });
            updateBulkCount();
        });
    }
    document.querySelectorAll('.pkg-plans-row-check').forEach(function (c) {
        c.addEventListener('change', function () {
            updateBulkCount();
            if (checkAll) {
                var all = document.querySelectorAll('.pkg-plans-row-check');
                var on = document.querySelectorAll('.pkg-plans-row-check:checked');
                checkAll.checked = all.length > 0 && on.length === all.length;
                checkAll.indeterminate = on.length > 0 && on.length < all.length;
            }
        });
    });

    if (bulkForm && bulkSel) {
        bulkForm.addEventListener('submit', function (e) {
            if (bulkSel.value !== 'remove') {
                e.preventDefault();
                return false;
            }
            var checked = document.querySelectorAll('.pkg-plans-row-check:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('Select at least one plan.');
                return false;
            }
            var n = checked.length;
            if (!confirm('Remove ' + n + ' plan(s) from the catalog? They will be hidden from this list.')) {
                e.preventDefault();
                return false;
            }
        });
    }
})();
</script>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
