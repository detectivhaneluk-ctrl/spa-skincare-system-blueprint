<?php

declare(strict_types=1);

$title = $title ?? 'Create Gift Card Template';
$mainClass = 'marketing-gift-card-templates-create-page';
$marketingTopActive = 'gift_cards';
$storageReady = !empty($storageReady ?? false);
$cloneCandidates = is_array($cloneCandidates ?? null) ? $cloneCandidates : [];
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
                <h2 style="margin-top:0;">Create Gift Card Template</h2>
                <?php require base_path('modules/marketing/views/gift-card-templates/partials/storage-not-ready-notice.php'); ?>
                <?php if ($storageReady): ?>
                <form method="post" action="/marketing/gift-card-templates" style="display:grid;gap:10px;max-width:700px;">
                    <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                    <label>
                        Clone from existing template (optional)
                        <select name="clone_source_template_id">
                            <option value="">Start from scratch</option>
                            <?php foreach ($cloneCandidates as $candidate): ?>
                            <option value="<?= (int) ($candidate['id'] ?? 0) ?>">
                                <?= htmlspecialchars((string) ($candidate['name'] ?? 'Template')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Template name
                        <input type="text" name="name" required maxlength="160" placeholder="Template name">
                    </label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="marketing-btn marketing-btn--primary" type="submit">Save and continue</button>
                        <a class="marketing-btn marketing-btn--secondary" href="/marketing/gift-card-templates">Cancel</a>
                    </div>
                </form>
                <?php else: ?>
                <p class="hint">Create is unavailable until migration 102 is applied.</p>
                <p><a class="marketing-btn marketing-btn--secondary" href="/marketing/gift-card-templates">Back to list</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
