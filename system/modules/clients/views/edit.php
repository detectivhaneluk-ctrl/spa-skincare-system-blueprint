<?php
$title = 'Client · Details · ' . ($client['display_name'] ?? '');
$mainClass = 'client-resume-page client-ref-surface client-ref--details-page';
$clientId = (int) $client['id'];
$clientRefActiveTab = 'details';
$clientRefDedicatedDetails = true;
ob_start();
?>
<div class="client-ref client-ref-surface client-ref--details-page">
<?php require base_path('modules/clients/views/partials/client-ref-header-tabs.php'); ?>

    <div class="client-ref-body">
<?php require base_path('modules/clients/views/partials/client-ref-sidebar.php'); ?>

        <main class="client-ref-main client-ref-main--details client-ref-details-main" role="main">
            <div class="client-ref-details-workspace">
            <?php if (!empty($errors)): ?>
            <div class="client-ref-details-errors">
            <ul class="form-errors">
                <?php foreach ($errors as $ek => $e): ?>
                <?php if (is_string($ek) && str_starts_with((string) $ek, 'custom_field_')) {
                    continue;
                } ?>
                <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
                <?php endforeach; ?>
            </ul>
            </div>
            <?php endif; ?>

            <form method="post" action="/clients/<?= $clientId ?>" class="entity-form client-ref-details-form" id="client-details-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">

                <div class="client-ref-details-actionbar">
                    <div class="client-ref-details-actionbar__primary">
                        <button type="submit" class="btn client-ref-details-btn-save">Save</button>
                        <a href="/clients/<?= $clientId ?>" class="btn client-ref-details-btn-cancel">Cancel</a>
                    </div>
                </div>

                <p class="client-ref-details-idline">Client ID <strong><?= $clientId ?></strong> · Field order follows your organization&rsquo;s <em>customer_details</em> layout when configured.</p>

                <h2 class="client-ref-details-page-title">Client details</h2>

                <div class="client-ref-details-fields">
                <?php
                $detailsLayoutKeys = $detailsLayoutKeys ?? [];
                require base_path('modules/clients/views/partials/client-details-layout-render.php');
                ?>
                </div>

                <div class="client-ref-details-actionbar client-ref-details-actionbar--footer">
                    <div class="client-ref-details-actionbar__primary">
                        <button type="submit" class="btn client-ref-details-btn-save">Save</button>
                        <a href="/clients/<?= $clientId ?>" class="btn client-ref-details-btn-cancel">Cancel</a>
                    </div>
                </div>
            </form>
            </div>
        </main>
    </div>
</div>

<?php require base_path('modules/clients/views/partials/client-ref-shell-styles.php'); ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
