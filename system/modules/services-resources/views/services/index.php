<?php
$title = 'Services';
ob_start();
?>
<h1>Services</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<form method="get" class="search-form">
    <select name="category">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (isset($_GET['category']) && (int)$_GET['category'] === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
</form>
<p><a href="/services-resources" class="btn">← Services & Resources</a> <a href="/services-resources/services/create" class="btn">Add Service</a></p>
<?php
$rows = array_map(static function (array $r): array {
    $d = isset($r['description']) && $r['description'] !== null ? trim((string) $r['description']) : '';
    if ($d === '') {
        $r['description_excerpt'] = '—';
    } else {
        $max = 80;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $r['description_excerpt'] = mb_strlen($d, 'UTF-8') > $max
                ? mb_substr($d, 0, $max, 'UTF-8') . '…'
                : $d;
        } else {
            $r['description_excerpt'] = strlen($d) > $max ? substr($d, 0, $max) . '…' : $d;
        }
    }

    return $r;
}, $services);
$headers = [
    ['key' => 'name', 'label' => 'Name', 'link' => true],
    ['key' => 'category_name', 'label' => 'Category'],
    ['key' => 'description_excerpt', 'label' => 'Description', 'link' => false],
    ['key' => 'duration_minutes', 'label' => 'Duration'],
    ['key' => 'price', 'label' => 'Price'],
];
$rowUrl = fn ($r) => '/services-resources/services/' . $r['id'];
$actions = fn ($r) => '<a href="/services-resources/services/' . $r['id'] . '/edit">Edit</a> | <form method="post" action="/services-resources/services/' . $r['id'] . '/delete" style="display:inline" onsubmit="return confirm(\'Delete?\')"><input type="hidden" name="' . htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) . '" value="' . htmlspecialchars($csrf) . '"><button type="submit">Delete</button></form>';
require shared_path('layout/table.php');
?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
