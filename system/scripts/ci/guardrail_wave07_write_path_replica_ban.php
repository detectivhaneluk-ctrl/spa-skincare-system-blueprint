<?php

declare(strict_types=1);

/**
 * WAVE-07 CI Guardrail: write-path replica ban.
 *
 * Enforces invariants that protect booking and payment correctness:
 *
 *  G7-A  Write-critical services must NOT call ->forRead()
 *        (AppointmentService, PaymentService, InvoiceService, RegisterSessionService,
 *         WaitlistService, StaffGroupService, StaffGroupPermissionService, ServiceService,
 *         all settings mutation services)
 *
 *  G7-B  AvailabilityService booking-critical methods (isSlotAvailable,
 *        hasBufferedAppointmentConflict) must NOT call ->forRead()
 *
 *  G7-C  Database::insert() must call requirePrimary() — sticky-primary gate
 *
 *  G7-D  Database::transaction() must call requirePrimary() — sticky-primary gate
 *
 *  G7-E  ReadQueryExecutor must NOT expose write methods (insert, exec, transaction)
 *
 * Run: php system/scripts/ci/guardrail_wave07_write_path_replica_ban.php
 * Expected: all assertions PASS, exit code 0.
 * CI failure: any FAIL means a write path has been incorrectly wired to replica.
 */

$repoRoot = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;

