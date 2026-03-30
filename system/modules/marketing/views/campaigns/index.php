<?php
$title = $title ?? 'Campaigns';
$mainClass = 'marketing-campaigns-page';
$marketingTopActive = 'email_campaigns';
$marketingRailActive = 'campaigns';
$filterQ = $filterQ ?? '';
$filterStatus = $filterStatus ?? '';
$filterChannel = $filterChannel ?? '';
$totalCount = (int) ($totalCount ?? 0);
$items = $items ?? [];
$canManageMarketing = !empty($canManageMarketing);
ob_start();
?>
<div class="marketing-module">
    <?php require base_path('modules/marketing/views/partials/marketing-top-nav.php'); ?>

    <div class="marketing-module__body">
        <?php require base_path('modules/marketing/views/partials/marketing-email-rail.php'); ?>

        <div class="marketing-module__workspace">
            <?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
            <div class="flash flash-<?= htmlspecialchars((string) $t) ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></div>
            <?php endif; ?>

            <header class="marketing-page-head">
                <div class="marketing-page-head__titles">
                    <h1 class="marketing-page-head__h1">Campaigns</h1>
                    <p class="marketing-page-head__meta"><?= (int) $totalCount ?> campaign<?= $totalCount === 1 ? '' : 's' ?></p>
                </div>
                <?php if ($canManageMarketing): ?>
                <a class="marketing-btn marketing-btn--primary" href="/marketing/campaigns/create">Create campaign</a>
                <?php endif; ?>
            </header>

            <form class="marketing-toolbar" method="get" action="/marketing/campaigns" role="search">
                <label class="marketing-toolbar__search">
                    <span class="visually-hidden">Search by campaign name</span>
                    <input type="search" name="q" value="<?= htmlspecialchars($filterQ) ?>"
                           placeholder="Search by campaign name" maxlength="200" autocomplete="off">
                </label>
                <label class="marketing-toolbar__field">
                    <span class="marketing-toolbar__label">Type</span>
                    <select name="channel">
                        <option value=""<?= $filterChannel === '' ? ' selected' : '' ?>>All campaigns</option>
                        <option value="email"<?= $filterChannel === 'email' ? ' selected' : '' ?>>Email</option>
                    </select>
                </label>
                <label class="marketing-toolbar__field">
                    <span class="marketing-toolbar__label">Status</span>
                    <select name="status">
                        <option value=""<?= $filterStatus === '' ? ' selected' : '' ?>>All statuses</option>
                        <option value="draft"<?= $filterStatus === 'draft' ? ' selected' : '' ?>>Draft</option>
                        <option value="archived"<?= $filterStatus === 'archived' ? ' selected' : '' ?>>Archived</option>
                    </select>
                </label>
                <button type="submit" class="marketing-btn marketing-btn--secondary">Apply</button>
            </form>

            <?php if ($items === []): ?>
            <div class="marketing-empty">
                <h2 class="marketing-empty__title">No campaigns match</h2>
                <p class="marketing-empty__text">
                    <?php if ($filterQ !== '' || $filterStatus !== '' || $filterChannel !== ''): ?>
                    Adjust your search or filters, or clear them to see all campaigns for this branch.
                    <?php else: ?>
                    Create a campaign to start sending segment-based email to your clients.
                    <?php endif; ?>
                </p>
                <div class="marketing-empty__actions">
                    <?php if ($filterQ !== '' || $filterStatus !== '' || $filterChannel !== ''): ?>
                    <a class="marketing-btn marketing-btn--secondary" href="/marketing/campaigns">Clear filters</a>
                    <?php endif; ?>
                    <?php if ($canManageMarketing): ?>
                    <a class="marketing-btn marketing-btn--primary" href="/marketing/campaigns/create">Create campaign</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <?php if ($totalCount > count($items)): ?>
            <p class="marketing-table-cap">Showing <?= count($items) ?> of <?= $totalCount ?> campaigns. Narrow filters to find the rest.</p>
            <?php endif; ?>
            <div class="marketing-table-wrap">
                <table class="index-table marketing-campaigns-table">
                    <thead>
                    <tr>
                        <th scope="col">Campaign name</th>
                        <th scope="col">Lists</th>
                        <th scope="col" class="marketing-campaigns-table__num">Sent</th>
                        <th scope="col" class="marketing-campaigns-table__num">Opens</th>
                        <th scope="col" class="marketing-campaigns-table__num">Clicks</th>
                        <th scope="col">Send date</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="marketing-campaigns-table__actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $c): ?>
                    <?php
                    $id = (int) ($c['id'] ?? 0);
                    $sendRaw = $c['send_date_raw'] ?? null;
                    $sendDisplay = '—';
                    if (is_string($sendRaw) && $sendRaw !== '') {
                        $ts = strtotime($sendRaw);
                        $sendDisplay = $ts !== false ? date('M j, Y g:i A', $ts) : $sendRaw;
                    }
                    $st = (string) ($c['status'] ?? '');
                    ?>
                    <tr>
                        <td class="marketing-campaigns-table__name">
                            <a href="/marketing/campaigns/<?= $id ?>"><?= htmlspecialchars((string) ($c['name'] ?? '')) ?></a>
                        </td>
                        <td class="marketing-campaigns-table__lists" title="Audience is driven by a segment; contact lists are not attached.">
                            <?= htmlspecialchars((string) ($c['lists_label'] ?? '—')) ?>
                        </td>
                        <td class="marketing-campaigns-table__num"><?= (int) ($c['sent_count'] ?? 0) ?></td>
                        <td class="marketing-campaigns-table__num marketing-campaigns-table__na">—</td>
                        <td class="marketing-campaigns-table__num marketing-campaigns-table__na">—</td>
                        <td><?= htmlspecialchars($sendDisplay) ?></td>
                        <td>
                            <span class="marketing-pill marketing-pill--<?= $st === 'archived' ? 'archived' : 'draft' ?>">
                                <?= htmlspecialchars((string) ($c['status_label'] ?? $st)) ?>
                            </span>
                        </td>
                        <td class="marketing-campaigns-table__actions">
                            <details class="marketing-row-actions">
                                <summary class="marketing-row-actions__trigger" aria-label="Actions for campaign">Actions</summary>
                                <ul class="marketing-row-actions__menu">
                                    <li><a href="/marketing/campaigns/<?= $id ?>">View</a></li>
                                    <?php if ($canManageMarketing && $st !== 'archived'): ?>
                                    <li><a href="/marketing/campaigns/<?= $id ?>/edit">Edit</a></li>
                                    <?php endif; ?>
                                </ul>
                            </details>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
