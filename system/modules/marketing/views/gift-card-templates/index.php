<?php

declare(strict_types=1);

$title = $title ?? 'Gift Card Templates';
$mainClass = 'marketing-gift-card-templates-page';
$marketingTopActive = 'gift_cards';
$storageReady = !empty($storageReady ?? false);
$read = is_array($read ?? null) ? $read : ['rows' => [], 'total' => 0, 'limit' => 25, 'offset' => 0];
$rows = is_array($read['rows'] ?? null) ? $read['rows'] : [];
$total = (int) ($read['total'] ?? 0);
$limit = (int) ($read['limit'] ?? 25);
$offset = (int) ($read['offset'] ?? 0);
$nextOffset = $offset + $limit;
$prevOffset = max(0, $offset - $limit);
$csrfName = (string) config('app.csrf_token_name', 'csrf_token');
ob_start();
?>
<div class="marketing-module">
    <?php require base_path('modules/marketing/views/partials/marketing-top-nav.php'); ?>
    <div class="marketing-module__body marketing-module__body--single">
        <div class="marketing-module__workspace">
            <?php if (!empty($flash) && is_array($flash)): $type = (string) array_key_first($flash); ?>
            <div class="flash flash-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars((string) ($flash[$type] ?? '')) ?></div>
            <?php endif; ?>

            <header class="marketing-page-head">
                <div class="marketing-page-head__titles">
                    <h1 class="marketing-page-head__h1">Gift Card Templates</h1>
                    <?php if ($storageReady): ?>
                    <p class="marketing-page-head__meta">Active templates: <?= $total ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($storageReady): ?>
                <div class="marketing-page-head__actions">
                    <a class="marketing-btn marketing-btn--secondary" href="/marketing/gift-card-templates/images">Manage Images</a>
                    <a class="marketing-btn marketing-btn--primary" href="/marketing/gift-card-templates/create">New Template</a>
                </div>
                <?php endif; ?>
            </header>

            <?php if (!$storageReady): ?>
            <?php require base_path('modules/marketing/views/gift-card-templates/partials/storage-not-ready-notice.php'); ?>
            <?php endif; ?>

            <?php if ($storageReady): ?>
            <div class="entity-form" style="margin-top:10px;">
                <div class="marketing-table-wrap">
                    <table class="index-table marketing-campaigns-table">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Sell on site / in-store enabled</th>
                            <th>Sell online enabled</th>
                            <th>Image</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                        <tr><td colspan="5"><span class="hint">No active gift card templates yet.</span></td></tr>
                        <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                        <?php $id = (int) ($row['id'] ?? 0); ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['name'] ?? '')) ?></td>
                            <td><?= !empty($row['sell_in_store_enabled']) ? 'Enabled' : 'Disabled' ?></td>
                            <td><?= !empty($row['sell_online_enabled']) ? 'Enabled' : 'Disabled' ?></td>
                            <td><?= !empty($row['has_image']) ? 'Image set' : 'No image' ?></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <a class="marketing-btn marketing-btn--secondary" href="/marketing/gift-card-templates/<?= $id ?>/edit">Edit</a>
                                    <form method="post" action="/marketing/gift-card-templates/<?= $id ?>/archive" onsubmit="return confirm('Archive this template?');">
                                        <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                                        <button type="submit" class="marketing-btn marketing-btn--secondary">Archive</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap;">
                    <p class="hint" style="margin:0;">
                        Total <?= $total ?> · limit <?= $limit ?> · offset <?= $offset ?>
                        <?php if ($total > 0 && $rows !== []): ?>
                        · rows <?= $offset + 1 ?>–<?= min($offset + count($rows), $total) ?>
                        <?php endif; ?>
                    </p>
                    <div style="display:flex;gap:6px;">
                        <?php if ($offset > 0): ?>
                        <a class="marketing-btn marketing-btn--secondary" href="/marketing/gift-card-templates?limit=<?= $limit ?>&offset=<?= $prevOffset ?>">Prev</a>
                        <?php else: ?>
                        <span class="marketing-btn marketing-btn--secondary is-disabled" style="opacity:0.5;cursor:not-allowed;" aria-disabled="true">Prev</span>
                        <?php endif; ?>
                        <?php if ($nextOffset < $total): ?>
                        <a class="marketing-btn marketing-btn--secondary" href="/marketing/gift-card-templates?limit=<?= $limit ?>&offset=<?= $nextOffset ?>">Next</a>
                        <?php else: ?>
                        <span class="marketing-btn marketing-btn--secondary is-disabled" style="opacity:0.5;cursor:not-allowed;" aria-disabled="true">Next</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
