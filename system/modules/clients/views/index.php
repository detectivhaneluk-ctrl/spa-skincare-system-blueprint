<?php
$title = 'Clients';
$clientsWorkspaceActiveTab = 'list';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
$totalPages = max(1, (int) ceil($total / $perPage));
$csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$csrfVal  = htmlspecialchars($csrf ?? '');
ob_start();
?>
<?php require base_path('modules/clients/views/partials/clients-workspace-shell.php'); ?>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<?php
$clientsListBuildQuery = static function (array $base, array $overrides = []): string {
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

$primaryPhone = static function (array $r): string {
    $m = trim((string) ($r['phone_mobile'] ?? ''));
    if ($m !== '') {
        return $m;
    }
    $h = trim((string) ($r['phone_home'] ?? ''));
    if ($h !== '') {
        return $h;
    }

    return trim((string) ($r['phone'] ?? ''));
};

$formatAddress = static function (array $r): string {
    $l1 = trim((string) ($r['home_address_1'] ?? ''));
    $l2 = trim((string) ($r['home_address_2'] ?? ''));
    if ($l1 === '' && $l2 === '') {
        return '';
    }
    if ($l2 === '') {
        return $l1;
    }
    if ($l1 === '') {
        return $l2;
    }

    return $l1 . ', ' . $l2;
};

$rowStart = $total > 0 ? (($page - 1) * $perPage + 1) : 0;
$rowEnd = $total > 0 ? min($page * $perPage, $total) : 0;

$svgHeldMembership = '<svg class="stf-held-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
$svgHeldPackage = '<svg class="stf-held-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
$svgHeldGift = '<svg class="stf-held-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>';
$svgCliView = '<svg class="stf-cli-act-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
$svgCliEdit = '<svg class="stf-cli-act-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
$svgCliTrash = '<svg class="stf-cli-act-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
?>

<div class="clients-directory">
<div class="stf-ws-toolbar">
    <div class="stf-ws-toolbar__left">
        <form method="get" action="/clients" class="stf-clients-search-form" id="clients-search-form">
            <div class="stf-search-wrap" id="clients-search-wrap">
                <svg class="stf-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" name="search" id="clients_search" class="stf-search-input" value="<?= htmlspecialchars($search) ?>" placeholder="Name, email, or phone" autocomplete="off" aria-label="Search clients">
                <button type="button" id="clients-search-clear" class="stf-search-clear" title="Clear" <?= $search === '' ? 'hidden' : '' ?>>✕</button>
            </div>
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
        <div class="stf-status-tabs" role="group" aria-label="Client list shortcuts">
            <a href="/memberships/client-memberships" class="stf-status-tab">Active memberships</a>
            <a href="/packages/client-packages" class="stf-status-tab">Client packages</a>
        </div>
        <?php if (!empty($canCreateClients)): ?>
        <a href="/clients/create" data-drawer-url="/clients/create" class="stf-create-btn">
            <svg class="stf-create-btn__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Client
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($clientsDupBannerShow)): ?>
<div class="cli-dup-intel-banner" role="status" aria-live="polite" data-cli-dup-intel-banner>
    <div class="cli-dup-intel-banner__inner">
        <p class="cli-dup-intel-banner__text"><span class="cli-dup-intel-banner__count"><?= (int) ($clientsDupBannerCount ?? 0) ?></span> <?= ((int) ($clientsDupBannerCount ?? 0)) === 1 ? 'duplicate client' : 'duplicate clients' ?> found — same name, phone, and email.</p>
        <?php if (!empty($canMergeClients) && !empty($mergeModalPair)): ?>
        <button type="button" class="cli-dup-intel-banner__cta" id="cli-merge-banner-open" data-cli-merge-open aria-haspopup="dialog">Review &amp; Merge</button>
        <?php elseif (!empty($canMergeClients)): ?>
        <span class="cli-dup-intel-banner__cta cli-dup-intel-banner__cta--disabled" title="Not enough clients in this list to open a comparison">Review &amp; Merge</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($clients) && !empty($canDeleteClients)): ?>
