<?php
/** @var string $csrf */
/** @var string $title */
/** @var array<string, mixed> $page */
/** @var \Modules\Organizations\Services\FounderIncidentPresenter $presenter */

if (!function_exists('platform_incident_severity_css')) {
    function platform_incident_severity_css(string $sev): string
    {
        return match ($sev) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            default => 'low',
        };
    }
}

$header = $page['header'] ?? [];
$cards = $page['category_cards'] ?? [];
$incidents = $page['incidents'] ?? [];
$totals = $page['totals'] ?? [];
$filters = $page['filters'] ?? ['category' => '', 'severity' => ''];
$isAllClear = !empty($page['is_all_clear']);
$filterEmpty = !empty($page['filter_empty']);
$catSel = (string) ($filters['category'] ?? '');
$sevSel = (string) ($filters['severity'] ?? '');
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
$filterBasePath = $filterBasePath ?? '/platform-admin/incidents';
$qBase = $filterBasePath;
$compactIncidentView = !empty($compactIncidentView ?? false);
?>
<div class="workspace-shell platform-control-plane">
    <?php if (!$compactIncidentView): ?>
    <?php $pagePurposeKey = 'incidents'; require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php'); ?>
    <?php endif; ?>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars((string) ($header['title'] ?? '')) ?></h1>
            <p class="workspace-module-head__sub"><?= htmlspecialchars((string) ($header['subtitle'] ?? '')) ?></p>
            <p class="platform-control-plane__recent-lead" role="status">
                Accounts evaluated for access-shape: <strong><?= (int) ($totals['accounts_evaluated'] ?? 0) ?></strong>.
                <?php if ($isAllClear): ?>
                    <span class="platform-incident-center__badge platform-incident-center__badge--ok">All clear</span>
                <?php else: ?>
                    <strong><?= (int) ($totals['active_incident_rows'] ?? 0) ?></strong> active incident type(s).
                <?php endif; ?>
            </p>
        </div>
    </header>

    <?php if (!$compactIncidentView): ?>
    <div class="founder-flow-callout" role="note">
        <p class="platform-incident-center__route-hint">Diagnose and route — not the main repair surface</p>
        <p class="platform-control-plane__recent-lead founder-flow-callout__p">Read <strong>First place to look</strong> and <strong>Primary open</strong> on each row. Repairs and lifecycle changes run in Access, Organizations, Branches, or Security — this page does not replace those actions.</p>
    </div>
    <?php endif; ?>

    <section class="dashboard-summary platform-control-plane__snap" aria-label="Incidents by category">
        <h2 class="dashboard-summary__heading">Summary by category</h2>
        <div class="dashboard-summary__grid">
            <?php foreach ($cards as $c): ?>
                <div class="dashboard-summary__card">
                    <span class="dashboard-summary__label"><?= htmlspecialchars($presenter->categoryLabel((string) ($c['category'] ?? ''))) ?></span>
                    <span class="dashboard-summary__value"><?= (int) ($c['active_incidents'] ?? 0) ?></span>
                    <span class="dashboard-summary__hint">
                        Worst: <?= htmlspecialchars($presenter->severityLabel((string) ($c['max_severity'] ?? ''))) ?>
                        · <?= htmlspecialchars((string) ($c['summary'] ?? '')) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="platform-control-plane__filters-wrap" aria-label="Filters">
        <h2 class="dashboard-quicklinks__heading">Filters</h2>
        <form class="platform-control-plane__filters" method="get" action="<?= htmlspecialchars($qBase) ?>">
            <label>Category
                <select name="category">
                    <?php foreach ($presenter->categoryFilterOptions() as $opt): ?>
                        <option value="<?= htmlspecialchars((string) $opt['value']) ?>"<?= $catSel === (string) $opt['value'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $opt['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Severity
                <select name="severity">
                    <?php foreach ($presenter->severityFilterOptions() as $opt): ?>
                        <option value="<?= htmlspecialchars((string) $opt['value']) ?>"<?= $sevSel === (string) $opt['value'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $opt['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Apply</button>
            <?php if ($catSel !== '' || $sevSel !== ''): ?>
                <a class="tenant-dash-table__link" href="<?= htmlspecialchars($qBase) ?>">Clear filters</a>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($isAllClear): ?>
        <section class="platform-incident-center__empty" aria-label="No incidents">
            <h2 class="dashboard-quicklinks__heading">No incidents detected</h2>
            <p class="platform-control-plane__recent-lead">There are no active signals from the current access-shape scan and registry checks. Continue monitoring from the <a class="tenant-dash-table__link" href="/platform-admin">dashboard</a>.</p>
        </section>
    <?php elseif ($filterEmpty): ?>
        <p class="platform-control-plane__recent-lead" role="status">No incidents match the current filters. <a class="tenant-dash-table__link" href="<?= htmlspecialchars($qBase) ?>">Clear filters</a>.</p>
    <?php else: ?>
        <section class="platform-incident-center__table" aria-label="Incident list">
            <h2 class="dashboard-quicklinks__heading">Incidents (scan — open a row to route)</h2>
            <div class="tenant-dash-table-wrap">
                <table class="tenant-dash-table">
                    <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col">Incident</th>
                        <th scope="col">Nature</th>
                        <th scope="col">Severity</th>
                        <th scope="col">Affected</th>
                        <th scope="col">Cause</th>
                        <th scope="col">Impact if unresolved</th>
                        <th scope="col">Next step</th>
                        <th scope="col">First place to look</th>
                        <th scope="col">Related modules</th>
                        <th scope="col">Primary open</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($incidents as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($presenter->categoryLabel((string) ($row['category'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars((string) ($row['title'] ?? '')) ?></td>
                            <td><span class="platform-control-plane__recent-lead" title="<?= htmlspecialchars((string) ($row['problem_role'] ?? '')) ?>"><?= htmlspecialchars((string) ($row['problem_role_label'] ?? '—')) ?></span></td>
                            <td>
                                <span class="platform-incident-center__sev platform-incident-center__sev--<?= htmlspecialchars(platform_incident_severity_css((string) ($row['severity'] ?? ''))) ?>">
                                    <?= htmlspecialchars($presenter->severityLabel((string) ($row['severity'] ?? ''))) ?>
                                </span>
                            </td>
                            <td><?= (int) ($row['affected_count'] ?? 0) ?></td>
                            <td><?= htmlspecialchars((string) ($row['cause_summary'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['impact_line'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['recommended_next_step'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['investigation_note'] ?? '')) ?></td>
                            <td><?php
                                $ctx = $row['context_links'] ?? [];
                                if (!is_array($ctx)) {
                                    $ctx = [];
                                }
                                $parts = [];
                                foreach ($ctx as $lnk) {
                                    if (!is_array($lnk)) {
                                        continue;
                                    }
                                    $u = (string) ($lnk['url'] ?? '');
                                    $lb = (string) ($lnk['label'] ?? '');
                                    if ($u !== '' && $lb !== '') {
                                        $parts[] = '<a class="tenant-dash-table__link" href="' . htmlspecialchars($u) . '">' . htmlspecialchars($lb) . '</a>';
                                    }
                                }
                                echo $parts !== [] ? implode('<br>', $parts) : '—';
                                ?></td>
                            <td><a class="tenant-dash-table__link" href="<?= htmlspecialchars((string) ($row['open_url'] ?? '#')) ?>"><?= htmlspecialchars((string) ($row['open_label'] ?? 'Open')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="platform-control-plane__actions" aria-label="Control plane modules">
        <h2 class="dashboard-quicklinks__heading">Related modules</h2>
        <p class="platform-control-plane__recent-lead">
            <a class="tenant-dash-table__link" href="/platform-admin/guide">Operator guide</a>
            <span aria-hidden="true"> · </span>
            <a class="tenant-dash-table__link" href="/platform-admin/access">Access</a>
            <span aria-hidden="true"> · </span>
            <a class="tenant-dash-table__link" href="/platform-admin/branches">Branches</a>
            <span aria-hidden="true"> · </span>
            <a class="tenant-dash-table__link" href="/platform-admin/salons">Salons</a>
            <span aria-hidden="true"> · </span>
            <a class="tenant-dash-table__link" href="/platform-admin/security">Security</a>
        </p>
    </section>
</div>
