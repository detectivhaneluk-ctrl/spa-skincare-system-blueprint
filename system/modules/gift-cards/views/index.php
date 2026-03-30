<?php
$title = 'Gift cards';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = 'gift_cards';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
$getv = static function (string $key): string {
    return htmlspecialchars((string) ($_GET[$key] ?? ''));
};
$workspaceBranchLabel = htmlspecialchars('#' . (int) $tenantBranchId);
foreach ($branches as $wb) {
    if ((int) ($wb['id'] ?? 0) === (int) $tenantBranchId) {
        $workspaceBranchLabel = htmlspecialchars((string) ($wb['name'] ?? ('#' . (int) $tenantBranchId)));
        break;
    }
}
$listBranchApplied = $listBranchApplied ?? '';
$indexQuery = $giftCardIndexQuery ?? [];
$indexUrl = static function (array $base, ?int $pageNum = null): string {
    $q = $base;
    if ($pageNum !== null && $pageNum > 1) {
        $q['page'] = $pageNum;
    }
    $qs = http_build_query($q);

    return '/gift-cards' . ($qs !== '' ? '?' . $qs : '');
};
$csrfName = htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'));
$csrfVal = htmlspecialchars((string) ($csrf ?? ''));
$canAdjust = !empty($canAdjustGiftCards);
$canRedeem = !empty($canRedeemGiftCards);
$canCancel = !empty($canCancelGiftCards);
$canCreate = !empty($canCreateGiftCards);
$bulkFormId = 'gift-cards-bulk-expiry-form';
?>
<h2 class="sales-workspace-section-title">Gift cards</h2>
<p class="hint">List and manage stored-value gift cards for your organization. This screen is gift-card data only (no separate &ldquo;checks&rdquo; product). Row actions match routes that exist today; full history lives on the card detail page.</p>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form" action="/gift-cards">
    <div>
        <label for="gc-code">Code / number</label><br>
        <input id="gc-code" type="text" name="code" placeholder="Contains…" value="<?= $getv('code') ?>">
    </div>
    <div>
        <label for="gc-client">Client name</label><br>
        <input id="gc-client" type="text" name="client_name" placeholder="First or last name…" value="<?= $getv('client_name') ?>">
    </div>
    <div>
        <label for="gc-status">Status</label><br>
        <select id="gc-status" name="status">
            <option value="">All statuses</option>
            <?php foreach (\Modules\GiftCards\Services\GiftCardService::STATUSES as $st): ?>
            <option value="<?= htmlspecialchars($st) ?>" <?= (($_GET['status'] ?? '') === $st) ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="gc-from">Issued from</label><br>
        <input id="gc-from" type="date" name="issued_from" value="<?= $getv('issued_from') ?>">
    </div>
    <div>
        <label for="gc-to">Issued to</label><br>
        <input id="gc-to" type="date" name="issued_to" value="<?= $getv('issued_to') ?>">
    </div>
    <div>
        <label for="gc-list-branch">Card scope</label><br>
        <select id="gc-list-branch" name="list_branch">
            <option value="" <?= $listBranchApplied === '' ? 'selected' : '' ?>>Branch cards + org-wide (client) cards — list as workspace branch (<?= $workspaceBranchLabel ?>)</option>
            <option value="global" <?= $listBranchApplied === 'global' ? 'selected' : '' ?>>Organization-wide cards only (no branch row)</option>
            <?php foreach ($branches as $b): ?>
            <?php $bid = (int) ($b['id'] ?? 0); ?>
            <option value="<?= $bid ?>" <?= ($listBranchApplied !== '' && $listBranchApplied !== 'global' && (int) $listBranchApplied === $bid) ? 'selected' : '' ?>>
                Branch-assigned cards: <?= htmlspecialchars((string) ($b['name'] ?? ('#' . $bid))) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <button type="submit">Apply filters</button>
        <a href="/gift-cards">Reset</a>
    </div>
</form>

<?php if ($canCreate): ?>
<p><a class="btn" href="/gift-cards/issue">Issue gift card</a></p>
<?php endif; ?>

