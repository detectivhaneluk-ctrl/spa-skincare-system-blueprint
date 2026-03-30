<?php
$title = $title ?? 'Edit campaign';
$mainClass = 'marketing-campaign-form-page';
$marketingTopActive = 'email_campaigns';
$marketingRailActive = 'campaigns';
$campaignFormMode = 'edit';
$formAction = '/marketing/campaigns/' . (int) ($campaign['id'] ?? 0);
ob_start();
?>
<div class="marketing-module">
    <?php require base_path('modules/marketing/views/partials/marketing-top-nav.php'); ?>

    <div class="marketing-module__body">
        <?php require base_path('modules/marketing/views/partials/marketing-email-rail.php'); ?>

        <div class="marketing-module__workspace">
            <header class="marketing-page-head marketing-page-head--form">
                <div class="marketing-page-head__titles">
                    <h1 class="marketing-page-head__h1">Edit campaign</h1>
                    <p class="marketing-page-head__meta">Update audience rules and content. Branch is fixed for this campaign.</p>
                </div>
            </header>

            <?php require base_path('modules/marketing/views/partials/campaign-form.php'); ?>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
