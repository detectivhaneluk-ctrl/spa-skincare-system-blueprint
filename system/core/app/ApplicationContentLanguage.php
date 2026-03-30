<?php

declare(strict_types=1);

namespace Core\App;

use Core\Branch\BranchContext;

/**
 * Emits branch-effective HTTP `Content-Language` from stored `establishment.language` (merged via {@see SettingsService::getEstablishmentSettings}).
 *
 * Runs after {@see \Core\Middleware\BranchContextMiddleware} so authenticated staff use the resolved branch overlay; guests use global (`branch_id = 0`) merge only.
 * Does not set PHP locales — header only.
 */
final class ApplicationContentLanguage
{
    private static bool $applied = false;

    public static function applyAfterBranchContextResolved(): void
    {
        if (self::$applied) {
            return;
        }
        self::$applied = true;
        if (headers_sent()) {
            return;
        }
        try {
            $settings = Application::container()->get(SettingsService::class);
            $branchId = Application::container()->get(BranchContext::class)->getCurrentBranchId();
            $tag = $settings->getEffectiveEstablishmentLanguageTag($branchId);
            if ($tag !== null && $tag !== '') {
                header('Content-Language: ' . $tag);
            }
        } catch (\Throwable) {
            // DB/settings unavailable: omit header.
        }
    }
}
