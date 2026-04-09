<?php
/**
 * Drawer-only step 1 (expects $service, $errors, $catTreeRows, $vatRates, $csrf, $formAction, $isCreate, $drawerTitle, $drawerSubtitle).
 */
$svcStepFormExtraAttrs = 'data-drawer-submit data-drawer-dirty-track';
$sep = str_contains($formAction, '?') ? '&' : '?';
$formAction = $formAction . $sep . 'drawer=1';
?>
<div
    class="drawer-workspace drawer-workspace--svc-step1"
    data-drawer-content-root
    data-drawer-title="<?= htmlspecialchars($drawerTitle, ENT_QUOTES, 'UTF-8') ?>"
    data-drawer-subtitle="<?= htmlspecialchars($drawerSubtitle, ENT_QUOTES, 'UTF-8') ?>"
    data-drawer-width="wide"
>
<?php require __DIR__ . '/../_step1_form.php'; ?>
</div>
