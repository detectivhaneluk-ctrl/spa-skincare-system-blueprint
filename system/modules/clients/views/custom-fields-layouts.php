<?php
$title = 'Client Fields · Page layouts';
$mainClass = 'clients-workspace-page wr-pro-page';
$clientFieldsSubtab = 'layouts';
$csrfTn = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$profileUrl = static function (string $pk): string {
    return '/clients/custom-fields/layouts?profile=' . rawurlencode($pk);
};
ob_start();
?>
<?php require base_path('modules/clients/views/partials/client-fields-admin-shell.php'); ?>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<?php if (($layoutStorageReady ?? true) === false): ?>
<div class="wr-pro wr-pro--muted">
    <p class="wr-pro__muted-copy">The layout editor is disabled until the database has the <code>client_page_layout_profiles</code> and <code>client_page_layout_items</code> tables. Until then, client forms and the client summary sidebar use the built-in default field order from the field catalog.</p>
</div>
<?php else: ?>
<?php
$removable = array_values(array_filter(
    array_map(static fn (array $r) => (string) $r['field_key'], $layoutItems ?? []),
    static fn (string $fk) => !in_array($fk, ['first_name', 'last_name'], true)
));
?>
<div class="wr-pro" data-wr-pro-root>
    <header class="wr-pro__intro">
        <h1 class="wr-pro__title">Page layouts</h1>
        <p class="wr-pro__subtitle">Choose which fields belong to each layout profile and the order they appear wherever that profile is used. First and last name always remain on the client edit form.</p>
    </header>

    <nav class="wr-pro__profiles" aria-label="Layout profiles">
        <?php foreach (($profiles ?? []) as $p): ?>
        <?php
        $pk = (string) $p['profile_key'];
        $active = $pk === ($selectedProfileKey ?? '');
        ?>
        <a href="<?= htmlspecialchars($profileUrl($pk)) ?>" class="wr-pro__profile-chip<?= $active ? ' wr-pro__profile-chip--active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <span class="wr-pro__profile-chip-label"><?= htmlspecialchars((string) $p['display_label']) ?></span>
            <?php if (empty($p['is_runtime_consumed'])): ?>
            <span class="wr-pro__profile-chip-note">Stored only</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="wr-pro__workspace">
        <!-- Available: same POST contract as legacy add-item form (field_key + profile_key + csrf) -->
        <section class="wr-pro__card wr-pro__card--available" aria-labelledby="wr-pro-available-heading">
            <div class="wr-pro__card-head">
                <h2 id="wr-pro-available-heading" class="wr-pro__card-title">Available fields</h2>
                <p class="wr-pro__card-lead">Not yet on this profile. Add them to the live field order.</p>
            </div>
            <?php if (!empty($availableToAdd)): ?>
            <label class="wr-pro__visually-hidden" for="wr-pro-filter-available">Filter available fields</label>
            <input type="search" id="wr-pro-filter-available" class="wr-pro__search" placeholder="Filter…" autocomplete="off" data-wr-pro-filter="available" inputmode="search">
            <form id="wr-pro-form-add" method="post" action="/clients/custom-fields/layouts/add-item" class="wr-pro__form">
                <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey ?? '') ?>">
                <div class="wr-pro__scroll" data-wr-pro-filter-target="available">
                    <fieldset class="wr-pro__fieldset">
                        <legend class="wr-pro__visually-hidden">Pick a field to add</legend>
                        <?php foreach (array_values($availableToAdd) as $i => $ak): ?>
                        <label class="wr-pro__pick-row" data-wr-pro-pick-row data-filter-text="<?= htmlspecialchars($ak) ?>">
                            <input type="radio" name="field_key" value="<?= htmlspecialchars($ak) ?>" class="wr-pro__pick-input" <?= $i === 0 ? 'checked' : '' ?>>
                            <span class="wr-pro__pick-body">
                                <span class="wr-pro__pick-key"><code><?= htmlspecialchars($ak) ?></code></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </fieldset>
                </div>
            </form>
            <?php else: ?>
            <div class="wr-pro__empty" role="status">
                <p class="wr-pro__empty-title">Everything is placed</p>
                <p class="wr-pro__empty-text">Every field from the catalog for this profile is already in the ordered list.</p>
            </div>
            <?php endif; ?>
        </section>

        <div class="wr-pro__rail" role="group" aria-label="Move fields between lists">
            <?php if (!empty($availableToAdd)): ?>
            <button type="submit" class="wr-pro__rail-btn wr-pro__rail-btn--primary" form="wr-pro-form-add">
                <span class="wr-pro__rail-btn-text">Add to form</span>
                <span class="wr-pro__rail-btn-hint" aria-hidden="true">→</span>
            </button>
            <?php endif; ?>

            <?php if (!empty($removable)): ?>
            <form id="wr-pro-form-remove" method="post" action="/clients/custom-fields/layouts/remove-item" class="wr-pro__form wr-pro__form--remove" onsubmit="return confirm('Remove this field from the layout?');">
                <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey ?? '') ?>">
                <p class="wr-pro__rail-label" id="wr-pro-remove-legend">Remove from form</p>
                <div class="wr-pro__scroll wr-pro__scroll--compact" aria-labelledby="wr-pro-remove-legend">
                    <fieldset class="wr-pro__fieldset">
                        <legend class="wr-pro__visually-hidden">Field to remove</legend>
                        <?php foreach ($removable as $ri => $rf): ?>
                        <label class="wr-pro__pick-row wr-pro__pick-row--compact" data-wr-pro-pick-row data-filter-text="<?= htmlspecialchars($rf) ?>">
                            <input type="radio" name="field_key" value="<?= htmlspecialchars($rf) ?>" class="wr-pro__pick-input" <?= $ri === 0 ? 'checked' : '' ?> required>
                            <span class="wr-pro__pick-body">
                                <span class="wr-pro__pick-key"><code><?= htmlspecialchars($rf) ?></code></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </fieldset>
                </div>
            </form>
            <button type="submit" class="wr-pro__rail-btn wr-pro__rail-btn--secondary" form="wr-pro-form-remove">
                <span class="wr-pro__rail-btn-text">Remove</span>
                <span class="wr-pro__rail-btn-hint" aria-hidden="true">←</span>
            </button>
            <?php endif; ?>
        </div>

        <section class="wr-pro__card wr-pro__card--selected" aria-labelledby="wr-pro-selected-heading">
            <div class="wr-pro__card-head">
                <h2 id="wr-pro-selected-heading" class="wr-pro__card-title">Selected fields</h2>
                <p class="wr-pro__card-lead wr-pro__card-lead--emphasis">Live order for profile <code><?= htmlspecialchars($selectedProfileKey ?? '') ?></code>. Disabled rows stay out of the form; order still matters when re-enabled.</p>
            </div>

            <form method="post" action="/clients/custom-fields/layouts/save" class="wr-pro__form wr-pro__form--save" id="wr-pro-form-save">
                <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="profile_key" value="<?= htmlspecialchars($selectedProfileKey ?? '') ?>">
                <?php if (!empty($layoutItems)): ?>
                <ul class="wr-pro__selected-list">
                    <?php foreach (($layoutItems ?? []) as $row): ?>
                    <?php $fk = (string) $row['field_key']; ?>
                    <li class="wr-pro__selected-row">
                        <div class="wr-pro__selected-main">
                            <span class="wr-pro__selected-key"><code><?= htmlspecialchars($fk) ?></code></span>
                            <label class="wr-pro__pos">
                                <span class="wr-pro__visually-hidden">Position for <?= htmlspecialchars($fk) ?></span>
                                <input type="number" class="wr-pro__pos-input" name="items[<?= htmlspecialchars($fk) ?>][position]" value="<?= (int) ($row['position'] ?? 0) ?>" min="0" step="1">
                            </label>
                            <div class="wr-pro__toggle">
                                <input type="hidden" name="items[<?= htmlspecialchars($fk) ?>][field_key]" value="<?= htmlspecialchars($fk) ?>">
                                <input type="hidden" name="items[<?= htmlspecialchars($fk) ?>][is_enabled]" value="0">
                                <label class="wr-pro__toggle-label">
                                    <input type="checkbox" name="items[<?= htmlspecialchars($fk) ?>][is_enabled]" value="1" class="wr-pro__toggle-input" <?= (int) ($row['is_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
                                    <span class="wr-pro__toggle-ui" aria-hidden="true"></span>
                                    <span class="wr-pro__toggle-text">On form</span>
                                </label>
                            </div>
                        </div>
                        <div class="wr-pro__selected-move">
                            <a class="wr-pro__move-link" href="<?= htmlspecialchars($profileUrl($selectedProfileKey ?? '') . '&shift_field=' . rawurlencode($fk) . '&shift=up') ?>" aria-label="Move <?= htmlspecialchars($fk) ?> up">↑</a>
                            <a class="wr-pro__move-link" href="<?= htmlspecialchars($profileUrl($selectedProfileKey ?? '') . '&shift_field=' . rawurlencode($fk) . '&shift=down') ?>" aria-label="Move <?= htmlspecialchars($fk) ?> down">↓</a>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="wr-pro__empty" role="status">
                    <p class="wr-pro__empty-title">No fields yet</p>
                    <p class="wr-pro__empty-text">Add fields from the left list, then save to persist order and visibility.</p>
                </div>
                <?php endif; ?>

                <footer class="wr-pro__footer">
                    <button type="submit" class="wr-pro__footer-primary">Save layout</button>
                    <a class="wr-pro__footer-secondary" href="<?= htmlspecialchars($profileUrl($selectedProfileKey ?? '')) ?>">Reload</a>
                </footer>
            </form>
        </section>
    </div>
</div>
<script>
(function () {
    var root = document.querySelector('[data-wr-pro-root]');
    if (!root) return;
    function reflowRadios(form) {
        if (!form) return;
        var checked = form.querySelector('input[name="field_key"]:checked');
        if (!checked) return;
        var row = checked.closest('[data-wr-pro-pick-row]');
        if (row && row.hidden) {
            var vis = form.querySelector('[data-wr-pro-pick-row]:not([hidden]) input[name="field_key"]');
            if (vis) vis.checked = true;
        }
    }
    root.querySelectorAll('[data-wr-pro-filter]').forEach(function (input) {
        var key = input.getAttribute('data-wr-pro-filter');
        if (!key) return;
        var target = root.querySelector('[data-wr-pro-filter-target="' + key + '"]');
        if (!target) return;
        input.addEventListener('input', function () {
            var q = (input.value || '').toLowerCase().trim();
            target.querySelectorAll('[data-wr-pro-pick-row]').forEach(function (row) {
                var hay = (row.getAttribute('data-filter-text') || '').toLowerCase();
                row.hidden = q !== '' && hay.indexOf(q) === -1;
            });
            reflowRadios(target.closest('form'));
        });
    });
})();
</script>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
