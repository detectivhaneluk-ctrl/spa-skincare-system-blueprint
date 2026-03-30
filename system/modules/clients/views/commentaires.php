<?php
$title = 'Client · Comments · ' . ($client['display_name'] ?? '');
$mainClass = 'client-resume-page client-ref-surface';
$clientId = (int) $client['id'];
$clientRefActiveTab = 'commentaires';
ob_start();
?>
<div class="client-ref">
<?php require base_path('modules/clients/views/partials/client-ref-header-tabs.php'); ?>

    <div class="client-ref-body">
<?php require base_path('modules/clients/views/partials/client-ref-sidebar.php'); ?>

        <main class="client-ref-main client-ref-commentaires-main">
            <section class="client-ref-block" aria-labelledby="client-ref-notes-progres-heading">
                <h2 id="client-ref-notes-progres-heading" class="client-ref-block-title">Progress notes</h2>
                <?php if (!empty($canEditClients)): ?>
                <form method="post" action="/clients/<?= $clientId ?>/notes" class="entity-form">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="form-row">
                        <label for="client_note_content">Add a note</label>
                        <textarea id="client_note_content" name="content" rows="3" required></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn">Save note</button>
                    </div>
                </form>
                <?php endif; ?>
                <?php if (empty($clientNotes)): ?>
                <p class="hint">No structured notes yet.</p>
                <?php else: ?>
                <table class="index-table">
                    <thead><tr><th>Created</th><th>Note</th><?php if (!empty($canEditClients)): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($clientNotes as $n): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $n['created_at']) ?></td>
                        <td><?= nl2br(htmlspecialchars((string) $n['content'])) ?></td>
                        <?php if (!empty($canEditClients)): ?>
                        <td>
                            <form method="post" action="/clients/<?= $clientId ?>/notes/<?= (int) $n['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this note?')">
                                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                <button type="submit" class="btn">Remove</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </section>

            <section class="client-ref-block" aria-labelledby="client-ref-notes-client-heading">
                <h2 id="client-ref-notes-client-heading" class="client-ref-block-title">Client notes</h2>
                <p class="hint">Free text on the client profile (same content as the Notes area on the Details tab).</p>
                <?php if (!empty($canEditClients)): ?>
                <form method="post" action="/clients/<?= $clientId ?>/profile-notes" class="entity-form">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="form-row">
                        <label for="client_profile_notes">Client notes</label>
                        <textarea id="client_profile_notes" name="notes" rows="6"><?= htmlspecialchars((string) ($client['notes'] ?? '')) ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn">Save</button>
                    </div>
                </form>
                <?php else: ?>
                <div class="client-ref-readonly-notes"><?php if ($client['notes'] !== null && trim((string) $client['notes']) !== ''): ?><?= nl2br(htmlspecialchars((string) $client['notes'])) ?><?php else: ?><p class="hint">—</p><?php endif; ?></div>
                <?php endif; ?>
            </section>

            <section class="client-ref-block" aria-labelledby="client-ref-allergies-heading">
                <h2 id="client-ref-allergies-heading" class="client-ref-block-title">Allergies</h2>
                <p class="hint">Not configured in this version — no persistence for this section (planned in a later iteration).</p>
            </section>

            <section class="client-ref-block" aria-labelledby="client-ref-traitements-heading">
                <h2 id="client-ref-traitements-heading" class="client-ref-block-title">Medical treatments / formulas</h2>
                <p class="hint">Not configured in this version — no persistence for this section (planned in a later iteration).</p>
            </section>
        </main>
    </div>
</div>

<?php require base_path('modules/clients/views/partials/client-ref-shell-styles.php'); ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
