<?php
$title = $title ?? (string) ($campaign['name'] ?? 'Campaign');
$mainClass = 'marketing-campaign-show-page';
$marketingTopActive = 'email_campaigns';
$marketingRailActive = 'campaigns';
$showVm = $showVm ?? [];
$delivery = $showVm['delivery'] ?? [];
$runSummary = $showVm['runs'] ?? [];
$latestRun = $showVm['latest_run'] ?? null;
$status = (string) ($campaign['status'] ?? 'draft');
$campaignId = (int) ($campaign['id'] ?? 0);

$fmtDate = static function (mixed $raw): string {
    if (!is_string($raw) || trim($raw) === '') {
        return '—';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }

    return date('M j, Y g:i A', $ts);
};

ob_start();
?>
<div class="marketing-module">
    <?php require base_path('modules/marketing/views/partials/marketing-top-nav.php'); ?>

    <div class="marketing-module__body">
        <?php require base_path('modules/marketing/views/partials/marketing-email-rail.php'); ?>

        <div class="marketing-module__workspace">
            <?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
            <div class="flash flash-<?= htmlspecialchars((string) $t) ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></div>
            <?php endif; ?>

            <header class="marketing-page-head marketing-page-head--show">
                <div class="marketing-page-head__titles">
                    <h1 class="marketing-page-head__h1"><?= htmlspecialchars((string) ($campaign['name'] ?? 'Campaign')) ?></h1>
                    <p class="marketing-page-head__meta">
                        Branch: <?= !empty($campaign['branch_id']) ? '#' . (int) $campaign['branch_id'] : 'All branches' ?>
                        · Segment: <?= htmlspecialchars((string) ($showVm['segment_label'] ?? '—')) ?>
                        · Channel: <?= htmlspecialchars((string) ($campaign['channel'] ?? 'email')) ?>
                        · Last sent: <?= htmlspecialchars($fmtDate($delivery['last_sent_at'] ?? null)) ?>
                    </p>
                </div>
                <div class="marketing-show-head__actions">
                    <span class="marketing-pill marketing-pill--<?= $status === 'archived' ? 'archived' : 'draft' ?>">
                        <?= htmlspecialchars((string) ($showVm['status_label'] ?? $status)) ?>
                    </span>
                    <a class="marketing-btn marketing-btn--secondary" href="/marketing/campaigns">Back to campaigns</a>
                    <?php if (!empty($canManageMarketing) && $status !== 'archived'): ?>
                    <a class="marketing-btn marketing-btn--primary" href="/marketing/campaigns/<?= $campaignId ?>/edit">Edit</a>
                    <?php endif; ?>
                </div>
            </header>

            <div class="marketing-show-grid">
                <section class="marketing-show-card">
                    <h2 class="marketing-show-card__title">Campaign summary</h2>
                    <dl class="marketing-show-kv">
                        <div><dt>Status</dt><dd><?= htmlspecialchars((string) ($showVm['status_label'] ?? $status)) ?></dd></div>
                        <div><dt>Created</dt><dd><?= htmlspecialchars($fmtDate($campaign['created_at'] ?? null)) ?></dd></div>
                        <div><dt>Last updated</dt><dd><?= htmlspecialchars($fmtDate($campaign['updated_at'] ?? null)) ?></dd></div>
                        <div><dt>Total sent</dt><dd><?= (int) ($delivery['sent_count'] ?? 0) ?></dd></div>
                    </dl>
                </section>

                <section class="marketing-show-card">
                    <h2 class="marketing-show-card__title">Audience</h2>
                    <p class="marketing-show-card__text"><?= htmlspecialchars((string) ($showVm['segment_description'] ?? '')) ?></p>
                    <?php $cfgItems = $showVm['segment_config_items'] ?? []; ?>
                    <?php if ($cfgItems !== []): ?>
                    <dl class="marketing-show-kv">
                        <?php foreach ($cfgItems as $item): ?>
                        <div>
                            <dt><?= htmlspecialchars((string) ($item['label'] ?? '')) ?></dt>
                            <dd><?= htmlspecialchars((string) ($item['value'] ?? '')) ?></dd>
                        </div>
                        <?php endforeach; ?>
                    </dl>
                    <?php endif; ?>
                    <div class="marketing-show-inline-actions">
                        <a class="marketing-btn marketing-btn--secondary" href="/marketing/campaigns/<?= $campaignId ?>?preview=1">Preview audience</a>
                        <?php if (isset($previewCount) && $previewError === null): ?>
                        <span class="marketing-show-note">Eligible now: <?= (int) $previewCount ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($previewError)): ?>
                    <p class="marketing-form__error"><?= htmlspecialchars((string) $previewError) ?></p>
                    <?php endif; ?>
                </section>

                <section class="marketing-show-card marketing-show-card--full">
                    <h2 class="marketing-show-card__title">Email content</h2>
                    <div class="marketing-show-content">
                        <p><strong>Subject:</strong> <?= htmlspecialchars((string) ($campaign['subject'] ?? '')) ?></p>
                        <pre class="marketing-show-content__body"><?= htmlspecialchars((string) ($campaign['body_text'] ?? '')) ?></pre>
                    </div>
                </section>

                <section class="marketing-show-card marketing-show-card--full">
                    <div class="marketing-show-card__head">
                        <h2 class="marketing-show-card__title">Runs and delivery</h2>
                        <?php if (!empty($canManageMarketing) && $status !== 'archived'): ?>
                        <form method="post" action="/marketing/campaigns/<?= $campaignId ?>/freeze-run">
                            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                            <button type="submit" class="marketing-btn marketing-btn--primary">Freeze audience</button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <dl class="marketing-show-kpi">
                        <div><dt>Runs</dt><dd><?= (int) ($runSummary['total'] ?? 0) ?></dd></div>
                        <div><dt>Completed</dt><dd><?= (int) ($runSummary['completed'] ?? 0) ?></dd></div>
                        <div><dt>Frozen</dt><dd><?= (int) ($runSummary['frozen'] ?? 0) ?></dd></div>
                        <div><dt>Dispatching</dt><dd><?= (int) ($runSummary['dispatching'] ?? 0) ?></dd></div>
                        <div><dt>Recipients</dt><dd><?= (int) ($delivery['recipient_count'] ?? 0) ?></dd></div>
                        <div><dt>Sent</dt><dd><?= (int) ($delivery['sent_count'] ?? 0) ?></dd></div>
                    </dl>

                    <?php if ($latestRun !== null): ?>
                    <p class="marketing-show-note">
                        Latest run #<?= (int) ($latestRun['id'] ?? 0) ?> ·
                        <?= htmlspecialchars((string) ($latestRun['status'] ?? '')) ?> ·
                        snapshot <?= htmlspecialchars($fmtDate($latestRun['snapshot_at'] ?? null)) ?>.
                        <a href="/marketing/campaigns/runs/<?= (int) ($latestRun['id'] ?? 0) ?>/recipients">View recipients</a>
                    </p>
                    <?php endif; ?>

                    <?php if ($runRows === []): ?>
                    <div class="marketing-empty marketing-empty--tight">
                        <h3 class="marketing-empty__title">No runs yet</h3>
                        <p class="marketing-empty__text">Freeze the audience to create your first run snapshot.</p>
                    </div>
                    <?php else: ?>
                    <div class="marketing-table-wrap">
                        <table class="index-table marketing-campaigns-table">
                            <thead>
                            <tr>
                                <th>Run</th>
                                <th>Status</th>
                                <th class="marketing-campaigns-table__num">Recipients</th>
                                <th>Snapshot</th>
                                <th class="marketing-campaigns-table__actions">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($runRows as $r): ?>
                            <tr>
                                <td>#<?= (int) ($r['id'] ?? 0) ?></td>
                                <td><?= htmlspecialchars((string) ($r['status'] ?? '')) ?></td>
                                <td class="marketing-campaigns-table__num"><?= (int) ($r['recipient_count'] ?? 0) ?></td>
                                <td><?= htmlspecialchars($fmtDate($r['snapshot_at'] ?? null)) ?></td>
                                <td class="marketing-campaigns-table__actions">
                                    <a href="/marketing/campaigns/runs/<?= (int) ($r['id'] ?? 0) ?>/recipients">Recipients</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
