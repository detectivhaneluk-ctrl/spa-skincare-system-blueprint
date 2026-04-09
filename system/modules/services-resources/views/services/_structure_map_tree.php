<?php
/**
 * Recursive category + service map for Services structure view.
 * Expects: $childrenByParent, $servicesByCategory, $categoryById, $csrfName, $csrfVal, $uncategorized
 * Optional: $mapRootCategoryId (int|null) — show only this category subtree when filtering.
 */
declare(strict_types=1);

$categoryById = isset($categoryById) && is_array($categoryById) ? $categoryById : [];

if (!function_exists('svc_map_render_category')) {
    /**
     * @param array<string,mixed> $cat
     * @param array<int,list<array<string,mixed>>> $childrenByParent
     * @param array<int,list<array<string,mixed>>> $servicesByCategory
     */
    function svc_map_render_category(
        array $cat,
        array $childrenByParent,
        array $servicesByCategory,
        string $csrfName,
        string $csrfVal,
        int $depth = 0,
    ): void {
        $id   = (int) ($cat['id'] ?? 0);
        $name = (string) ($cat['name'] ?? '');
        $slug = strtolower($name);

        $childCats = $childrenByParent[$id] ?? [];
        $svcList   = $servicesByCategory[$id] ?? [];
        $hasKids   = $childCats !== [] || $svcList !== [];

        echo '<li class="svc-map-branch svc-map-branch--category" data-map-kind="category" data-svc-depth="' . $depth . '" data-map-search="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="svc-map-node svc-map-node--category">';
        echo '<button type="button" class="svc-map-collapse" aria-expanded="true" aria-label="Toggle ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">';
        echo '<svg class="svc-map-collapse__icon" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';
        echo '</button>';
        echo '<a href="/services-resources/categories/' . $id . '" class="svc-map-node__title">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
        echo '<span class="svc-map-node__actions">';
        echo '<a href="/services-resources/categories/create?parent_id=' . $id . '" class="svc-map-act svc-map-act--icon" title="Add subcategory" aria-label="Add subcategory under ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">';
        echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
        echo '</a>';
        echo '<a href="/services-resources/categories?edit=' . $id . '" class="svc-map-act svc-map-act--icon" title="Edit category" aria-label="Edit category">';
        echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
        echo '</a>';
        echo '<a href="' . htmlspecialchars(svcIndexUrl(['view' => null, 'category' => (string) $id]), ENT_QUOTES, 'UTF-8') . '" class="svc-map-act svc-map-act--icon" title="List services in this category" aria-label="List services in this category">';
        echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>';
        echo '</a>';
        echo '</span></div>';

        if ($hasKids) {
            echo '<ul class="svc-map-children svc-map-children--band">';
            foreach ($childCats as $ch) {
                svc_map_render_category($ch, $childrenByParent, $servicesByCategory, $csrfName, $csrfVal, $depth + 1);
            }
            foreach ($svcList as $s) {
                $sid   = (int) ($s['id'] ?? 0);
                $sname = (string) ($s['name'] ?? '');
                $slugS = strtolower($sname . ' ' . (string) ($s['sku'] ?? ''));
                $sActive = !empty($s['is_active']);
                echo '<li class="svc-map-branch svc-map-branch--service" data-map-kind="service" data-svc-depth="' . ($depth + 1) . '" data-map-search="' . htmlspecialchars($slugS, ENT_QUOTES, 'UTF-8') . '">';
                echo '<div class="svc-map-node svc-map-node--service">';
                echo '<a href="/services-resources/services/' . $sid . '" class="svc-map-node__title svc-map-node__title--service">' . htmlspecialchars($sname, ENT_QUOTES, 'UTF-8') . '</a>';
                echo '<span class="svc-map-node__meta">';
                if (!$sActive) {
                    echo '<span class="svc-map-pill svc-map-pill--inactive">Inactive</span>';
                }
                echo '</span>';
                echo '<span class="svc-map-node__actions">';
                echo '<a href="/services-resources/services/create" class="svc-map-act svc-map-act--icon svc-map-act--on-dark" title="New service" aria-label="New service">';
                echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
                echo '</a>';
                echo '<a href="/services-resources/services/' . $sid . '/edit" class="svc-map-act svc-map-act--icon svc-map-act--on-dark" title="Edit service" aria-label="Edit service">';
                echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
                echo '</a>';
                echo '<form method="post" action="/services-resources/services/' . $sid . '/delete" class="svc-map-act-form" onsubmit=\'return confirm(' . json_encode('Move «' . $sname . '» to Trash?', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ')\'>';
                echo '<input type="hidden" name="' . htmlspecialchars($csrfName, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($csrfVal, ENT_QUOTES, 'UTF-8') . '">';
                echo '<button type="submit" class="svc-map-act svc-map-act--icon svc-map-act--danger svc-map-act--on-dark" title="Move to Trash" aria-label="Move to Trash">';
                echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                echo '</button></form>';
                echo '</span></div></li>';
            }
            echo '</ul>';
        }
        echo '</li>';
    }
}

$mapRootCategoryId = isset($mapRootCategoryId) ? $mapRootCategoryId : null;
$roots             = $childrenByParent[0] ?? [];

?>
<ul class="svc-map-tree" id="svc-map-tree">
    <li class="svc-map-branch svc-map-branch--root" data-map-kind="root" data-svc-depth="-1" data-map-search="categories">
        <div class="svc-map-node svc-map-node--root">
            <span class="svc-map-node__title svc-map-node__title--root"><?= htmlspecialchars('Categories', ENT_QUOTES, 'UTF-8') ?></span>
            <span class="svc-map-node__actions">
                <a href="/services-resources/categories/create" class="svc-map-act svc-map-act--icon" title="Add root category" aria-label="Add root category">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </a>
                <a href="/services-resources/categories" class="svc-map-act svc-map-act--icon" title="Manage all categories" aria-label="Manage all categories">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                </a>
            </span>
        </div>
        <?php if ($mapRootCategoryId !== null && isset($categoryById[$mapRootCategoryId])): ?>
        <ul class="svc-map-children svc-map-children--band svc-map-children--root-band">
            <?php
            svc_map_render_category(
                $categoryById[$mapRootCategoryId],
                $childrenByParent,
                $servicesByCategory,
                $csrfName,
                $csrfVal,
                0,
            );
            ?>
        </ul>
        <?php elseif ($mapRootCategoryId !== null): ?>
        <p class="svc-map-filter-miss svc-muted"><?= htmlspecialchars('No category matches this filter.', ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
        <ul class="svc-map-children svc-map-children--band svc-map-children--root-band">
            <?php foreach ($roots as $rootCat): ?>
                <?php svc_map_render_category($rootCat, $childrenByParent, $servicesByCategory, $csrfName, $csrfVal, 0); ?>
            <?php endforeach; ?>
            <?php if (!empty($uncategorized)): ?>
            <li class="svc-map-branch svc-map-branch--uncat" data-map-kind="category" data-svc-depth="0" data-map-search="uncategorized">
                <div class="svc-map-node svc-map-node--category svc-map-node--uncat">
                    <button type="button" class="svc-map-collapse" aria-expanded="true" aria-label="Toggle Uncategorized">
                        <svg class="svc-map-collapse__icon" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <span class="svc-map-node__title"><?= htmlspecialchars('Uncategorized', ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="svc-map-node__actions">
                        <a href="/services-resources/categories" class="svc-map-act svc-map-act--icon" title="Manage categories" aria-label="Manage categories">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                        </a>
                    </span>
                </div>
                <ul class="svc-map-children svc-map-children--band svc-map-children--services-only">
                    <?php foreach ($uncategorized as $s): ?>
                        <?php
                        $sid   = (int) ($s['id'] ?? 0);
                        $sname = (string) ($s['name'] ?? '');
                        $slugS = strtolower($sname . ' ' . (string) ($s['sku'] ?? ''));
                        $sActive = !empty($s['is_active']);
                        ?>
                    <li class="svc-map-branch svc-map-branch--service" data-map-kind="service" data-svc-depth="1" data-map-search="<?= htmlspecialchars($slugS, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="svc-map-node svc-map-node--service">
                            <a href="/services-resources/services/<?= $sid ?>" class="svc-map-node__title svc-map-node__title--service"><?= htmlspecialchars($sname, ENT_QUOTES, 'UTF-8') ?></a>
                            <span class="svc-map-node__meta">
                                <?php if (!$sActive): ?><span class="svc-map-pill svc-map-pill--inactive">Inactive</span><?php endif; ?>
                            </span>
                            <span class="svc-map-node__actions">
                                <a href="/services-resources/services/create" class="svc-map-act svc-map-act--icon svc-map-act--on-dark" title="New service" aria-label="New service">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                </a>
                                <a href="/services-resources/services/<?= $sid ?>/edit" class="svc-map-act svc-map-act--icon svc-map-act--on-dark" title="Edit service" aria-label="Edit service">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </a>
                                <form method="post" action="/services-resources/services/<?= $sid ?>/delete" class="svc-map-act-form" onsubmit='return confirm(<?= json_encode('Move «' . $sname . '» to Trash?', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <input type="hidden" name="<?= htmlspecialchars($csrfName, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($csrfVal, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="svc-map-act svc-map-act--icon svc-map-act--danger svc-map-act--on-dark" title="Move to Trash" aria-label="Move to Trash">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </form>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <?php endif; ?>
        </ul>
        <?php endif; ?>
    </li>
</ul>
