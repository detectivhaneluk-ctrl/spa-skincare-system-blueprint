<?php
$title       = $trashView ? 'Services — Trash' : 'Services';
$csrfName    = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$csrfVal     = htmlspecialchars($csrf ?? '');

// ── View-state from URL ────────────────────────────────────────────────────
$activeView  = (!$trashView && isset($_GET['view']) && $_GET['view'] === 'structure') ? 'structure' : 'list';
$sortCol     = isset($_GET['sort']) ? (string) $_GET['sort'] : 'name';
$sortDir     = isset($_GET['dir']) && $_GET['dir'] === 'desc' ? 'desc' : 'asc';
$allowedSortCols = ['name', 'category', 'duration', 'price', 'status'];
if (!in_array($sortCol, $allowedSortCols, true)) {
    $sortCol = 'name';
}

// ── URL builder helper ─────────────────────────────────────────────────────
function svcIndexUrl(array $overrides = []): string
{
    $base = [
        'view'     => null,
        'category' => null,
        'status'   => null,
        'sort'     => null,
        'dir'      => null,
    ];
    $params = array_filter(array_merge($base, $overrides), fn ($v) => $v !== null && $v !== '');
    return '/services-resources/services' . ($params ? ('?' . http_build_query($params)) : '');
}

$baseCategory = $categoryId !== null ? (int) $categoryId : null;
$baseStatus   = $trashView ? 'trash' : null;
$baseView     = $activeView === 'structure' ? 'structure' : null;
$baseSort     = $sortCol !== 'name' ? $sortCol : null;
$baseDir      = $sortDir !== 'asc' ? $sortDir : null;

ob_start();
$svcWorkspaceActiveTab = 'services';
require base_path('modules/services-resources/views/partials/services-workspace-shell.php');

// ── Group services by category for Structure view ──────────────────────────
$categoryMap  = [];
foreach ($categories as $c) {
    $categoryMap[(int) $c['id']] = $c;
}
$grouped      = [];   // category_id => ['category' => [...], 'services' => [...]]
$uncategorized = [];  // services with no category
foreach ($services as $s) {
    $cid = (int) ($s['category_id'] ?? 0);
    if ($cid && isset($categoryMap[$cid])) {
        $grouped[$cid]['category'] = $categoryMap[$cid];
        $grouped[$cid]['services'][] = $s;
    } else {
        $uncategorized[] = $s;
    }
}
// Preserve category sort order from $categories list
$orderedGrouped = [];
foreach ($categories as $c) {
    $cid = (int) $c['id'];
    if (isset($grouped[$cid])) {
        $orderedGrouped[$cid] = $grouped[$cid];
    }
}
// Add any category IDs that appeared in services but were absent from $categories list
foreach ($grouped as $cid => $g) {
    if (!isset($orderedGrouped[$cid])) {
        $orderedGrouped[$cid] = $g;
    }
}

