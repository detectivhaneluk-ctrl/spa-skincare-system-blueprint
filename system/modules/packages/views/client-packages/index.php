<?php
declare(strict_types=1);

$title = 'Client-held packages';
$totalPages = max(1, (int) ceil($total / $perPage));
$csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$csrfVal = htmlspecialchars($csrf ?? '');
ob_start();
$pkgWorkspaceActiveTab = 'held';
$pkgWorkspaceShellTitle = 'Client-held packages';
$pkgWorkspaceShellSub = 'Client-held package records. Plan templates live under Package plans; checkout assignments live in Sales.';
require base_path('modules/packages/views/partials/packages-workspace-shell.php');
?>
<p class="hint" style="margin-top:0;margin-bottom:0.75rem;">Each row is a <strong>client-owned record</strong> (sessions used/remaining, expiry). The plan template is defined in <a href="/packages">Package plans</a>.</p>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<?php
$pkgHeldBuildQuery = static function (array $base, array $overrides = []): string {
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
if ($filterClientId > 0) {
    $listQueryBase['client_id'] = (string) $filterClientId;
}

$rowStart = $total > 0 ? (($page - 1) * $perPage + 1) : 0;
$rowEnd = $total > 0 ? min($page * $perPage, $total) : 0;
?>

<div class="stf-ws-toolbar">
    <div class="stf-ws-toolbar__left">
        <form method="get" action="/packages/client-packages" class="stf-pkg-held-filter-form" id="pkg-held-filter-form">
            <?php if ($filterClientId > 0): ?>
            <input type="hidden" name="client_id" value="<?= (int) $filterClientId ?>">
            <?php endif; ?>
            <div class="stf-search-wrap" id="pkg-held-search-wrap">
                <svg class="stf-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" name="search" id="pkg_held_search" class="stf-search-input" value="<?= htmlspecialchars($search) ?>" placeholder="Search package/client…" autocomplete="off" aria-label="Search packages">
                <button type="button" id="pkg-held-search-clear" class="stf-search-clear" title="Clear" <?= $search === '' ? 'hidden' : '' ?>>✕</button>
            </div>
            <select name="status" class="stf-toolbar-select" aria-label="Status filter">
                <option value="">All statuses</option>
                <?php foreach (\Modules\Packages\Services\PackageService::CLIENT_PACKAGE_STATUSES as $st): ?>
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
            <strong><?= (int) $total ?></strong> result<?= $total === 1 ? '' : 's' ?> · showing <?= (int) $rowStart ?>–<?= (int) $rowEnd ?>
            <?php endif; ?>
        </p>
        <div class="stf-status-tabs" role="group" aria-label="Related">
            <a href="/packages" class="stf-status-tab">Package plans</a>
            <a href="/clients" class="stf-status-tab">Clients</a>
        </div>
        <a href="/packages/client-packages/assign" class="stf-create-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Assign package
        </a>
    </div>
</div>

<?php if (!empty($canBulkCancel) && !empty($rows)): ?>
<form method="post" action="/packages/client-packages/bulk-cancel" id="pkg-bulk-form" class="stf-bulk-bar stf-bulk-bar--pkg-held">
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
    <input type="hidden" name="list_search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="list_status" value="<?= htmlspecialchars($status) ?>">
    <input type="hidden" name="list_branch_id" value="<?= htmlspecialchars($branchRaw) ?>">
    <input type="hidden" name="list_client_id" value="<?= (int) $filterClientId ?>">
    <input type="hidden" name="list_page" value="<?= (int) $page ?>">
    <select name="bulk_action" id="pkg-bulk-action" class="stf-bulk-select" aria-label="Bulk action">
        <option value="">Bulk action…</option>
        <option value="cancel">Cancel selected</option>
    </select>
    <input type="text" name="bulk_notes" class="stf-bulk-notes" placeholder="Optional note (all rows)" autocomplete="off" aria-label="Optional cancellation note">
    <button type="submit" class="stf-bulk-apply" id="pkg-bulk-apply">Apply</button>
    <span class="stf-bulk-count" id="pkg-bulk-count" hidden></span>
</form>
<?php endif; ?>

<div class="stf-table-wrap">
<table class="stf-table stf-table--pkg-held" id="pkg-held-table">
    <thead>
        <tr>
            <?php if (!empty($canBulkCancel) && !empty($rows)): ?>
            <th class="stf-th stf-th--check">
                <input type="checkbox" id="pkg-check-all" title="Select all on this page" aria-label="Select all packages on this page">
            </th>
            <?php endif; ?>
            <th class="stf-th">ID</th>
            <th class="stf-th">Client</th>
            <th class="stf-th">Plan name</th>
            <th class="stf-th">Assigned</th>
            <th class="stf-th">Remaining</th>
            <th class="stf-th">Status</th>
            <th class="stf-th">Expires</th>
            <th class="stf-th">Branch</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="stf-row">
            <?php if (!empty($canBulkCancel)): ?>
            <td class="stf-td stf-td--check">
                <input class="pkg-row-check" type="checkbox" name="client_package_ids[]" value="<?= (int) $r['id'] ?>" form="pkg-bulk-form" aria-label="Select package #<?= (int) $r['id'] ?>">
            </td>
            <?php endif; ?>
            <td class="stf-td"><a href="/packages/client-packages/<?= (int) $r['id'] ?>" class="stf-email-link">#<?= (int) $r['id'] ?></a></td>
            <td class="stf-td"><?= htmlspecialchars($r['client_display']) ?></td>
            <td class="stf-td"><?= htmlspecialchars((string) ($r['package_name'] ?? '')) ?></td>
            <td class="stf-td stf-td--num"><?= (int) $r['assigned_sessions'] ?></td>
            <td class="stf-td stf-td--num">
                <span class="badge <?= ((int) $r['remaining_now'] <= 0) ? 'badge-warn' : 'badge-success' ?>"><?= (int) $r['remaining_now'] ?></span>
            </td>
            <td class="stf-td"><span class="badge badge-muted"><?= htmlspecialchars((string) ($r['status'] ?? '')) ?></span></td>
            <td class="stf-td"><?= htmlspecialchars((string) ($r['expires_at'] ?? '—')) ?></td>
            <td class="stf-td"><?= !empty($r['branch_id']) ? ('#' . (int) $r['branch_id']) : 'Organisation-wide' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<?php
$pgBase = '/packages/client-packages' . $pkgHeldBuildQuery($listQueryBase);
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

<p class="hint">Branch-assigned packages are managed within their branch. Organisation-wide packages have no branch restriction. List is scoped to your current branch context.</p>

<script>
(function () {
    'use strict';
    var input = document.getElementById('pkg_held_search');
    var clearBtn = document.getElementById('pkg-held-search-clear');

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
            var form = document.getElementById('pkg-held-filter-form');
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

    var bulkForm = document.getElementById('pkg-bulk-form');
    var bulkSel = document.getElementById('pkg-bulk-action');
    var checkAll = document.getElementById('pkg-check-all');
    var bulkCount = document.getElementById('pkg-bulk-count');

    function updateBulkCount() {
        if (!bulkCount) return;
        var n = document.querySelectorAll('.pkg-row-check:checked').length;
        if (n > 0) {
            bulkCount.hidden = false;
            bulkCount.textContent = n + ' selected';
        } else {
            bulkCount.hidden = true;
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.pkg-row-check').forEach(function (c) { c.checked = checkAll.checked; });
            updateBulkCount();
        });
    }
    document.querySelectorAll('.pkg-row-check').forEach(function (c) {
        c.addEventListener('change', function () {
            updateBulkCount();
            if (checkAll) {
                var all = document.querySelectorAll('.pkg-row-check');
                var on = document.querySelectorAll('.pkg-row-check:checked');
                checkAll.checked = all.length > 0 && on.length === all.length;
                checkAll.indeterminate = on.length > 0 && on.length < all.length;
            }
        });
    });

    if (bulkForm && bulkSel) {
        bulkForm.addEventListener('submit', function (e) {
            if (bulkSel.value !== 'cancel') {
                e.preventDefault();
                return false;
            }
            var checked = document.querySelectorAll('.pkg-row-check:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('Select at least one package.');
                return false;
            }
            var n = checked.length;
            if (!confirm('Cancel ' + n + ' client package record(s)?')) {
                e.preventDefault();
                return false;
            }
        });
    }
})();
</script>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
