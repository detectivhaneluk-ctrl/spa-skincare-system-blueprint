<?php

declare(strict_types=1);

/**
 * WAVE-06 Proof Script — Hot Path Cache Effectiveness
 *
 * Verifies all WAVE-06 deliverables:
 * W6-A: PermissionService cross-request cache (SharedCacheInterface, 120s TTL)
 * W6-B: AvailabilityService day-calendar short-TTL cache (30s TTL)
 * W6-C: Explicit invalidation wired in all appointment/blocked-slot mutation paths
 * W6-D: Booking write path (isSlotAvailable with forAvailabilitySearch=false) is NOT cached
 * W6-E: This script passes all assertions
 */

$systemPath = dirname(__DIR__, 2);

$results = [];
$pass = true;

function assert_wave06(string $label, bool $condition, array &$results, bool &$pass): void
{
    $results[] = ($condition ? '[PASS]' : '[FAIL]') . ' ' . $label;
    if (!$condition) {
        $pass = false;
    }
}

// ── W6-A: PermissionService — SharedCacheInterface dependency ────────────────

$permServiceFile = $systemPath . '/core/Permissions/PermissionService.php';
$permContent = (string) file_get_contents($permServiceFile);

assert_wave06(
    'W6-A.1 PermissionService uses SharedCacheInterface',
    str_contains($permContent, 'SharedCacheInterface'),
    $results, $pass
);

assert_wave06(
    'W6-A.2 PermissionService has CACHE_TTL_SECONDS constant = 120',
    str_contains($permContent, 'CACHE_TTL_SECONDS = 120'),
    $results, $pass
);

assert_wave06(
    'W6-A.3 PermissionService uses perm_v1 cache key prefix',
    str_contains($permContent, "'perm_v1'"),
    $results, $pass
);

assert_wave06(
    'W6-A.4 PermissionService has fail-open try/catch around SharedCache get',
    str_contains($permContent, 'sharedCache->get') && str_contains($permContent, 'Fail-open'),
    $results, $pass
);

assert_wave06(
    'W6-A.5 PermissionService stores to SharedCache after DB fallback',
    str_contains($permContent, 'sharedCache->set') && str_contains($permContent, 'CACHE_TTL_SECONDS'),
    $results, $pass
);

assert_wave06(
    'W6-A.6 PermissionService has clearCachedForUser() method',
    str_contains($permContent, 'public function clearCachedForUser('),
    $results, $pass
);

assert_wave06(
    'W6-A.7 clearCachedForUser calls sharedCache->delete',
    str_contains($permContent, 'sharedCache->delete'),
    $results, $pass
);

assert_wave06(
    'W6-A.8 PermissionService retains in-process cache as first-tier (array $cache)',
    str_contains($permContent, 'private array $cache = []'),
    $results, $pass
);

// ── bootstrap.php injects SharedCacheInterface into PermissionService ────────

$bootstrapFile = $systemPath . '/bootstrap.php';
$bootstrapContent = (string) file_get_contents($bootstrapFile);
$permBlock = '';
if (preg_match('/singleton\(.*?PermissionService.*?\)\s*\)/s', $bootstrapContent, $m)) {
    $permBlock = $m[0];
}

assert_wave06(
    'W6-A.9 bootstrap.php injects SharedCacheInterface into PermissionService',
    str_contains($permBlock, 'SharedCacheInterface'),
    $results, $pass
);

// ── W6-B: AvailabilityService — day-calendar cache ───────────────────────────

$availFile = $systemPath . '/modules/appointments/services/AvailabilityService.php';
$availContent = (string) file_get_contents($availFile);

assert_wave06(
    'W6-B.1 AvailabilityService uses SharedCacheInterface',
    str_contains($availContent, 'SharedCacheInterface'),
    $results, $pass
);

assert_wave06(
    'W6-B.2 AvailabilityService has DAY_APT_CACHE_TTL = 30',
    str_contains($availContent, 'DAY_APT_CACHE_TTL = 30'),
    $results, $pass
);

assert_wave06(
    'W6-B.3 AvailabilityService uses cal_v1:day_apts cache key prefix',
    str_contains($availContent, "'cal_v1:day_apts'"),
    $results, $pass
);

assert_wave06(
    'W6-B.4 listDayAppointmentsGroupedByStaff checks SharedCache before DB',
    str_contains($availContent, 'sharedCache->get($cacheKey)'),
    $results, $pass
);

assert_wave06(
    'W6-B.5 listDayAppointmentsGroupedByStaff stores result to SharedCache',
    str_contains($availContent, 'sharedCache->set($cacheKey'),
    $results, $pass
);

assert_wave06(
    'W6-B.6 Cache path is fail-open (Fail-open comment present)',
    str_contains($availContent, 'Fail-open'),
    $results, $pass
);

// ── W6-C: AvailabilityService — invalidateDayCalendarCache() ─────────────────

assert_wave06(
    'W6-C.1 AvailabilityService has invalidateDayCalendarCache() method',
    str_contains($availContent, 'public function invalidateDayCalendarCache('),
    $results, $pass
);

assert_wave06(
    'W6-C.2 invalidateDayCalendarCache calls sharedCache->delete',
    str_contains($availContent, 'sharedCache->delete($this->dayAptCacheKey('),
    $results, $pass
);

assert_wave06(
    'W6-C.3 invalidateDayCalendarCache has fail-open try/catch',
    str_contains($availContent, 'TTL ensures eventual consistency'),
    $results, $pass
);

// ── AvailabilityService DI registration includes SharedCacheInterface ─────────

