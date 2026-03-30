<?php

declare(strict_types=1);

namespace Core\App;

use Core\Branch\BranchContext;

/**
 * Enforces a single PHP default timezone for the request (BKM-005).
 *
 * Resolution order (same for every apply call):
 * 1. `establishment.timezone` from `SettingsService::getEstablishmentSettings(BranchContext::getCurrentBranchId())`
 *    (branch **null** ⇒ global merge via `branch_id = 0` only; non-null ⇒ branch rows override globals per `SettingsService::all()`).
 *    Legacy flat `timezone` key is merged inside `getEstablishmentSettings`.
 * 2. `config/app.php` → `timezone` (env `APP_TIMEZONE`, default UTC).
 * 3. `UTC` if the config value is invalid.
 *
 * **Pipeline:** `Application::run()` calls {@see applyForHttpRequest()} while branch context is usually still **unresolved**
 * (guests / pre-middleware) so early handlers get a sane default. {@see syncAfterBranchContextResolved()} runs at the end of
 * `BranchContextMiddleware` and reapplies using the resolved branch (including **null** for public/unauthenticated flows — idempotent
 * when the effective merge matches the first pass).
 *
 * Stored `appointments.start_at` / `end_at` remain naive local datetimes in establishment-local wall time; this class
 * only makes `strtotime` / `date` / `time()` interpret those strings and "today" consistently with that wall clock.
 */
final class ApplicationTimezone
{
    private static bool $applied = false;

    private static ?string $appliedIdentifier = null;

    /**
     * First pass: call from `Application::run()` after the container is available (before the HTTP middleware stack).
     */
    public static function applyForHttpRequest(): void
    {
        if (self::$applied) {
            return;
        }

        self::resolveAndSetDefaultTimezone();
        self::$applied = true;
    }

    /**
     * Second pass: call after `BranchContextMiddleware` sets `BranchContext` so PHP's default timezone matches branch-effective
     * establishment settings for staff; guests keep global merge (null context).
     */
    public static function syncAfterBranchContextResolved(): void
    {
        self::resolveAndSetDefaultTimezone();
        self::$applied = true;
    }

    private static function resolveAndSetDefaultTimezone(): void
    {
        $container = Application::container();
        $config = $container->get(Config::class);
        $fallbackRaw = $config->get('app.timezone', 'UTC');
        $fallback = is_string($fallbackRaw) ? trim($fallbackRaw) : 'UTC';
        if ($fallback === '' || !self::isValidTimezoneId($fallback)) {
            $fallback = 'UTC';
        }

        $fromSettings = '';
        try {
            $settings = $container->get(SettingsService::class);
            $branchContext = $container->get(BranchContext::class);
            $est = $settings->getEstablishmentSettings($branchContext->getCurrentBranchId());
            if (isset($est['timezone'])) {
                $fromSettings = trim((string) $est['timezone']);
            }
        } catch (\Throwable) {
            // Unavailable DB or settings read: use config fallback only.
        }

        if ($fromSettings !== '' && self::isValidTimezoneId($fromSettings)) {
            $resolved = $fromSettings;
        } else {
            $resolved = $fallback;
        }

        date_default_timezone_set($resolved);
        self::$appliedIdentifier = $resolved;
    }

    public static function getAppliedIdentifier(): ?string
    {
        return self::$appliedIdentifier;
    }

    private static function isValidTimezoneId(string $id): bool
    {
        if ($id === '') {
            return false;
        }
        try {
            new \DateTimeZone($id);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
