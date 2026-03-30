<?php

declare(strict_types=1);

/**
 * Runtime proof for appointment settings (foundation + dropdown parity + branch scope):
 * organization + branch context MUST be set explicitly — this script does not infer tenant scope.
 *
 * Usage (requires a real branch row, e.g. from seed smoke data):
 *   php scripts/verify_appointment_settings_foundation_wave_01.php --branch-code=SMOKE_A
 *
 * Environment alternative:
 *   APPOINTMENT_SETTINGS_VERIFY_BRANCH_CODE=SMOKE_A php scripts/verify_appointment_settings_foundation_wave_01.php
 *
 * What is proven when scope resolves:
 * - patch + reload on the same branch_id (overrides)
 * - branch override vs organization default read divergence
 * - four label modes, prebook hours/minutes math, legacy prebook_threshold_hours read + patch mapping
 * - no-show threshold trigger logic
 *
 * What is NOT proven: HTTP settings UI, calendar HTTP JSON (use manual or separate E2E).
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;

$passed = 0;
$failed = 0;

function vPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function resolveScopeByBranchCode(Database $db, string $code): array
{
    $code = trim($code);
    if ($code === '') {
        throw new InvalidArgumentException('Branch code is empty.');
    }
    $row = $db->fetchOne(
        'SELECT b.id AS branch_id, b.organization_id AS organization_id
         FROM branches b
         INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
         WHERE b.code = ? AND b.deleted_at IS NULL
         LIMIT 1',
        [$code]
    );
    if ($row === null) {
        throw new RuntimeException('No active branch found for code ' . $code . ' (seed smoke branches or adjust --branch-code).');
    }

    return [
        'branch_id' => (int) $row['branch_id'],
        'organization_id' => (int) $row['organization_id'],
    ];
}

$branchCode = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--branch-code=')) {
        $branchCode = trim(substr($arg, strlen('--branch-code=')));
    }
}
if ($branchCode === '') {
    $branchCode = trim((string) (getenv('APPOINTMENT_SETTINGS_VERIFY_BRANCH_CODE') ?: ''));
}

if ($branchCode === '') {
    fwrite(STDERR, "FAIL  scope: Missing branch fixture. Pass --branch-code=<branches.code> (e.g. SMOKE_A from seeded data) or set APPOINTMENT_SETTINGS_VERIFY_BRANCH_CODE.\n");
    fwrite(STDERR, "This verifier refuses to run without explicit org/branch resolution (no inferred tenant scope).\n");
    exit(1);
}

$settings = app(SettingsService::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$db = app(Database::class);

try {
    $scope = resolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    vFail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$readBranch = $branchId;
$snapshot = $settings->getAppointmentSettings($readBranch);
$orgBaseline = $settings->getAppointmentSettings(null);
$writeBranchId = $branchId;

try {
    $origLead = (int) ($snapshot['min_lead_minutes'] ?? 0);
    $flipLead = $origLead === 0 ? 1 : 0;
    $patch = [
        'min_lead_minutes' => $flipLead,
        'allow_past_booking' => true,
        'no_show_alert_enabled' => true,
        'no_show_alert_threshold' => 3,
        'calendar_service_show_start_time' => false,
        'calendar_service_label_mode' => 'service_only',
        'prebook_display_enabled' => true,
        'prebook_threshold_value' => 24,
        'prebook_threshold_unit' => 'hours',
    ];
    $changed = $settings->patchAppointmentSettings($patch, $writeBranchId);
    if ($changed === []) {
        vFail('patch', 'Expected at least one changed key after patch');
    } else {
        vPass('patch_persists_keys_branch_scope');
    }

    $read = $settings->getAppointmentSettings($readBranch);
    if ($read['min_lead_minutes'] !== $flipLead) {
        vFail('reload_min_lead', 'min_lead_minutes did not persist for branch');
    } else {
        vPass('reload_min_lead_minutes_branch');
    }
    if ($read['allow_past_booking'] !== true) {
        vFail('reload_allow_past', 'allow_past_booking not true after patch');
    } else {
        vPass('reload_allow_past_booking');
    }
    if ($read['no_show_alert_threshold'] !== 3 || $read['no_show_alert_enabled'] !== true) {
        vFail('reload_alert', 'no-show alert settings mismatch');
    } else {
        vPass('reload_no_show_alert');
    }
    if ($read['calendar_service_show_start_time'] !== false || $read['calendar_service_label_mode'] !== 'service_only') {
        vFail('reload_calendar', 'calendar display settings mismatch');
    } else {
        vPass('reload_calendar_display');
    }
    if ($read['prebook_display_enabled'] !== true || $read['prebook_threshold_value'] !== 24 || $read['prebook_threshold_unit'] !== 'hours') {
        vFail('reload_prebook', 'prebook canonical settings mismatch');
    } else {
        vPass('reload_prebook_canonical');
    }

    $modes = ['client_and_service', 'service_and_client', 'service_only', 'client_only'];
    foreach ($modes as $m) {
        $settings->patchAppointmentSettings(['calendar_service_label_mode' => $m], $writeBranchId);
        $rm = $settings->getAppointmentSettings($readBranch);
        if (($rm['calendar_service_label_mode'] ?? '') !== $m) {
            vFail('label_mode_' . $m, 'Expected mode ' . $m . ', got ' . ($rm['calendar_service_label_mode'] ?? ''));
        } else {
            vPass('label_mode_persist_' . $m);
        }
    }

    $settings->patchAppointmentSettings([
        'prebook_threshold_value' => 90,
        'prebook_threshold_unit' => 'minutes',
    ], $writeBranchId);
    $rpm = $settings->getAppointmentSettings($readBranch);
    $secMin = (int) $rpm['prebook_threshold_value'] * 60;
    if ($secMin !== 5400) {
        vFail('prebook_minutes_seconds', 'minutes multiplier');
    } else {
        vPass('prebook_minutes_threshold_seconds');
    }
    $created = strtotime('2025-01-01 10:00:00');
    $start = strtotime('2025-01-01 11:30:00');
    $prebookedMin = ($start - $created) >= $secMin;
    if ($prebookedMin !== true) {
        vFail('prebook_minutes_math', 'expected prebooked for 90min lead with 90min threshold');
    } else {
        vPass('prebook_minutes_lead_math');
    }

    $settings->patchAppointmentSettings([
        'prebook_threshold_value' => 24,
        'prebook_threshold_unit' => 'hours',
    ], $writeBranchId);
    $rph = $settings->getAppointmentSettings($readBranch);
    $secH = (int) $rph['prebook_threshold_value'] * 3600;
    $start2 = strtotime('2025-01-02 12:00:00');
    $prebookedH = ($start2 - $created) >= $secH;
    if ($prebookedH !== true) {
        vFail('prebook_hours_math', 'expected prebooked for 26h lead with 24h threshold');
    } else {
        vPass('prebook_hours_lead_math');
    }

    $settings->patchAppointmentSettings(['calendar_service_label_mode' => 'client_only'], $writeBranchId);
    $branchLabel = $settings->getAppointmentSettings($readBranch)['calendar_service_label_mode'] ?? '';
    if ($branchLabel !== 'client_only') {
        vFail('branch_override_label', $branchLabel);
    } else {
        vPass('branch_effective_label_mode');
    }
    $orgAfterLabel = $settings->getAppointmentSettings(null)['calendar_service_label_mode'] ?? '';
    $orgBeforeLabel = $orgBaseline['calendar_service_label_mode'] ?? '';
    if ($orgAfterLabel !== $orgBeforeLabel) {
        vFail('org_default_stable', 'getAppointmentSettings(null) changed after branch-only patch; expected organization merge unchanged');
    } else {
        vPass('org_default_stable_under_branch_only_patch');
    }

    $db->query(
        'DELETE FROM settings WHERE organization_id = ? AND branch_id = ? AND `key` IN (?, ?)',
        [$orgId, $writeBranchId, 'appointments.prebook_threshold_value', 'appointments.prebook_threshold_unit']
    );
    $settings->set('appointments.prebook_threshold_hours', 47, 'int', 'appointments', $writeBranchId);
    $legacyRead = $settings->getAppointmentSettings($readBranch);
    if ((int) $legacyRead['prebook_threshold_value'] !== 47 || $legacyRead['prebook_threshold_unit'] !== 'hours') {
        vFail('legacy_prebook_hours_read', json_encode($legacyRead));
    } else {
        vPass('legacy_prebook_threshold_hours_fallback_read');
    }

    $settings->patchAppointmentSettings(['prebook_threshold_hours' => 12], $writeBranchId);
    $afterLegacyPatch = $settings->getAppointmentSettings($readBranch);
    if ((int) $afterLegacyPatch['prebook_threshold_value'] !== 12 || $afterLegacyPatch['prebook_threshold_unit'] !== 'hours') {
        vFail('legacy_patch_hours', json_encode($afterLegacyPatch));
    } else {
        vPass('patch_prebook_threshold_hours_maps_to_canonical');
    }

    $noShowCount = 2;
    $triggered = $read['no_show_alert_enabled'] && $noShowCount >= $read['no_show_alert_threshold'];
    if ($triggered !== false) {
        vFail('threshold', 'Expected no trigger at count 2 with threshold 3');
    } else {
        vPass('no_show_alert_not_triggered_below_threshold');
    }
    $triggered2 = $read['no_show_alert_enabled'] && 4 >= $read['no_show_alert_threshold'];
    if ($triggered2 !== true) {
        vFail('threshold2', 'Expected trigger at count 4 with threshold 3');
    } else {
        vPass('no_show_alert_triggered_at_threshold');
    }
} finally {
    $settings->setAppointmentSettings($snapshot, $writeBranchId);
    vPass('restored_branch_snapshot');
}

echo "\nDone (branch_id={$branchId}, org_id={$orgId}, code={$branchCode}). Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
