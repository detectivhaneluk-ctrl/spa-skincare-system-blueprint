<?php
$title = 'Registration #' . (int) $registration['id'] . ' · Clients';
$mainClass = 'clients-workspace-page wr-reg-pro-page';
$clientsWorkspaceActiveTab = 'registrations';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
ob_start();
?>
<?php require base_path('modules/clients/views/partials/clients-workspace-shell.php'); ?>
<div class="wr-reg-pro">
    <header class="wr-reg-pro__intro wr-reg-pro__intro--detail">
        <div class="wr-reg-pro__intro-main">
            <p class="wr-reg-pro__eyebrow">Registration request</p>
            <h1 class="wr-reg-pro__title">#<?= (int) $registration['id'] ?></h1>
            <p class="wr-reg-pro__subtitle wr-reg-pro__subtitle--tight"><?= htmlspecialchars((string) $registration['full_name']) ?></p>
        </div>
        <a href="/clients/registrations" class="wr-reg-pro__btn wr-reg-pro__btn--ghost wr-reg-pro__btn--compact">← All requests</a>
    </header>

    <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
    <div class="flash flash-<?= htmlspecialchars($t) ?> wr-reg-pro__flash"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
    <?php endif; ?>

    <?php
    $branchLabel = 'Global';
    foreach ($branches as $b) {
        if ((int) ($registration['branch_id'] ?? 0) === (int) ($b['id'] ?? 0)) {
            $branchLabel = (string) $b['name'];
            break;
        }
    }
    ?>

    <section class="wr-reg-pro__detail-card" aria-labelledby="wr-reg-detail-heading">
        <h2 id="wr-reg-detail-heading" class="wr-reg-pro__card-title">Details</h2>
        <dl class="wr-reg-pro__dl">
            <div class="wr-reg-pro__dl-row">
                <dt class="wr-reg-pro__dt">Branch</dt>
                <dd class="wr-reg-pro__dd"><?= htmlspecialchars($branchLabel) ?></dd>
            </div>
            <div class="wr-reg-pro__dl-row">
                <dt class="wr-reg-pro__dt">Full name</dt>
                <dd class="wr-reg-pro__dd"><?= htmlspecialchars((string) $registration['full_name']) ?></dd>
            </div>
            <div class="wr-reg-pro__dl-row">
                <dt class="wr-reg-pro__dt">Phone</dt>
                <dd class="wr-reg-pro__dd"><?= htmlspecialchars((string) ($registration['phone'] ?? '—')) ?></dd>
            </div>
            <div class="wr-reg-pro__dl-row">
                <dt class="wr-reg-pro__dt">Email</dt>
                <dd class="wr-reg-pro__dd"><?= htmlspecialchars((string) ($registration['email'] ?? '—')) ?></dd>
            </div>
            <div class="wr-reg-pro__dl-row">
                <dt class="wr-reg-pro__dt">Source</dt>
                <dd class="wr-reg-pro__dd"><span class="wr-reg-pro__pill"><?= htmlspecialchars((string) ($registration['source'] ?? 'manual')) ?></span></dd>
            </div>
            <div class="wr-reg-pro__dl-row">
                <dt class="wr-reg-pro__dt">Status</dt>
                <dd class="wr-reg-pro__dd"><span class="wr-reg-pro__pill wr-reg-pro__pill--status"><?= htmlspecialchars((string) ($registration['status'] ?? 'new')) ?></span></dd>
            </div>
            <div class="wr-reg-pro__dl-row">
                <dt class="wr-reg-pro__dt">Linked client</dt>
                <dd class="wr-reg-pro__dd">
                    <?php if (!empty($registration['linked_client_id'])): ?>
                        <a class="wr-reg-pro__link" href="/clients/<?= (int) $registration['linked_client_id'] ?>">#<?= (int) $registration['linked_client_id'] ?> <?= htmlspecialchars(trim((string) ($registration['linked_client_first_name'] ?? '') . ' ' . (string) ($registration['linked_client_last_name'] ?? ''))) ?></a>
                    <?php else: ?>
                        <span class="wr-reg-pro__muted">—</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div class="wr-reg-pro__dl-row wr-reg-pro__dl-row--block">
                <dt class="wr-reg-pro__dt">Notes</dt>
                <dd class="wr-reg-pro__dd wr-reg-pro__dd--multiline"><?php
                    $notesRaw = trim((string) ($registration['notes'] ?? ''));
                    echo $notesRaw === '' ? '<span class="wr-reg-pro__muted">—</span>' : nl2br(htmlspecialchars($notesRaw));
                ?></dd>
            </div>
        </dl>
    </section>

    <section class="wr-reg-pro__panel" aria-labelledby="wr-reg-status-heading">
        <h2 id="wr-reg-status-heading" class="wr-reg-pro__panel-title">Review status</h2>
        <p class="wr-reg-pro__panel-lead">Update workflow state and add optional review notes. This does not convert the request to a client.</p>
        <form method="post" action="/clients/registrations/<?= (int) $registration['id'] ?>/status" class="wr-reg-pro__form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="wr-reg-pro__field">
                <label class="wr-reg-pro__label" for="status">Status</label>
                <select id="status" name="status" class="wr-reg-pro__select wr-reg-pro__select--full">
                    <?php foreach ($statusOptions as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= (($registration['status'] ?? 'new') === $s) ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wr-reg-pro__field">
                <label class="wr-reg-pro__label" for="status_notes">Review notes</label>
                <textarea id="status_notes" name="notes" class="wr-reg-pro__textarea" rows="2"></textarea>
            </div>
            <div class="wr-reg-pro__form-footer wr-reg-pro__form-footer--inline">
                <button type="submit" class="wr-reg-pro__btn wr-reg-pro__btn--primary">Update status</button>
            </div>
        </form>
    </section>

    <section class="wr-reg-pro__panel" aria-labelledby="wr-reg-convert-heading">
        <h2 id="wr-reg-convert-heading" class="wr-reg-pro__panel-title">Convert to client</h2>
        <p class="wr-reg-pro__panel-lead">Link an existing profile, or leave the selector on “Create new client” to mint one from this request.</p>
        <form method="get" action="/clients/registrations/<?= (int) $registration['id'] ?>" class="wr-reg-pro__search-row">
            <label class="wr-reg-pro__visually-hidden" for="client_search">Search existing clients</label>
            <input type="text" id="client_search" name="client_search" class="wr-reg-pro__input wr-reg-pro__input--flex" value="<?= htmlspecialchars((string) ($_GET['client_search'] ?? '')) ?>" placeholder="Search existing clients" autocomplete="off">
            <button type="submit" class="wr-reg-pro__btn wr-reg-pro__btn--secondary wr-reg-pro__btn--compact">Search</button>
        </form>
        <form method="post" action="/clients/registrations/<?= (int) $registration['id'] ?>/convert" class="wr-reg-pro__form wr-reg-pro__form--spaced">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="wr-reg-pro__field">
                <label class="wr-reg-pro__label" for="existing_client_id">Existing client <span class="wr-reg-pro__optional">(optional)</span></label>
                <select id="existing_client_id" name="existing_client_id" class="wr-reg-pro__select wr-reg-pro__select--full">
                    <option value="">Create new client</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= (int) $c['id'] ?>">#<?= (int) $c['id'] ?> <?= htmlspecialchars(trim((string) $c['first_name'] . ' ' . (string) $c['last_name'])) ?><?= !empty($c['phone']) ? ' - ' . htmlspecialchars((string) $c['phone']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wr-reg-pro__form-footer wr-reg-pro__form-footer--inline">
                <button type="submit" class="wr-reg-pro__btn wr-reg-pro__btn--primary">Convert</button>
            </div>
        </form>
    </section>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
