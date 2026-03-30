<?php
$title = 'Client · Mail marketing · ' . ($client['display_name'] ?? '');
$mainClass = 'client-resume-page client-ref-surface client-ref--client-tab client-ref--tab-mail-marketing';
$clientRefTitleRowSecondaryTab = true;
$hasMarketingRows = !empty($marketingListMemberships) || !empty($marketingRecipientRows);
$marketingHasAnyError = ($marketingListError !== null) || ($marketingRecipientError !== null);
ob_start();
?>
<div class="client-ref client-ref-surface client-ref--client-tab client-ref--tab-mail-marketing">
<?php require base_path('modules/clients/views/partials/client-ref-header-tabs.php'); ?>

    <div class="client-ref-body">
<?php require base_path('modules/clients/views/partials/client-ref-sidebar.php'); ?>

        <div class="client-ref-main client-ref-main--client-tab" role="main">
            <div class="client-ref-tab-workspace client-ref-mail-workspace">
                <header class="client-ref-tab-workspace__head client-ref-mail-workspace__head">
                    <div>
                        <h2 class="client-ref-tab-workspace__title">Mail marketing</h2>
                        <p class="client-ref-tab-workspace__lede">Contact lists, campaign recipient snapshots, and preference flags (read-only).</p>
                    </div>
                    <a class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--ghost" href="/marketing/campaigns">Open Marketing</a>
                </header>

                <?php if ($marketingListError !== null): ?>
                <p class="client-ref-tab-workspace__muted client-ref-documents-workspace__load-error" role="alert"><?= htmlspecialchars($marketingListError) ?></p>
                <?php endif; ?>
                <?php if ($marketingRecipientError !== null): ?>
                <p class="client-ref-tab-workspace__muted client-ref-documents-workspace__load-error" role="alert"><?= htmlspecialchars($marketingRecipientError) ?></p>
                <?php endif; ?>

                <p class="client-ref-mail-workspace__opt-line">Marketing preference: <strong><?= $marketingOptIn ? 'Opted in' : 'Not opted in' ?></strong>. <a class="client-ref-tab-workspace__link" href="/clients/<?= (int) $clientId ?>/edit">Edit on Details</a> if you have access.</p>

                <?php if (!$marketingHasAnyError && !$hasMarketingRows): ?>
                <div class="client-ref-mail-workspace__empty-panel" role="status">
                    <div class="client-ref-mail-workspace__empty-visual" aria-hidden="true"></div>
                    <p class="client-ref-mail-workspace__empty-title">No list or campaign rows</p>
                    <p class="client-ref-mail-workspace__empty-text">This client is not on a contact list in the current branch, and has no stored campaign recipient rows yet.</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($marketingListMemberships)): ?>
                <h3 class="client-ref-tab-workspace__subhead">Contact lists</h3>
                <div class="client-ref-tab-workspace__table-wrap">
                    <table class="client-ref-tab-workspace__table">
                        <thead>
                            <tr>
                                <th scope="col">List</th>
                                <th scope="col">Member since</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($marketingListMemberships as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($m['list_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($m['member_since'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($marketingRecipientRows)): ?>
                <h3 class="client-ref-tab-workspace__subhead client-ref-mail-workspace__subhead-spaced">Recent campaign recipients</h3>
                <p class="client-ref-tab-workspace__muted">Rows from marketing runs (snapshot channel email; delivery status as stored).</p>
                <div class="client-ref-tab-workspace__table-wrap">
                    <table class="client-ref-tab-workspace__table">
                        <thead>
                            <tr>
                                <th scope="col">Campaign</th>
                                <th scope="col">Email</th>
                                <th scope="col">Delivery</th>
                                <th scope="col">Run</th>
                                <th scope="col">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($marketingRecipientRows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($r['campaign_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($r['email_snapshot'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($r['delivery_status'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($r['run_status'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($r['created_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require base_path('modules/clients/views/partials/client-ref-shell-styles.php'); ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
