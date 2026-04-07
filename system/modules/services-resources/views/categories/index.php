<?php
$title = 'Service Categories';
ob_start();

$csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$csrfVal  = htmlspecialchars($csrf ?? '');

// ── Panel state ────────────────────────────────────────────────────────
$isEditMode    = ($panelMode ?? 'create') === 'edit';
$isChildMode   = !$isEditMode && ($preParentId ?? 0) > 0;
$panelCat      = $editCategory ?? $panelFormData ?? [];
$panelErrors   = $editErrors ?? [];
$panelParentId = $isEditMode
    ? (int) ($panelCat['parent_id'] ?? 0)
    : (int) ($panelFormData['parent_id'] ?? $preParentId ?? 0);

// Find full path of pre-selected parent for contextual hint
$parentHintPath = '';
if ($panelParentId > 0) {
    foreach ($panelTreeRows ?? $treeRows as $tr) {
        if ((int) $tr['id'] === $panelParentId) {
            $parentHintPath = $tr['path'] ?? $tr['name'] ?? '';
            break;
        }
    }
}

// Find full path of the category being edited
$editCatPath = '';
if ($isEditMode && !empty($panelCat['id'])) {
    foreach ($treeRows as $tr) {
        if ((int) $tr['id'] === (int) $panelCat['id']) {
            $editCatPath = $tr['path'] ?? $tr['name'] ?? '';
            break;
        }
    }
}

// Panel mode labels
if ($isEditMode) {
    $panelTitle    = 'Edit category';
    $panelBtnLabel = 'Save changes';
} elseif ($isChildMode) {
    $panelTitle    = 'Add child category';
    $panelBtnLabel = 'Add child category';
} else {
    $panelTitle    = 'Add root category';
    $panelBtnLabel = 'Add root category';
}

