<?php
$title = 'Add registration · Clients';
$mainClass = 'clients-workspace-page wr-reg-pro-page';
$clientsWorkspaceActiveTab = 'registrations';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
ob_start();
?>
<?php require base_path('modules/clients/views/partials/clients-workspace-shell.php'); ?>
<div class="wr-reg-pro">
    <header class="wr-reg-pro__intro">
        <h1 class="wr-reg-pro__title">Add registration request</h1>
        <p class="wr-reg-pro__subtitle">Capture a manual intake. Branch and source are stored with the request for your team’s review queue.</p>
    </header>

    <?php if (!empty($errors['_general'])): ?>
    <div class="flash flash-error wr-reg-pro__flash"><?= htmlspecialchars((string) $errors['_general']) ?></div>
    <?php endif; ?>

    <div class="wr-reg-pro__form-card">
        <form method="post" action="/clients/registrations" class="wr-reg-pro__form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="wr-reg-pro__field">
                <label class="wr-reg-pro__label" for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id" class="wr-reg-pro__select wr-reg-pro__select--full">
                    <option value="">Global</option>
                    <?php foreach ($branches as $b): ?>
                    <?php $bid = (string) ((int) ($b['id'] ?? 0)); ?>
                    <option value="<?= htmlspecialchars($bid) ?>" <?= ((string) ($registration['branch_id'] ?? '') === $bid) ? 'selected' : '' ?>><?= htmlspecialchars((string) $b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wr-reg-pro__field">
                <label class="wr-reg-pro__label" for="full_name">Full name <span class="wr-reg-pro__req" aria-hidden="true">*</span></label>
                <input type="text" id="full_name" name="full_name" class="wr-reg-pro__input" required value="<?= htmlspecialchars((string) ($registration['full_name'] ?? '')) ?>" autocomplete="name">
            </div>
            <div class="wr-reg-pro__field">
                <label class="wr-reg-pro__label" for="phone">Phone</label>
                <input type="text" id="phone" name="phone" class="wr-reg-pro__input" value="<?= htmlspecialchars((string) ($registration['phone'] ?? '')) ?>" autocomplete="tel">
            </div>
            <div class="wr-reg-pro__field">
                <label class="wr-reg-pro__label" for="email">Email</label>
                <input type="email" id="email" name="email" class="wr-reg-pro__input" value="<?= htmlspecialchars((string) ($registration['email'] ?? '')) ?>" autocomplete="email">
            </div>
            <div class="wr-reg-pro__field">
                <label class="wr-reg-pro__label" for="source">Source</label>
                <select id="source" name="source" class="wr-reg-pro__select wr-reg-pro__select--full">
                    <?php foreach ($sources as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= (($registration['source'] ?? 'manual') === $s) ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wr-reg-pro__field">
                <label class="wr-reg-pro__label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="wr-reg-pro__textarea" rows="4"><?= htmlspecialchars((string) ($registration['notes'] ?? '')) ?></textarea>
            </div>
            <footer class="wr-reg-pro__form-footer">
                <button type="submit" class="wr-reg-pro__btn wr-reg-pro__btn--primary">Create request</button>
                <a href="/clients/registrations" class="wr-reg-pro__btn wr-reg-pro__btn--ghost">Cancel</a>
            </footer>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
