<?php
/** @var array<string, mixed>|null $flash */
/** @var array{salons: list<array<string, mixed>>} $payload */
/** @var bool $canManage */
$payload = is_array($payload ?? null) ? $payload : ['salons' => []];
$salons = $payload['salons'] ?? [];
$q = isset($_GET['q']) ? (string) $_GET['q'] : '';
$status = isset($_GET['status']) ? (string) $_GET['status'] : 'all';
$problemsOnly = isset($_GET['problems']) && (string) $_GET['problems'] === '1';
?>
<div class="founder-registry">
    <div class="founder-registry__head">
        <h1 class="founder-registry__title">Salons</h1>
        <?php if (!empty($canManage)): ?>
            <a class="founder-registry__add" href="/platform-admin/salons/create">Add salon</a>
        <?php endif; ?>
    </div>

    <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
        <p class="founder-registry__flash" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></p>
    <?php endif; ?>

    <form class="founder-registry__filters" method="get" action="/platform-admin/salons">
        <label class="founder-registry__filter">
            <span class="founder-registry__filter-label">Search</span>
            <input class="founder-registry__input" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Name, code, ID" autocomplete="off">
        </label>
        <label class="founder-registry__filter">
            <span class="founder-registry__filter-label">Status</span>
            <select class="founder-registry__select" name="status">
                <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option>
                <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="suspended"<?= $status === 'suspended' ? ' selected' : '' ?>>Suspended</option>
                <option value="archived"<?= $status === 'archived' ? ' selected' : '' ?>>Archived</option>
            </select>
        </label>
        <label class="founder-registry__filter founder-registry__filter--inline">
            <input type="checkbox" name="problems" value="1"<?= $problemsOnly ? ' checked' : '' ?>>
            <span>Problems only</span>
        </label>
        <button type="submit" class="founder-registry__apply">Apply</button>
    </form>

    <div class="founder-registry__table-wrap">
        <table class="founder-registry__table">
            <thead>
            <tr>
                <th scope="col" class="founder-registry__th--narrow">ID</th>
                <th scope="col">Salon</th>
                <th scope="col">Code</th>
                <th scope="col">Status</th>
                <th scope="col">Admin</th>
                <th scope="col" class="founder-registry__th--num">Branches</th>
                <th scope="col" class="founder-registry__th--num">Problems</th>
                <th scope="col">Plan</th>
                <th scope="col">Updated</th>
                <th scope="col" class="founder-registry__th--actions"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($salons as $row): ?>
                <?php
                $actions = $row['available_actions'] ?? [];
                if (!is_array($actions)) {
                    $actions = [];
                }
                $lifecycle = (string) ($row['lifecycle_status'] ?? '');
                $rid = (int) ($row['id'] ?? 0);
                $prob = (int) ($row['problem_count'] ?? 0);
                $plan = (string) ($row['plan_summary'] ?? '');
                $planMuted = ($plan === '' || $plan === '—');
                $secondary = [];
                foreach ($actions as $a) {
                    if (!is_array($a)) {
                        continue;
                    }
                    if (($a['key'] ?? '') === 'open') {
                        continue;
                    }
                    $secondary[] = $a;
                }
                ?>
                <tr class="founder-registry__row">
                    <td class="founder-registry__mono"><?= $rid ?></td>
                    <td>
                        <a class="founder-registry__salon-name" href="/platform-admin/salons/<?= $rid ?>"><?= htmlspecialchars((string) ($row['name'] ?? '')) ?></a>
                    </td>
                    <td class="founder-registry__mono"><?php $c = $row['code'] ?? null; echo $c !== null && $c !== '' ? '<code class="founder-registry__code">' . htmlspecialchars((string) $c) . '</code>' : '<span class="founder-registry__muted">—</span>'; ?></td>
                    <td><span class="founder-registry__status founder-registry__status--<?= htmlspecialchars($lifecycle) ?>"><?= htmlspecialchars($lifecycle) ?></span></td>
                    <td class="founder-registry__email"><?php $em = $row['primary_admin_email'] ?? null; echo $em !== null && $em !== '' ? htmlspecialchars((string) $em) : '<span class="founder-registry__muted">—</span>'; ?></td>
                    <td class="founder-registry__num"><?= (int) ($row['branch_count'] ?? 0) ?></td>
                    <td class="founder-registry__num<?= $prob > 0 ? ' founder-registry__num--alert' : ' founder-registry__num--quiet' ?>"><?= $prob ?></td>
                    <td class="<?= $planMuted ? 'founder-registry__plan-muted' : '' ?>"><?= $planMuted ? '<span class="founder-registry__muted">—</span>' : htmlspecialchars($plan) ?></td>
                    <td class="founder-registry__mono founder-registry__updated"><?= htmlspecialchars((string) ($row['updated_at'] ?? '')) ?></td>
                    <td class="founder-registry__actions">
                        <?php if ($secondary !== []): ?>
                            <div class="founder-registry__secondary">
                                <?php foreach ($secondary as $a): ?>
                                    <a class="founder-registry__sec-link" href="<?= htmlspecialchars((string) ($a['url'] ?? '#')) ?>"><?= htmlspecialchars((string) ($a['label'] ?? '')) ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($salons === []): ?>
                <tr><td colspan="10" class="founder-registry__empty">No salons match.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