$totalCount = count($treeRows);
?>
<div class="taxmgr-wrap">

    <!-- ── Page header ─────────────────────────────────────────────────── -->
    <div class="taxmgr-header">
        <div class="taxmgr-header-left">
            <a href="/services-resources" class="taxmgr-back-link">← Services &amp; Resources</a>
            <h1 class="taxmgr-title">
                Service Categories
                <?php if ($totalCount > 0): ?>
                <span class="taxmgr-count-badge"><?= $totalCount ?></span>
                <?php endif; ?>
            </h1>
            <p class="taxmgr-subtitle">Canonical service taxonomy — unlimited depth. Each service stores one category node.</p>
        </div>
    </div>

    <?php if ($flash && is_array($flash)): $ft = array_key_first($flash); ?>
    <div class="flash flash-<?= htmlspecialchars($ft) ?>"><?= htmlspecialchars($flash[$ft] ?? '') ?></div>
    <?php endif; ?>

    <!-- ── Two-column body ─────────────────────────────────────────────── -->
    <div class="taxmgr-body">

        <!-- ════════════════════════════════════════
             LEFT: Authoring panel
             ════════════════════════════════════════ -->
        <aside class="taxmgr-panel" id="taxmgr-panel">

            <!-- Panel header — changes by mode -->
            <div class="taxmgr-panel-header taxmgr-panel-header--<?= $isEditMode ? 'edit' : ($isChildMode ? 'child' : 'root') ?>">
                <span class="taxmgr-panel-mode-icon">
                    <?php if ($isEditMode): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php elseif ($isChildMode): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <?php else: ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <?php endif; ?>
                </span>
                <span class="taxmgr-panel-title" id="taxmgr-panel-title"><?= htmlspecialchars($panelTitle) ?></span>
                <?php if ($isEditMode || $isChildMode): ?>
                <a href="/services-resources/categories" class="taxmgr-panel-reset" title="Reset to default mode">✕</a>
                <?php endif; ?>
            </div>

            <!-- Context strip — shown in child / edit mode -->
            <?php if ($isChildMode && $parentHintPath !== ''): ?>
            <div class="taxmgr-panel-ctx taxmgr-panel-ctx--child">
                <span class="taxmgr-ctx-label">Under</span>
                <span class="taxmgr-ctx-path"><?= htmlspecialchars($parentHintPath) ?></span>
            </div>
            <?php elseif ($isEditMode && $editCatPath !== ''): ?>
            <div class="taxmgr-panel-ctx taxmgr-panel-ctx--edit">
                <span class="taxmgr-ctx-label">Editing</span>
                <span class="taxmgr-ctx-path"><?= htmlspecialchars($editCatPath) ?></span>
            </div>
            <?php endif; ?>

            <!-- Validation errors -->
            <?php if (!empty($panelErrors)): ?>
            <div class="taxmgr-panel-errors">
                <?php foreach ($panelErrors as $field => $msg): ?>
                <p><?= htmlspecialchars(is_string($msg) ? $msg : ($msg['message'] ?? 'Error')) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="post"
                  action="<?= $isEditMode ? '/services-resources/categories/' . (int) $panelCat['id'] : '/services-resources/categories' ?>"
                  class="taxmgr-form"
                  id="taxmgr-form">
                <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">

                <div class="taxmgr-form-row">
                    <label for="taxmgr-name">Name <span class="taxmgr-required" aria-label="required">*</span></label>
                    <input type="text" id="taxmgr-name" name="name" required autocomplete="off"
                           value="<?= htmlspecialchars($panelCat['name'] ?? '') ?>"
                           placeholder="<?= $isChildMode ? 'Child category name' : 'e.g. Facial Treatments' ?>">
                    <?php if (!empty($panelErrors['name'])): ?>
                    <span class="taxmgr-field-error"><?= htmlspecialchars($panelErrors['name']) ?></span>
                    <?php endif; ?>
                </div>

                <?php
                // In root mode: parent picker is shown collapsed/secondary.
                // In child/edit mode: always shown prominently.
                $showParentPicker = $isEditMode || $isChildMode || ($panelParentId > 0);
                ?>
                <div class="taxmgr-form-row" id="taxmgr-parent-row"
                     <?php if (!$isEditMode && !$isChildMode && $panelParentId === 0): ?>
                     data-collapsible="true"
                     <?php endif; ?>>
                    <label for="taxmgr-parent">
                        Parent category
                        <?php if ($isEditMode): ?>
                        <span class="taxmgr-field-hint-inline">(self &amp; descendants excluded)</span>
                        <?php endif; ?>
                    </label>
                    <select id="taxmgr-parent" name="parent_id">
                        <option value="">— None (root category) —</option>
                        <?php foreach ($panelTreeRows ?? $treeRows as $tr): ?>
                        <?php $depth = (int) ($tr['depth'] ?? 0); ?>
                        <option value="<?= (int) $tr['id'] ?>"
                            <?= $panelParentId === (int) $tr['id'] ? 'selected' : '' ?>>
                            <?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth) ?><?= $depth > 0 ? '└ ' : '' ?><?= htmlspecialchars($tr['name'] ?? '') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($panelErrors['parent_id'])): ?>
                    <span class="taxmgr-field-error"><?= htmlspecialchars($panelErrors['parent_id']) ?></span>
                    <?php endif; ?>
                    <?php if (!$isEditMode && !$isChildMode): ?>
                    <span class="taxmgr-field-hint">Leave blank for a root (top-level) category.</span>
                    <?php endif; ?>
                </div>

                <div class="taxmgr-form-row taxmgr-form-row--sort">
                    <label for="taxmgr-sort">Sort order</label>
                    <div class="taxmgr-sort-row">
                        <input type="number" id="taxmgr-sort" name="sort_order" min="0"
                               value="<?= (int) ($panelCat['sort_order'] ?? 0) ?>"
                               class="taxmgr-sort-panel-input">
                        <span class="taxmgr-field-hint">Lower = earlier within siblings.</span>
                    </div>
                </div>

                <div class="taxmgr-form-actions">
                    <button type="submit" class="taxmgr-btn-primary">
                        <?= htmlspecialchars($panelBtnLabel) ?>
                    </button>
                    <?php if ($isEditMode || $isChildMode): ?>
                    <a href="/services-resources/categories" class="taxmgr-btn-ghost">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Edit meta footer -->
            <?php if ($isEditMode && !empty($panelCat['id'])): ?>
            <?php
            $editId2 = (int) $panelCat['id'];
            $editChildCnt = 0;
            foreach ($treeRows as $tr) {
                if ((int) ($tr['parent_id'] ?? 0) === $editId2) $editChildCnt++;
            }
            $editSvcCnt = (int) ($panelCat['service_count'] ?? 0);
            ?>
            <div class="taxmgr-panel-footer">
                <div class="taxmgr-panel-footer-stats">
                    <span title="Direct children"><strong><?= $editChildCnt ?></strong> <?= $editChildCnt === 1 ? 'child' : 'children' ?></span>
                    <span class="taxmgr-footer-sep">·</span>
                    <span title="Services assigned"><strong><?= $editSvcCnt ?></strong> <?= $editSvcCnt === 1 ? 'service' : 'services' ?></span>
                </div>
                <a href="/services-resources/categories/<?= $editId2 ?>" class="taxmgr-footer-view-link">View →</a>
            </div>
            <?php endif; ?>
        </aside>

        <!-- ════════════════════════════════════════
             RIGHT: Tree table
             ════════════════════════════════════════ -->
        <main class="taxmgr-main">
            <?php if ($totalCount === 0): ?>
            <div class="taxmgr-empty">
                <div class="taxmgr-empty-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                </div>
                <p class="taxmgr-empty-title">No categories yet</p>
                <p class="taxmgr-empty-sub">Use the panel on the left to add your first root category.</p>
            </div>
            <?php else: ?>

            <!-- Search + filter toolbar -->
            <div class="taxmgr-toolbar">
                <div class="taxmgr-search-wrap">
                    <svg class="taxmgr-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="taxmgr-search" class="taxmgr-search-input"
                           placeholder="Search categories…"
                           autocomplete="off"
                           aria-label="Search categories">
                    <button type="button" id="taxmgr-search-clear" class="taxmgr-search-clear" title="Clear search" hidden>✕</button>
                </div>
                <div class="taxmgr-filter-tabs" role="tablist" aria-label="Filter categories">
                    <button type="button" class="taxmgr-filter-tab taxmgr-filter-tab--active" data-filter="all" role="tab">All</button>
                    <button type="button" class="taxmgr-filter-tab" data-filter="roots" role="tab">Roots</button>
                    <button type="button" class="taxmgr-filter-tab" data-filter="unused" role="tab">Unused</button>
                    <button type="button" class="taxmgr-filter-tab" data-filter="used" role="tab">In use</button>
                </div>
                <div class="taxmgr-toolbar-right">
                    <button type="button" id="taxmgr-expand-all" class="taxmgr-btn-icon-sm" title="Expand all">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                        Expand all
                    </button>
                    <button type="button" id="taxmgr-collapse-all" class="taxmgr-btn-icon-sm" title="Collapse all">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>
                        Collapse all
                    </button>
                </div>
            </div>

            <!-- No-results message (hidden initially) -->
            <p id="taxmgr-no-results" class="taxmgr-no-results" hidden>No categories match your search.</p>

            <table class="taxmgr-tree-table" id="taxmgr-tree-table" aria-label="Service categories">
                <thead>
                    <tr>
                        <th class="taxmgr-col-name">Category</th>
                        <th class="taxmgr-col-services">
                            <span title="Services directly assigned to this category">Services</span>
                        </th>
                        <th class="taxmgr-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="taxmgr-tree-body">
                <?php foreach ($treeRows as $row): ?>
                    <?php
                    $depth      = (int) ($row['depth'] ?? 0);
                    $rowId      = (int) $row['id'];
                    $svcCount   = (int) ($row['service_count'] ?? 0);
                    $childCount = (int) ($row['child_count'] ?? 0);
                    $sortOrder  = (int) ($row['sort_order'] ?? 0);
                    $rowName    = $row['name'] ?? '';
                    $rowPath    = $row['path'] ?? $rowName;
                    $isBeingEdited = $isEditMode && isset($panelCat['id']) && (int) $panelCat['id'] === $rowId;

                    // Delete state: only show real delete for true leaf with no services
                    $canDelete  = ($childCount === 0 && $svcCount === 0);
                    $blockReason = '';
                    if ($childCount > 0 && $svcCount > 0) {
                        $blockReason = 'Has ' . $childCount . ' ' . ($childCount === 1 ? 'child' : 'children') . ' and ' . $svcCount . ' assigned ' . ($svcCount === 1 ? 'service' : 'services');
                    } elseif ($childCount > 0) {
                        $blockReason = 'Has ' . $childCount . ' ' . ($childCount === 1 ? 'child category' : 'child categories') . ' — remove or re-parent them first';
                    } elseif ($svcCount > 0) {
                        $blockReason = $svcCount . ' ' . ($svcCount === 1 ? 'service is' : 'services are') . ' assigned — reassign them first';
                    }
                    ?>
                    <tr class="taxmgr-row taxmgr-depth-<?= $depth ?><?= $isBeingEdited ? ' taxmgr-row--editing' : '' ?><?= $canDelete ? '' : ' taxmgr-row--protected' ?>"
                        data-id="<?= $rowId ?>"
                        data-depth="<?= $depth ?>"
                        data-name="<?= htmlspecialchars(strtolower($rowName)) ?>"
                        data-path="<?= htmlspecialchars(strtolower($rowPath)) ?>"
                        data-svc="<?= $svcCount ?>"
                        data-sort="<?= $sortOrder ?>">
                        <td class="taxmgr-col-name">
                            <div class="taxmgr-name-cell" style="--depth:<?= $depth ?>">
                                <!-- Toggle / indent guide -->
                                <?php if ($depth > 0): ?>
                                <span class="taxmgr-depth-guide" aria-hidden="true"></span>
                                <?php endif; ?>
                                <?php if ($childCount > 0): ?>
                                <button type="button" class="taxmgr-toggle" data-parent="<?= $rowId ?>" aria-expanded="true" aria-label="Collapse <?= htmlspecialchars($rowName) ?>">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="taxmgr-toggle-icon" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                                <?php else: ?>
                                <span class="taxmgr-leaf" aria-hidden="true"></span>
                                <?php endif; ?>

                                <!-- Name + secondary info -->
                                <div class="taxmgr-name-info">
                                    <a href="/services-resources/categories/<?= $rowId ?>"
                                       class="taxmgr-name-link"
                                       data-depth="<?= $depth ?>">
                                        <?= htmlspecialchars($rowName) ?>
                                    </a>
                                    <?php if ($depth > 0): ?>
                                    <span class="taxmgr-name-path" title="Full path"><?= htmlspecialchars($rowPath) ?></span>
                                    <?php endif; ?>
                                    <?php if ($childCount > 0): ?>
                                    <span class="taxmgr-child-pill" title="<?= $childCount ?> direct <?= $childCount === 1 ? 'child' : 'children' ?>"><?= $childCount ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="taxmgr-col-services">
                            <?php if ($svcCount > 0): ?>
                            <a href="/services-resources/services?category=<?= $rowId ?>"
                               class="taxmgr-svc-badge taxmgr-svc-badge--active"
                               title="<?= $svcCount ?> <?= $svcCount === 1 ? 'service uses' : 'services use' ?> this category — click to filter">
                                <?= $svcCount ?>
                            </a>
                            <?php else: ?>
                            <span class="taxmgr-svc-badge taxmgr-svc-badge--empty" title="No services assigned">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="taxmgr-col-actions">
                            <div class="taxmgr-row-actions">
                                <a href="/services-resources/categories?edit=<?= $rowId ?>"
                                   class="taxmgr-act taxmgr-act--edit<?= $isBeingEdited ? ' taxmgr-act--current' : '' ?>"
                                   title="Edit <?= htmlspecialchars($rowName) ?>">
                                    Edit
                                </a>
                                <a href="/services-resources/categories?parent_id=<?= $rowId ?>"
                                   class="taxmgr-act taxmgr-act--child"
                                   title="Add a child under <?= htmlspecialchars($rowName) ?>">
                                    + Child
                                </a>
                                <?php if ($canDelete): ?>
                                <form method="post" action="/services-resources/categories/<?= $rowId ?>/delete"
                                      class="taxmgr-act-form"
                                      onsubmit="return confirm('Delete «<?= htmlspecialchars(addslashes($rowName)) ?>»?\n\nThis action cannot be undone.')">
                                    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                                    <button type="submit" class="taxmgr-act taxmgr-act--delete" title="Delete this category">Delete</button>
                                </form>
                                <?php else: ?>
                                <span class="taxmgr-act taxmgr-act--locked"
                                      tabindex="0"
                                      title="<?= htmlspecialchars($blockReason) ?>"
                                      aria-label="Cannot delete: <?= htmlspecialchars($blockReason) ?>">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    Delete
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </main>
    </div><!-- /.taxmgr-body -->
