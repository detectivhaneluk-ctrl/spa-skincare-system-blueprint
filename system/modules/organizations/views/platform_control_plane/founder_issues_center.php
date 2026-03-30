<?php
/** @var string $title */
/** @var array<string, mixed> $page */

$page = is_array($page ?? null) ? $page : [];
$summary = is_array($page['summary'] ?? null) ? $page['summary'] : [];
$items = is_array($page['items'] ?? null) ? $page['items'] : [];
$filters = is_array($page['filters'] ?? null) ? $page['filters'] : ['q' => '', 'filter' => 'all', 'severity' => ''];
$isEmpty = !empty($page['is_empty']);
$filterNoMatch = !empty($page['filter_no_match']);
$q = (string) ($filters['q'] ?? '');
$filterSel = (string) ($filters['filter'] ?? 'all');
$sevSel = (string) ($filters['severity'] ?? '');
$base = '/platform-admin/problems';

if (!function_exists('founder_issues_severity_class')) {
    function founder_issues_severity_class(string $sev): string
    {
        return match ($sev) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            default => 'medium',
        };
    }
}

if (!function_exists('founder_issues_severity_label')) {
    function founder_issues_severity_label(string $sev): string
    {
        return match ($sev) {
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
            default => 'Medium',
        };
    }
}
?>
<div class="workspace-shell platform-control-plane founder-issues-inbox">
    <header class="founder-issues-inbox__head">
        <h1 class="founder-issues-inbox__title"><?= htmlspecialchars($title) ?></h1>
        <p class="founder-issues-inbox__lede">Exceptions only · triage top to bottom</p>
    </header>

    <section class="founder-issues-inbox__summary" aria-label="Inbox summary">
        <div class="founder-issues-inbox__summary-grid">
            <div class="founder-issues-inbox__summary-card">
                <span class="founder-issues-inbox__summary-label">In inbox</span>
                <span class="founder-issues-inbox__summary-value"><?= (int) ($summary['salons_needing_attention'] ?? 0) ?></span>
            </div>
            <div class="founder-issues-inbox__summary-card">
                <span class="founder-issues-inbox__summary-label">High priority</span>
                <span class="founder-issues-inbox__summary-value"><?= (int) ($summary['high_priority_salons'] ?? 0) ?></span>
            </div>
            <div class="founder-issues-inbox__summary-card">
                <span class="founder-issues-inbox__summary-label">Access</span>
                <span class="founder-issues-inbox__summary-value"><?= (int) ($summary['salons_with_access_issues'] ?? 0) ?></span>
            </div>
            <div class="founder-issues-inbox__summary-card">
                <span class="founder-issues-inbox__summary-label">Operations</span>
                <span class="founder-issues-inbox__summary-value"><?= (int) ($summary['salons_with_operations_issues'] ?? 0) ?></span>
            </div>
        </div>
    </section>

    <section class="founder-issues-inbox__filters" aria-label="Narrow inbox">
        <form class="founder-issues-inbox__filter-form" method="get" action="<?= htmlspecialchars($base) ?>">
            <label class="founder-issues-inbox__filter">
                <span class="founder-issues-inbox__filter-label">Find in inbox</span>
                <input class="founder-issues-inbox__filter-input" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Salon name, code, ID" autocomplete="off">
            </label>
            <label class="founder-issues-inbox__filter">
                <span class="founder-issues-inbox__filter-label">Queue</span>
                <select class="founder-issues-inbox__filter-select" name="filter">
                    <option value="all"<?= $filterSel === 'all' ? ' selected' : '' ?>>All</option>
                    <option value="high"<?= $filterSel === 'high' ? ' selected' : '' ?>>High priority</option>
                    <option value="access"<?= $filterSel === 'access' ? ' selected' : '' ?>>Includes access issue</option>
                    <option value="operations"<?= $filterSel === 'operations' ? ' selected' : '' ?>>Includes operations issue</option>
                </select>
            </label>
            <label class="founder-issues-inbox__filter">
                <span class="founder-issues-inbox__filter-label">Top issue severity</span>
                <select class="founder-issues-inbox__filter-select" name="severity">
                    <option value=""<?= $sevSel === '' ? ' selected' : '' ?>>Any</option>
                    <option value="critical"<?= $sevSel === 'critical' ? ' selected' : '' ?>>Critical</option>
                    <option value="high"<?= $sevSel === 'high' ? ' selected' : '' ?>>High</option>
                    <option value="medium"<?= $sevSel === 'medium' ? ' selected' : '' ?>>Medium</option>
                    <option value="low"<?= $sevSel === 'low' ? ' selected' : '' ?>>Low</option>
                </select>
            </label>
            <button type="submit" class="founder-issues-inbox__apply">Apply</button>
            <?php if ($q !== '' || $filterSel !== 'all' || $sevSel !== ''): ?>
                <a class="founder-issues-inbox__clear" href="<?= htmlspecialchars($base) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($isEmpty): ?>
        <div class="founder-issues-inbox__empty">
            <p class="founder-issues-inbox__empty-title">Inbox clear</p>
            <p class="founder-issues-inbox__empty-sub">No exceptions. All tracked salons look operational.</p>
        </div>
    <?php elseif ($filterNoMatch): ?>
        <p class="founder-issues-inbox__no-match" role="status">Nothing matches these filters. <a class="founder-issues-inbox__clear" href="<?= htmlspecialchars($base) ?>">Clear</a></p>
    <?php else: ?>
        <ol class="founder-issues-inbox__queue" aria-label="Exception queue">
            <?php foreach ($items as $row): ?>
                <?php if (!is_array($row)) {
                    continue;
                } ?>
                <?php
                $sev = (string) ($row['severity'] ?? 'medium');
                $sid = (int) ($row['salon_id'] ?? 0);
                $more = (int) ($row['more_issue_count'] ?? 0);
                ?>
                <li class="founder-issues-inbox__item founder-issues-inbox__item--<?= htmlspecialchars(founder_issues_severity_class($sev)) ?>">
                    <div class="founder-issues-inbox__item-core">
                        <div class="founder-issues-inbox__issue-block">
                            <span class="founder-issues-inbox__issue-title"><?= htmlspecialchars((string) ($row['title'] ?? '')) ?></span>
                            <?php $sum = trim((string) ($row['summary'] ?? '')); ?>
                            <?php if ($sum !== ''): ?>
                                <p class="founder-issues-inbox__issue-consequence"><?= htmlspecialchars($sum) ?></p>
                            <?php endif; ?>
                        </div>
                        <p class="founder-issues-inbox__salon-line">
                            <a class="founder-issues-inbox__salon-link" href="/platform-admin/salons/<?= $sid ?>#issues"><?= htmlspecialchars((string) ($row['salon_name'] ?? '')) ?></a>
                            <?php if ($more > 0): ?>
                                <span class="founder-issues-inbox__more" title="More issues on salon detail">+<?= $more ?> more on this salon</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="founder-issues-inbox__item-aside">
                        <span class="founder-issues-inbox__sev founder-issues-inbox__sev--<?= htmlspecialchars(founder_issues_severity_class($sev)) ?>"><?= htmlspecialchars(founder_issues_severity_label($sev)) ?></span>
                        <span class="founder-issues-inbox__cat"><?= htmlspecialchars((string) ($row['category_display'] ?? '')) ?></span>
                        <a class="founder-issues-inbox__cta" href="<?= htmlspecialchars((string) ($row['action_url'] ?? '#')) ?>"><?= htmlspecialchars((string) ($row['action_label'] ?? 'Open')) ?></a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <footer class="founder-issues-inbox__foot">
        <p class="founder-issues-inbox__foot-line">
            <a class="founder-issues-inbox__foot-link" href="/platform-admin/incidents">System diagnostics</a>
            <span class="founder-issues-inbox__foot-sep" aria-hidden="true">·</span>
            <span class="founder-issues-inbox__foot-hint">Full incident table</span>
        </p>
    </footer>
</div>
