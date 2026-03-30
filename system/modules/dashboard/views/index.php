<?php
/** @var array<string, mixed> $tenantDashboard */
$header = $tenantDashboard['header'] ?? [];
$cards = $tenantDashboard['cards'] ?? [];
$attention = $tenantDashboard['attention'] ?? [];
$upcoming = $tenantDashboard['upcoming'] ?? [];
$showBranchColumn = !empty($tenantDashboard['show_branch_column']);
$quickLinks = $tenantDashboard['quick_links'] ?? [];
?>
<div class="workspace-shell dashboard-shell dashboard-shell--tenant">
    <header class="workspace-module-head tenant-dash-head tenant-dash-panel tenant-dash-panel--header">
        <div class="workspace-module-head__text tenant-dash-head__text">
            <p class="tenant-dash-head__kicker">Operations overview</p>
            <h1 class="workspace-module-head__title tenant-dash-head__title"><?= htmlspecialchars((string) ($header['title'] ?? 'Dashboard')) ?></h1>
            <p class="workspace-module-head__sub tenant-dash-head__sub">
                <?= htmlspecialchars((string) ($header['subtitle'] ?? '')) ?>
            </p>
            <dl class="tenant-dash-meta" aria-label="Dashboard context">
                <div class="tenant-dash-meta__row">
                    <dt>Scope</dt>
                    <dd><?= htmlspecialchars((string) ($header['scope_label'] ?? '')) ?></dd>
                </div>
                <div class="tenant-dash-meta__row">
                    <dt>Timezone</dt>
                    <dd><?= htmlspecialchars((string) ($header['timezone'] ?? '')) ?></dd>
                </div>
            </dl>
        </div>
    </header>

    <section class="dashboard-summary tenant-dash-snap tenant-dash-panel" aria-label="Today snapshot">
        <h2 class="dashboard-summary__heading">Today snapshot</h2>
        <?php if ($cards === []): ?>
            <p class="tenant-dash-empty">Snapshot metrics are not available for this scope yet.</p>
        <?php else: ?>
            <div class="dashboard-summary__grid">
                <?php foreach ($cards as $card): ?>
                    <div class="dashboard-summary__card">
                        <span class="dashboard-summary__label"><?= htmlspecialchars((string) ($card['label'] ?? '')) ?></span>
                        <span class="dashboard-summary__value"><?= (int) ($card['value'] ?? 0) ?></span>
                        <?php if (!empty($card['hint'])): ?>
                            <span class="dashboard-summary__hint"><?= htmlspecialchars((string) $card['hint']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="tenant-dash-attention tenant-dash-panel" aria-label="Attention needed">
        <h2 class="tenant-dash-attention__heading">Attention needed</h2>
        <?php if ($attention === []): ?>
            <p class="tenant-dash-empty tenant-dash-empty--calm">No immediate items need attention.</p>
        <?php else: ?>
            <ul class="tenant-dash-attention__list">
                <?php foreach ($attention as $item): ?>
                    <li><?= htmlspecialchars((string) ($item['text'] ?? '')) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="tenant-dash-upcoming tenant-dash-panel" aria-label="Next appointments">
        <div class="tenant-dash-upcoming__head">
            <h2 class="tenant-dash-upcoming__heading">Next appointments</h2>
            <p class="tenant-dash-upcoming__lead">Soonest scheduled rows in scope (read-only).</p>
        </div>
        <?php if ($upcoming === []): ?>
            <p class="tenant-dash-empty">No upcoming appointments in this scope.</p>
        <?php else: ?>
            <div class="tenant-dash-table-wrap">
                <table class="tenant-dash-table">
                    <thead>
                        <tr>
                            <th scope="col">Time</th>
                            <th scope="col">Client</th>
                            <th scope="col">Service</th>
                            <th scope="col">Staff</th>
                            <th scope="col">Status</th>
                            <?php if ($showBranchColumn): ?>
                                <th scope="col">Branch</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming as $row): ?>
                            <tr>
                                <td>
                                    <?php if (($row['id'] ?? 0) > 0): ?>
                                        <a href="<?= htmlspecialchars((string) ($row['show_url'] ?? '#')) ?>" class="tenant-dash-table__link"><?= htmlspecialchars((string) ($row['time_display'] ?? '')) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars((string) ($row['time_display'] ?? '')) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string) ($row['client'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($row['service'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($row['staff'] ?? '')) ?></td>
                                <td><span class="tenant-dash-pill"><?= htmlspecialchars((string) ($row['status'] ?? '')) ?></span></td>
                                <?php if ($showBranchColumn): ?>
                                    <td><?= htmlspecialchars((string) ($row['branch'] ?? '')) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-quicklinks tenant-dash-actions tenant-dash-panel" aria-label="Quick access">
        <h2 class="dashboard-quicklinks__heading">Quick access</h2>
        <?php if ($quickLinks === []): ?>
            <p class="tenant-dash-empty">No quick links are configured for this account.</p>
        <?php else: ?>
            <div class="tenant-dash-actions__grid">
                <?php foreach ($quickLinks as $link): ?>
                    <a class="tenant-dash-action-card" href="<?= htmlspecialchars((string) ($link['href'] ?? '#')) ?>">
                        <span class="tenant-dash-action-card__label"><?= htmlspecialchars((string) ($link['label'] ?? '')) ?></span>
                        <?php if (!empty($link['hint'])): ?>
                            <span class="tenant-dash-action-card__hint"><?= htmlspecialchars((string) $link['hint']) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
