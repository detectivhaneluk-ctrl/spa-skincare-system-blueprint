<?php
declare(strict_types=1);

use Core\App\Application;
use Modules\Organizations\Services\FounderPagePurposePresenter;

$key = $pagePurposeKey ?? 'dashboard';
$purpose = $pagePurpose ?? Application::container()->get(FounderPagePurposePresenter::class)->forPage($key);
$panelTitle = (string) ($purpose['panel_title'] ?? 'This page');
$whatFor = (string) ($purpose['what_for'] ?? '');
$whenUse = $purpose['when_use'] ?? [];
$whenNot = $purpose['when_not'] ?? [];
$nextBest = $purpose['next_best'] ?? [];
$wrongHint = isset($purpose['wrong_page_hint']) ? (string) $purpose['wrong_page_hint'] : '';
if (!is_array($whenUse)) {
    $whenUse = [];
}
if (!is_array($whenNot)) {
    $whenNot = [];
}
if (!is_array($nextBest)) {
    $nextBest = [];
}
$hTag = ($pagePurposeKey ?? '') === 'guide' ? 'h1' : 'h2';
?>
<section class="founder-page-purpose" aria-labelledby="founder-page-purpose-title">
    <?= '<' . $hTag ?> id="founder-page-purpose-title" class="founder-page-purpose__title"><?= htmlspecialchars($panelTitle) ?></<?= $hTag ?>>
    <p class="founder-page-purpose__lead"><?= htmlspecialchars($whatFor) ?></p>
    <div class="founder-page-purpose__cols">
        <div class="founder-page-purpose__col">
            <h3 class="founder-page-purpose__subhead">Use this page when…</h3>
            <ul class="founder-page-purpose__list">
                <?php foreach ($whenUse as $line): ?>
                    <li><?= htmlspecialchars((string) $line) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="founder-page-purpose__col">
            <h3 class="founder-page-purpose__subhead">Do not use it for…</h3>
            <ul class="founder-page-purpose__list founder-page-purpose__list--muted">
                <?php foreach ($whenNot as $line): ?>
                    <li><?= htmlspecialchars((string) $line) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php if ($wrongHint !== ''): ?>
        <p class="founder-page-purpose__hint" role="note"><strong>Wrong page?</strong> <?= htmlspecialchars($wrongHint) ?></p>
    <?php endif; ?>
    <?php if ($nextBest !== []): ?>
        <p class="founder-page-purpose__next">
            <span class="founder-page-purpose__next-label">If this is not the right place, try:</span>
            <?php
            $parts = [];
            foreach ($nextBest as $nb) {
                if (!is_array($nb)) {
                    continue;
                }
                $lb = (string) ($nb['label'] ?? '');
                $href = (string) ($nb['href'] ?? '');
                if ($lb !== '' && $href !== '') {
                    $parts[] = '<a class="tenant-dash-table__link" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($lb) . '</a>';
                }
            }
            echo $parts !== [] ? implode('<span aria-hidden="true"> · </span>', $parts) : '—';
            ?>
        </p>
    <?php endif; ?>
</section>
