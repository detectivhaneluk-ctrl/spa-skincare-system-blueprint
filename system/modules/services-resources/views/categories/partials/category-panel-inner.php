<?php
/** @var bool $isDrawerCategoryPanel When true, form posts via app drawer + no full-page navigation */
$isDrawerCategoryPanel = !empty($isDrawerCategoryPanel);
$catPostPath = $isEditMode ? '/services-resources/categories/' . (int) $panelCat['id'] : '/services-resources/categories';
$catFormAction = $catPostPath . ($isDrawerCategoryPanel ? '?drawer=1' : '');
$formExtra = $isDrawerCategoryPanel ? ' data-drawer-submit data-drawer-dirty-track' : '';
?>
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
                <?php if ($isDrawerCategoryPanel): ?>
                <button type="button" class="taxmgr-panel-reset" title="Close" data-app-drawer-close aria-label="Close">✕</button>
                <?php else: ?>
                <a href="/services-resources/categories" class="taxmgr-panel-reset" title="Reset to default mode">✕</a>
                <?php endif; ?>
                <?php endif; ?>
            </div>

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

            <?php if (!empty($panelErrors)): ?>
            <div class="taxmgr-panel-errors">
                <?php foreach ($panelErrors as $field => $msg): ?>
                <p><?= htmlspecialchars(is_string($msg) ? $msg : ($msg['message'] ?? 'Error')) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="post"
                  action="<?= htmlspecialchars($catFormAction) ?>"
                  class="taxmgr-form"
                  id="taxmgr-form"<?= $formExtra ?>>
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
                    <?php if ($isDrawerCategoryPanel): ?>
                    <button type="button" class="taxmgr-btn-ghost" data-app-drawer-close>Close</button>
                    <?php else: ?>
                    <a href="/services-resources/categories" class="taxmgr-btn-ghost">Cancel</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($isEditMode && !empty($panelCat['id'])): ?>
            <?php
            $editId2 = (int) $panelCat['id'];
            $editChildCnt = 0;
            foreach ($treeRows as $tr) {
                if ((int) ($tr['parent_id'] ?? 0) === $editId2) {
                    $editChildCnt++;
                }
            }
            $editSvcCnt = (int) ($panelCat['service_count'] ?? 0);
            ?>
            <div class="taxmgr-panel-footer">
                <div class="taxmgr-panel-footer-stats">
                    <span title="Direct children"><strong><?= $editChildCnt ?></strong> <?= $editChildCnt === 1 ? 'child' : 'children' ?></span>
                    <span class="taxmgr-footer-sep">·</span>
                    <span title="Services assigned"><strong><?= $editSvcCnt ?></strong> <?= $editSvcCnt === 1 ? 'service' : 'services' ?></span>
                </div>
                <a href="/services-resources/categories/<?= $editId2 ?>" class="taxmgr-footer-view-link"<?= $isDrawerCategoryPanel ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>View →</a>
            </div>
            <?php endif; ?>
