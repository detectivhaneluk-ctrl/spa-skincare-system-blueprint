<?php

declare(strict_types=1);

/** @var string $clientFieldsSubtab 'fields'|'layouts' */
$clientsWorkspaceActiveTab = 'custom_fields';
$layoutStorageReady = $layoutStorageReady ?? true;
require base_path('modules/clients/views/partials/clients-workspace-data.php');
?>
<?php require base_path('modules/clients/views/partials/clients-workspace-shell.php'); ?>
<nav class="workspace-subnav client-fields-subtabs" aria-label="Client fields admin" style="margin:0 0 1rem">
    <a href="/clients/custom-fields" class="workspace-subnav__link<?= ($clientFieldsSubtab ?? '') === 'fields' ? ' workspace-subnav__link--active' : '' ?>"<?= ($clientFieldsSubtab ?? '') === 'fields' ? ' aria-current="page"' : '' ?>>Fields</a>
    <a href="/clients/custom-fields/layouts" class="workspace-subnav__link<?= ($clientFieldsSubtab ?? '') === 'layouts' ? ' workspace-subnav__link--active' : '' ?>"<?= ($clientFieldsSubtab ?? '') === 'layouts' ? ' aria-current="page"' : '' ?>>Page Layouts</a>
</nav>
<?php if ($layoutStorageReady === false): ?>
<div class="flash flash-error" role="alert">
    <strong>Client page layouts storage is not available.</strong>
    <?= htmlspecialchars(\Modules\Clients\Services\ClientPageLayoutService::LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE) ?>
    <p class="hint" style="margin:0.5rem 0 0">From the <code>system/</code> directory run <code>php scripts/migrate.php</code> so pending migrations (including <code>113_clients_fields_layouts_and_extended_columns.sql</code>) are applied.</p>
</div>
<?php endif; ?>
