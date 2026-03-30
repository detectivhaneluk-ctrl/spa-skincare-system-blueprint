<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'Founder') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <?php
    $navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $navPath = is_string($navPath) && $navPath !== '' ? $navPath : '/';
    $csrf = $csrf ?? \Core\App\Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();

    $activeNav = 'dashboard';
    if (str_starts_with($navPath, '/platform-admin/salons')) {
        $activeNav = 'salons';
    } elseif (str_starts_with($navPath, '/platform-admin/problems')) {
        $activeNav = 'problems';
    } elseif (str_starts_with($navPath, '/platform-admin/access')) {
        $activeNav = 'access';
    } elseif (str_starts_with($navPath, '/platform-admin/security')) {
        $activeNav = 'security';
    } elseif (
        str_starts_with($navPath, '/platform-admin/incidents')
        || str_starts_with($navPath, '/platform-admin/guide')
        || str_starts_with($navPath, '/platform-admin/tenant-access')
        || str_starts_with($navPath, '/platform-admin/branches')
        || str_starts_with($navPath, '/platform-admin/safe-actions')
        || str_starts_with($navPath, '/platform-admin/system')
        || str_starts_with($navPath, '/platform-admin/platform')
        || str_starts_with($navPath, '/platform-admin/billing')
    ) {
        $activeNav = 'dashboard';
    }

    $navItems = [
        ['/platform-admin', 'Dashboard', $activeNav === 'dashboard'],
        ['/platform-admin/salons', 'Salons', $activeNav === 'salons'],
        ['/platform-admin/access', 'Access', $activeNav === 'access'],
        ['/platform-admin/problems', 'Issues', $activeNav === 'problems'],
        ['/platform-admin/security', 'Security', $activeNav === 'security'],
    ];
    ?>
    <header class="app-shell__header app-shell__header--platform app-shell__header--founder">
        <a class="app-shell__brand app-shell__brand--platform" href="/platform-admin">Founder</a>
        <nav class="app-shell__nav app-shell__nav--founder" aria-label="Founder control plane">
            <?php foreach ($navItems as [$href, $label, $active]): ?>
            <a class="app-shell__nav-link<?= $active ? ' is-active' : '' ?>" href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="app-shell__aside app-shell__aside--platform">
            <form method="post" action="/logout" class="app-shell__logout-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="app-shell__logout-btn">Logout</button>
            </form>
        </div>
    </header>
    <main class="app-shell__main main platform-shell__main<?= !empty($mainClass) ? ' ' . htmlspecialchars((string) $mainClass) : '' ?>">
        <?= $content ?? '' ?>
    </main>
</body>
</html>
