<?php

declare(strict_types=1);

$title = $title ?? 'Edit Gift Card Template';
$mainClass = 'marketing-gift-card-templates-edit-page';
$marketingTopActive = 'gift_cards';
$storageReady = !empty($storageReady ?? false);
$template = is_array($template ?? null) ? $template : [];
$images = is_array($images ?? null) ? $images : [];
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
            <div class="entity-form">
                <h2 style="margin-top:0;">Edit Gift Card Template</h2>
                <?php require base_path('modules/marketing/views/gift-card-templates/partials/storage-not-ready-notice.php'); ?>
                <?php if ($storageReady): ?>
                <form method="post" action="/marketing/gift-card-templates/<?= (int) ($template['id'] ?? 0) ?>" style="display:grid;gap:10px;max-width:760px;">
                    <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                    <label>
                        Template name
                        <input type="text" name="name" maxlength="160" required value="<?= htmlspecialchars((string) ($template['name'] ?? '')) ?>">
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="sell_in_store_enabled" value="1" <?= !empty($template['sell_in_store_enabled']) ? 'checked' : '' ?>>
                        Sell on site / in-store enabled
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="sell_online_enabled" value="1" <?= !empty($template['sell_online_enabled']) ? 'checked' : '' ?>>
                        Sell online enabled
                    </label>
                    <label>
                        Primary image
                        <select name="image_id">
                            <option value="">No image selected</option>
                            <?php foreach ($images as $img): ?>
                            <?php $imgId = (int) ($img['id'] ?? 0); ?>
                            <?php
                            $optLabel = (string) (($img['title'] ?? '') !== '' ? $img['title'] : ($img['display_filename'] ?? $img['filename'] ?? ('Image #' . $imgId)));
                            if (empty($img['selectable_for_template']) && (string) ($img['library_status'] ?? '') !== 'legacy') {
                                $optLabel .= ' [' . (string) ($img['library_status'] ?? 'pending') . ' — not usable as final art yet]';
                            }
                            ?>
                            <option value="<?= $imgId ?>" <?= ((int) ($template['image_id'] ?? 0) === $imgId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($optLabel) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="marketing-btn marketing-btn--primary" type="submit">Save</button>
                        <a class="marketing-btn marketing-btn--secondary" href="/marketing/gift-card-templates/images">Manage images</a>
                        <a class="marketing-btn marketing-btn--secondary" href="/marketing/gift-card-templates">Back to list</a>
                    </div>
                </form>
                <?php else: ?>
                <p class="hint">Editing is unavailable until migration 102 is applied.</p>
                <p><a class="marketing-btn marketing-btn--secondary" href="/marketing/gift-card-templates">Back to list</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
