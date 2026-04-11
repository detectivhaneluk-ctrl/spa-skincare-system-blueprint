<?php
$title = 'Add Client Custom Field';
ob_start();
?>
<h1>Add Client Custom Field</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/clients/custom-fields" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="field_key">Field key (optional)</label>
        <input id="field_key" name="field_key" value="<?= htmlspecialchars((string) ($field['field_key'] ?? '')) ?>" placeholder="Leave blank to generate from label">
        <p class="form-hint" style="font-size:0.85rem;color:#666;margin:0.25rem 0 0">If empty, a unique key is created from the label automatically.</p>
    </div>
    <div class="form-row">
        <label for="label">Label *</label>
        <input id="label" name="label" required value="<?= htmlspecialchars((string) ($field['label'] ?? '')) ?>" placeholder="Loyalty Tier">
    </div>
    <div class="form-row">
        <label for="field_type">Type *</label>
        <select id="field_type" name="field_type">
            <?php
            $ftOpts = [
                'text' => 'Single line text',
                'textarea' => 'Paragraph text',
                'number' => 'Number',
                'date' => 'Date',
                'phone' => 'Phone',
                'email' => 'Email',
                'select' => 'Picklist',
                'multiselect' => 'Multiselect (one per line in value)',
                'boolean' => 'Boolean',
                'address' => 'Address (text block)',
            ];
            foreach ($ftOpts as $ft => $lab): ?>
            <option value="<?= htmlspecialchars($ft) ?>" <?= (($field['field_type'] ?? 'text') === $ft) ? 'selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="options_json">Options JSON (for select)</label>
        <textarea id="options_json" name="options_json" rows="2" placeholder='["A","B"]'><?= htmlspecialchars((string) ($field['options_json'] ?? '')) ?></textarea>
    </div>
    <div class="form-row">
        <label for="sort_order">Sort Order</label>
        <input id="sort_order" type="number" name="sort_order" value="<?= (int) ($field['sort_order'] ?? 0) ?>">
    </div>
    <div class="form-row">
        <label><input type="checkbox" name="is_required" value="1" <?= !empty($field['is_required']) ? 'checked' : '' ?>> Required</label>
        <label><input type="checkbox" name="is_active" value="1" <?= array_key_exists('is_active', $field) ? (!empty($field['is_active']) ? 'checked' : '') : 'checked' ?>> Active</label>
    </div>
    <div class="form-actions">
        <button type="submit">Create Field</button>
        <a href="/clients/custom-fields">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