<form method="post" action="/clients/bulk-delete" id="cli-bulk-form" class="stf-bulk-bar stf-bulk-bar--clients">
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
    <input type="hidden" name="list_search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="list_page" value="<?= (int) $page ?>">
    <select name="bulk_action" id="cli-bulk-action" class="stf-bulk-select" aria-label="Bulk action">
        <option value="">Bulk action…</option>
        <option value="delete">Delete selected</option>
    </select>
    <button type="submit" class="stf-bulk-apply" id="cli-bulk-apply">Apply</button>
    <span class="stf-bulk-count" id="cli-bulk-count" hidden></span>
</form>
<?php endif; ?>

<div class="stf-table-wrap">
<table class="stf-table stf-table--clients" id="clients-table">
    <thead>
        <tr>
            <?php if (!empty($canDeleteClients) && !empty($clients)): ?>
            <th class="stf-th stf-th--check">
                <input type="checkbox" id="cli-check-all" title="Select all on this page" aria-label="Select all clients on this page">
            </th>
            <?php endif; ?>
            <th class="stf-th stf-th--cli-first">First name</th>
            <th class="stf-th stf-th--cli-last">Last name</th>
            <th class="stf-th stf-th--cli-location">Location</th>
            <th class="stf-th stf-th--cli-email">Email</th>
            <th class="stf-th stf-th--cli-phone">Primary phone</th>
            <th class="stf-th stf-th--cli-held" scope="col">
                <span class="visually-hidden">Memberships, packages, and gift cards</span>
                <span aria-hidden="true">Held</span>
            </th>
            <th class="stf-th stf-th--actions stf-th--cli-actions" scope="col">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($clients as $r): ?>
        <?php
        $cid = (int) $r['id'];
        $addr = $formatAddress($r);
        $city = trim((string) ($r['home_city'] ?? ''));
        $postal = trim((string) ($r['home_postal_code'] ?? ''));
        $locParts = array_filter([$addr, $city, $postal], static fn ($x) => $x !== '');
        $locationLine = $locParts === [] ? '' : implode(' · ', $locParts);
        $pd = $r['display_phone'] ?? null;
        $phoneDisp = ($pd !== null && trim((string) $pd) !== '') ? trim((string) $pd) : $primaryPhone($r);
        ?>
        <tr class="stf-row">
            <?php if (!empty($canDeleteClients)): ?>
            <td class="stf-td stf-td--check">
                <input class="cli-row-check" type="checkbox" name="client_ids[]" value="<?= $cid ?>" form="cli-bulk-form" aria-label="Select <?= htmlspecialchars(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?>">
            </td>
            <?php endif; ?>
            <td class="stf-td stf-td--cli-name"><?= htmlspecialchars((string) ($r['first_name'] ?? '')) ?></td>
            <td class="stf-td stf-td--cli-name"><?= htmlspecialchars((string) ($r['last_name'] ?? '')) ?></td>
            <td class="stf-td stf-td--cli-location"<?= $locationLine !== '' ? ' title="' . htmlspecialchars($locationLine, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= $locationLine !== '' ? htmlspecialchars($locationLine) : '<span class="stf-empty-dash">—</span>' ?></td>
            <?php $email = trim((string) ($r['email'] ?? '')); ?>
            <td class="stf-td stf-td--email">
                <?php if ($email !== ''): ?>
                <a href="mailto:<?= htmlspecialchars($email) ?>" class="stf-email-link"><?= htmlspecialchars($email) ?></a>
                <?php else: ?>
                <span class="stf-empty-dash">—</span>
                <?php endif; ?>
            </td>
            <td class="stf-td stf-td--phone"><span class="stf-mono"><?= $phoneDisp !== '' ? htmlspecialchars($phoneDisp) : '<span class="stf-empty-dash">—</span>' ?></span></td>
            <td class="stf-td stf-td--cli-held">
                <?php
                $heldLinks = [];
                if (!empty($canDeepLinkMemberships)) {
                    $heldLinks[] = '<a class="stf-held-link" href="/memberships/client-memberships?client_id=' . $cid . '" title="Memberships" aria-label="Memberships for this client">' . $svgHeldMembership . '</a>';
                }
                if (!empty($canDeepLinkPackages)) {
                    $heldLinks[] = '<a class="stf-held-link" href="/packages/client-packages?client_id=' . $cid . '" title="Packages" aria-label="Packages for this client">' . $svgHeldPackage . '</a>';
                }
                if (!empty($canDeepLinkGiftCards)) {
                    $heldLinks[] = '<a class="stf-held-link" href="/gift-cards?client_id=' . $cid . '" title="Gift cards" aria-label="Gift cards for this client">' . $svgHeldGift . '</a>';
                }
                echo $heldLinks === [] ? '<span class="stf-empty-dash">—</span>' : '<div class="stf-held-links">' . implode('', $heldLinks) . '</div>';
                ?>
            </td>
            <td class="stf-td stf-td--actions stf-td--cli-actions">
                <div class="stf-row-actions stf-row-actions--iconbar">
                    <a href="/clients/<?= $cid ?>" class="stf-cli-act" title="Open client" aria-label="Open client"><?= $svgCliView ?></a>
                    <a href="/clients/<?= $cid ?>/edit" class="stf-cli-act" title="Edit details" aria-label="Edit client details"><?= $svgCliEdit ?></a>
                    <?php if (!empty($canDeleteClients)): ?>
                    <form method="post" action="/clients/<?= $cid ?>/delete" class="stf-act-form" onsubmit="return confirm('Delete this client?')">
                        <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                        <button type="submit" class="stf-cli-act stf-cli-act--danger" title="Delete client" aria-label="Delete client"><?= $svgCliTrash ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<?php
$pgBase = '/clients' . $clientsListBuildQuery($listQueryBase);
$pgSep  = str_contains($pgBase, '?') ? '&' : '?';
?>
<div class="stf-pagination">
    <span class="stf-pagination__info">Page <?= (int) $page ?> of <?= (int) $totalPages ?> &middot; <?= (int) $total ?> total</span>
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

<?php if (!empty($canMergeClients) && !empty($mergeModalPair) && is_array($mergeModalPair) && count($mergeModalPair) === 2): ?>
<?php
$mm0 = $mergeModalPair[0];
$mm1 = $mergeModalPair[1];
$mmAuto = !empty($mergeModalAutoOpen);
?>
<div
    id="cli-merge-modal"
    class="cli-merge-modal"
    hidden
    data-cli-merge-modal
    data-cli-merge-auto-open="<?= $mmAuto ? '1' : '0' ?>"
    aria-hidden="true"
>
    <div class="cli-merge-modal__backdrop" data-cli-merge-close tabindex="-1" aria-hidden="true"></div>
    <div
        class="cli-merge-modal__dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="cli-merge-modal-title"
        tabindex="-1"
    >
        <button type="button" class="cli-merge-modal__close" data-cli-merge-close aria-label="Close">&times;</button>
        <h2 id="cli-merge-modal-title" class="cli-merge-modal__title">Merge Duplicate Clients</h2>
        <p class="cli-merge-modal__lead">Select the primary information to keep. The other record will be archived.</p>
        <form id="cli-merge-action-form" method="post" action="/clients/merge" class="cli-merge-modal__form">
            <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
            <input type="hidden" name="merge_response" value="json">
            <input type="hidden" name="primary_id" id="cli-merge-primary-id" value="<?= (int) $mm0['id'] ?>">
            <input type="hidden" name="secondary_id" id="cli-merge-secondary-id" value="<?= (int) $mm1['id'] ?>">
            <div class="cli-merge-modal__grid">
                <button
                    type="button"
                    class="cli-merge-card is-selected"
                    data-cli-merge-card
                    data-client-id="<?= (int) $mm0['id'] ?>"
                    aria-pressed="true"
                >
                    <span class="cli-merge-card__badge"><?= htmlspecialchars($mm0['record_label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="cli-merge-card__hint">Primary profile</span>
                    <dl class="cli-merge-card__rows">
                        <div class="cli-merge-card__row">
                            <dt class="cli-merge-card__dt">Name</dt>
                            <dd class="cli-merge-card__dd"><?= htmlspecialchars($mm0['name'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div class="cli-merge-card__row">
                            <dt class="cli-merge-card__dt">Phone</dt>
                            <dd class="cli-merge-card__dd cli-merge-card__dd--mono"><?= htmlspecialchars($mm0['phone'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div class="cli-merge-card__row">
                            <dt class="cli-merge-card__dt">Email</dt>
                            <dd class="cli-merge-card__dd"><?= htmlspecialchars($mm0['email'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div class="cli-merge-card__row">
                            <dt class="cli-merge-card__dt">Last visit</dt>
                            <dd class="cli-merge-card__dd"><?= htmlspecialchars($mm0['last_visit'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    </dl>
                </button>
                <button
                    type="button"
                    class="cli-merge-card"
                    data-cli-merge-card
                    data-client-id="<?= (int) $mm1['id'] ?>"
                    aria-pressed="false"
                >
                    <span class="cli-merge-card__badge"><?= htmlspecialchars($mm1['record_label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="cli-merge-card__hint">Tap to use as primary</span>
                    <dl class="cli-merge-card__rows">
                        <div class="cli-merge-card__row">
                            <dt class="cli-merge-card__dt">Name</dt>
                            <dd class="cli-merge-card__dd"><?= htmlspecialchars($mm1['name'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div class="cli-merge-card__row">
                            <dt class="cli-merge-card__dt">Phone</dt>
                            <dd class="cli-merge-card__dd cli-merge-card__dd--mono"><?= htmlspecialchars($mm1['phone'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div class="cli-merge-card__row">
                            <dt class="cli-merge-card__dt">Email</dt>
                            <dd class="cli-merge-card__dd"><?= htmlspecialchars($mm1['email'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div class="cli-merge-card__row">
                            <dt class="cli-merge-card__dt">Last visit</dt>
                            <dd class="cli-merge-card__dd"><?= htmlspecialchars($mm1['last_visit'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    </dl>
                </button>
            </div>
            <p class="cli-merge-modal__footnote">Linked appointments and sales move to the primary. This queues a background merge job.</p>
            <div class="cli-merge-modal__notes">
                <label for="cli-merge-notes" class="cli-merge-modal__notes-label">Notes <span class="cli-merge-modal__optional">(optional)</span></label>
                <textarea id="cli-merge-notes" name="notes" class="cli-merge-modal__notes-input" rows="2" placeholder="Internal note for this merge"></textarea>
            </div>
            <div class="cli-merge-modal__footer">
                <button type="button" class="cli-merge-modal__btn cli-merge-modal__btn--secondary" data-cli-merge-close>Cancel</button>
                <button type="submit" class="cli-merge-modal__btn cli-merge-modal__btn--primary" id="cli-merge-confirm">Confirm Merge</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

</div>

<script src="/assets/js/client-merge-modal.js" defer></script>
<script>
(function () {
    'use strict';
    var input = document.getElementById('clients_search');
    var clearBtn = document.getElementById('clients-search-clear');

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
            window.location.href = '/clients';
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== '/') return;
        var tag = document.activeElement ? document.activeElement.tagName : '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        e.preventDefault();
        if (input) { input.focus(); input.select(); }
    });

    var bulkForm = document.getElementById('cli-bulk-form');
    var bulkSel = document.getElementById('cli-bulk-action');
    var checkAll = document.getElementById('cli-check-all');
    var bulkCount = document.getElementById('cli-bulk-count');

    function updateBulkCount() {
        if (!bulkCount) return;
        var n = document.querySelectorAll('.cli-row-check:checked').length;
        if (n > 0) {
            bulkCount.hidden = false;
            bulkCount.textContent = n + ' selected';
        } else {
            bulkCount.hidden = true;
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.cli-row-check').forEach(function (c) { c.checked = checkAll.checked; });
            updateBulkCount();
        });
    }
    document.querySelectorAll('.cli-row-check').forEach(function (c) {
        c.addEventListener('change', function () {
            updateBulkCount();
            if (checkAll) {
                var all = document.querySelectorAll('.cli-row-check');
                var on = document.querySelectorAll('.cli-row-check:checked');
                checkAll.checked = all.length > 0 && on.length === all.length;
                checkAll.indeterminate = on.length > 0 && on.length < all.length;
            }
        });
    });

    if (bulkForm && bulkSel) {
        bulkForm.addEventListener('submit', function (e) {
            if (bulkSel.value !== 'delete') {
                e.preventDefault();
                return false;
            }
            var checked = document.querySelectorAll('.cli-row-check:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('Select at least one client.');
                return false;
            }
            var n = checked.length;
            if (!confirm('Delete ' + n + ' client(s)? This cannot be undone from this screen.')) {
                e.preventDefault();
                return false;
            }
        });
    }
})();
</script>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
