<?php
/** @var string $csrf */
/** @var string $title */
$pagePurposeKey = 'guide';
?>
<div class="workspace-shell platform-control-plane">
    <?php require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php'); ?>

    <section class="founder-guide-section" aria-labelledby="guide-modules">
        <h2 id="guide-modules" class="dashboard-quicklinks__heading">What each module is for</h2>
        <dl class="platform-control-plane__meta founder-guide-dl">
            <div class="platform-control-plane__meta-row"><dt>Dashboard</dt><dd>Snapshot and shortcuts — start here for scale signals and routing.</dd></div>
            <div class="platform-control-plane__meta-row"><dt>Incident Center</dt><dd>Diagnose and route — what is wrong, severity, first place to look. Does not replace repairs elsewhere.</dd></div>
            <div class="platform-control-plane__meta-row"><dt>Access</dt><dd>People and logins — scan, open a user, repair recommended paths, provisioning.</dd></div>
            <div class="platform-control-plane__meta-row"><dt>Organizations</dt><dd>Tenant/company lifecycle — suspension, reactivation, blast radius.</dd></div>
            <div class="platform-control-plane__meta-row"><dt>Branches</dt><dd>Location catalog — metadata and ownership context for each branch row.</dd></div>
            <div class="platform-control-plane__meta-row"><dt>Security</dt><dd>Deployment-wide public emergency controls and founder audit visibility — not staff workspace permissions alone.</dd></div>
        </dl>
    </section>

    <section class="founder-guide-section" aria-labelledby="guide-workflow">
        <h2 id="guide-workflow" class="dashboard-quicklinks__heading">Recommended workflow</h2>
        <ol class="founder-guide-steps">
            <li><strong>Dashboard</strong> — orient and pick a path.</li>
            <li><strong>Incident Center</strong> — see what is broken and which module owns the next step.</li>
            <li><strong>Correct module</strong> — Access, Organizations, Branches, or Security.</li>
            <li><strong>Action</strong> — use previews, reasons, and confirmations where shown.</li>
            <li><strong>Verify</strong> — re-check the user or org state; use Incident Center if signals remain.</li>
        </ol>
    </section>

    <section class="founder-guide-section" aria-labelledby="guide-scenarios">
        <h2 id="guide-scenarios" class="dashboard-quicklinks__heading">Common scenarios</h2>
        <ul class="tenant-dash-attention__list">
            <li><strong>User cannot log in</strong> — Incident Center if unsure → Access → open the user → follow safest next step or guided repair.</li>
            <li><strong>Organization is suspended</strong> — Organizations → review lifecycle → reactivate via safe preview when appropriate → then Access for affected users if needed.</li>
            <li><strong>Branches appear under a suspended org</strong> — root cause is usually org suspension; fix lifecycle first, then verify Access for blocked users.</li>
            <li><strong>Create tenant admin or reception</strong> — Access → Provision users (manage permission required).</li>
            <li><strong>Stop public booking (deployment-wide)</strong> — Security → kill switches preview — public emergency control, not user access repair.</li>
        </ul>
    </section>

    <section class="founder-guide-section" aria-labelledby="guide-map">
        <h2 id="guide-map" class="dashboard-quicklinks__heading">Which page do I use?</h2>
        <div class="tenant-dash-table-wrap">
            <table class="tenant-dash-table">
                <thead>
                <tr>
                    <th scope="col">If your question is…</th>
                    <th scope="col">Start here</th>
                </tr>
                </thead>
                <tbody>
                <tr><td>What is broken across the platform?</td><td><a class="tenant-dash-table__link" href="/platform-admin/incidents">Incident Center</a></td></tr>
                <tr><td>One person’s login or membership</td><td><a class="tenant-dash-table__link" href="/platform-admin/access">Access</a></td></tr>
                <tr><td>Whole company suspended or lifecycle</td><td><a class="tenant-dash-table__link" href="/platform-admin/organizations">Organizations</a></td></tr>
                <tr><td>Branch name, code, catalog</td><td><a class="tenant-dash-table__link" href="/platform-admin/branches">Branches</a></td></tr>
                <tr><td>Public booking/API/commerce emergency</td><td><a class="tenant-dash-table__link" href="/platform-admin/security">Security</a></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="founder-guide-section" aria-labelledby="guide-not">
        <h2 id="guide-not" class="dashboard-quicklinks__heading">What not to do</h2>
        <ul class="tenant-dash-attention__list founder-guide-warn">
            <li>Do not treat Incident Center as the only place to apply fixes — it routes you to the right module.</li>
            <li>Do not edit branch names to fix organization suspension — review tenant/company lifecycle first.</li>
            <li>Do not use Security kill switches for routine staff access — use Access.</li>
            <li>Do not skip previews and reasons on high-impact actions — they protect audit and reversibility labeling.</li>
        </ul>
    </section>

    <p class="platform-control-plane__recent-lead">
        <a class="tenant-dash-table__link" href="/platform-admin">← Dashboard</a>
        <span aria-hidden="true"> · </span>
        <a class="tenant-dash-table__link" href="/platform-admin/incidents">Incident Center</a>
    </p>
</div>
