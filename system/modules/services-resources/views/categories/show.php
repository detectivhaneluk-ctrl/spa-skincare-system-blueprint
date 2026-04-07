<?php
$title = 'Service Category: ' . htmlspecialchars($category['name'] ?? '');
ob_start();
$breadcrumb = implode(' › ', array_map(fn ($c) => htmlspecialchars($c['name'] ?? ''), $ancestorChain));
$parentRow = count($ancestorChain) > 1 ? $ancestorChain[count($ancestorChain) - 2] : null;
$csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
?>
<div class="taxmgr-show-wrap">
    <div class="taxmgr-show-nav">
        <a href="/services-resources/categories" class="taxmgr-back-link">← Categories</a>
        <span class="taxmgr-show-breadcrumb">
            <?php foreach ($ancestorChain as $i => $node): ?>
            <?php if ($i > 0): ?><span class="taxmgr-sep"> › </span><?php endif; ?>
            <?php if ($i < count($ancestorChain) - 1): ?>
            <a href="/services-resources/categories/<?= (int) $node['id'] ?>"><?= htmlspecialchars($node['name'] ?? '') ?></a>
            <?php else: ?>
            <strong><?= htmlspecialchars($node['name'] ?? '') ?></strong>
            <?php endif; ?>
            <?php endforeach; ?>
        </span>
    </div>

    <div class="taxmgr-show-header">
        <div>
            <h1 class="taxmgr-title"><?= htmlspecialchars($category['name'] ?? '') ?></h1>
        </div>
        <div class="taxmgr-show-actions">
            <a href="/services-resources/categories?edit=<?= (int) $category['id'] ?>" class="btn taxmgr-btn-primary">Edit</a>
            <a href="/services-resources/categories?parent_id=<?= (int) $category['id'] ?>" class="btn btn-ghost">+ Add child</a>
        </div>
    </div>

    <div class="taxmgr-show-body">
        <div class="taxmgr-show-meta">
            <dl class="taxmgr-show-dl">
                <dt>Full path</dt>
                <dd><?= $breadcrumb ?: htmlspecialchars($category['name'] ?? '') ?></dd>

                <dt>Parent</dt>
                <dd>
                    <?php if ($parentRow): ?>
                    <a href="/services-resources/categories/<?= (int) $parentRow['id'] ?>"><?= htmlspecialchars($parentRow['name'] ?? '') ?></a>
                    <?php else: ?>
                    <em class="taxmgr-muted">Root category</em>
                    <?php endif; ?>
                </dd>

                <dt>Sort order</dt>
                <dd><?= (int) ($category['sort_order'] ?? 0) ?></dd>

                <dt>Direct children</dt>
                <dd><?= count($directChildren) ?></dd>
            </dl>
        </div>

        <?php if (!empty($directChildren)): ?>
        <div class="taxmgr-show-children">
            <p class="taxmgr-section-label">Direct children</p>
            <ul class="taxmgr-children-list">
                <?php foreach ($directChildren as $child): ?>
                <li>
                    <a href="/services-resources/categories/<?= (int) $child['id'] ?>"><?= htmlspecialchars($child['name'] ?? '') ?></a>
                    <a href="/services-resources/categories?edit=<?= (int) $child['id'] ?>" class="taxmgr-muted taxmgr-action-sm">Edit</a>
                </li>
                <?php endforeach; ?>
            </ul>
            <p><a href="/services-resources/categories?parent_id=<?= (int) $category['id'] ?>" class="btn btn-ghost taxmgr-btn-sm">+ Add child category</a></p>
        </div>
        <?php else: ?>
        <p class="taxmgr-muted">This is a leaf category (no children).
            <a href="/services-resources/categories?parent_id=<?= (int) $category['id'] ?>">Add a child</a>.
        </p>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