</div><!-- /.taxmgr-wrap -->

<script>
(function () {
    'use strict';

    var csrfName = <?= json_encode(config('app.csrf_token_name', 'csrf_token')) ?>;
    var csrfVal  = <?= json_encode($csrf ?? '') ?>;

    // ── Helpers ──────────────────────────────────────────────────────────
    function allRows() {
        return Array.prototype.slice.call(document.querySelectorAll('#taxmgr-tree-body tr[data-id]'));
    }

    function getDescendantRows(parentId, rows) {
        var parentIdx = -1, parentDepth = -1;
        for (var i = 0; i < rows.length; i++) {
            if (parseInt(rows[i].dataset.id) === parentId) {
                parentIdx = i;
                parentDepth = parseInt(rows[i].dataset.depth);
                break;
            }
        }
        if (parentIdx < 0) return [];
        var result = [];
        for (var j = parentIdx + 1; j < rows.length; j++) {
            if (parseInt(rows[j].dataset.depth) <= parentDepth) break;
            result.push(rows[j]);
        }
        return result;
    }

    // ── Collapse / expand ─────────────────────────────────────────────────
    var collapseState = {}; // rowId → true if collapsed

    function applyCollapse(parentId, collapse, rows) {
        collapseState[parentId] = collapse;
        var tog = document.querySelector('.taxmgr-toggle[data-parent="' + parentId + '"]');
        if (tog) {
            tog.setAttribute('aria-expanded', collapse ? 'false' : 'true');
            tog.setAttribute('aria-label', (collapse ? 'Expand' : 'Collapse') + ' ' + (tog.closest('tr') ? tog.closest('tr').dataset.name : ''));
            var icon = tog.querySelector('.taxmgr-toggle-icon');
            if (icon) icon.style.transform = collapse ? 'rotate(-90deg)' : '';
        }
        getDescendantRows(parentId, rows).forEach(function (r) { r.style.display = collapse ? 'none' : ''; });
    }

    document.querySelectorAll('.taxmgr-toggle').forEach(function (tog) {
        var parentId = parseInt(tog.getAttribute('data-parent'));
        tog.addEventListener('click', function () {
            applyCollapse(parentId, !collapseState[parentId], allRows());
        });
    });

    var expandAllBtn   = document.getElementById('taxmgr-expand-all');
    var collapseAllBtn = document.getElementById('taxmgr-collapse-all');
    if (expandAllBtn) expandAllBtn.addEventListener('click', function () {
        var rows = allRows();
        document.querySelectorAll('.taxmgr-toggle').forEach(function (tog) {
            applyCollapse(parseInt(tog.getAttribute('data-parent')), false, rows);
        });
    });
    if (collapseAllBtn) collapseAllBtn.addEventListener('click', function () {
        var rows = allRows();
        document.querySelectorAll('.taxmgr-toggle').forEach(function (tog) {
            applyCollapse(parseInt(tog.getAttribute('data-parent')), true, rows);
        });
    });

    // ── Search ─────────────────────────────────────────────────────────────
    var searchInput  = document.getElementById('taxmgr-search');
    var searchClear  = document.getElementById('taxmgr-search-clear');
    var noResults    = document.getElementById('taxmgr-no-results');
    var activeFilter = 'all';

    function applyFilters() {
        var q = searchInput ? searchInput.value.toLowerCase().trim() : '';
        if (searchClear) searchClear.hidden = q === '';

        var rows = allRows();
        var visibleCount = 0;

        rows.forEach(function (row) {
            var name   = row.dataset.name || '';
            var path   = row.dataset.path || '';
            var svc    = parseInt(row.dataset.svc || '0');
            var depth  = parseInt(row.dataset.depth || '0');

            var matchSearch = q === '' || name.includes(q) || path.includes(q);
            var matchFilter = true;
            if (activeFilter === 'roots') matchFilter = depth === 0;
            else if (activeFilter === 'unused') matchFilter = svc === 0;
            else if (activeFilter === 'used')  matchFilter = svc > 0;

            var show = matchSearch && matchFilter;
            // If searching, also make sure parent rows are visible for context
            if (show && q !== '') {
                // show ancestors
                var pRow = findParentRow(row, rows);
                while (pRow) {
                    pRow.style.display = '';
                    pRow.dataset._forced = '1';
                    pRow = findParentRow(pRow, rows);
                }
            }
            if (!row.dataset._forced) {
                row.style.display = show ? '' : 'none';
            }
            if (show) visibleCount++;
        });

        // Clean forced markers
        rows.forEach(function (r) { delete r.dataset._forced; });

        if (noResults) noResults.hidden = visibleCount > 0 || (q === '' && activeFilter === 'all');
    }

    function findParentRow(row, rows) {
        var depth = parseInt(row.dataset.depth || '0');
        if (depth === 0) return null;
        var idx = rows.indexOf(row);
        for (var i = idx - 1; i >= 0; i--) {
            if (parseInt(rows[i].dataset.depth) < depth) return rows[i];
        }
        return null;
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
        if (searchClear) searchClear.addEventListener('click', function () {
            searchInput.value = '';
            applyFilters();
            searchInput.focus();
        });
    }

    document.querySelectorAll('.taxmgr-filter-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.taxmgr-filter-tab').forEach(function (t) {
                t.classList.remove('taxmgr-filter-tab--active');
            });
            tab.classList.add('taxmgr-filter-tab--active');
            activeFilter = tab.dataset.filter;
            applyFilters();
        });
    });

    // ── Keyboard: focus search with / ─────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
            e.preventDefault();
            if (searchInput) { searchInput.focus(); searchInput.select(); }
        }
    });

    // ── Sort AJAX (triggered from edit panel form) ─────────────────────────
    // Sort order editing happens in the left panel form when editing a category.
    // No per-row sort spinners on the table. Nothing to do here.

})();
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
