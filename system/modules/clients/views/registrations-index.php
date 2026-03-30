<?php
$title = 'Web Registrations · Clients';
$mainClass = 'clients-workspace-page wr-reg-pro-page';
$clientsWorkspaceActiveTab = 'registrations';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
ob_start();
?>
<?php require base_path('modules/clients/views/partials/clients-workspace-shell.php'); ?>
<div class="wr-reg-pro">
    <header class="wr-reg-pro__intro">
        <h1 class="wr-reg-pro__title">Web registrations</h1>
        <p class="wr-reg-pro__subtitle">Review inbound requests, filter by status or branch, and open a record to update review state or convert it to a client.</p>
    </header>

    <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
    <div class="flash flash-<?= htmlspecialchars($t) ?> wr-reg-pro__flash"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
    <?php endif; ?>

    <div class="wr-reg-pro__actions">
        <a href="/clients/registrations/create" class="wr-reg-pro__btn wr-reg-pro__btn--primary">Add registration request</a>
        <a href="/clients" class="wr-reg-pro__btn wr-reg-pro__btn--ghost">Back to clients</a>
    </div>

    <form method="get" class="wr-reg-pro__filters" aria-label="Filter registration requests">
        <div class="wr-reg-pro__filter-group">
            <label class="wr-reg-pro__filter-label" for="wr-reg-filter-status">Status</label>
            <select id="wr-reg-filter-status" name="status" class="wr-reg-pro__select">
                <option value="">All statuses</option>
                <?php foreach ($statusOptions as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= (($_GET['status'] ?? '') === $s) ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="wr-reg-pro__filter-group">
            <label class="wr-reg-pro__filter-label" for="wr-reg-filter-source">Source</label>
            <select id="wr-reg-filter-source" name="source" class="wr-reg-pro__select">
                <option value="">All sources</option>
                <?php foreach ($sources as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= (($_GET['source'] ?? '') === $s) ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="wr-reg-pro__filter-group">
            <label class="wr-reg-pro__filter-label" for="wr-reg-filter-branch">Branch</label>
            <select id="wr-reg-filter-branch" name="branch_id" class="wr-reg-pro__select">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?>
                <?php $bid = (string) ((int) ($b['id'] ?? 0)); ?>
                <option value="<?= htmlspecialchars($bid) ?>" <?= (($_GET['branch_id'] ?? '') === $bid) ? 'selected' : '' ?>><?= htmlspecialchars((string) $b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="wr-reg-pro__filter-actions">
            <button type="submit" class="wr-reg-pro__btn wr-reg-pro__btn--primary wr-reg-pro__btn--compact">Apply filters</button>
        </div>
    </form>

    <?php if (empty($registrations)): ?>
    <section class="wr-reg-pro__empty-card" aria-live="polite">
        <h2 class="wr-reg-pro__empty-title">No requests match</h2>
        <p class="wr-reg-pro__empty-text">Try another filter or add a registration request to get started.</p>
    </section>
    <?php else: ?>
    <div class="wr-reg-pro__table-card">
        <div class="wr-reg-pro__table-scroll">
            <table class="wr-reg-pro__table">
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Phone</th>
                        <th scope="col">Email</th>
                        <th scope="col">Source</th>
                        <th scope="col">Status</th>
                        <th scope="col">Linked client</th>
                        <th scope="col">Created</th>
                        <th scope="col"><span class="wr-reg-pro__visually-hidden">Open</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $r): ?>
                    <tr>
                        <td><span class="wr-reg-pro__mono">#<?= (int) $r['id'] ?></span></td>
                        <td><?= htmlspecialchars((string) $r['full_name']) ?></td>
                        <td><?= htmlspecialchars((string) ($r['phone'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['email'] ?? '')) ?></td>
                        <td><span class="wr-reg-pro__pill"><?= htmlspecialchars((string) $r['source']) ?></span></td>
                        <td><span class="wr-reg-pro__pill wr-reg-pro__pill--status"><?= htmlspecialchars((string) $r['status']) ?></span></td>
                        <td>
                            <?php if (!empty($r['linked_client_id'])): ?>
                                <a class="wr-reg-pro__link" href="/clients/<?= (int) $r['linked_client_id'] ?>">#<?= (int) $r['linked_client_id'] ?> <?= htmlspecialchars(trim((string) ($r['linked_client_first_name'] ?? '') . ' ' . (string) ($r['linked_client_last_name'] ?? ''))) ?></a>
                            <?php else: ?>
                                <span class="wr-reg-pro__muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="wr-reg-pro__cell-muted"><?= htmlspecialchars((string) ($r['created_at'] ?? '')) ?></td>
                        <td><a class="wr-reg-pro__table-action" href="/clients/registrations/<?= (int) $r['id'] ?>">Review</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($total > count($registrations)): ?>
    <p class="wr-reg-pro__pagination">Page <?= (int) $page ?> · <?= (int) $total ?> total</p>
    <?php endif; ?>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
