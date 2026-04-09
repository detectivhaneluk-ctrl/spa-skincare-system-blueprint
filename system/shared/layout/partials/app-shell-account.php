<?php
declare(strict_types=1);

/** @var string $accountSuffix Unique suffix for ids: top | side */
/** @var string $accountVariant header | sidebar */
$suffix = preg_replace('/[^a-z0-9_-]/i', '', (string) ($accountSuffix ?? 'top')) ?: 'top';
$variant = (string) ($accountVariant ?? 'header');
$isSidebar = $variant === 'sidebar';
$idTrigger = 'app-shell-account-trigger-' . $suffix;
$idPanel = 'app-shell-account-panel-' . $suffix;
$name = htmlspecialchars((string) ($shellDisplayName ?? 'Account'), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string) ($shellEmail ?? ''), ENT_QUOTES, 'UTF-8');
$initial = htmlspecialchars((string) ($shellInitial ?? 'A'), ENT_QUOTES, 'UTF-8');
$integrationsUrl = '/settings?section=hardware';
?>
<div class="app-shell__account<?= $isSidebar ? ' app-shell__account--sidebar' : '' ?>" data-app-shell-account>
    <button
        type="button"
        class="app-shell__account-trigger<?= $isSidebar ? ' app-shell__account-trigger--sidebar' : '' ?>"
        id="<?= htmlspecialchars($idTrigger, ENT_QUOTES, 'UTF-8') ?>"
        aria-expanded="false"
        aria-haspopup="true"
        aria-controls="<?= htmlspecialchars($idPanel, ENT_QUOTES, 'UTF-8') ?>"
        data-app-shell-account-trigger
    >
        <span class="app-shell__account-trigger-avatar" aria-hidden="true"><?= $initial ?></span>
        <?php if ($isSidebar): ?>
        <span class="app-shell__account-trigger-meta">
            <span class="app-shell__account-trigger-name"><?= $name ?></span>
            <span class="app-shell__account-trigger-email"><?= $email !== '' ? $email : '—' ?></span>
        </span>
        <svg class="app-shell__account-trigger-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path d="M9 18l6-6-6-6"/>
        </svg>
        <?php endif; ?>
    </button>
    <div
        class="app-shell__account-panel"
        id="<?= htmlspecialchars($idPanel, ENT_QUOTES, 'UTF-8') ?>"
        role="menu"
        aria-labelledby="<?= htmlspecialchars($idTrigger, ENT_QUOTES, 'UTF-8') ?>"
        data-app-shell-account-panel
        hidden
    >
        <div class="app-shell__account-panel-inner">
            <div class="app-shell__account-head">
                <span class="app-shell__account-head-avatar" aria-hidden="true"><?= $initial ?></span>
                <div class="app-shell__account-head-text">
                    <div class="app-shell__account-head-row">
                        <span class="app-shell__account-head-name" title="<?= $name ?>"><?= $name ?></span>
                        <span class="app-shell__account-badge">ADMIN</span>
                    </div>
                    <span class="app-shell__account-head-email"><?= $email !== '' ? $email : '—' ?></span>
                </div>
            </div>

            <div class="app-shell__account-divider" aria-hidden="true"></div>

            <div class="app-shell__account-theme-block" role="none">
                <div class="app-shell__account-row app-shell__account-row--switch app-shell__account-row--theme" role="none">
                    <span class="app-shell__account-theme-dial" aria-hidden="true">
                        <span class="app-shell__account-theme-dial-track"></span>
                        <span class="app-shell__account-theme-dial-sun">
                            <svg class="app-shell__account-theme-dial-svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <g class="app-shell__account-theme-dial-sun-mark" stroke="currentColor" stroke-width="1.35" stroke-linecap="round">
                                    <circle cx="12" cy="12" r="3.75" fill="none"/>
                                    <path d="M12 2.25v2M12 19.75v2M2.25 12h2M19.75 12h2M4.4 4.4l1.42 1.42M18.18 18.18l1.42 1.42M4.4 19.6l1.42-1.42M18.18 5.82l1.42-1.42"/>
                                </g>
                            </svg>
                        </span>
                        <span class="app-shell__account-theme-dial-moon">
                            <svg class="app-shell__account-theme-dial-svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                            </svg>
                        </span>
                    </span>
                    <div class="app-shell__account-theme-text">
                        <span class="app-shell__account-theme-title" id="<?= htmlspecialchars($idPanel, ENT_QUOTES, 'UTF-8') ?>-theme-title">Appearance</span>
                        <span class="app-shell__account-theme-state" id="<?= htmlspecialchars($idPanel, ENT_QUOTES, 'UTF-8') ?>-theme-state" data-app-theme-state-label aria-live="polite">Light</span>
                    </div>
                    <button
                        type="button"
                        class="app-shell__theme-switch app-shell__theme-switch--app"
                        role="switch"
                        aria-checked="false"
                        aria-labelledby="<?= htmlspecialchars($idPanel, ENT_QUOTES, 'UTF-8') ?>-theme-title <?= htmlspecialchars($idPanel, ENT_QUOTES, 'UTF-8') ?>-theme-state"
                        data-app-theme-toggle
                    >
                        <span class="app-shell__theme-switch-thumb" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <div class="app-shell__account-divider" aria-hidden="true"></div>

            <div class="app-shell__account-layout">
                <p class="app-shell__account-sublabel" id="<?= htmlspecialchars($idPanel, ENT_QUOTES, 'UTF-8') ?>-layout-label">Navigation layout</p>
                <div class="app-shell__layout-switch app-shell__layout-switch--segmented app-shell__layout-switch--in-account" role="group" aria-labelledby="<?= htmlspecialchars($idPanel, ENT_QUOTES, 'UTF-8') ?>-layout-label">
                    <button type="button" class="app-shell__layout-switch-btn" data-app-shell-layout="top" aria-pressed="true">Top</button>
                    <button type="button" class="app-shell__layout-switch-btn" data-app-shell-layout="sidebar" aria-pressed="false">Sidebar</button>
                </div>
            </div>

            <div class="app-shell__account-divider" aria-hidden="true"></div>

            <button type="button" class="app-shell__account-link app-shell__account-link--button" role="menuitem">
                <span class="app-shell__account-row-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                </span>
                <span class="app-shell__account-link-label">Notifications</span>
            </button>
            <a class="app-shell__account-link" role="menuitem" href="/dashboard">
                <span class="app-shell__account-row-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                    </svg>
                </span>
                <span class="app-shell__account-link-label">Activity</span>
            </a>
            <a class="app-shell__account-link app-shell__account-link--chevron" role="menuitem" href="<?= htmlspecialchars($integrationsUrl, ENT_QUOTES, 'UTF-8') ?>">
                <span class="app-shell__account-row-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="5" cy="6" r="2"/><circle cx="19" cy="6" r="2"/><circle cx="12" cy="18" r="2"/>
                        <path d="M5 8v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M12 10v6"/>
                    </svg>
                </span>
                <span class="app-shell__account-link-label">Integrations</span>
                <svg class="app-shell__account-link-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path d="M9 18l6-6-6-6"/>
                </svg>
            </a>
            <a class="app-shell__account-link" role="menuitem" href="/settings">
                <span class="app-shell__account-row-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.26.6.97 1 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </span>
                <span class="app-shell__account-link-label">Settings</span>
            </a>

            <div class="app-shell__account-divider" aria-hidden="true"></div>

            <form method="post" action="/logout" class="app-shell__account-logout-form">
                <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                <button type="submit" class="app-shell__account-link app-shell__account-link--button" role="menuitem">
                    <span class="app-shell__account-row-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                    </span>
                    <span class="app-shell__account-link-label">Log out</span>
                </button>
            </form>

            <p class="app-shell__account-footer">
                <span class="app-shell__account-footer-muted">Ollira Admin</span>
                <span class="app-shell__account-footer-sep" aria-hidden="true">·</span>
                <span class="app-shell__account-footer-muted">Terms &amp; Conditions</span>
            </p>
        </div>
    </div>
</div>
