<?php
/**
 * Wizard progress header for service create/edit.
 *
 * Required variables:
 *   $service       — service row (needs at minimum ['id','name'])
 *   $currentStep   — int 1..4
 *   $isCreate      — bool (true = service not yet persisted, only Step 1 clickable)
 */
$svcId   = (int) ($service['id'] ?? 0);
$svcName = htmlspecialchars($service['name'] ?? '');
$steps   = [
    1 => ['Definition',   $svcId ? '/services-resources/services/' . $svcId . '/edit'   : null],
    2 => ['Products',     $svcId ? '/services-resources/services/' . $svcId . '/step-2' : null],
    3 => ['Employees',    $svcId ? '/services-resources/services/' . $svcId . '/step-3' : null],
    4 => ['Spaces',       $svcId ? '/services-resources/services/' . $svcId . '/step-4' : null],
];
?>
<div class="svc-step-header">
    <div class="svc-step-breadcrumb">
        <a href="/services-resources">Catalog</a>
        <span class="svc-step-breadcrumb-sep">›</span>
        <a href="/services-resources/services">Services</a>
        <?php if ($svcName): ?>
        <span class="svc-step-breadcrumb-sep">›</span>
        <span><?= $svcName ?></span>
        <?php endif; ?>
    </div>
    <h1><?= $svcId ? 'Edit service' : 'New service' ?></h1>

    <nav class="svc-wizard-steps" aria-label="Setup steps">
        <?php foreach ($steps as $num => [$label, $url]): ?>
        <?php
            $isCurrent  = ($num === $currentStep);
            $isReachable = $svcId > 0; // all steps reachable once service is created
            $cls = 'svc-wizard-step';
            if ($isCurrent)    $cls .= ' is-current';
            if ($num < $currentStep) $cls .= ' is-done';
        ?>
        <span class="<?= $cls ?>">
            <?php if ($isReachable && $url && !$isCurrent): ?>
            <a href="<?= htmlspecialchars($url) ?>"><?= (int) $num ?>. <?= htmlspecialchars($label) ?></a>
            <?php else: ?>
            <?= (int) $num ?>. <?= htmlspecialchars($label) ?>
            <?php endif; ?>
        </span>
        <?php if ($num < 4): ?><span class="svc-wizard-sep">›</span><?php endif; ?>
        <?php endforeach; ?>
    </nav>
</div>
