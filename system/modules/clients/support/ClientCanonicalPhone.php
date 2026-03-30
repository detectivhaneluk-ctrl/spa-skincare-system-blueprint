<?php

declare(strict_types=1);

namespace Modules\Clients\Support;

/**
 * Single server-side rule for the primary phone column and display: first non-empty of
 * mobile → home → work → legacy {@code clients.phone}.
 *
 * @see system/docs/CLIENT-BACKEND-CONTRACT-FREEZE.md §3
 */
final class ClientCanonicalPhone
{
    /**
     * Value to persist on {@code clients.phone} and to use for duplicate matching when a single string is needed.
     *
     * @param array<string, mixed> $data Incoming or row fragment with phone_* keys
     */
    public static function resolvePrimaryForPersistence(array $data, ?array $current = null): ?string
    {
        $mobile = trim((string) ($data['phone_mobile'] ?? ''));
        if ($mobile !== '') {
            return $mobile;
        }
        $home = trim((string) ($data['phone_home'] ?? ''));
        if ($home !== '') {
            return $home;
        }
        $work = trim((string) ($data['phone_work'] ?? ''));
        if ($work !== '') {
            return $work;
        }
        if (array_key_exists('phone', $data)) {
            $p = trim((string) $data['phone']);
            if ($p !== '') {
                return $p;
            }
        }
        if ($current !== null) {
            foreach (['phone_mobile', 'phone_home', 'phone_work', 'phone'] as $k) {
                $t = trim((string) ($current[$k] ?? ''));
                if ($t !== '') {
                    return $t;
                }
            }
        }

        return null;
    }

    /**
     * Display / summary helper from a full client row (read path).
     *
     * @param array<string, mixed> $client
     */
    public static function displayPrimary(array $client): ?string
    {
        return self::resolvePrimaryForPersistence($client, null);
    }
}
