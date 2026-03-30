<?php
/** @var string $title */
$title = $title ?? 'System';
?>
<div class="workspace-shell platform-control-plane">
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title">System</h1>
            <p class="workspace-module-head__sub">Access, branches, security, and reference tools.</p>
        </div>
    </header>
    <ul class="platform-salons__action-list">
        <li><a class="tenant-dash-table__link" href="/platform-admin/access">Access</a></li>
        <li><a class="tenant-dash-table__link" href="/platform-admin/branches">Branches</a></li>
        <li><a class="tenant-dash-table__link" href="/platform-admin/security">Security</a></li>
        <li><a class="tenant-dash-table__link" href="/platform-admin/incidents">Incidents</a> (legacy list)</li>
        <li><a class="tenant-dash-table__link" href="/platform-admin/guide">Operator guide</a></li>
    </ul>
</div>
