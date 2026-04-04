<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf ?? '') ?>">
    <meta name="csrf-name" content="<?= htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token')) ?>">
    <title><?= htmlspecialchars($title ?? 'SPA & Skincare') ?></title>
    <script>
    (function () {
        try {
            var L = 'ollira_app_nav_layout';
            var C = 'ollira_app_sidebar_collapsed';
            var v = localStorage.getItem(L) === 'sidebar' ? 'sidebar' : 'top';
            document.documentElement.setAttribute('data-app-nav-layout', v);
            if (localStorage.getItem(C) === '1') {
                document.documentElement.setAttribute('data-app-sidebar-collapsed', 'true');
            }
        } catch (e) {
            document.documentElement.setAttribute('data-app-nav-layout', 'top');
        }
    })();
    </script>
    <link rel="stylesheet" href="/assets/css/inter-fonts.css">
    <link rel="stylesheet" href="/assets/css/design-tokens.css">
    <link rel="stylesheet" href="/assets/css/design-system.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app-drawer.js" defer></script>
    <script src="/assets/js/app-shell-nav.js" defer></script>
</head>
<body>
    <?php
    $supportEntrySession = \Core\App\Application::container()->get(\Core\Auth\SessionAuth::class);
    if (empty($hideNav) && $supportEntrySession->isSupportEntryActive()) {
        $supportEff = $supportEntrySession->user();
        $supportEffLabel = $supportEff
            ? htmlspecialchars((string) ($supportEff['email'] ?? '')) . ' (user #' . (int) ($supportEff['id'] ?? 0) . ')'
            : 'tenant user';
        $supportActorLabel = htmlspecialchars((string) ($supportEntrySession->supportActorLabel() ?? 'platform principal'));
        $supportCsrfName = htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'));
        $supportCsrfVal = htmlspecialchars((string) ($csrf ?? $supportEntrySession->csrfToken()));
        echo '<div class="app-shell__support-banner" role="status">';
        echo '<span><strong>Support entry active.</strong> Workspace actions run as <strong>' . $supportEffLabel . '</strong> ';
        echo 'while you are signed in as platform principal <strong>' . $supportActorLabel . '</strong>.</span>';
        echo '<form method="post" action="/support-entry/stop" class="app-shell__support-banner-form">';
        echo '<input type="hidden" name="' . $supportCsrfName . '" value="' . $supportCsrfVal . '">';
        echo '<button type="submit" class="app-shell__support-banner-btn">End support entry</button>';
        echo '</form></div>';
    }
    $navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $navPath = is_string($navPath) && $navPath !== '' ? $navPath : '/';
    $navIsAppointments = str_starts_with($navPath, '/appointments') || str_starts_with($navPath, '/calendar');
    if (empty($hideNav)) {
        $navUser = \Core\App\Application::container()->get(\Core\Auth\AuthService::class)->user();
        if ($navUser !== null) {
            $planeResolver = \Core\App\Application::container()->get(\Core\Auth\PrincipalPlaneResolver::class);
            if ($planeResolver->resolveForUserId((int) ($navUser['id'] ?? 0)) !== \Core\Auth\PrincipalPlaneResolver::TENANT_PLANE) {
                $hideNav = true;
            }
        }
    }
    $settingsActivePrefixes = [
        '/settings',
        '/memberships',
        '/payroll',
        '/services-resources',
        '/branches',
    ];
    $navIsSettings = false;
    foreach ($settingsActivePrefixes as $prefix) {
        if (str_starts_with($navPath, $prefix)) {
            $navIsSettings = true;
            break;
        }
    }

    $navIsSales = str_starts_with($navPath, '/sales')
        || str_starts_with($navPath, '/gift-cards')
        || str_starts_with($navPath, '/packages');
    $navItems = [
        ['/dashboard', 'Dashboard', $navPath === '/' || str_starts_with($navPath, '/dashboard')],
        ['/appointments/calendar/day', 'Appointments', $navIsAppointments],
        ['/clients', 'Clients', str_starts_with($navPath, '/clients')],
        ['/staff', 'Staff', str_starts_with($navPath, '/staff')],
        ['/sales', 'Sales', $navIsSales],
        ['/inventory', 'Inventory', str_starts_with($navPath, '/inventory')],
        ['/marketing/campaigns', 'Marketing', str_starts_with($navPath, '/marketing')],
        ['/settings', 'Settings', $navIsSettings],
    ];
    $navSideIcons = [
        'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M9 22V12h6v10',
        'M8 2v4 M16 2v4 M3 10h18 M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2',
        'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M23 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75',
        'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M12 7a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'M12 20V10 M18 20V4 M6 20v-4',
        'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z',
        'M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9 M13 13h3a5 5 0 0 0 5-5v-1',
        'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    ];
    $mainClassAttr = 'app-shell__main main'
        . (!empty($hideNav) ? ' app-shell__main--auth' : '')
        . (!empty($mainClass) ? ' ' . htmlspecialchars((string) $mainClass) : '');
    $csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
    $csrfVal = htmlspecialchars($csrf ?? '');
    ?>
    <?php if (!empty($hideNav)): ?>
    <main class="<?= $mainClassAttr ?>">
        <?= $content ?? '' ?>
    </main>
    <?php else: ?>
    <?php
    $shellUser = \Core\App\Application::container()->get(\Core\Auth\AuthService::class)->user();
    $shellEmail = is_array($shellUser) ? (string) ($shellUser['email'] ?? '') : '';
    $shellDisplay = $shellEmail !== '' ? $shellEmail : 'Signed in';
    ?>
    <div class="app-shell">
        <header class="app-shell__header app-shell__header--top">
            <a class="app-shell__brand" href="/dashboard">
                <span class="app-shell__brand-logo" aria-hidden="true">
                    <svg class="app-shell__brand-logo-svg" width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
                        <path class="app-shell__brand-logo-bolt" fill="currentColor" d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/>
                    </svg>
                </span>
                <span class="app-shell__brand-text">
                    <span class="app-shell__brand-name">Ollira</span>
                    <span class="app-shell__brand-role">Admin</span>
                </span>
            </a>
            <nav class="app-shell__nav" aria-label="Main modules">
                <?php foreach ($navItems as [$href, $label, $active]): ?>
                <a class="app-shell__nav-link<?= $active ? ' is-active' : '' ?>" href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($label) ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="app-shell__aside">
                <div class="app-shell__layout-switch app-shell__layout-switch--segmented" role="group" aria-label="Navigation layout">
                    <button type="button" class="app-shell__layout-switch-btn" data-app-shell-layout="top" aria-pressed="true">Top</button>
                    <button type="button" class="app-shell__layout-switch-btn" data-app-shell-layout="sidebar" aria-pressed="false">Sidebar</button>
                </div>
                <div class="app-shell__header-tools">
                    <button type="button" class="app-shell__header-icon" aria-label="Notifications">
                        <svg class="app-shell__header-icon-svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                    </button>
                    <a class="app-shell__header-icon" href="/settings" aria-label="Account and settings">
                        <svg class="app-shell__header-icon-svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </a>
                </div>
                <form method="post" action="/logout" class="app-shell__logout-form">
                    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                    <button type="submit" class="app-shell__logout-btn">Log out</button>
                </form>
            </div>
        </header>

        <header class="app-shell__header app-shell__header--mobile" hidden inert>
            <button type="button" class="app-shell__mobile-menu-btn" aria-controls="app-shell-sidebar" aria-expanded="false" aria-label="Open menu">
                <svg class="app-shell__mobile-menu-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <a class="app-shell__brand app-shell__brand--mobile" href="/dashboard">
                <span class="app-shell__brand-logo" aria-hidden="true">
                    <svg class="app-shell__brand-logo-svg" width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
                        <path class="app-shell__brand-logo-bolt" fill="currentColor" d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/>
                    </svg>
                </span>
                <span class="app-shell__brand-name">Ollira</span>
            </a>
        </header>

        <div class="app-shell__backdrop" data-app-shell-backdrop hidden aria-hidden="true"></div>

        <aside id="app-shell-sidebar" class="app-shell__sidebar" hidden inert aria-label="Main navigation">
            <div class="app-shell__sidebar-inner">
                <div class="app-shell__sidebar-top">
                    <a class="app-shell__brand app-shell__sidebar-brand" href="/dashboard">
                        <span class="app-shell__brand-logo" aria-hidden="true">
                            <svg class="app-shell__brand-logo-svg" width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
                                <path class="app-shell__brand-logo-bolt" fill="currentColor" d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/>
                            </svg>
                        </span>
                        <span class="app-shell__brand-text app-shell__sidebar-brand-text">
                            <span class="app-shell__brand-name">Ollira</span>
                            <span class="app-shell__brand-role">Admin</span>
                        </span>
                    </a>
                    <button type="button" class="app-shell__sidebar-collapse-btn" data-app-shell-sidebar-collapse aria-pressed="false" aria-label="Collapse sidebar" title="Collapse sidebar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M11 19l-7-7 7-7M18 19l-7-7 7-7"/>
                        </svg>
                    </button>
                </div>
                <div class="app-shell__sidebar-search">
                    <span class="app-shell__sidebar-search-icon" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    </span>
                    <input type="search" class="app-shell__sidebar-search-input" placeholder="Search" disabled autocomplete="off" aria-disabled="true" title="Search is not available yet">
                </div>
                <p class="app-shell__sidebar-section-label">Modules</p>
                <nav class="app-shell__side-nav" aria-label="Main modules">
                    <?php foreach ($navItems as $idx => $tuple):
                        [$href, $label, $active] = $tuple;
                        $pathD = $navSideIcons[$idx] ?? 'M4 6h16M4 12h16M4 18h16';
                        ?>
                    <a class="app-shell__side-nav-link<?= $active ? ' is-active' : '' ?>" href="<?= htmlspecialchars($href) ?>" title="<?= htmlspecialchars($label) ?>">
                        <span class="app-shell__side-nav-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="<?= htmlspecialchars($pathD) ?>"/></svg>
                        </span>
                        <span class="app-shell__side-nav-label"><?= htmlspecialchars($label) ?></span>
                    </a>
                    <?php endforeach; ?>
                </nav>
                <div class="app-shell__sidebar-spacer" aria-hidden="true"></div>
                <footer class="app-shell__sidebar-footer">
                    <div class="app-shell__sidebar-user">
                        <span class="app-shell__sidebar-user-avatar" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/></svg>
                        </span>
                        <span class="app-shell__sidebar-user-text">
                            <span class="app-shell__sidebar-user-name">Account</span>
                            <span class="app-shell__sidebar-user-email"><?= htmlspecialchars($shellDisplay) ?></span>
                        </span>
                    </div>
                    <div class="app-shell__layout-switch app-shell__layout-switch--segmented" role="group" aria-label="Navigation layout">
                        <button type="button" class="app-shell__layout-switch-btn" data-app-shell-layout="top" aria-pressed="true">Top</button>
                        <button type="button" class="app-shell__layout-switch-btn" data-app-shell-layout="sidebar" aria-pressed="false">Sidebar</button>
                    </div>
                    <form method="post" action="/logout" class="app-shell__logout-form app-shell__sidebar-logout">
                        <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                        <button type="submit" class="app-shell__logout-btn app-shell__sidebar-logout-btn">Log out</button>
                    </form>
                </footer>
            </div>
        </aside>
        <script>
        (function () {
            try {
                var v = localStorage.getItem('ollira_app_nav_layout') === 'sidebar' ? 'sidebar' : 'top';
                document.documentElement.setAttribute('data-app-nav-layout', v);
                var side = document.getElementById('app-shell-sidebar');
                var top = document.querySelector('.app-shell__header--top');
                var mobile = document.querySelector('.app-shell__header--mobile');
                if (!side || !top) return;
                if (v === 'sidebar') {
                    top.setAttribute('hidden', '');
                    top.setAttribute('inert', '');
                    if (window.matchMedia('(max-width: 900px)').matches) {
                        side.setAttribute('hidden', '');
                        side.setAttribute('inert', '');
                        if (mobile) { mobile.removeAttribute('hidden'); mobile.removeAttribute('inert'); }
                    } else {
                        side.removeAttribute('hidden');
                        side.removeAttribute('inert');
                        if (mobile) { mobile.setAttribute('hidden', ''); mobile.setAttribute('inert', ''); }
                    }
                } else {
                    side.setAttribute('hidden', '');
                    side.setAttribute('inert', '');
                    top.removeAttribute('hidden');
                    top.removeAttribute('inert');
                    if (mobile) { mobile.setAttribute('hidden', ''); mobile.setAttribute('inert', ''); }
                }
                if (localStorage.getItem('ollira_app_sidebar_collapsed') === '1') {
                    document.documentElement.setAttribute('data-app-sidebar-collapsed', 'true');
                }
            } catch (e) {}
        })();
        </script>
        <main class="<?= $mainClassAttr ?>">
            <?= $content ?? '' ?>
        </main>
    </div>
    <?php endif; ?>
    <div id="app-drawer-host" class="app-drawer-host" aria-live="polite"></div>
</body>
</html>