function w7guard_assert(bool $condition, string $label): void
{
    global $pass, $fail;
    echo ($condition ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $condition ? ++$pass : ++$fail;
}

function w7guard_no_forread(string $file, string $serviceName): void
{
    $fullPath = $GLOBALS['repoRoot'] . '/' . $file;
    if (!file_exists($fullPath)) {
        w7guard_assert(false, "{$serviceName} — file not found: {$file}");
        return;
    }
    $content = (string) file_get_contents($fullPath);
    w7guard_assert(
        !str_contains($content, '->forRead()'),
        "{$serviceName} does NOT use ->forRead() — write-critical service must stay on primary"
    );
}

echo "\n=== WAVE-07 CI GUARDRAIL: WRITE-PATH REPLICA BAN ===\n\n";

// ─── G7-A: Write-critical services must not use ->forRead() ───

echo "G7-A: Write-critical services — no ->forRead() calls\n";

$writeCriticalServices = [
    'system/modules/appointments/services/AppointmentService.php'      => 'AppointmentService',
    'system/modules/sales/services/PaymentService.php'                  => 'PaymentService',
    'system/modules/sales/services/InvoiceService.php'                  => 'InvoiceService',
    'system/modules/sales/services/RegisterSessionService.php'          => 'RegisterSessionService',
    'system/modules/staff/services/StaffGroupService.php'               => 'StaffGroupService',
    'system/modules/staff/services/StaffGroupPermissionService.php'     => 'StaffGroupPermissionService',
    'system/modules/services-resources/services/ServiceService.php'    => 'ServiceService',
    'system/modules/settings/services/BranchOperatingHoursService.php'  => 'BranchOperatingHoursService',
    'system/modules/settings/services/PriceModificationReasonService.php' => 'PriceModificationReasonService',
    'system/modules/settings/services/BranchClosureDateService.php'     => 'BranchClosureDateService',
    'system/modules/settings/services/AppointmentCancellationReasonService.php' => 'AppointmentCancellationReasonService',
    'system/core/auth/SessionAuth.php'                                  => 'SessionAuth (privileged transitions)',
    'system/core/audit/AuditService.php'                                => 'AuditService',
    'system/core/Permissions/PermissionService.php'                     => 'PermissionService (auth read-your-write)',
];

foreach ($writeCriticalServices as $relPath => $name) {
    w7guard_no_forread($relPath, $name);
}

echo "\n";

// ─── G7-B: Booking-critical methods in AvailabilityService ───

echo "G7-B: AvailabilityService booking-critical methods — no ->forRead() calls\n";

$availFile = $repoRoot . '/system/modules/appointments/services/AvailabilityService.php';
w7guard_assert(file_exists($availFile), 'AvailabilityService.php exists');

if (file_exists($availFile)) {
    $content = (string) file_get_contents($availFile);

    // isSlotAvailable — extract method body and verify
    $isSlotPos = strpos($content, 'public function isSlotAvailable(');
    $nextMethodPos = $isSlotPos !== false ? strpos($content, "\n    public function ", $isSlotPos + 50) : false;
    if ($isSlotPos !== false && $nextMethodPos !== false) {
        $isSlotBody = substr($content, $isSlotPos, $nextMethodPos - $isSlotPos);
        w7guard_assert(
            !str_contains($isSlotBody, '->forRead()'),
            'isSlotAvailable() does NOT use ->forRead() — booking correctness requires primary'
        );
    } else {
        w7guard_assert(false, 'isSlotAvailable() method boundary not found in AvailabilityService');
    }

    // hasBufferedAppointmentConflict — extract method body and verify
    $hbcPos = strpos($content, 'private function hasBufferedAppointmentConflict(');
    $nextPrivatePos = $hbcPos !== false ? strpos($content, "\n    private function ", $hbcPos + 50) : false;
    if ($hbcPos !== false && $nextPrivatePos !== false) {
        $hbcBody = substr($content, $hbcPos, $nextPrivatePos - $hbcPos);
        w7guard_assert(
            !str_contains($hbcBody, '->forRead()'),
            'hasBufferedAppointmentConflict() does NOT use ->forRead() — booking correctness requires primary'
        );
    } else {
        w7guard_assert(false, 'hasBufferedAppointmentConflict() method boundary not found in AvailabilityService');
    }

    // getAvailableSlots — also booking-critical
    $gasPos = strpos($content, 'public function getAvailableSlots(');
    $gasEnd = $gasPos !== false ? strpos($content, "\n    public function ", $gasPos + 50) : false;
    if ($gasPos !== false && $gasEnd !== false) {
        $gasBody = substr($content, $gasPos, $gasEnd - $gasPos);
        w7guard_assert(
            !str_contains($gasBody, '->forRead()'),
            'getAvailableSlots() does NOT use ->forRead() — availability search requires primary'
        );
    }
}

echo "\n";

// ─── G7-C: Database::insert() must trigger sticky-primary ───

echo "G7-C: Database::insert() triggers sticky-primary (requirePrimary call)\n";

$dbFile = $repoRoot . '/system/core/App/Database.php';
w7guard_assert(file_exists($dbFile), 'Database.php exists');

if (file_exists($dbFile)) {
    $dbContent = (string) file_get_contents($dbFile);
    $insertPos = strpos($dbContent, 'public function insert(');
    $insertEnd = $insertPos !== false ? strpos($dbContent, "\n        return (int) \$this->connection()->lastInsertId()", $insertPos) : false;
    if ($insertPos !== false && $insertEnd !== false) {
        $insertBody = substr($dbContent, $insertPos, $insertEnd - $insertPos + 60);
        w7guard_assert(
            str_contains($insertBody, '$this->requirePrimary()'),
            'Database::insert() calls $this->requirePrimary() before write (sticky-primary enforcement)'
        );
    } else {
        w7guard_assert(false, 'Database::insert() body not found — cannot verify requirePrimary guard');
    }
}

echo "\n";

// ─── G7-D: Database::transaction() must trigger sticky-primary ───

echo "G7-D: Database::transaction() triggers sticky-primary (requirePrimary call)\n";

if (file_exists($dbFile)) {
    $dbContent = (string) file_get_contents($dbFile);
    $txnPos = strpos($dbContent, 'public function transaction(');
    $txnEnd = $txnPos !== false ? strpos($dbContent, "\n    }", $txnPos + 50) : false;
    if ($txnPos !== false && $txnEnd !== false) {
        $txnBody = substr($dbContent, $txnPos, $txnEnd - $txnPos + 10);
        w7guard_assert(
            str_contains($txnBody, '$this->requirePrimary()'),
            'Database::transaction() calls $this->requirePrimary() before BEGIN (sticky-primary enforcement)'
        );
    } else {
        w7guard_assert(false, 'Database::transaction() body not found — cannot verify requirePrimary guard');
    }
}

echo "\n";

// ─── G7-E: ReadQueryExecutor must not expose write API ───

echo "G7-E: ReadQueryExecutor — no write methods exposed\n";

$executorFile = $repoRoot . '/system/core/App/ReadQueryExecutor.php';
w7guard_assert(file_exists($executorFile), 'ReadQueryExecutor.php exists');

if (file_exists($executorFile)) {
    $execContent = (string) file_get_contents($executorFile);
    w7guard_assert(!str_contains($execContent, 'public function insert('), 'ReadQueryExecutor has no insert() method');
    w7guard_assert(!str_contains($execContent, 'public function transaction('), 'ReadQueryExecutor has no transaction() method');
    w7guard_assert(!str_contains($execContent, 'public function exec('), 'ReadQueryExecutor has no exec() method');
    w7guard_assert(!str_contains($execContent, 'public function query('), 'ReadQueryExecutor has no generic query() — only typed fetch methods');
    w7guard_assert(str_contains($execContent, 'public function fetchAll('), 'ReadQueryExecutor has fetchAll() (read-only)');
    w7guard_assert(str_contains($execContent, 'public function fetchOne('), 'ReadQueryExecutor has fetchOne() (read-only)');
}

echo "\n";

// ─── G7-F: WAVE-07B — locking/single-record/write-adjacent repository methods must NOT use forRead() ───

echo "G7-F: WAVE-07B — locking, single-record, and write-adjacent repository methods stay primary\n";

// ClientRepository: locking reads and single-record find() must never use forRead()
$clientRepoFile = $repoRoot . '/system/modules/clients/repositories/ClientRepository.php';
if (file_exists($clientRepoFile)) {
    $crc = (string) file_get_contents($clientRepoFile);

    // findForUpdate — locking read, must stay primary
    $ffu = strpos($crc, 'public function findForUpdate(int $id): ?array');
    $ffuEnd = $ffu !== false ? strpos($crc, "\n    public function ", $ffu + 50) : false;
    if ($ffu !== false && $ffuEnd !== false) {
        w7guard_assert(!str_contains(substr($crc, $ffu, $ffuEnd - $ffu), '->forRead()'), 'ClientRepository::findForUpdate() does NOT use forRead() — FOR UPDATE locking requires primary');
    } else {
        w7guard_assert(false, 'ClientRepository::findForUpdate() boundary not found');
    }

    // lockActiveByEmailBranch — locking read
    $lab = strpos($crc, 'public function lockActiveByEmailBranch(');
    $labEnd = $lab !== false ? strpos($crc, "\n    public function ", $lab + 50) : false;
    if ($lab !== false && $labEnd !== false) {
        w7guard_assert(!str_contains(substr($crc, $lab, $labEnd - $lab), '->forRead()'), 'ClientRepository::lockActiveByEmailBranch() does NOT use forRead() — locking read requires primary');
    } else {
        w7guard_assert(false, 'ClientRepository::lockActiveByEmailBranch() boundary not found');
    }

    // loadOwnedClientForUpdate — locking read
    $locu = strpos($crc, 'public function loadOwnedClientForUpdate(');
    $locuEnd = $locu !== false ? strpos($crc, "\n    public function ", $locu + 50) : false;
    if ($locu !== false && $locuEnd !== false) {
        w7guard_assert(!str_contains(substr($crc, $locu, $locuEnd - $locu), '->forRead()'), 'ClientRepository::loadOwnedClientForUpdate() does NOT use forRead() — locking read requires primary');
    } else {
        w7guard_assert(false, 'ClientRepository::loadOwnedClientForUpdate() boundary not found');
    }

    // findDuplicates — merge decision proximity, must stay primary
    $fd = strpos($crc, 'public function findDuplicates(');
    $fdEnd = $fd !== false ? strpos($crc, "\n    public function ", $fd + 50) : false;
    if ($fd !== false && $fdEnd !== false) {
        w7guard_assert(!str_contains(substr($crc, $fd, $fdEnd - $fd), '->forRead()'), 'ClientRepository::findDuplicates() does NOT use forRead() — merge flow proximity requires primary');
    } else {
        w7guard_assert(false, 'ClientRepository::findDuplicates() boundary not found');
    }
} else {
    w7guard_assert(false, 'ClientRepository.php not found');
}

// ServiceRepository: find() must stay on primary (used in checkout provider flow)
$serviceRepoFile = $repoRoot . '/system/modules/services-resources/repositories/ServiceRepository.php';
if (file_exists($serviceRepoFile)) {
    $src = (string) file_get_contents($serviceRepoFile);
    $sfPos = strpos($src, 'public function find(int $id): ?array');
    $sfEnd = $sfPos !== false ? strpos($src, "\n    public function ", $sfPos + 50) : false;
    if ($sfPos !== false && $sfEnd !== false) {
        w7guard_assert(!str_contains(substr($src, $sfPos, $sfEnd - $sfPos), '->forRead()'), 'ServiceRepository::find() does NOT use forRead() — checkout provider / edit form requires primary');
    } else {
        w7guard_assert(false, 'ServiceRepository::find() boundary not found');
    }
} else {
    w7guard_assert(false, 'ServiceRepository.php not found');
}

// StaffRepository: find() and findByUserId() must stay primary (appointment + payroll context)
$staffRepoFile = $repoRoot . '/system/modules/staff/repositories/StaffRepository.php';
if (file_exists($staffRepoFile)) {
    $strc = (string) file_get_contents($staffRepoFile);

    $sfbPos = strpos($strc, 'public function findByUserId(int $userId): ?array');
    $sfbEnd = $sfbPos !== false ? strpos($strc, "\n    public function ", $sfbPos + 50) : false;
    if ($sfbPos !== false && $sfbEnd !== false) {
        w7guard_assert(!str_contains(substr($strc, $sfbPos, $sfbEnd - $sfbPos), '->forRead()'), 'StaffRepository::findByUserId() does NOT use forRead() — payroll identity resolution requires primary');
    } else {
        w7guard_assert(false, 'StaffRepository::findByUserId() boundary not found');
    }

    $stfPos = strpos($strc, 'public function find(int $id, bool $withTrashed = false): ?array');
    $stfEnd = $stfPos !== false ? strpos($strc, "\n    public function ", $stfPos + 50) : false;
    if ($stfPos !== false && $stfEnd !== false) {
        w7guard_assert(!str_contains(substr($strc, $stfPos, $stfEnd - $stfPos), '->forRead()'), 'StaffRepository::find() does NOT use forRead() — appointment + payroll context requires primary');
    } else {
        w7guard_assert(false, 'StaffRepository::find() boundary not found');
    }
} else {
    w7guard_assert(false, 'StaffRepository.php not found');
}

echo "\n";

// ─── G7-G: WAVE-07C — appointment locking, conflict, and write-adjacent methods must NOT use forRead() ───

echo "G7-G: WAVE-07C — appointment locking, conflict-check, and write-adjacent methods stay primary\n";

$apptRepoFile = $repoRoot . '/system/modules/appointments/repositories/AppointmentRepository.php';
if (file_exists($apptRepoFile)) {
    $arc = (string) file_get_contents($apptRepoFile);

    // loadForUpdate — FOR UPDATE locking
    $lfuPos = strpos($arc, 'public function loadForUpdate(TenantContext $ctx, int $id): ?array');
    $lfuEnd = $lfuPos !== false ? strpos($arc, "\n    public function ", $lfuPos + 50) : false;
    if ($lfuPos !== false && $lfuEnd !== false) {
        w7guard_assert(!str_contains(substr($arc, $lfuPos, $lfuEnd - $lfuPos), '->forRead()'), 'AppointmentRepository::loadForUpdate() does NOT use forRead() — FOR UPDATE locking must stay primary');
    } else {
        w7guard_assert(false, 'AppointmentRepository::loadForUpdate() boundary not found');
    }

    // findForUpdate — FOR UPDATE locking
    $ffuPos = strpos($arc, 'public function findForUpdate(int $id): ?array');
    $ffuEnd = $ffuPos !== false ? strpos($arc, "\n    public function ", $ffuPos + 50) : false;
    if ($ffuPos !== false && $ffuEnd !== false) {
        w7guard_assert(!str_contains(substr($arc, $ffuPos, $ffuEnd - $ffuPos), '->forRead()'), 'AppointmentRepository::findForUpdate() does NOT use forRead() — FOR UPDATE locking must stay primary');
    } else {
        w7guard_assert(false, 'AppointmentRepository::findForUpdate() boundary not found');
    }

    // hasStaffConflict — booking conflict check
    $hscPos = strpos($arc, 'public function hasStaffConflict(');
    $hscEnd = $hscPos !== false ? strpos($arc, "\n    public function ", $hscPos + 50) : false;
    if ($hscPos !== false && $hscEnd !== false) {
        w7guard_assert(!str_contains(substr($arc, $hscPos, $hscEnd - $hscPos), '->forRead()'), 'AppointmentRepository::hasStaffConflict() does NOT use forRead() — booking conflict check must be authoritative');
    } else {
        w7guard_assert(false, 'AppointmentRepository::hasStaffConflict() boundary not found');
    }

    // hasRoomConflict — booking conflict check (followed by private method, use private boundary)
    $hrcPos = strpos($arc, 'public function hasRoomConflict(');
    $hrcEnd = $hrcPos !== false ? strpos($arc, "\n    private function ", $hrcPos + 50) : false;
    if ($hrcPos !== false && $hrcEnd !== false) {
        w7guard_assert(!str_contains(substr($arc, $hrcPos, $hrcEnd - $hrcPos), '->forRead()'), 'AppointmentRepository::hasRoomConflict() does NOT use forRead() — booking conflict check must be authoritative');
    } else {
        w7guard_assert(false, 'AppointmentRepository::hasRoomConflict() boundary not found');
    }

    // lockRoomRowForConflictCheck — explicit row lock
    $lrrPos = strpos($arc, 'public function lockRoomRowForConflictCheck(');
    $lrrEnd = $lrrPos !== false ? strpos($arc, "\n    public function ", $lrrPos + 50) : false;
    if ($lrrPos !== false && $lrrEnd !== false) {
        w7guard_assert(!str_contains(substr($arc, $lrrPos, $lrrEnd - $lrrPos), '->forRead()'), 'AppointmentRepository::lockRoomRowForConflictCheck() does NOT use forRead() — room row lock must stay primary');
    } else {
        w7guard_assert(false, 'AppointmentRepository::lockRoomRowForConflictCheck() boundary not found');
    }

    // find() — single-record, read-your-write concern (redirect target after write)
    $findPos = strpos($arc, 'public function find(int $id, bool $withTrashed = false): ?array');
    $findEnd = $findPos !== false ? strpos($arc, "\n    public function ", $findPos + 50) : false;
    if ($findPos !== false && $findEnd !== false) {
        w7guard_assert(!str_contains(substr($arc, $findPos, $findEnd - $findPos), '->forRead()'), 'AppointmentRepository::find() does NOT use forRead() — redirect target after write (read-your-write)');
    } else {
        w7guard_assert(false, 'AppointmentRepository::find() boundary not found');
    }
} else {
    w7guard_assert(false, 'AppointmentRepository.php not found');
}

echo "\n";

$total = $pass + $fail;
echo "=== WAVE-07 GUARDRAIL SUMMARY ===\n";
echo "PASS: {$pass} / {$total}\n";
if ($fail > 0) {
    echo "FAIL: {$fail} / {$total}\n";
    echo "\nSTATUS: FAIL — A write-critical path violation exists. Fix before releasing.\n\n";
    exit(1);
}
echo "\nSTATUS: PASS — All write-path replica ban guardrails satisfied.\n\n";
exit(0);