<table class="index-table">
    <thead>
    <tr>
        <?php if ($canAdjust): ?>
        <th scope="col"><span class="visually-hidden">Select for bulk expiry</span></th>
        <?php endif; ?>
        <th>Code</th>
        <th>Client</th>
        <th>Original</th>
        <th>Balance</th>
        <th>Status</th>
        <th>Expires</th>
        <th>Branch</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($giftCards as $g): ?>
    <?php
        $gid = (int) $g['id'];
        $gstatus = (string) ($g['status'] ?? '');
        $isActive = $gstatus === 'active';
    ?>
    <tr>
        <?php if ($canAdjust): ?>
        <td>
            <?php if ($isActive): ?>
            <input type="checkbox" name="gift_card_ids[]" value="<?= $gid ?>" form="<?= htmlspecialchars($bulkFormId) ?>" aria-label="Select <?= htmlspecialchars($g['code']) ?> for bulk expiry">
            <?php else: ?>
            <span aria-hidden="true">—</span>
            <?php endif; ?>
        </td>
        <?php endif; ?>
        <td><a href="/gift-cards/<?= $gid ?>"><?= htmlspecialchars($g['code']) ?></a></td>
        <td><?= htmlspecialchars($g['client_display']) ?></td>
        <td><?= number_format((float) $g['original_amount'], 2) ?> <?= htmlspecialchars($g['currency']) ?></td>
        <td><span class="badge <?= ((float) $g['current_balance'] <= 0) ? 'badge-warn' : 'badge-success' ?>"><?= number_format((float) $g['current_balance'], 2) ?></span></td>
        <td><span class="badge badge-muted"><?= htmlspecialchars($gstatus) ?></span></td>
        <td><?= htmlspecialchars($g['expires_at'] ?? '—') ?></td>
        <td><?= $g['branch_id'] ? ('#' . (int) $g['branch_id']) : 'Org-wide' ?></td>
        <td class="gift-cards-actions">
            <a href="/gift-cards/<?= $gid ?>">View</a>
            <?php if ($isActive && $canRedeem): ?>
            · <a href="/gift-cards/<?= $gid ?>/redeem">Redeem</a>
            <?php endif; ?>
            <?php if ($isActive && $canAdjust): ?>
            · <a href="/gift-cards/<?= $gid ?>/adjust">Adjust</a>
            <?php endif; ?>
            <?php if ($isActive && $canCancel): ?>
            · <form method="post" action="/gift-cards/<?= $gid ?>/cancel" class="gift-cards-inline-cancel" onsubmit="return confirm('Cancel this gift card?');">
                <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                <button type="submit" class="link-button">Cancel</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($canAdjust): ?>
<form id="<?= htmlspecialchars($bulkFormId) ?>" method="post" action="/gift-cards/bulk-update-expires-at" class="gift-cards-bulk-form">
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
    <?php if ($indexQuery['code'] ?? ''): ?><input type="hidden" name="ret_code" value="<?= htmlspecialchars((string) $indexQuery['code']) ?>"><?php endif; ?>
    <?php if ($indexQuery['client_name'] ?? ''): ?><input type="hidden" name="ret_client_name" value="<?= htmlspecialchars((string) $indexQuery['client_name']) ?>"><?php endif; ?>
    <?php if ($indexQuery['status'] ?? ''): ?><input type="hidden" name="ret_status" value="<?= htmlspecialchars((string) $indexQuery['status']) ?>"><?php endif; ?>
    <?php if ($indexQuery['issued_from'] ?? ''): ?><input type="hidden" name="ret_issued_from" value="<?= htmlspecialchars((string) $indexQuery['issued_from']) ?>"><?php endif; ?>
    <?php if ($indexQuery['issued_to'] ?? ''): ?><input type="hidden" name="ret_issued_to" value="<?= htmlspecialchars((string) $indexQuery['issued_to']) ?>"><?php endif; ?>
    <?php if ($indexQuery['list_branch'] ?? ''): ?><input type="hidden" name="ret_list_branch" value="<?= htmlspecialchars((string) $indexQuery['list_branch']) ?>"><?php endif; ?>
    <?php if ((int) $page > 1): ?><input type="hidden" name="ret_page" value="<?= (int) $page ?>"><?php endif; ?>

    <fieldset>
        <legend>Bulk expiration (active cards only)</legend>
        <p class="hint">Checkboxes apply to <strong>this page only</strong>, not the full filtered result set.</p>
        <label for="bulk-expires-at">New expiration date</label>
        <input id="bulk-expires-at" type="date" name="bulk_expires_at" value="">
        <label><input type="checkbox" name="clear_expiry" value="1"> Clear expiration (no expiry date)</label>
        <button type="submit" name="bulk_expiry_submit" value="1">Apply to selected</button>
    </fieldset>
</form>
<?php else: ?>
<p class="hint">Bulk expiration and balance adjustments require the <strong>Adjust gift cards</strong> permission.</p>
<?php endif; ?>

<?php if ($total > 0): ?>
<nav class="pagination" aria-label="Gift card list pages">
    <?php if ($page > 1): ?>
    <a href="<?= htmlspecialchars($indexUrl($indexQuery, $page - 1)) ?>">Previous</a>
    <?php else: ?>
    <span aria-disabled="true">Previous</span>
    <?php endif; ?>
    <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?> (<?= (int) $total ?> total)</span>
    <?php if ($page < $totalPages): ?>
    <a href="<?= htmlspecialchars($indexUrl($indexQuery, $page + 1)) ?>">Next</a>
    <?php else: ?>
    <span aria-disabled="true">Next</span>
    <?php endif; ?>
</nav>
<?php elseif ($total === 0): ?>
<p class="hint">No gift cards match these filters.</p>
<?php endif; ?>

<p class="hint">Filters apply only to fields above. <strong>Card scope</strong> chooses which branch is used for branch-owned rows (validated against your organization). &ldquo;Organization-wide&rdquo; limits the list to cards without a branch assignment.</p>
<style>
.gift-cards-actions { white-space: nowrap; }
.gift-cards-inline-cancel { display: inline; margin: 0; padding: 0; }
.gift-cards-inline-cancel .link-button {
    background: none; border: none; padding: 0; margin: 0;
    color: inherit; text-decoration: underline; cursor: pointer;
    font: inherit;
}
.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }
</style>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
