<?php
$title = 'Clients';
$mainClass = 'clients-workspace-page';
$clientsWorkspaceActiveTab = 'list';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
$totalPages = max(1, (int) ceil($total / $perPage));
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
?>

<div class="calendar-workspace clients-ws-body">
    <div class="calendar-workspace-layout">
        <aside class="calendar-sidebar" aria-label="Search clients">
            <div class="calendar-sidebar-card">
                <p class="calendar-sidebar-kicker">Search</p>
                <p class="clients-ws-sidebar-lead">Match first name, last name, email, or any phone field on file. Open a client’s summary to see <strong>held packages</strong> (client-owned records; plan templates live in Catalog) and gift cards in the Owned value section.</p>
                <form method="get" action="/clients" class="clients-ws-sidebar-form">
                    <div class="calendar-field">
                        <label for="clients_search">Terms</label>
                        <input type="text" id="clients_search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, email, or phone" autocomplete="off">
                    </div>
                    <div class="clients-ws-sidebar-form__submit">
                        <button type="submit" class="calendar-btn calendar-btn--primary clients-ws-btn-block">Search</button>
                    </div>
                </form>
                <div class="clients-ws-placeholder-block">
                    <p class="clients-ws-placeholder-block__title">Member card #</p>
                    <p class="hint calendar-sidebar-foot clients-ws-placeholder-block__text">Not available yet — no member-card field on the client record in this version.</p>
                    <p class="clients-ws-placeholder-block__title">Customer exports</p>
                    <p class="hint calendar-sidebar-foot clients-ws-placeholder-block__text">Not available yet — no export action is wired for this list.</p>
                </div>
            </div>
        </aside>

        <div class="calendar-workspace-primary">
            <div class="clients-ws-op-canvas">
                <div class="clients-ws-toolbar" role="region" aria-label="Client list actions">
                    <div class="clients-ws-toolbar__primary">
                        <a href="/clients/create" class="calendar-btn calendar-btn--primary">New Client</a>
                        <a href="/clients/duplicates" class="calendar-btn">Duplicate Search</a>
                        <a href="/memberships/client-memberships" class="calendar-btn">Active memberships</a>
                        <a href="/packages/client-packages" class="calendar-btn">Client packages</a>
                    </div>
                    <p class="clients-ws-results-meta">
                        <?php if ($total === 0): ?>
                        <strong>0</strong> results
                        <?php else: ?>
                        <strong><?= (int) $total ?></strong> result<?= $total === 1 ? '' : 's' ?> · showing <?= (int) $rowStart ?>–<?= (int) $rowEnd ?>
                        <?php endif; ?>
                    </p>
                </div>

                <?php
                $svgEye = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
                $svgPencil = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L8 18l-4 1 1-4 11.5-11.5z"/></svg>';
                $svgTrash = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>';
                ?>

                <div class="clients-ws-table-wrap">
                <table class="index-table clients-ws-table">
                    <thead>
                        <tr>
                            <th>First name</th>
                            <th>Last name</th>
                            <th>Address</th>
                            <th>City</th>
                            <th>Postal code</th>
                            <th>Email</th>
                            <th>Primary phone</th>
                            <th aria-label="Actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $r): ?>
                        <?php
                        $cid = (int) $r['id'];
                        $addr = $formatAddress($r);
                        $pd = $r['display_phone'] ?? null;
                        $phoneDisp = ($pd !== null && trim((string) $pd) !== '') ? trim((string) $pd) : $primaryPhone($r);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($r['first_name'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($r['last_name'] ?? '')) ?></td>
                            <td><?= $addr !== '' ? htmlspecialchars($addr) : '—' ?></td>
                            <?php $city = trim((string) ($r['home_city'] ?? '')); ?>
                            <?php $postal = trim((string) ($r['home_postal_code'] ?? '')); ?>
                            <?php $email = trim((string) ($r['email'] ?? '')); ?>
                            <td><?= $city !== '' ? htmlspecialchars($city) : '—' ?></td>
                            <td><?= $postal !== '' ? htmlspecialchars($postal) : '—' ?></td>
                            <td><?= $email !== '' ? htmlspecialchars($email) : '—' ?></td>
                            <td><?= $phoneDisp !== '' ? htmlspecialchars($phoneDisp) : '—' ?></td>
                            <td>
                                <div class="clients-ws-icon-actions">
                                    <a class="clients-ws-icon-btn" href="/clients/<?= $cid ?>" title="Open client (summary)" aria-label="Open client"><?= $svgEye ?></a>
                                    <a class="clients-ws-icon-btn" href="/clients/<?= $cid ?>/edit" title="Edit details" aria-label="Edit client"><?= $svgPencil ?></a>
                                    <form method="post" action="/clients/<?= $cid ?>/delete" class="clients-ws-icon-form" onsubmit="return confirm('Delete this client?')">
                                        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                        <button type="submit" class="clients-ws-icon-btn clients-ws-icon-btn--danger" title="Delete client" aria-label="Delete client"><?= $svgTrash ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <nav class="clients-ws-pagination" aria-label="Pagination">
                    <?php if ($page <= 1): ?>
                    <span class="clients-ws-pagination__disabled">Previous</span>
                    <?php else: ?>
                    <a href="/clients<?= htmlspecialchars($clientsListBuildQuery($listQueryBase, ['page' => $page - 1])) ?>">Previous</a>
                    <?php endif; ?>
                    <?php
                    $winStart = max(1, $page - 2);
                    $winEnd = min($totalPages, $page + 2);
                    if ($winStart > 1): ?>
                    <a href="/clients<?= htmlspecialchars($clientsListBuildQuery($listQueryBase, ['page' => 1])) ?>">1</a>
                    <?php if ($winStart > 2): ?><span class="clients-ws-pagination__ellipsis">…</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $winStart; $p <= $winEnd; $p++): ?>
                    <?php if ($p === $page): ?>
                    <span class="clients-ws-pagination__current"><?= (int) $p ?></span>
                    <?php else: ?>
                    <a href="/clients<?= htmlspecialchars($clientsListBuildQuery($listQueryBase, ['page' => $p])) ?>"><?= (int) $p ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($winEnd < $totalPages): ?>
                    <?php if ($winEnd < $totalPages - 1): ?><span class="clients-ws-pagination__ellipsis">…</span><?php endif; ?>
                    <a href="/clients<?= htmlspecialchars($clientsListBuildQuery($listQueryBase, ['page' => $totalPages])) ?>"><?= (int) $totalPages ?></a>
                    <?php endif; ?>
                    <?php if ($page >= $totalPages): ?>
                    <span class="clients-ws-pagination__disabled">Next</span>
                    <?php else: ?>
                    <a href="/clients<?= htmlspecialchars($clientsListBuildQuery($listQueryBase, ['page' => $page + 1])) ?>">Next</a>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
