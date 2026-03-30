<?php
$title = $title ?? 'Create campaign';
$mainClass = 'marketing-campaign-form-page';
$marketingTopActive = 'email_campaigns';
$marketingRailActive = 'campaigns';
$campaignFormMode = 'create';
$formAction = '/marketing/campaigns';
ob_start();
?>
<div class="marketing-module">
    <?php require base_path('modules/marketing/views/partials/marketing-top-nav.php'); ?>

    <div class="marketing-module__body">
        <?php require base_path('modules/marketing/views/partials/marketing-email-rail.php'); ?>

        <div class="marketing-module__workspace">
            <header class="marketing-page-head marketing-page-head--form">
                <div class="marketing-page-head__titles">
                    <h1 class="marketing-page-head__h1">Create campaign</h1>
                    <p class="marketing-page-head__meta">Draft, audience, and message. You can run sends from the campaign page when ready.</p>
                </div>
            </header>

            <?php require base_path('modules/marketing/views/partials/campaign-form.php'); ?>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
