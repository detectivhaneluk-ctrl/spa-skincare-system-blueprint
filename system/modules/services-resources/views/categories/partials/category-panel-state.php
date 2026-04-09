<?php
/**
 * Shared panel state for categories index + drawer (expects $treeRows, $panelTreeRows, $panelMode, $editCategory, $editErrors, $panelFormData, $preParentId from controller).
 */
$csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$csrfVal  = htmlspecialchars($csrf ?? '');

$isEditMode    = ($panelMode ?? 'create') === 'edit';
$isChildMode   = !$isEditMode && ($preParentId ?? 0) > 0;
$panelCat      = $editCategory ?? $panelFormData ?? [];
$panelErrors   = $editErrors ?? [];
$panelParentId = $isEditMode
    ? (int) ($panelCat['parent_id'] ?? 0)
    : (int) ($panelFormData['parent_id'] ?? $preParentId ?? 0);

$parentHintPath = '';
if ($panelParentId > 0) {
    foreach ($panelTreeRows ?? $treeRows as $tr) {
        if ((int) $tr['id'] === $panelParentId) {
            $parentHintPath = $tr['path'] ?? $tr['name'] ?? '';
            break;
        }
    }
}

$editCatPath = '';
if ($isEditMode && !empty($panelCat['id'])) {
    foreach ($treeRows as $tr) {
        if ((int) $tr['id'] === (int) $panelCat['id']) {
            $editCatPath = $tr['path'] ?? $tr['name'] ?? '';
            break;
        }
    }
}

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
