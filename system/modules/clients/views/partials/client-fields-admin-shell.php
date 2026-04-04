<?php

declare(strict_types=1);

/** @var string $clientFieldsSubtab 'fields'|'layouts' */
$clientsWorkspaceActiveTab = 'custom_fields';
$layoutStorageReady = $layoutStorageReady ?? true;
require base_path('modules/clients/views/partials/clients-workspace-data.php');
?>
<?php require base_path('modules/clients/views/partials/clients-workspace-shell.php'); ?>
<nav class="ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb client-fields-subtabs" aria-label="Client fields admin" data-ds-segmented-thumb style="margin:0 0 1rem">
    <span class="ds-segmented__thumb" aria-hidden="true"></span>
    <a href="/clients/custom-fields" class="ds-segmented__link<?= ($clientFieldsSubtab ?? '') === 'fields' ? ' is-active' : '' ?>"<?= ($clientFieldsSubtab ?? '') === 'fields' ? ' aria-current="page"' : '' ?>>Fields</a>
    <a href="/clients/custom-fields/layouts" class="ds-segmented__link<?= ($clientFieldsSubtab ?? '') === 'layouts' ? ' is-active' : '' ?>"<?= ($clientFieldsSubtab ?? '') === 'layouts' ? ' aria-current="page"' : '' ?>>Page Layouts</a>
</nav>
<?php if ($layoutStorageReady === false): ?>
<div class="flash flash-error" role="alert">
    <strong>Client page layouts storage is not available.</strong>
    <?= htmlspecialchars(\Modules\Clients\Services\ClientPageLayoutService::LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE) ?>
    <p class="hint" style="margin:0.5rem 0 0">From the <code>system/</code> directory run <code>php scripts/migrate.php</code> so pending migrations (including <code>113_clients_fields_layouts_and_extended_columns.sql</code>) are applied.</p>
</div>
<?php endif; ?>
