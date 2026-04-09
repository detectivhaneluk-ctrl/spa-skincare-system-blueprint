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

// ── Category tree + per-category services for Structure (map) view ─────────
$serviceMapCategories = $serviceMapCategories ?? [];
$categoryById = [];
foreach ($serviceMapCategories as $c) {
    $categoryById[(int) $c['id']] = $c;
}
$childrenByParent = [];
foreach ($serviceMapCategories as $c) {
    $pid = isset($c['parent_id']) && $c['parent_id'] !== '' && $c['parent_id'] !== null
        ? (int) $c['parent_id']
        : 0;
    $childrenByParent[$pid][] = $c;
}
foreach ($childrenByParent as &$grp) {
    usort(
        $grp,
        fn ($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0)
            ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
    );
}
unset($grp);

$servicesByCategory = [];
$uncategorized      = [];
foreach ($services as $s) {
    $cid = (int) ($s['category_id'] ?? 0);
    if ($cid > 0 && isset($categoryById[$cid])) {
        $servicesByCategory[$cid][] = $s;
    } else {
        $uncategorized[] = $s;
    }
}
foreach ($servicesByCategory as &$sg) {
    usort($sg, fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
}
unset($sg);

$mapRootCategoryId = $baseCategory !== null ? $baseCategory : null;

require_once __DIR__ . '/_flow_tree_build.php';
$olliraFlowTree = ollira_services_build_flow_tree(
    $childrenByParent,
    $categoryById,
    $servicesByCategory,
    $uncategorized,
    'Ollira',
    $mapRootCategoryId
);
try {
    $olliraFlowJson = json_encode(
        $olliraFlowTree,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    );
} catch (Throwable $e) {
    $olliraFlowJson = '{"id":"root","type":"root","name":"Ollira","children":[]}';
}
$olliraFlowBuilt = is_file(base_path('public/assets/services-map/ollira-services-map.js'));

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
<?php elseif (empty($serviceMapCategories) && empty($services)): ?>
<div class="svc-empty">
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
    <p class="svc-empty__title">No categories or services yet</p>
    <p class="svc-empty__sub"><a href="/services-resources/categories/create">Add a category</a> or <a href="/services-resources/services/create">create a service</a> to build your map.</p>
</div>
<?php else: ?>

<div class="svc-map-toolbar svc-struct-toolbar">
    <div class="svc-struct-toolbar__left">
        <?php if (!$olliraFlowBuilt): ?>
        <div class="svc-search-wrap" id="svc-struct-search-wrap">
            <svg class="svc-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="svc-struct-search" class="svc-search-input" placeholder="Search map…" autocomplete="off" aria-label="Search categories and services on the map">
            <button type="button" id="svc-struct-search-clear" class="svc-search-clear" title="Clear" hidden>✕</button>
        </div>
        <?php endif; ?>
    </div>
    <div class="svc-struct-toolbar__right svc-map-toolbar__right">
        <a href="/services-resources/categories" class="svc-map-toolbar-btn taxmgr-btn-icon-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><polyline points="15 19 12 22 9 19"/><polyline points="19 9 22 12 19 15"/></svg>
            Edit order
        </a>
        <a href="<?= htmlspecialchars(svcIndexUrl(['category' => $baseCategory ? (string) $baseCategory : null, 'sort' => $baseSort, 'dir' => $baseDir])) ?>" class="svc-map-toolbar-btn taxmgr-btn-icon-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            List view
        </a>
        <?php if (!$olliraFlowBuilt): ?>
        <button type="button" class="svc-map-toolbar-btn taxmgr-btn-icon-sm" id="svc-map-reset-view" title="Reset pan and zoom">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
            Reset view
        </button>
        <button type="button" class="svc-struct-expand-all taxmgr-btn-icon-sm" id="svc-struct-expand-all">Expand all</button>
        <button type="button" class="svc-struct-collapse-all taxmgr-btn-icon-sm" id="svc-struct-collapse-all">Collapse all</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($olliraFlowBuilt): ?>
<link rel="stylesheet" href="/assets/services-map/ollira-services-map.css">
<p id="svc-struct-no-results" class="svc-no-results" hidden></p>
<div
    id="ollira-svc-flow-root"
    class="ollira-svc-flow-host"
    style="min-height:72vh;height:72vh;width:100%;box-sizing:border-box"
    data-csrf-name="<?= htmlspecialchars($csrfName, ENT_QUOTES, 'UTF-8') ?>"
    data-csrf-value="<?= htmlspecialchars($csrfVal, ENT_QUOTES, 'UTF-8') ?>"
></div>
<script type="application/json" id="ollira-svc-flow-json"><?= $olliraFlowJson ?></script>
<script src="/assets/services-map/ollira-services-map.js" defer></script>
<script>
(function () {
    var flowMounted = false;
    function showFlowErr(msg) {
        var el = document.getElementById('ollira-svc-flow-root');
        if (el && !flowMounted) {
            el.innerHTML = '<p class="svc-muted" style="padding:1rem;">' + msg + '</p>';
        }
    }
    function bootOlliraFlow() {
        var struct = document.getElementById('svc-view-structure');
        if (struct && struct.classList.contains('svc-view--hidden')) {
            return;
        }
        var el = document.getElementById('ollira-svc-flow-root');
        var j = document.getElementById('ollira-svc-flow-json');
        var M = window.OlliraServicesMap;
        if (!el || !j) return;
        if (!M || typeof M.mount !== 'function') {
            return;
        }
        if (flowMounted) {
            requestAnimationFrame(function () { window.dispatchEvent(new Event('resize')); });
            return;
        }
        var csrfName = el.getAttribute('data-csrf-name') || '';
        var csrfVal = el.getAttribute('data-csrf-value') || '';
        try {
            function openStructureDrawer(url) {
                if (window.AppDrawer && typeof window.AppDrawer.openUrl === 'function') {
                    window.AppDrawer.openUrl(url);
                    return true;
                }
                return false;
            }
            M.mount(el, {
                tree: JSON.parse(j.textContent || '{}'),
                height: '72vh',
                categoryReparentUrl: '/services-resources/categories/reparent',
                csrfToken: csrfVal,
                onAction: function (p) {
                    if (p.action === 'reparent-success') {
                        window.location.reload();
                        return;
                    }
                    if (p.action === 'edit') {
                        if (p.node.type === 'service') {
                            var editSvc = '/services-resources/services/' + String(p.node.id).replace(/^svc-/, '') + '/edit';
                            if (!openStructureDrawer(editSvc)) {
                                window.location.assign(editSvc);
                            }
                            return;
                        }
                        if (p.node.type === 'category') {
                            var ce = String(p.node.id).replace(/^cat-/, '');
                            if (ce === 'uncategorized') return;
                            var catEdit = '/services-resources/categories?edit=' + encodeURIComponent(ce);
                            if (!openStructureDrawer(catEdit)) {
                                window.location.assign(catEdit);
                            }
                            return;
                        }
                    }
                    if (p.action === 'add-child') {
                        if (p.nodeId === 'root') {
                            var catRoot = '/services-resources/categories';
                            if (!openStructureDrawer(catRoot)) {
                                window.location.assign(catRoot);
                            }
                            return;
                        }
                        if (String(p.nodeId).indexOf('cat-') === 0) {
                            var cp = String(p.nodeId).replace(/^cat-/, '');
                            if (cp === 'uncategorized') {
                                var crUnc = '/services-resources/services/create';
                                if (!openStructureDrawer(crUnc)) {
                                    window.location.assign(crUnc);
                                }
                                return;
                            }
                            var catChild = '/services-resources/categories?parent_id=' + encodeURIComponent(cp);
                            if (!openStructureDrawer(catChild)) {
                                window.location.assign(catChild);
                            }
                            return;
                        }
                        var crSvc = '/services-resources/services/create';
                        if (!openStructureDrawer(crSvc)) {
                            window.location.assign(crSvc);
                        }
                    }
                    if (p.action === 'delete' && p.node.type === 'service') {
                        var sid = String(p.node.id).replace(/^svc-/, '');
                        if (!confirm('Move «' + (p.node.name || '') + '» to Trash?')) return;
                        if (window.AppDrawer && typeof window.fetch === 'function') {
                            var fd = new FormData();
                            fd.append(csrfName, csrfVal);
                            fetch('/services-resources/services/' + encodeURIComponent(sid) + '/delete?drawer=1', {
                                method: 'POST',
                                body: fd,
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-App-Drawer': '1'
                                }
                            })
                                .then(function (r) {
                                    return r.json().catch(function () {
                                        return null;
                                    });
                                })
                                .then(function (payload) {
                                    if (payload && payload.success && payload.data && payload.data.reload_host) {
                                        var T = window.OlliraToast;
                                        if (T && payload.data.message && typeof T.success === 'function') {
                                            T.success(payload.data.message);
                                        }
                                        window.location.reload();
                                        return;
                                    }
                                    var msg =
                                        payload && payload.error && payload.error.message
                                            ? payload.error.message
                                            : 'Could not move service to Trash.';
                                    var Te = window.OlliraToast;
                                    if (Te && typeof Te.error === 'function') {
                                        Te.error(msg);
                                    } else {
                                        window.alert(msg);
                                    }
                                })
                                .catch(function () {
                                    var Tx = window.OlliraToast;
                                    if (Tx && typeof Tx.error === 'function') {
                                        Tx.error('Could not move service to Trash.');
                                    }
                                });
                            return;
                        }
                        var f = document.createElement('form');
                        f.method = 'post';
                        f.action = '/services-resources/services/' + sid + '/delete';
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = csrfName;
                        inp.value = csrfVal;
                        f.appendChild(inp);
                        document.body.appendChild(f);
                        f.submit();
                    }
                }
            });
            flowMounted = true;
            requestAnimationFrame(function () { window.dispatchEvent(new Event('resize')); });
        } catch (e) {
            if (window.console && console.error) console.error(e);
            showFlowErr('Map failed to start. Check the browser console.');
        }
    }
    function waitForMapLib(then) {
        var n = 0;
        var id = setInterval(function () {
            n++;
            if (window.OlliraServicesMap && typeof window.OlliraServicesMap.mount === 'function') {
                clearInterval(id);
                then();
            } else if (n >= 120) {
                clearInterval(id);
                showFlowErr('Could not load /assets/services-map/ollira-services-map.js (blocked or 404). Run <code>npm run build</code> in <code>frontend/services-map</code>.');
            }
        }, 50);
    }
    function whenStructureVisible(then) {
        var struct = document.getElementById('svc-view-structure');
        if (!struct) return;
        if (!struct.classList.contains('svc-view--hidden')) {
            then();
            return;
        }
        var mo = new MutationObserver(function () {
            if (!struct.classList.contains('svc-view--hidden')) {
                mo.disconnect();
                then();
            }
        });
        mo.observe(struct, { attributes: true, attributeFilter: ['class'] });
    }
    function schedule() {
        whenStructureVisible(function () {
            waitForMapLib(bootOlliraFlow);
        });
    }
    if (document.readyState === 'complete') {
        schedule();
    } else {
        window.addEventListener('load', schedule);
    }
})();
</script>
<?php else: ?>
<p class="svc-muted" style="margin:0 0 0.75rem;font-size:0.8125rem;">Run <code>cd frontend/services-map && npm install && npm run build</code> to enable the React Flow mind-map (dagre layout).</p>
<p id="svc-struct-no-results" class="svc-no-results" hidden>No matches in this map.</p>
<div class="svc-map-wrap" id="svc-map-wrap">
    <div class="svc-map-viewport" id="svc-map-viewport" tabindex="0" aria-label="Service category map, drag to pan">
        <button type="button" class="svc-map-pan svc-map-pan--n" id="svc-map-pan-n" aria-label="Pan up"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg></button>
        <button type="button" class="svc-map-pan svc-map-pan--s" id="svc-map-pan-s" aria-label="Pan down"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg></button>
        <button type="button" class="svc-map-pan svc-map-pan--w" id="svc-map-pan-w" aria-label="Pan left"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>
        <button type="button" class="svc-map-pan svc-map-pan--e" id="svc-map-pan-e" aria-label="Pan right"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></button>
        <div class="svc-map-zoom" role="group" aria-label="Zoom">
            <button type="button" class="svc-map-zoom__btn" id="svc-map-zoom-out" aria-label="Zoom out">−</button>
            <span class="svc-map-zoom__label" id="svc-map-zoom-label">100%</span>
            <button type="button" class="svc-map-zoom__btn" id="svc-map-zoom-in" aria-label="Zoom in">+</button>
        </div>
        <div class="svc-map-stage" id="svc-map-stage">
            <div class="svc-map-canvas" id="svc-map-canvas">
                <?php require __DIR__ . '/_structure_map_tree.php'; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
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

    // ── Structure map (legacy HTML tree only when React bundle not built) ───
    if (document.getElementById('svc-map-wrap')) {
    function setMapBranchCollapsed(ul, collapsed) {
        if (!ul) return;
        ul.classList.toggle('svc-map-children--collapsed', collapsed);
        var prev = ul.previousElementSibling;
        if (prev && prev.classList && prev.classList.contains('svc-map-node')) {
            var tbtn = prev.querySelector('.svc-map-collapse');
            if (tbtn) {
                tbtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                var ic = tbtn.querySelector('.svc-map-collapse__icon');
                if (ic) ic.style.transform = collapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
            }
        }
    }

    document.querySelectorAll('.svc-map-collapse').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var node = btn.closest('.svc-map-branch');
            if (!node) return;
            var ul = node.querySelector(':scope > ul.svc-map-children');
            if (!ul) return;
            var collapsed = !ul.classList.contains('svc-map-children--collapsed');
            setMapBranchCollapsed(ul, collapsed);
        });
    });

    var expandAllBtn   = document.getElementById('svc-struct-expand-all');
    var collapseAllBtn = document.getElementById('svc-struct-collapse-all');

    if (expandAllBtn) {
        expandAllBtn.addEventListener('click', function () {
            document.querySelectorAll('#svc-map-tree ul.svc-map-children').forEach(function (ul) {
                setMapBranchCollapsed(ul, false);
            });
        });
    }
    if (collapseAllBtn) {
        collapseAllBtn.addEventListener('click', function () {
            document.querySelectorAll('#svc-map-tree ul.svc-map-children').forEach(function (ul) {
                setMapBranchCollapsed(ul, true);
            });
        });
    }

    // ── Structure map: pan & zoom ───────────────────────────────────────────
    var mapCanvas = document.getElementById('svc-map-canvas');
    var mapStage  = document.getElementById('svc-map-stage');
    var mapViewport = document.getElementById('svc-map-viewport');
    var zoomLabel = document.getElementById('svc-map-zoom-label');
    var mapScale = 1;
    var mapTx = 0;
    var mapTy = 0;
    var mapDrag = false;
    var mapDragX = 0;
    var mapDragY = 0;
    var mapStartTx = 0;
    var mapStartTy = 0;

    function clampMapScale(s) {
        if (s < 0.45) return 0.45;
        if (s > 1.6) return 1.6;
        return s;
    }

    function applyMapTransform() {
        if (!mapCanvas) return;
        mapCanvas.style.transform = 'translate(' + mapTx + 'px,' + mapTy + 'px) scale(' + mapScale + ')';
        if (zoomLabel) zoomLabel.textContent = Math.round(mapScale * 100) + '%';
    }

    function nudgeMap(dx, dy) {
        mapTx += dx;
        mapTy += dy;
        applyMapTransform();
    }

    if (mapCanvas && mapStage) {
        applyMapTransform();

        mapStage.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            var t = e.target;
            if (t.closest('a, button, input, textarea, select, .svc-map-act-form')) return;
            mapDrag = true;
            mapDragX = e.clientX;
            mapDragY = e.clientY;
            mapStartTx = mapTx;
            mapStartTy = mapTy;
            mapStage.classList.add('svc-map-stage--dragging');
            e.preventDefault();
        });
        window.addEventListener('mousemove', function (e) {
            if (!mapDrag) return;
            mapTx = mapStartTx + (e.clientX - mapDragX);
            mapTy = mapStartTy + (e.clientY - mapDragY);
            applyMapTransform();
        });
        window.addEventListener('mouseup', function () {
            if (mapDrag) {
                mapDrag = false;
                mapStage.classList.remove('svc-map-stage--dragging');
            }
        });

        [['svc-map-pan-n', 0, 56], ['svc-map-pan-s', 0, -56], ['svc-map-pan-w', 56, 0], ['svc-map-pan-e', -56, 0]].forEach(function (row) {
            var el = document.getElementById(row[0]);
            if (el) el.addEventListener('click', function () { nudgeMap(row[1], row[2]); });
        });

        var zIn  = document.getElementById('svc-map-zoom-in');
        var zOut = document.getElementById('svc-map-zoom-out');
        if (zIn) zIn.addEventListener('click', function () {
            mapScale = clampMapScale(mapScale + 0.1);
            applyMapTransform();
        });
        if (zOut) zOut.addEventListener('click', function () {
            mapScale = clampMapScale(mapScale - 0.1);
            applyMapTransform();
        });

        var zReset = document.getElementById('svc-map-reset-view');
        if (zReset) zReset.addEventListener('click', function () {
            mapScale = 1;
            mapTx = 0;
            mapTy = 0;
            applyMapTransform();
        });

        mapViewport.addEventListener('wheel', function (e) {
            if (!e.ctrlKey) return;
            e.preventDefault();
            var delta = e.deltaY < 0 ? 0.08 : -0.08;
            mapScale = clampMapScale(mapScale + delta);
            applyMapTransform();
        }, { passive: false });
    }

    // ── Structure map search ────────────────────────────────────────────────
    var structSearch      = document.getElementById('svc-struct-search');
    var structSearchClear = document.getElementById('svc-struct-search-clear');
    var structNoResults   = document.getElementById('svc-struct-no-results');

    function mapBranchVisible(li, q) {
        var self = (li.dataset.mapSearch || '').toLowerCase();
        var childBranches = li.querySelectorAll(':scope > ul.svc-map-children > li.svc-map-branch');
        var anyChild = false;
        childBranches.forEach(function (childLi) {
            if (mapBranchVisible(childLi, q)) anyChild = true;
        });
        var selfMatch = q === '' || self.indexOf(q) !== -1;
        var show = selfMatch || anyChild;
        li.style.display = show ? '' : 'none';
        if (anyChild && q !== '') {
            var ul = li.querySelector(':scope > ul.svc-map-children');
            if (ul) setMapBranchCollapsed(ul, false);
        }
        return show;
    }

    function applyStructSearch() {
        if (!structSearch) return;
        var q = structSearch.value.toLowerCase().trim();
        if (structSearchClear) structSearchClear.hidden = q === '';

        var tree = document.getElementById('svc-map-tree');
        if (!tree) return;
        var rootLi = tree.querySelector(':scope > li.svc-map-branch--root');
        if (!rootLi) return;
        mapBranchVisible(rootLi, q);

        var anyVis = false;
        rootLi.querySelectorAll('li.svc-map-branch').forEach(function (li) {
            if (!li.classList.contains('svc-map-branch--root') && li.style.display !== 'none') anyVis = true;
        });
        if (structNoResults) structNoResults.hidden = anyVis || q === '';
    }

    if (structSearch) {
        structSearch.addEventListener('input', applyStructSearch);
        if (structSearchClear) structSearchClear.addEventListener('click', function () {
            structSearch.value = '';
            applyStructSearch();
            structSearch.focus();
        });
    }

    }

})();
</script>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
