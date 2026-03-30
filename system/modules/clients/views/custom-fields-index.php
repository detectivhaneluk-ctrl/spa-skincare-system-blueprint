<?php
$title = 'Client Fields';
$mainClass = 'clients-workspace-page';
$clientFieldsSubtab = $clientFieldsSubtab ?? 'fields';
ob_start();
?>
<?php require base_path('modules/clients/views/partials/client-fields-admin-shell.php'); ?>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<div class="clients-ws-op-canvas">
    <p class="hint">System fields are defined in code and cannot be deleted. Custom fields are stored per branch rules below.</p>
    <p><a class="calendar-btn calendar-btn--primary" href="/clients/custom-fields/create">Create custom field</a></p>

    <h2 class="client-ref-block-title">System fields (catalog)</h2>
    <div class="clients-ws-table-wrap">
        <table class="index-table clients-ws-table">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Field type</th>
                    <th>Field key</th>
                    <th>Source</th>
                    <th>Configurable in layouts</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (($systemCatalog ?? []) as $skey => $smeta): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($smeta['label'] ?? $skey)) ?></td>
                    <td><?= htmlspecialchars((string) ($smeta['admin_field_type'] ?? '—')) ?></td>
                    <td><code><?= htmlspecialchars($skey) ?></code></td>
                    <td>system</td>
                    <td><?= !empty($smeta['configurable']) ? 'yes' : 'no' ?></td>
                    <td><span class="hint">—</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="client-ref-block-title">Custom fields</h2>
    <div class="clients-ws-table-wrap">
        <table class="index-table clients-ws-table">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Field type</th>
                    <th>Field key</th>
                    <th>Source</th>
                    <th>Configurable</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($definitions)): ?>
                <tr><td colspan="6"><span class="hint">No custom fields defined.</span></td></tr>
            <?php else: ?>
                <?php foreach ($definitions as $d): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $d['label']) ?></td>
                    <td><?= htmlspecialchars((string) $d['field_type']) ?></td>
                    <td><code><?= htmlspecialchars((string) $d['field_key']) ?></code></td>
                    <td>custom</td>
                    <td>yes</td>
                    <td>
                        <form method="post" action="/clients/custom-fields/<?= (int) $d['id'] ?>" style="display:inline-block">
                            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="label" value="<?= htmlspecialchars((string) $d['label']) ?>">
                            <input type="hidden" name="field_type" value="<?= htmlspecialchars((string) $d['field_type']) ?>">
                            <input type="hidden" name="sort_order" value="<?= (int) ($d['sort_order'] ?? 0) ?>">
                            <input type="hidden" name="is_required" value="<?= (int) ($d['is_required'] ?? 0) === 1 ? '1' : '' ?>">
                            <label style="display:inline-flex; gap:4px; align-items:center;">
                                <input type="checkbox" name="is_active" value="1" <?= (int) ($d['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
                                active
                            </label>
                            <button type="submit" class="calendar-btn">Save</button>
                        </form>
                        <form method="post" action="/clients/custom-fields/<?= (int) $d['id'] ?>/delete" style="display:inline-block;margin-left:8px" onsubmit="return confirm('Delete this custom field? Values on clients will be removed on next save path.');">
                            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                            <button type="submit" class="calendar-btn">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