?>
<?php if ($flash && is_array($flash)): $fk = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($fk) ?>"><?= htmlspecialchars($flash[$fk] ?? '') ?></div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     SERVICES WORKSPACE — page toolbar
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="svc-ws-toolbar">

    <!-- Left: search + category filter -->
    <div class="svc-ws-toolbar__left">
        <!-- Client-side search (List view only) -->
        <?php if (!$trashView): ?>
        <div class="svc-search-wrap <?= $activeView === 'structure' ? 'svc-search-wrap--hidden' : '' ?>" id="svc-search-wrap">
            <svg class="svc-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="svc-search-input" class="svc-search-input" placeholder="Search services…" autocomplete="off" aria-label="Search services">
            <button type="button" id="svc-search-clear" class="svc-search-clear" title="Clear" hidden>✕</button>
        </div>
        <?php endif; ?>

        <!-- Category filter (server-side) -->
        <form method="get" class="svc-filter-form" id="svc-filter-form">
            <?php if ($activeView === 'structure'): ?>
            <input type="hidden" name="view" value="structure">
            <?php endif; ?>
            <?php if ($trashView): ?>
            <input type="hidden" name="status" value="trash">
            <?php endif; ?>
            <?php if ($sortCol !== 'name'): ?>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortCol) ?>">
            <?php endif; ?>
            <?php if ($sortDir !== 'asc'): ?>
            <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDir) ?>">
            <?php endif; ?>
            <select name="category" id="svc-category-filter" class="svc-filter-select" onchange="this.form.submit()">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= ($baseCategory !== null && $baseCategory === (int) $c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Right: view switcher + status tabs + create CTA -->
    <div class="svc-ws-toolbar__right">
        <!-- Status tabs: All / Trash -->
        <div class="svc-status-tabs" role="tablist" aria-label="Service status">
            <a href="<?= htmlspecialchars(svcIndexUrl(['view' => $baseView, 'category' => $baseCategory ? (string) $baseCategory : null])) ?>"
               class="svc-status-tab <?= !$trashView ? 'svc-status-tab--active' : '' ?>"
               role="tab" <?= !$trashView ? 'aria-selected="true"' : '' ?>>
                Active <span class="svc-status-count"><?= (int) ($countActive ?? 0) ?></span>
            </a>
            <a href="<?= htmlspecialchars(svcIndexUrl(['status' => 'trash', 'view' => null, 'category' => $baseCategory ? (string) $baseCategory : null])) ?>"
               class="svc-status-tab <?= $trashView ? 'svc-status-tab--active' : '' ?>"
               role="tab" <?= $trashView ? 'aria-selected="true"' : '' ?>>
                Trash <span class="svc-status-count"><?= (int) ($countTrash ?? 0) ?></span>
            </a>
        </div>

        <!-- View switcher — only in active (non-trash) mode -->
        <?php if (!$trashView): ?>
        <div class="svc-view-switcher" role="group" aria-label="Switch workspace view">
            <a href="<?= htmlspecialchars(svcIndexUrl(['category' => $baseCategory ? (string) $baseCategory : null, 'sort' => $baseSort, 'dir' => $baseDir])) ?>"
               class="svc-view-btn <?= $activeView === 'list' ? 'svc-view-btn--active' : '' ?>"
               title="List view"
               aria-pressed="<?= $activeView === 'list' ? 'true' : 'false' ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                List
            </a>
            <a href="<?= htmlspecialchars(svcIndexUrl(['view' => 'structure', 'category' => $baseCategory ? (string) $baseCategory : null])) ?>"
               class="svc-view-btn <?= $activeView === 'structure' ? 'svc-view-btn--active' : '' ?>"
               title="Structure view"
               aria-pressed="<?= $activeView === 'structure' ? 'true' : 'false' ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Structure
            </a>
        </div>
        <?php endif; ?>

        <!-- Create CTA -->
        <?php if (empty($trashView)): ?>
        <a href="/services-resources/services/create" class="svc-create-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New service
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     LIST VIEW
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="svc-view-list" class="svc-view <?= $activeView === 'list' ? '' : 'svc-view--hidden' ?>">

<?php if (empty($services)): ?>
<div class="svc-empty">
    <?php if ($trashView): ?>
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
    <p class="svc-empty__title">Trash is empty</p>
    <p class="svc-empty__sub">No trashed services.</p>
    <?php elseif ($baseCategory !== null): ?>
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
    <p class="svc-empty__title">No services in this category</p>
    <p class="svc-empty__sub"><a href="/services-resources/services/create">Create a service</a> or choose a different category.</p>
    <?php else: ?>
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
    <p class="svc-empty__title">No services yet</p>
    <p class="svc-empty__sub"><a href="/services-resources/services/create">Create your first service</a> to get started.</p>
    <?php endif; ?>
</div>
<?php else: ?>

<!-- Bulk action bar -->
<form method="post" action="/services-resources/services/bulk-trash" id="svc-bulk-form" class="svc-bulk-bar">
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
    <input type="hidden" name="list_category" value="<?= $baseCategory !== null ? $baseCategory : '' ?>">
    <input type="hidden" name="list_status" value="<?= $trashView ? 'trash' : '' ?>">
    <select name="bulk_action" id="svc-bulk-action" class="svc-bulk-select" aria-label="Bulk action">
        <option value="">Bulk action…</option>
        <?php if (empty($trashView)): ?>
        <option value="move_to_trash">Move to Trash</option>
        <?php else: ?>
        <option value="restore">Restore</option>
        <option value="delete_permanently">Delete permanently</option>
        <?php endif; ?>
    </select>
    <button type="submit" class="svc-bulk-apply" id="svc-bulk-apply">Apply</button>
    <span class="svc-bulk-count" id="svc-bulk-count" hidden></span>
</form>

<!-- Services table -->
<div class="svc-table-wrap">
<table class="svc-table" id="svc-table">
    <thead>
        <tr>
            <th class="svc-th svc-th--check">
                <input type="checkbox" id="svc-check-all" title="Select all" aria-label="Select all visible rows">
            </th>
            <th class="svc-th svc-th--name">
                <?php
                $nameDir = ($sortCol === 'name' && $sortDir === 'asc') ? 'desc' : 'asc';
                $nameActive = ($sortCol === 'name');
                ?>
                <a href="<?= htmlspecialchars(svcIndexUrl(['view' => $baseView, 'category' => $baseCategory ? (string) $baseCategory : null, 'status' => $baseStatus, 'sort' => 'name', 'dir' => $nameDir])) ?>"
                   class="svc-sort-link <?= $nameActive ? 'svc-sort-link--active' : '' ?>">
                    Name
                    <span class="svc-sort-icon"><?= $nameActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="svc-th svc-th--category">
                <?php
                $catDir = ($sortCol === 'category' && $sortDir === 'asc') ? 'desc' : 'asc';
                $catActive = ($sortCol === 'category');
                ?>
                <a href="<?= htmlspecialchars(svcIndexUrl(['view' => $baseView, 'category' => $baseCategory ? (string) $baseCategory : null, 'status' => $baseStatus, 'sort' => 'category', 'dir' => $catDir])) ?>"
                   class="svc-sort-link <?= $catActive ? 'svc-sort-link--active' : '' ?>">
                    Category
                    <span class="svc-sort-icon"><?= $catActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="svc-th svc-th--type">Type</th>
            <th class="svc-th svc-th--sku">SKU</th>
            <th class="svc-th svc-th--dur">
                <?php
                $durDir = ($sortCol === 'duration' && $sortDir === 'asc') ? 'desc' : 'asc';
                $durActive = ($sortCol === 'duration');
                ?>
                <a href="<?= htmlspecialchars(svcIndexUrl(['view' => $baseView, 'category' => $baseCategory ? (string) $baseCategory : null, 'status' => $baseStatus, 'sort' => 'duration', 'dir' => $durDir])) ?>"
                   class="svc-sort-link <?= $durActive ? 'svc-sort-link--active' : '' ?>">
                    Duration
                    <span class="svc-sort-icon"><?= $durActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="svc-th svc-th--price">
                <?php
                $priceDir = ($sortCol === 'price' && $sortDir === 'asc') ? 'desc' : 'asc';
                $priceActive = ($sortCol === 'price');
                ?>
                <a href="<?= htmlspecialchars(svcIndexUrl(['view' => $baseView, 'category' => $baseCategory ? (string) $baseCategory : null, 'status' => $baseStatus, 'sort' => 'price', 'dir' => $priceDir])) ?>"
                   class="svc-sort-link <?= $priceActive ? 'svc-sort-link--active' : '' ?>">
                    Price
                    <span class="svc-sort-icon"><?= $priceActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="svc-th svc-th--assigns" title="Staff assigned">Staff</th>
            <th class="svc-th svc-th--assigns" title="Spaces assigned">Spc</th>
            <th class="svc-th svc-th--assigns" title="Products assigned">Prod</th>
            <th class="svc-th svc-th--status">
                <?php
                $statusDir = ($sortCol === 'status' && $sortDir === 'asc') ? 'desc' : 'asc';
                $statusActive = ($sortCol === 'status');
                ?>
                <a href="<?= htmlspecialchars(svcIndexUrl(['view' => $baseView, 'category' => $baseCategory ? (string) $baseCategory : null, 'status' => $baseStatus, 'sort' => 'status', 'dir' => $statusDir])) ?>"
                   class="svc-sort-link <?= $statusActive ? 'svc-sort-link--active' : '' ?>">
                    Status
                    <span class="svc-sort-icon"><?= $statusActive ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?></span>
                </a>
            </th>
            <th class="svc-th svc-th--actions">Actions</th>
        </tr>
    </thead>
    <tbody id="svc-table-body">
    <?php
    // Sort client-submitted sort in PHP before rendering
    $sortedServices = $services;
    if ($sortCol !== 'name') {
        usort($sortedServices, function ($a, $b) use ($sortCol, $sortDir) {
            $va = '';
            $vb = '';
            if ($sortCol === 'category') {
                $va = strtolower((string) ($a['category_name'] ?? ''));
                $vb = strtolower((string) ($b['category_name'] ?? ''));
            } elseif ($sortCol === 'duration') {
                $va = (int) ($a['duration_minutes'] ?? 0);
                $vb = (int) ($b['duration_minutes'] ?? 0);
                return $sortDir === 'asc' ? ($va <=> $vb) : ($vb <=> $va);
            } elseif ($sortCol === 'price') {
                $va = (float) ($a['price'] ?? 0);
                $vb = (float) ($b['price'] ?? 0);
                return $sortDir === 'asc' ? ($va <=> $vb) : ($vb <=> $va);
            } elseif ($sortCol === 'status') {
                $va = (int) (!empty($a['is_active']));
                $vb = (int) (!empty($b['is_active']));
                return $sortDir === 'asc' ? ($vb <=> $va) : ($va <=> $vb);
            }
            return $sortDir === 'asc' ? strcmp($va, $vb) : strcmp($vb, $va);
        });
    }
    foreach ($sortedServices as $r):
        $dMin    = (int) ($r['duration_minutes'] ?? 0);
        $dLabel  = $dMin >= 60
            ? (floor($dMin / 60) . 'h' . ($dMin % 60 ? ' ' . ($dMin % 60) . 'm' : ''))
            : ($dMin > 0 ? $dMin . 'm' : '—');
        $active  = !empty($r['is_active']);
        $svcType = match ($r['service_type'] ?? 'service') {
            'package_item' => 'Package',
            'other'        => 'Other',
            default        => 'Service',
        };
        $addOn  = !empty($r['add_on']);
        $online = !empty($r['show_in_online_menu']);
        $price  = (float) ($r['price'] ?? 0);
        $staff  = (int) ($r['staff_count'] ?? 0);
        $spaces = (int) ($r['room_count'] ?? 0);
        $prods  = (int) ($r['product_count'] ?? 0);
    ?>
    <tr class="svc-row" data-name="<?= htmlspecialchars(strtolower($r['name'] ?? '')) ?>" data-cat="<?= htmlspecialchars(strtolower($r['category_name'] ?? '')) ?>" data-sku="<?= htmlspecialchars(strtolower($r['sku'] ?? '')) ?>">
        <td class="svc-td svc-td--check">
            <input class="svc-row-check" type="checkbox" name="service_ids[]" value="<?= (int) $r['id'] ?>" form="svc-bulk-form" aria-label="Select <?= htmlspecialchars($r['name'] ?? '') ?>">
        </td>
        <td class="svc-td svc-td--name">
            <div class="svc-name-cell">
                <a href="/services-resources/services/<?= (int) $r['id'] ?>" class="svc-name-link"><?= htmlspecialchars($r['name'] ?? '') ?></a>
                <div class="svc-name-badges">
                    <?php if ($addOn): ?><span class="svc-badge svc-badge--addon" title="Add-on service">Add-on</span><?php endif; ?>
                    <?php if ($online): ?><span class="svc-badge svc-badge--online" title="Shown in online booking menu">Online</span><?php endif; ?>
                </div>
            </div>
        </td>
        <td class="svc-td svc-td--category">
            <?php if ($r['category_name'] ?? null): ?>
            <a href="<?= htmlspecialchars(svcIndexUrl(['view' => $baseView, 'category' => (string) (int) $r['category_id']])) ?>" class="svc-cat-link"><?= htmlspecialchars($r['category_name']) ?></a>
            <?php else: ?>
            <span class="svc-muted">—</span>
            <?php endif; ?>
        </td>
        <td class="svc-td svc-td--type">
            <span class="svc-type-badge svc-type-badge--<?= htmlspecialchars(strtolower(str_replace(' ', '', $svcType))) ?>"><?= htmlspecialchars($svcType) ?></span>
        </td>
        <td class="svc-td svc-td--sku"><span class="svc-mono"><?= htmlspecialchars($r['sku'] ?? '') ?: '<span class="svc-muted">—</span>' ?></span></td>
        <td class="svc-td svc-td--dur"><?= htmlspecialchars($dLabel) ?></td>
        <td class="svc-td svc-td--price"><?= $price > 0 ? htmlspecialchars(number_format($price, 2)) : '<span class="svc-muted">—</span>' ?></td>
        <td class="svc-td svc-td--assigns <?= $staff === 0 ? 'svc-assigns--zero' : '' ?>"><?= $staff ?: '<span class="svc-muted">—</span>' ?></td>
        <td class="svc-td svc-td--assigns <?= $spaces === 0 ? 'svc-assigns--zero' : '' ?>"><?= $spaces ?: '<span class="svc-muted">—</span>' ?></td>
        <td class="svc-td svc-td--assigns <?= $prods === 0 ? 'svc-assigns--zero' : '' ?>"><?= $prods ?: '<span class="svc-muted">—</span>' ?></td>
        <td class="svc-td svc-td--status">
            <span class="svc-status <?= $active ? 'svc-status--active' : 'svc-status--inactive' ?>"><?= $active ? 'Active' : 'Inactive' ?></span>
        </td>
        <td class="svc-td svc-td--actions">
            <div class="svc-row-actions">
                <?php if (empty($trashView)): ?>
                <a href="/services-resources/services/<?= (int) $r['id'] ?>/edit" class="svc-act svc-act--edit" title="Edit service">Edit</a>
                <form method="post" action="/services-resources/services/<?= (int) $r['id'] ?>/delete" class="svc-act-form"
                      onsubmit="return confirm('Move «<?= htmlspecialchars(addslashes($r['name'] ?? '')) ?>» to Trash?')">
                    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                    <button type="submit" class="svc-act svc-act--trash" title="Move to Trash">Trash</button>
                </form>
                <?php else: ?>
                <form method="post" action="/services-resources/services/<?= (int) $r['id'] ?>/restore" class="svc-act-form">
                    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                    <button type="submit" class="svc-act svc-act--restore" title="Restore service">Restore</button>
                </form>
                <form method="post" action="/services-resources/services/<?= (int) $r['id'] ?>/permanent-delete" class="svc-act-form"
                      onsubmit="return confirm('Permanently delete «<?= htmlspecialchars(addslashes($r['name'] ?? '')) ?>»? This cannot be undone.')">
                    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                    <button type="submit" class="svc-act svc-act--delete" title="Permanently delete">Delete</button>
                </form>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- No results (search) -->
<p id="svc-no-results" class="svc-no-results" hidden>No services match your search.</p>

<?php endif; ?>
</div><!-- /#svc-view-list -->

<!-- ══════════════════════════════════════════════════════════════════════════
     STRUCTURE VIEW — grouped category / service hierarchy
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="svc-view-structure" class="svc-view <?= $activeView === 'structure' ? '' : 'svc-view--hidden' ?>">
<?php if ($trashView): ?>
<p class="svc-muted" style="margin:1rem 0;">Structure view is not available in Trash mode.</p>
<?php elseif (empty($services) && empty($uncategorized)): ?>
<div class="svc-empty">
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
    <p class="svc-empty__title">No services yet</p>
    <p class="svc-empty__sub"><a href="/services-resources/services/create">Create your first service</a> to see the structure.</p>
</div>
<?php else: ?>

<!-- Structure toolbar -->
<div class="svc-struct-toolbar">
    <div class="svc-struct-toolbar__left">
        <div class="svc-search-wrap" id="svc-struct-search-wrap">
            <svg class="svc-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="svc-struct-search" class="svc-search-input" placeholder="Search in structure…" autocomplete="off" aria-label="Search services in structure view">
            <button type="button" id="svc-struct-search-clear" class="svc-search-clear" title="Clear" hidden>✕</button>
        </div>
    </div>
    <div class="svc-struct-toolbar__right">
        <button type="button" class="svc-struct-expand-all taxmgr-btn-icon-sm" id="svc-struct-expand-all">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
            Expand all
        </button>
        <button type="button" class="svc-struct-collapse-all taxmgr-btn-icon-sm" id="svc-struct-collapse-all">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>
            Collapse all
        </button>
        <a href="/services-resources/categories" class="taxmgr-btn-icon-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            Manage categories
        </a>
    </div>
</div>

<p id="svc-struct-no-results" class="svc-no-results" hidden>No services match your search.</p>

<div class="svc-struct-grid" id="svc-struct-grid">

<?php foreach ($orderedGrouped as $cid => $group):
    $cat  = $group['category'];
    $svcs = $group['services'];
    $cnt  = count($svcs);
?>
<div class="svc-struct-group" data-cat-id="<?= (int) $cid ?>" id="svc-struct-group-<?= (int) $cid ?>">
    <div class="svc-struct-group-head">
        <button type="button" class="svc-struct-toggle" data-target="svc-struct-group-<?= (int) $cid ?>" aria-expanded="true" aria-label="Toggle <?= htmlspecialchars($cat['name'] ?? '') ?>">
            <svg class="svc-struct-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <a href="/services-resources/categories/<?= (int) $cid ?>" class="svc-struct-cat-name"><?= htmlspecialchars($cat['name'] ?? '') ?></a>
        <?php if (!empty($cat['path']) && strpos($cat['path'], ' › ') !== false): ?>
        <span class="svc-struct-cat-path"><?= htmlspecialchars($cat['path'] ?? '') ?></span>
        <?php endif; ?>
        <span class="svc-struct-cat-count" title="<?= $cnt ?> <?= $cnt === 1 ? 'service' : 'services' ?>"><?= $cnt ?></span>
        <div class="svc-struct-cat-actions">
            <a href="/services-resources/categories?edit=<?= (int) $cid ?>" class="svc-struct-act" title="Edit category">Edit cat.</a>
            <a href="<?= htmlspecialchars(svcIndexUrl(['view' => null, 'category' => (string) $cid])) ?>" class="svc-struct-act" title="Filter list to this category">List view</a>
        </div>
    </div>
    <div class="svc-struct-group-body" id="svc-struct-body-<?= (int) $cid ?>">
        <?php foreach ($svcs as $s):
            $sd = (int) ($s['duration_minutes'] ?? 0);
            $sdLabel = $sd >= 60 ? (floor($sd / 60) . 'h' . ($sd % 60 ? ' ' . ($sd % 60) . 'm' : '')) : ($sd > 0 ? $sd . 'm' : '—');
            $sActive = !empty($s['is_active']);
        ?>
        <div class="svc-struct-row" data-name="<?= htmlspecialchars(strtolower($s['name'] ?? '')) ?>" data-sku="<?= htmlspecialchars(strtolower($s['sku'] ?? '')) ?>">
            <span class="svc-struct-row-indent" aria-hidden="true"></span>
            <div class="svc-struct-row-content">
                <a href="/services-resources/services/<?= (int) $s['id'] ?>" class="svc-struct-svc-name"><?= htmlspecialchars($s['name'] ?? '') ?></a>
                <div class="svc-struct-svc-meta">
                    <?php if ($sdLabel !== '—'): ?><span class="svc-meta-chip svc-meta-chip--dur"><?= htmlspecialchars($sdLabel) ?></span><?php endif; ?>
                    <?php $sp = (float) ($s['price'] ?? 0); if ($sp > 0): ?><span class="svc-meta-chip svc-meta-chip--price"><?= htmlspecialchars(number_format($sp, 2)) ?></span><?php endif; ?>
                    <?php if (!empty($s['add_on'])): ?><span class="svc-meta-chip svc-meta-chip--addon">Add-on</span><?php endif; ?>
                    <?php if (!empty($s['show_in_online_menu'])): ?><span class="svc-meta-chip svc-meta-chip--online">Online</span><?php endif; ?>
                    <span class="svc-meta-status <?= $sActive ? 'svc-status--active' : 'svc-status--inactive' ?>" style="margin-left:auto;"><?= $sActive ? 'Active' : 'Inactive' ?></span>
                </div>
            </div>
            <div class="svc-struct-row-actions">
                <a href="/services-resources/services/<?= (int) $s['id'] ?>/edit" class="svc-act svc-act--edit">Edit</a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($cnt === 0): ?>
        <div class="svc-struct-row svc-struct-row--empty">
            <span class="svc-struct-row-indent" aria-hidden="true"></span>
            <span class="svc-muted" style="font-size:0.8rem;">No services in this category.</span>
            <a href="/services-resources/services/create" class="svc-act svc-act--edit" style="margin-left:auto;">+ Add service</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (!empty($uncategorized)): ?>
<div class="svc-struct-group svc-struct-group--uncat" id="svc-struct-group-uncat">
    <div class="svc-struct-group-head">
        <button type="button" class="svc-struct-toggle" data-target="svc-struct-group-uncat" aria-expanded="true" aria-label="Toggle Uncategorized">
            <svg class="svc-struct-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <span class="svc-struct-cat-name svc-struct-cat-name--uncat">Uncategorized</span>
        <span class="svc-struct-cat-count" title="<?= count($uncategorized) ?> uncategorized services"><?= count($uncategorized) ?></span>
        <div class="svc-struct-cat-actions">
            <a href="/services-resources/categories" class="svc-struct-act" title="Assign a category">Manage categories</a>
        </div>
    </div>
    <div class="svc-struct-group-body" id="svc-struct-body-uncat">
        <?php foreach ($uncategorized as $s):
            $sd = (int) ($s['duration_minutes'] ?? 0);
            $sdLabel = $sd >= 60 ? (floor($sd / 60) . 'h' . ($sd % 60 ? ' ' . ($sd % 60) . 'm' : '')) : ($sd > 0 ? $sd . 'm' : '—');
            $sActive = !empty($s['is_active']);
        ?>
        <div class="svc-struct-row" data-name="<?= htmlspecialchars(strtolower($s['name'] ?? '')) ?>" data-sku="<?= htmlspecialchars(strtolower($s['sku'] ?? '')) ?>">
            <span class="svc-struct-row-indent" aria-hidden="true"></span>
            <div class="svc-struct-row-content">
                <a href="/services-resources/services/<?= (int) $s['id'] ?>" class="svc-struct-svc-name"><?= htmlspecialchars($s['name'] ?? '') ?></a>
                <div class="svc-struct-svc-meta">
                    <?php if ($sdLabel !== '—'): ?><span class="svc-meta-chip svc-meta-chip--dur"><?= htmlspecialchars($sdLabel) ?></span><?php endif; ?>
                    <?php $sp = (float) ($s['price'] ?? 0); if ($sp > 0): ?><span class="svc-meta-chip svc-meta-chip--price"><?= htmlspecialchars(number_format($sp, 2)) ?></span><?php endif; ?>
                    <?php if (!empty($s['add_on'])): ?><span class="svc-meta-chip svc-meta-chip--addon">Add-on</span><?php endif; ?>
                    <?php if (!empty($s['show_in_online_menu'])): ?><span class="svc-meta-chip svc-meta-chip--online">Online</span><?php endif; ?>
                    <span class="svc-meta-status <?= $sActive ? 'svc-status--active' : 'svc-status--inactive' ?>" style="margin-left:auto;"><?= $sActive ? 'Active' : 'Inactive' ?></span>
                </div>
            </div>
            <div class="svc-struct-row-actions">
                <a href="/services-resources/services/<?= (int) $s['id'] ?>/edit" class="svc-act svc-act--edit">Edit</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div><!-- /.svc-struct-grid -->
<?php endif; ?>
</div><!-- /#svc-view-structure -->

<script>
(function () {
    'use strict';

    // ── Bulk form routing ───────────────────────────────────────────────────
    var bulkForm  = document.getElementById('svc-bulk-form');
    var bulkSel   = document.getElementById('svc-bulk-action');
    var checkAll  = document.getElementById('svc-check-all');
    var bulkCount = document.getElementById('svc-bulk-count');

    function updateBulkCount() {
        var n = document.querySelectorAll('.svc-row-check:checked').length;
        if (bulkCount) {
            if (n > 0) { bulkCount.hidden = false; bulkCount.textContent = n + ' selected'; }
            else        { bulkCount.hidden = true; }
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.svc-row-check').forEach(function (c) { c.checked = checkAll.checked; });
            updateBulkCount();
        });
    }

    document.querySelectorAll('.svc-row-check').forEach(function (c) {
        c.addEventListener('change', updateBulkCount);
    });

    if (bulkForm && bulkSel) {
        bulkForm.addEventListener('submit', function (e) {
            var act = bulkSel.value;
            if (!act) { e.preventDefault(); return false; }
            var checked = document.querySelectorAll('.svc-row-check:checked');
            if (checked.length === 0) { e.preventDefault(); alert('Select at least one service.'); return false; }
            var n = checked.length;
            var msg = act === 'move_to_trash'
                ? 'Move ' + n + ' service(s) to Trash?'
                : act === 'restore'
                ? 'Restore ' + n + ' service(s)?'
                : 'Permanently delete ' + n + ' service(s)? This cannot be undone.';
            if (!confirm(msg)) { e.preventDefault(); return false; }
            if (act === 'move_to_trash')     bulkForm.action = '/services-resources/services/bulk-trash';
            else if (act === 'restore')       bulkForm.action = '/services-resources/services/bulk-restore';
            else if (act === 'delete_permanently') bulkForm.action = '/services-resources/services/bulk-permanent-delete';
            else { e.preventDefault(); return false; }
        });
    }

    // ── List-view client search ─────────────────────────────────────────────
    var searchInput = document.getElementById('svc-search-input');
    var searchClear = document.getElementById('svc-search-clear');
    var noResults   = document.getElementById('svc-no-results');

    function applyListSearch() {
        if (!searchInput) return;
        var q = searchInput.value.toLowerCase().trim();
        if (searchClear) searchClear.hidden = q === '';
        var rows = document.querySelectorAll('#svc-table-body .svc-row');
        var vis = 0;
        rows.forEach(function (r) {
            var match = q === ''
                || (r.dataset.name || '').includes(q)
                || (r.dataset.cat  || '').includes(q)
                || (r.dataset.sku  || '').includes(q);
            r.style.display = match ? '' : 'none';
            if (match) vis++;
        });
        if (noResults) noResults.hidden = vis > 0 || q === '';
        // Uncheck hidden rows' checkboxes
        rows.forEach(function (r) {
            if (r.style.display === 'none') {
                var cb = r.querySelector('.svc-row-check');
                if (cb) cb.checked = false;
            }
        });
        updateBulkCount();
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyListSearch);
        if (searchClear) searchClear.addEventListener('click', function () {
            searchInput.value = '';
            applyListSearch();
            searchInput.focus();
        });
    }

    // Keyboard: focus search with /
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/') return;
        var tag = document.activeElement ? document.activeElement.tagName : '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        e.preventDefault();
        var target = document.getElementById('svc-view-list').classList.contains('svc-view--hidden')
            ? document.getElementById('svc-struct-search')
            : searchInput;
        if (target) { target.focus(); target.select(); }
    });

    // ── Structure-view collapse/expand ─────────────────────────────────────
    function applyStructureCollapse(groupId, collapse) {
        var body = document.getElementById('svc-struct-body-' + groupId);
        var group = document.getElementById('svc-struct-group-' + groupId);
        if (!body || !group) return;
        body.style.display = collapse ? 'none' : '';
        var btn = group.querySelector('.svc-struct-toggle');
        if (btn) {
            btn.setAttribute('aria-expanded', collapse ? 'false' : 'true');
            var chevron = btn.querySelector('.svc-struct-chevron');
            if (chevron) chevron.style.transform = collapse ? 'rotate(-90deg)' : '';
        }
    }

    document.querySelectorAll('.svc-struct-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-target');
            var body = document.getElementById('svc-struct-body-' + target.replace('svc-struct-group-', ''));
            var group = document.getElementById(target);
            if (!body) return;
            var collapsed = body.style.display === 'none';
            applyStructureCollapse(target.replace('svc-struct-group-', ''), !collapsed);
        });
    });

    var expandAllBtn   = document.getElementById('svc-struct-expand-all');
    var collapseAllBtn = document.getElementById('svc-struct-collapse-all');

    function getAllGroupIds() {
        var groups = document.querySelectorAll('#svc-struct-grid .svc-struct-group');
        return Array.prototype.map.call(groups, function (g) {
            return g.id.replace('svc-struct-group-', '');
        });
    }

    if (expandAllBtn) {
        expandAllBtn.addEventListener('click', function () {
            getAllGroupIds().forEach(function (id) { applyStructureCollapse(id, false); });
        });
    }
    if (collapseAllBtn) {
        collapseAllBtn.addEventListener('click', function () {
            getAllGroupIds().forEach(function (id) { applyStructureCollapse(id, true); });
        });
    }

    // ── Structure search ────────────────────────────────────────────────────
    var structSearch      = document.getElementById('svc-struct-search');
    var structSearchClear = document.getElementById('svc-struct-search-clear');
    var structNoResults   = document.getElementById('svc-struct-no-results');

    function applyStructSearch() {
        if (!structSearch) return;
        var q = structSearch.value.toLowerCase().trim();
        if (structSearchClear) structSearchClear.hidden = q === '';

        var totalVis = 0;
        var groups = document.querySelectorAll('#svc-struct-grid .svc-struct-group');
        groups.forEach(function (group) {
            var rows = group.querySelectorAll('.svc-struct-row:not(.svc-struct-row--empty)');
            var groupVis = 0;
            rows.forEach(function (r) {
                var match = q === ''
                    || (r.dataset.name || '').includes(q)
                    || (r.dataset.sku  || '').includes(q);
                r.style.display = match ? '' : 'none';
                if (match) groupVis++;
            });
            // Show group if any child matches or no query
            group.style.display = (q === '' || groupVis > 0) ? '' : 'none';
            // Expand groups that have matches
            if (q !== '' && groupVis > 0) {
                var body = group.querySelector('[id^="svc-struct-body-"]');
                if (body) body.style.display = '';
                var btn = group.querySelector('.svc-struct-toggle');
                if (btn) {
                    btn.setAttribute('aria-expanded', 'true');
                    var chevron = btn.querySelector('.svc-struct-chevron');
                    if (chevron) chevron.style.transform = '';
                }
            }
            totalVis += groupVis;
        });

        if (structNoResults) structNoResults.hidden = totalVis > 0 || q === '';
    }

    if (structSearch) {
        structSearch.addEventListener('input', applyStructSearch);
        if (structSearchClear) structSearchClear.addEventListener('click', function () {
            structSearch.value = '';
            applyStructSearch();
            structSearch.focus();
        });
    }

})();
</script>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
