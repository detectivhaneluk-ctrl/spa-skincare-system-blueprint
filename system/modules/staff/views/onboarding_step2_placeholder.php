<?php
$title = 'New Employee : Step 2 of 4';
ob_start();
?>
<div class="wizard-layout">

    <nav class="wizard-steps" aria-label="Onboarding steps">
        <ol class="wizard-steps__list">
            <li class="wizard-steps__item wizard-steps__item--done">
                <span class="wizard-steps__number">1</span>
                <span class="wizard-steps__label">Employee Info</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--active" aria-current="step">
                <span class="wizard-steps__number">2</span>
                <span class="wizard-steps__label">Step 2</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--pending">
                <span class="wizard-steps__number">3</span>
                <span class="wizard-steps__label">Step 3</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--pending">
                <span class="wizard-steps__number">4</span>
                <span class="wizard-steps__label">Step 4</span>
            </li>
        </ol>
    </nav>

    <div class="wizard-body">
        <header class="wizard-body__header">
            <h1 class="wizard-body__title">New Employee</h1>
            <p class="wizard-body__subtitle">Step 2 of 4</p>
        </header>

        <?php if (!empty($flash['success'])): ?>
        <div class="flash flash--success" role="status">
            <?= htmlspecialchars((string) $flash['success'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="wizard-placeholder">
            <div class="wizard-placeholder__icon" aria-hidden="true">&#9203;</div>
            <h2 class="wizard-placeholder__heading">Step 2 is not yet implemented</h2>
            <p class="wizard-placeholder__body">
                Step 1 information for
                <strong><?= htmlspecialchars((string) ($staff['display_name'] ?? 'this employee'), ENT_QUOTES, 'UTF-8') ?></strong>
                has been saved successfully.
            </p>
            <p class="wizard-placeholder__body">
                Step 2 will be available in a future release. The employee record is accessible now and can be edited
                through the standard staff management interface.
            </p>
            <div class="wizard-placeholder__actions">
                <a href="/staff" class="btn btn--secondary">Back to Staff List</a>
                <a href="/staff/<?= (int) ($staff['id'] ?? 0) ?>" class="btn btn--primary">View Employee Record</a>
            </div>
        </div>

    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
