<?php
/**
 * Reusable index table. Expects: $headers, $rows, $rowUrl(callback), $actions(callback optional)
 */
?>
<table class="index-table">
    <thead>
        <tr>
            <?php foreach ($headers as $k => $h): ?>
            <?php
            $thLabel = is_array($h) ? ($h['label'] ?? (string) $k) : (string) $h;
            $thClass = is_array($h) ? (string) ($h['th_class'] ?? '') : '';
            ?>
            <th<?php if ($thClass !== ''): ?> class="<?= htmlspecialchars($thClass, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>><?= htmlspecialchars((string) $thLabel) ?></th>
            <?php endforeach; ?>
            <?php if (!empty($actions)): ?>
            <th></th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <?php foreach ($headers as $k => $h): ?>
            <?php
            $key = is_array($h) ? ($h['key'] ?? $k) : $k;
            $val = $row[$key] ?? '';
            $url = isset($rowUrl) ? $rowUrl($row) : null;
            $linkAllowed = is_array($h) ? ($h['link'] ?? true) : (bool) $url;
            $raw = is_array($h) && !empty($h['raw']);
            $cellClass = is_array($h) ? (string) ($h['cell_class'] ?? '') : '';
            ?>
            <td<?php if ($cellClass !== ''): ?> class="<?= htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
                <?php
                if ($url && $linkAllowed) {
                    echo '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars((string) $val) . '</a>';
                } elseif ($raw) {
                    echo (string) $val;
                } else {
                    echo htmlspecialchars((string) $val);
                }
                ?>
            </td>
            <?php endforeach; ?>
            <?php if (!empty($actions)): ?>
            <td><?= $actions($row) ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
