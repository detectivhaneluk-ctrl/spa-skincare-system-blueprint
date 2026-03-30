<?php

declare(strict_types=1);

namespace Modules\OnlineBooking\Repositories;

use Core\App\Database;

/**
 * Persistence for {@see \Modules\OnlineBooking\Services\PublicBookingAbuseGuardService} (public booking, commerce, intake buckets).
 */
final class PublicBookingAbuseGuardRepository
{
    public function __construct(private Database $db)
    {
    }

    public function pruneExpired(int $maxWindowSeconds): void
    {
        $this->db->query(
            'DELETE FROM public_booking_abuse_hits WHERE created_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)',
            [$maxWindowSeconds]
        );
    }

    /**
     * @return array{count: int, oldest_unix: int|null}
     */
    public function getWindowStats(string $bucket, string $throttleKey, int $windowSeconds): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c, UNIX_TIMESTAMP(MIN(created_at)) AS oldest_unix
             FROM public_booking_abuse_hits
             WHERE bucket = ?
               AND throttle_key = ?
               AND created_at > (UTC_TIMESTAMP() - INTERVAL ? SECOND)',
            [$bucket, $throttleKey, $windowSeconds]
        );

        return [
            'count' => (int) ($row['c'] ?? 0),
            'oldest_unix' => isset($row['oldest_unix']) ? (int) $row['oldest_unix'] : null,
        ];
    }

    public function addHit(string $bucket, string $throttleKey): void
    {
        $this->db->query(
            'INSERT INTO public_booking_abuse_hits (bucket, throttle_key, created_at) VALUES (?, ?, UTC_TIMESTAMP())',
            [$bucket, $throttleKey]
        );
    }

    /**
     * Named session lock (max 64 chars). Serialize count+insert for one bucket/throttle_key pair.
     *
     * @return bool True if lock acquired
     */
    public function acquireThrottleLock(string $lockName, int $timeoutSeconds): bool
    {
        $row = $this->db->fetchOne('SELECT GET_LOCK(?, ?) AS acquired', [$lockName, $timeoutSeconds]);

        return isset($row['acquired']) && (int) $row['acquired'] === 1;
    }

    public function releaseThrottleLock(string $lockName): void
    {
        $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
    }
}