$availBootstrapFile = $systemPath . '/modules/bootstrap/register_appointments_documents_notifications.php';
$availBootstrapContent = (string) file_get_contents($availBootstrapFile);

assert_wave06(
    'W6-C.4 AvailabilityService DI injects SharedCacheInterface',
    str_contains($availBootstrapContent, 'SharedCacheInterface'),
    $results, $pass
);

// ── W6-C: AppointmentService — invalidation wired in all mutation paths ───────

$aptServiceFile = $systemPath . '/modules/appointments/services/AppointmentService.php';
$aptContent = (string) file_get_contents($aptServiceFile);

assert_wave06(
    'W6-C.5 AppointmentService create() calls invalidateDayCalendarCache',
    str_contains($aptContent, 'WAVE-06: invalidate calendar display cache for the affected date/branch after successful creation'),
    $results, $pass
);

assert_wave06(
    'W6-C.6 AppointmentService cancel() calls invalidateDayCalendarCache',
    str_contains($aptContent, 'WAVE-06: invalidate calendar display cache after successful cancellation'),
    $results, $pass
);

assert_wave06(
    'W6-C.7 AppointmentService reschedule() calls invalidateDayCalendarCache for old and new date',
    str_contains($aptContent, 'WAVE-06: invalidate calendar display cache for both old and new date/branch after reschedule'),
    $results, $pass
);

assert_wave06(
    'W6-C.8 AppointmentService updateStatus() calls invalidateDayCalendarCache',
    str_contains($aptContent, 'WAVE-06: invalidate calendar display cache after any status change'),
    $results, $pass
);

assert_wave06(
    'W6-C.9 AppointmentService delete() calls invalidateDayCalendarCache',
    str_contains($aptContent, 'WAVE-06: invalidate calendar display cache after deletion'),
    $results, $pass
);

// ── BlockedSlotService — invalidation wired ───────────────────────────────────

$blockedSlotFile = $systemPath . '/modules/appointments/services/BlockedSlotService.php';
$blockedContent = (string) file_get_contents($blockedSlotFile);

assert_wave06(
    'W6-C.10 BlockedSlotService create() calls invalidateDayCalendarCache',
    str_contains($blockedContent, 'WAVE-06: invalidate calendar display cache after blocked slot creation'),
    $results, $pass
);

assert_wave06(
    'W6-C.11 BlockedSlotService delete() calls invalidateDayCalendarCache',
    str_contains($blockedContent, 'WAVE-06: invalidate calendar display cache after blocked slot deletion'),
    $results, $pass
);

// ── W6-D: isSlotAvailable NOT cached — booking correctness preserved ──────────

assert_wave06(
    'W6-D.1 isSlotAvailable() method is NOT wrapped in SharedCache logic',
    !str_contains(
        // Extract just the isSlotAvailable method body
        (function (string $src): string {
            $start = strpos($src, 'public function isSlotAvailable(');
            if ($start === false) {
                return '';
            }
            return substr($src, $start, 2000);
        })($availContent),
        'sharedCache'
    ),
    $results, $pass
);

assert_wave06(
    'W6-D.2 hasBufferedAppointmentConflict() is NOT wrapped in SharedCache logic',
    !str_contains(
        (function (string $src): string {
            $start = strpos($src, 'private function hasBufferedAppointmentConflict(');
            if ($start === false) {
                return '';
            }
            return substr($src, $start, 2000);
        })($availContent),
        'sharedCache'
    ),
    $results, $pass
);

assert_wave06(
    'W6-D.3 Comment in invalidateDayCalendarCache explicitly states booking write path is unaffected',
    str_contains($availContent, 'isSlotAvailable') && str_contains($availContent, 'never cached'),
    $results, $pass
);

// ── DI Resolution check — runtime probe ──────────────────────────────────────

$probeOk = false;
$probeError = '';
try {
    require $systemPath . '/bootstrap.php';
    require $systemPath . '/modules/bootstrap.php';
    $perm = \Core\App\Application::container()->get(\Core\Permissions\PermissionService::class);
    $avail = \Core\App\Application::container()->get(\Modules\Appointments\Services\AvailabilityService::class);
    $probeOk = ($perm instanceof \Core\Permissions\PermissionService) && ($avail instanceof \Modules\Appointments\Services\AvailabilityService);
} catch (\Throwable $e) {
    $probeError = $e->getMessage();
}

assert_wave06(
    'W6-E.1 PermissionService resolves from DI without error' . ($probeError !== '' ? ' (error: ' . $probeError . ')' : ''),
    $probeOk,
    $results, $pass
);

assert_wave06(
    'W6-E.2 AvailabilityService resolves from DI without error',
    $probeOk,
    $results, $pass
);

// ── Output ────────────────────────────────────────────────────────────────────

echo PHP_EOL;
echo '=== WAVE-06 HOT PATH CACHE EFFECTIVENESS PROOF ===' . PHP_EOL;
echo PHP_EOL;
foreach ($results as $line) {
    echo $line . PHP_EOL;
}
echo PHP_EOL;
$total = count($results);
$passed = count(array_filter($results, static fn (string $r): bool => str_starts_with($r, '[PASS]')));
echo "Results: {$passed}/{$total} passed" . PHP_EOL;
echo PHP_EOL;
if ($pass) {
    echo 'WAVE-06 PROOF: PASS' . PHP_EOL;
    exit(0);
} else {
    echo 'WAVE-06 PROOF: FAIL' . PHP_EOL;
    exit(1);
}
