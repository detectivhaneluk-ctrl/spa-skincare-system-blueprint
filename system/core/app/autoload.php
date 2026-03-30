<?php

declare(strict_types=1);

if (defined('SPA_AUTOLOAD_BOOTSTRAPPED')) {
    return;
}

define('SPA_AUTOLOAD_BOOTSTRAPPED', true);

$repoRoot = dirname(__DIR__, 3);
$vendorAutoload = $repoRoot . '/vendor/autoload.php';
$autoloadMode = strtolower(trim((string) getenv('SPA_AUTOLOAD_MODE')));
$composerAutoloadAvailable = is_file($vendorAutoload);

if (($autoloadMode === 'composer_only' || $autoloadMode === 'release_law') && !$composerAutoloadAvailable) {
    throw new RuntimeException(
        'Composer autoload is required in strict mode. Run `composer dump-autoload -o -a` before bootstrapping.'
    );
}

if ($composerAutoloadAvailable && $autoloadMode !== 'legacy_only') {
    require_once $vendorAutoload;

    if ($autoloadMode === 'composer_only' || $autoloadMode === 'release_law') {
        return;
    }

    if ((string) getenv('SPA_AUTOLOAD_ENABLE_LEGACY_FALLBACK') !== '1') {
        return;
    }
}

spl_autoload_register(function (string $class): void {
    $base = dirname(__DIR__, 2);
    // Core prefixes are single-path, exact-case mappings. Do not add fallback casing here.
    $prefixes = [
        'Core\\App\\' => $base . '/core/app/',
        'Core\\Contracts\\' => $base . '/core/contracts/',
        'Core\\Runtime\\' => $base . '/core/Runtime/',
        'Core\\Router\\' => $base . '/core/router/',
        'Core\\Middleware\\' => $base . '/core/middleware/',
        'Core\\Auth\\' => $base . '/core/auth/',
        'Core\\Permissions\\' => $base . '/core/permissions/',
        'Core\\Audit\\' => $base . '/core/audit/',
        'Core\\Branch\\' => $base . '/core/Branch/',
        'Core\\Organization\\' => $base . '/core/Organization/',
        'Core\\Tenant\\' => $base . '/core/tenant/',
        'Core\\Repository\\' => $base . '/core/Repository/',
        'Core\\Storage\\' => $base . '/core/Storage/',
        'Core\\Observability\\' => $base . '/core/Observability/',
        'Core\\Errors\\' => $base . '/core/errors/',
        'Core\\Http\\' => $base . '/core/http/',
    ];

    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $dir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }

    // Modules: Module\Dir\Class -> modules/{module}/{dir...}/Class.php (all segments between module and class)
    // Two-segment Modules\Module\Class -> modules/{module}/Class.php (A-003)
    // Deep: Module\Http\Controllers\Admin\Thing -> .../http/controllers/admin/Thing.php (then ucfirst + original-case fallbacks)
    if (str_starts_with($class, 'Modules\\')) {
        $relative = substr($class, 8);
        $parts = explode('\\', $relative);
        if (count($parts) >= 2) {
            $basePath = (defined('SYSTEM_PATH') && SYSTEM_PATH !== '') ? SYSTEM_PATH : $base;
            foreach (moduleClassFileCandidates($basePath, $parts) as $file) {
                if (is_file($file)) {
                    require $file;
                    return;
                }
            }
        }
    }
});

/**
 * @param list<string> $parts [ModulePascal, ...path segments..., ClassName]
 * @return list<string>
 */
function moduleClassFileCandidates(string $basePath, array $parts): array
{
    $module = moduleFolder($parts[0]);
    $className = $parts[count($parts) - 1];
    $dirSegments = array_slice($parts, 1, -1);
    $prefix = rtrim($basePath, '/') . '/modules/' . $module . '/';
    if ($dirSegments === []) {
        return [$prefix . $className . '.php'];
    }

    $relVariants = [
        implode('/', array_map(static fn (string $s): string => strtolower($s), $dirSegments)),
        implode('/', array_map(static fn (string $s): string => ucfirst(strtolower($s)), $dirSegments)),
        implode('/', $dirSegments),
    ];

    $out = [];
    foreach (array_unique($relVariants) as $rel) {
        $out[] = $prefix . $rel . '/' . $className . '.php';
    }

    return $out;
}

function moduleFolder(string $pascal): string
{
    $map = ['GiftcardsPackages' => 'giftcards-packages', 'ServicesResources' => 'services-resources', 'OnlineBooking' => 'online-booking'];
    return $map[$pascal] ?? strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $pascal));
}
