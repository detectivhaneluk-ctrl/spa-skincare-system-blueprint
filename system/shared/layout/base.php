<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'SPA & Skincare') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
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
    ?>
    <?php if (!empty($hideNav)): ?>
    <?php else: ?>
    <header class="app-shell__header">
        <a class="app-shell__brand" href="/dashboard">SPA Admin</a>
        <nav class="app-shell__nav" aria-label="Main modules">
            <?php foreach ($navItems as [$href, $label, $active]): ?>
            <a class="app-shell__nav-link<?= $active ? ' is-active' : '' ?>" href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="app-shell__aside">
            <form method="post" action="/logout" class="app-shell__logout-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <button type="submit" class="app-shell__logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <?php endif; ?>
    <main class="app-shell__main main<?= !empty($hideNav) ? ' app-shell__main--auth' : '' ?><?= !empty($mainClass) ? ' ' . htmlspecialchars((string) $mainClass) : '' ?>">
        <?= $content ?? '' ?>
    </main>
</body>
</html>
