<?php
$title = 'Sales orders';
$mainClass = 'sales-workspace-page sales-orders-list-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = 'manage_sales';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
$getv = static function (string $key): string {
    return htmlspecialchars((string) ($_GET[$key] ?? ''));
};
$ordersListQuery = $ordersListQuery ?? [];
$branchListValue = $branchListValue ?? null;
$listBranchId = $listBranchId ?? null;
$perPage = (int) ($perPage ?? 20);
$totalPages = (int) ($totalPages ?? 1);
$csrfName = htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'));
$csrfVal = htmlspecialchars((string) ($csrf ?? ''));
$canEdit = !empty($canEditInvoice);
$canDelete = !empty($canDeleteInvoice);
$canCreate = !empty($canCreateInvoice);

$listUrl = static function (array $base, ?int $pageNum = null): string {
    $q = $base;
    if ($pageNum !== null && $pageNum > 1) {
        $q['page'] = (string) $pageNum;
    }
    $qs = http_build_query($q);

    return '/sales/invoices' . ($qs !== '' ? '?' . $qs : '');
};
?>
<h2 class="sales-workspace-section-title">Sales orders</h2>
<p class="hint">Search and open invoices for your organization. <strong>New sale</strong> opens staff checkout (draft invoice). <strong>Register</strong> under Sales is the cash drawer module—separate from new sale.</p>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form" action="/sales/invoices">
    <div>
        <label for="so-invoice-number">Invoice / order number</label><br>
        <input id="so-invoice-number" type="text" name="invoice_number" placeholder="Contains…" value="<?= $getv('invoice_number') ?>" maxlength="50">
    </div>
    <div>
        <label for="so-status">Status</label><br>
        <select id="so-status" name="status">
            <option value="">All statuses</option>
            <?php foreach (['draft', 'open', 'partial', 'paid', 'cancelled', 'refunded'] as $st): ?>
            <option value="<?= htmlspecialchars($st) ?>" <?= (($_GET['status'] ?? '') === $st) ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="so-branch">Branch</label><br>
        <select id="so-branch" name="branch_id">
            <?php
            $allBranchesSelected = ($branchListValue !== null && $branchListValue === '')
                || ($branchListValue === null && $listBranchId === null);
            ?>
            <option value="" <?= $allBranchesSelected ? 'selected' : '' ?>>All branches (organization)</option>
            <?php foreach ($branches as $b): ?>
            <?php
                $bid = (int) ($b['id'] ?? 0);
                $selected = false;
                if ($branchListValue !== null && $branchListValue !== '') {
                    $selected = (int) $branchListValue === $bid;
                } elseif ($branchListValue === null && $listBranchId !== null) {
                    $selected = $listBranchId === $bid;
                }
                ?>
            <option value="<?= $bid ?>" <?= $selected ? 'selected' : '' ?>><?= htmlspecialchars((string) ($b['name'] ?? ('#' . $bid))) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="so-client-name">Customer name</label><br>
        <input id="so-client-name" type="text" name="client_name" placeholder="First or last name…" value="<?= $getv('client_name') ?>" maxlength="100">
    </div>
    <div>
        <label for="so-client-phone">Phone</label><br>
        <input id="so-client-phone" type="text" name="client_phone" placeholder="Contains…" value="<?= $getv('client_phone') ?>" maxlength="50">
    </div>
    <div>
        <label for="so-issued-from">Order date from</label><br>
        <input id="so-issued-from" type="date" name="issued_from" value="<?= $getv('issued_from') ?>">
    </div>
    <div>
        <label for="so-issued-to">Order date to</label><br>
        <input id="so-issued-to" type="date" name="issued_to" value="<?= $getv('issued_to') ?>">
    </div>
    <div>
        <label for="so-per-page">Per page</label><br>
        <select id="so-per-page" name="per_page">
            <?php foreach ([10, 20, 50] as $pp): ?>
            <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <button type="submit">Search</button>
        <a href="/sales/invoices">Reset</a>
    </div>
