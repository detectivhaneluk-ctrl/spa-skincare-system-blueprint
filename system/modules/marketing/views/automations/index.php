<?php
$title = $title ?? 'Automated emails';
$marketingTopActive = 'automated';
$marketingRailActive = 'automations';
ob_start();
?>
<div class="marketing-module">
    <?php require base_path('modules/marketing/views/partials/marketing-top-nav.php'); ?>
    <div class="marketing-module__body">
        <?php require base_path('modules/marketing/views/partials/marketing-email-rail.php'); ?>
        <div class="marketing-module__workspace">
            <h1>Automated emails</h1>
            <?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
            <div class="flash flash-<?= htmlspecialchars((string) $t) ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></div>
            <?php endif; ?>

            <p>Branch #<?= (int) ($branchId ?? 0) ?></p>

            <?php if (!empty($storageReady)): ?>
            <div class="flash <?= !empty($schedulerAcknowledged) ? 'flash-success' : 'flash-error' ?>">
                <strong>Execution depends on an external scheduler.</strong>
                This application does not run marketing automations by itself. Messages are queued only when
                <code>system/scripts/marketing_automations_execute.php</code> is invoked (for example from cron) for each automation key and branch.
                <?php if (empty($schedulerAcknowledged)): ?>
                <br><br>
                <strong>Scheduler not acknowledged for this branch.</strong>
                No operator has recorded that an external scheduler is configured. Enabled automations below are configuration only until that job runs on a schedule you maintain.
                <?php if (!empty($anyAutomationEnabled)): ?>
                <br><br>
                At least one automation is enabled in software — treat automations as <strong>not production-live</strong> until an external job is configured and acknowledged below.
                <?php endif; ?>
                <?php else: ?>
                <br><br>
                <strong>Operator acknowledgment on file</strong> for this branch (not a heartbeat or proof that cron is succeeding).
                <?php endif; ?>
            </div>

            <?php if (!empty($canManageMarketing)): ?>
            <form method="post" action="/marketing/automations/scheduler-acknowledgment" class="marketing-automations__scheduler-ack" style="margin: 1rem 0;">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <label>
                    <input type="checkbox" name="scheduler_acknowledged" value="1"<?= !empty($schedulerAcknowledged) ? ' checked' : '' ?>>
                    We have configured an external scheduler to run <code>marketing_automations_execute.php</code> for this branch (operator acknowledgment only).
                </label>
                <button type="submit">Save scheduler acknowledgment</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($storageReady)): ?>
            <div class="flash flash-error">
                <?= htmlspecialchars((string) ($storageNotice ?? 'Automation storage is not ready. Apply required migration.')) ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($storageReady)): ?>
            <table class="index-table">
    <thead>
    <tr>
        <th>Automation</th>
        <th>Enabled</th>
        <th>Config</th>
        <th>Persisted</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
    <?php
    $key = (string) ($row['automation_key'] ?? '');
    $cfg = is_array($row['config'] ?? null) ? $row['config'] : [];
    ?>
    <tr>
        <td>
            <strong><?= htmlspecialchars((string) ($row['title'] ?? $key)) ?></strong><br>
            <small><code><?= htmlspecialchars($key) ?></code></small><br>
            <small><?= htmlspecialchars((string) ($row['description'] ?? '')) ?></small>
        </td>
        <td>
            <?= !empty($row['enabled']) ? 'Yes' : 'No' ?>
            <?php if (!empty($row['enabled'])): ?>
            <br><small><?= !empty($schedulerAcknowledged) ? 'Sends only when the external script runs.' : 'Not scheduled until external script runs.' ?></small>
            <?php endif; ?>
        </td>
        <td>
            <form method="post" action="/marketing/automations/<?= htmlspecialchars($key) ?>/settings">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <label>
                    Enabled
                    <select name="enabled">
                        <option value="0"<?= empty($row['enabled']) ? ' selected' : '' ?>>No</option>
                        <option value="1"<?= !empty($row['enabled']) ? ' selected' : '' ?>>Yes</option>
                    </select>
                </label>
                <?php foreach ($cfg as $cfgKey => $cfgVal): ?>
                <label>
                    <?= htmlspecialchars((string) $cfgKey) ?>
                    <input type="number" name="<?= htmlspecialchars((string) $cfgKey) ?>" value="<?= (int) $cfgVal ?>">
                </label>
                <?php endforeach; ?>
                <button type="submit">Save</button>
            </form>
        </td>
        <td><?= !empty($row['has_persisted_override']) ? 'Yes' : 'No' ?></td>
        <td>
            <form method="post" action="/marketing/automations/<?= htmlspecialchars($key) ?>/toggle">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit"><?= !empty($row['enabled']) ? 'Disable' : 'Enable' ?></button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
            <?php else: ?>
            <p>Automations are unavailable until storage migration is applied.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
