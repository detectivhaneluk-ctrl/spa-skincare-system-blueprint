<?php
$title = 'Duplicate Search · Clients';
$mainClass = 'clients-workspace-page';
$clientsWorkspaceActiveTab = 'duplicates';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
ob_start();
?>
<?php require base_path('modules/clients/views/partials/clients-workspace-shell.php'); ?>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<?php
$dupQueryBuild = static function (array $base, array $overrides = []): string {
    $merged = array_merge($base, $overrides);
    $out = [];
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        if ($k === 'partial' && (string) $v === '0') {
            continue;
        }
        $out[$k] = $v;
    }

    return $out === [] ? '' : '?' . http_build_query($out);
};

$queryBase = [];
if ($name !== '') {
    $queryBase['name'] = $name;
}
if ($email !== '') {
    $queryBase['email'] = $email;
}
if ($phone !== '') {
    $queryBase['phone'] = $phone;
}
if ($partial) {
    $queryBase['partial'] = '1';
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
    $w = trim((string) ($r['phone_work'] ?? ''));
    if ($w !== '') {
        return $w;
    }

    return trim((string) ($r['phone'] ?? ''));
};
?>

<div class="calendar-workspace clients-ws-body">
    <div class="clients-ws-op-canvas">
        <h2 class="clients-ws-secondary-panel__title" style="margin-top:0">Find possible duplicate clients</h2>
        <p class="clients-ws-secondary-panel__lead">Search by name, email, or phone. Phone matches the primary, home, mobile, and work fields on file. Use merge preview to combine records safely.</p>

        <form method="get" action="/clients/duplicates" class="clients-ws-dup-form search-form" style="margin-bottom:1.5rem">
            <div class="calendar-field">
                <label for="dup_name">Client name</label>
                <input type="text" id="dup_name" name="name" value="<?= htmlspecialchars($name) ?>" placeholder="Full name" autocomplete="off">
            </div>
            <div class="calendar-field">
                <label for="dup_email">Email</label>
                <input type="text" id="dup_email" name="email" value="<?= htmlspecialchars($email) ?>" placeholder="Email" autocomplete="off">
            </div>
            <div class="calendar-field">
                <label for="dup_phone">Phone</label>
                <input type="text" id="dup_phone" name="phone" value="<?= htmlspecialchars($phone) ?>" placeholder="Any phone on file" autocomplete="off">
            </div>
            <input type="hidden" name="partial" value="0">
            <label><input type="checkbox" name="partial" value="1" <?= $partial ? 'checked' : '' ?>> Include partial match</label>
            <button type="submit" class="calendar-btn calendar-btn--primary">Search</button>
        </form>

        <?php if (!$searchRun): ?>
        <p class="hint">Enter at least one search field and submit to run a duplicate check.</p>
        <?php elseif ($searchRun && !($dupNormalizedSearchReady ?? true)): ?>
        <p class="hint" role="status"><?= htmlspecialchars(\Modules\Clients\Support\ClientNormalizedSearchSchemaReadiness::PUBLIC_UNAVAILABLE_MESSAGE) ?></p>
        <?php elseif ($total === 0): ?>
        <p class="hint">No clients matched these criteria in your organization scope.</p>
        <?php else: ?>
        <?php if (!empty($canMergeClients)): ?>
        <form method="get" action="/clients/merge" id="clients-dup-merge-form">
            <p class="hint" style="margin-top:0">Select one <strong>primary</strong> record (kept) and one <strong>secondary</strong> record (merged in), then open the existing merge preview.</p>
        <?php endif; ?>
        <div class="clients-ws-table-wrap">
            <table class="index-table clients-ws-table">
                <thead>
                    <tr>
                        <?php if (!empty($canMergeClients)): ?>
                        <th>Primary</th>
                        <th>Secondary</th>
                        <?php endif; ?>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Created</th>
                        <th aria-label="Actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($duplicateResults as $r): ?>
                    <?php $cid = (int) $r['id']; ?>
                    <tr>
                        <?php if (!empty($canMergeClients)): ?>
                        <td><input type="radio" name="primary_id" value="<?= $cid ?>"></td>
                        <td><input type="radio" name="secondary_id" value="<?= $cid ?>"></td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars((string) ($r['display_name'] ?? '')) ?></td>
                        <td><?php $em = trim((string) ($r['email'] ?? '')); ?><?= $em !== '' ? htmlspecialchars($em) : '—' ?></td>
                        <td><?php $ph = $primaryPhone($r); ?><?= $ph !== '' ? htmlspecialchars($ph) : '—' ?></td>
                        <td><?php $ca = trim((string) ($r['created_at'] ?? '')); ?><?= $ca !== '' ? htmlspecialchars($ca) : '—' ?></td>
                        <td>
                            <a href="/clients/<?= $cid ?>">Summary</a>
                            ·
                            <a href="/clients/<?= $cid ?>/edit">Edit</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($canMergeClients)): ?>
            <p class="form-actions" style="margin-top:1rem">
                <button type="submit" class="calendar-btn calendar-btn--primary">Review merge</button>
            </p>
        </form>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
        <nav class="clients-ws-pagination" aria-label="Pagination">
            <?php if ($page <= 1): ?>
            <span class="clients-ws-pagination__disabled">Previous</span>
            <?php else: ?>
            <a href="/clients/duplicates<?= htmlspecialchars($dupQueryBuild($queryBase, ['page' => $page - 1])) ?>">Previous</a>
            <?php endif; ?>
            <span class="clients-ws-pagination__current">Page <?= (int) $page ?> of <?= (int) $totalPages ?> (<?= (int) $total ?> total)</span>
            <?php if ($page >= $totalPages): ?>
            <span class="clients-ws-pagination__disabled">Next</span>
            <?php else: ?>
            <a href="/clients/duplicates<?= htmlspecialchars($dupQueryBuild($queryBase, ['page' => $page + 1])) ?>">Next</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($searchRun && $total > 0 && empty($canMergeClients)): ?>
        <p class="hint">Merging requires the <code>clients.edit</code> permission. You can still open client records from the results.</p>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