</form>

<?php if ($canCreate): ?>
<p class="sales-orders-new-sale-wrap"><a href="/sales/invoices/create" class="sales-orders-new-sale-btn">New sale</a></p>
<?php endif; ?>

<?php if ($branchListValue === null && $listBranchId !== null): ?>
<p class="hint">Branch filter defaults to your <strong>current workspace branch</strong> until you change it above (choose &ldquo;All branches&rdquo; and search to include the whole organization).</p>
<?php endif; ?>

<?php
$rows = $invoices;
$actions = static function (array $r) use ($csrfName, $csrfVal, $canEdit, $canDelete): string {
    $id = (int) ($r['id'] ?? 0);
    $out = '<a href="/sales/invoices/' . $id . '">View</a>';
    if ($canEdit && !empty($r['invoice_editable'])) {
        $out .= ' · <a href="/sales/invoices/' . $id . '/edit">Edit</a>';
    }
    if ($canDelete && !empty($r['invoice_deletable'])) {
        $out .= ' · <form method="post" action="/sales/invoices/' . $id . '/delete" style="display:inline" onsubmit="return confirm(\'Delete this invoice?\');">'
            . '<input type="hidden" name="' . $csrfName . '" value="' . $csrfVal . '">'
            . '<button type="submit" class="link-button">Delete</button></form>';
    }
    return $out;
};
?>
<table class="index-table">
    <thead>
        <tr>
            <th>Number</th>
            <th>Client</th>
            <th>Total</th>
            <th>Paid</th>
            <th>Balance</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
        <?php
        $invoiceId = (int) ($row['id'] ?? 0);
        $saleDetailHref = '/sales/invoices/' . $invoiceId;
        $clientId = (int) ($row['client_id'] ?? 0);
        ?>
        <tr>
            <td><a href="<?= htmlspecialchars($saleDetailHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($row['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a></td>
            <td><?php
                $clientLabel = (string) ($row['client_display'] ?? '');
                if ($clientId > 0) {
                    $clientHref = '/clients/' . $clientId;
                    echo '<a href="' . htmlspecialchars($clientHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($clientLabel, ENT_QUOTES, 'UTF-8') . '</a>';
                } else {
                    echo htmlspecialchars($clientLabel, ENT_QUOTES, 'UTF-8');
                }
                ?></td>
            <td><?= htmlspecialchars((string) ($row['total_amount'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) ($row['paid_amount'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) ($row['balance_due'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) ($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= $actions($row) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($total > 0): ?>
<nav class="pagination" aria-label="Sales orders pages">
    <?php if ($page > 1): ?>
    <a href="<?= htmlspecialchars($listUrl($ordersListQuery, $page - 1)) ?>">Previous</a>
    <?php else: ?>
    <span aria-disabled="true">Previous</span>
    <?php endif; ?>
    <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?> (<?= (int) $total ?> total)</span>
    <?php if ($page < $totalPages): ?>
    <a href="<?= htmlspecialchars($listUrl($ordersListQuery, $page + 1)) ?>">Next</a>
    <?php else: ?>
    <span aria-disabled="true">Next</span>
    <?php endif; ?>
</nav>
<?php elseif ($total === 0): ?>
<p class="hint">No invoices match these filters.</p>
<?php endif; ?>

<style>
.sales-orders-list-page .sales-orders-new-sale-btn {
    display: inline-block;
    padding: 0.5rem 1rem;
    background: #0d9488;
    color: #fff;
    text-decoration: none;
    border: 1px solid #0f766e;
    border-radius: 4px;
    font-weight: 600;
}
.sales-orders-list-page .sales-orders-new-sale-btn:hover { background: #0f766e; color: #fff; }
.link-button { background: none; border: none; padding: 0; margin: 0; font: inherit; color: inherit; text-decoration: underline; cursor: pointer; }
</style>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
