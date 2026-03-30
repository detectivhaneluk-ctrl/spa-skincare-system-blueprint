<?php

declare(strict_types=1);

/**
 * APPOINTMENT-NO-SHOW-ALERT-OPERATIONALIZATION-01: structured no_show_alert on getSummary + setting gates.
 *
 * From system/:
 *   php scripts/verify_appointment_no_show_alert_operationalization_01.php --branch-code=SMOKE_A
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Contracts\ClientAppointmentProfileProvider;
use Core\Organization\OrganizationContext;

$passed = 0;
$failed = 0;

function vNsPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vNsFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function vNsResolveScopeByBranchCode(Database $db, string $code): array
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
        throw new RuntimeException('No active branch found for code ' . $code);
    }

    return [
        'branch_id' => (int) $row['branch_id'],
        'organization_id' => (int) $row['organization_id'],
    ];
}

/**
 * @param array<string, mixed> $alert
 */
function vNsAssertAlertShape(array $alert, string $ctx): bool
{
    $need = ['active', 'code', 'severity', 'settings_enabled', 'recorded_no_show_count', 'threshold', 'message'];
    foreach ($need as $k) {
        if (!array_key_exists($k, $alert)) {
            fwrite(STDERR, "FAIL  alert_shape_{$ctx}: missing key {$k}\n");

            return false;
        }
    }
    if (($alert['code'] ?? '') !== 'client_no_show_threshold') {
        fwrite(STDERR, "FAIL  alert_shape_{$ctx}: expected code client_no_show_threshold\n");

        return false;
    }
    if (($alert['severity'] ?? '') !== 'warning') {
        fwrite(STDERR, "FAIL  alert_shape_{$ctx}: expected severity warning\n");

        return false;
    }

    return true;
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
    fwrite(STDERR, "FAIL  scope: Pass --branch-code=<branches.code> (or APPOINTMENT_SETTINGS_VERIFY_BRANCH_CODE).\n");
    exit(1);
}

$db = app(Database::class);
$settings = app(SettingsService::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$appointmentsProfile = app(ClientAppointmentProfileProvider::class);

try {
    $scope = vNsResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    vNsFail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$noShowRow = $db->fetchOne(
    'SELECT client_id, COUNT(*) AS c
     FROM appointments
     WHERE deleted_at IS NULL AND status = \'no_show\'
       AND branch_id = ?
     GROUP BY client_id
     HAVING c >= 1
     ORDER BY c DESC
     LIMIT 1',
    [$branchId]
);

if ($noShowRow === null || (int) ($noShowRow['client_id'] ?? 0) <= 0) {
    echo "SKIP  no_show_fixture: No client with no_show appointment on this branch scope.\n";
    exit(0);
}

$clientId = (int) $noShowRow['client_id'];

$beforeEffective = $settings->getAppointmentSettings($branchId);

$s0 = $appointmentsProfile->getSummary($clientId);
if (!isset($s0['no_show_alert']) || !is_array($s0['no_show_alert'])) {
    vNsFail('summary_has_no_show_alert', 'getSummary must include no_show_alert array.');
    exit(1);
}
if (!vNsAssertAlertShape($s0['no_show_alert'], 'baseline')) {
    exit(1);
}
vNsPass('getSummary_includes_canonical_no_show_alert_shape');

if (($s0['no_show_alert_triggered'] ?? null) !== ($s0['no_show_alert']['active'] ?? null)) {
    vNsFail('parity_triggered_vs_active', 'no_show_alert_triggered must match no_show_alert.active');
    exit(1);
}
vNsPass('flat_triggered_matches_nested_active');

$settings->patchAppointmentSettings([
    'no_show_alert_enabled' => true,
    'no_show_alert_threshold' => 1,
], $branchId);

$s1 = $appointmentsProfile->getSummary($clientId);
$nsCount = (int) ($s1['no_show'] ?? 0);
if ($nsCount < 1) {
    $settings->patchAppointmentSettings([
        'no_show_alert_enabled' => $beforeEffective['no_show_alert_enabled'],
        'no_show_alert_threshold' => $beforeEffective['no_show_alert_threshold'],
    ], $branchId);
    vNsFail('fixture_count', 'Expected at least one no_show in summary for fixture client.');
    exit(1);
}
if (empty($s1['no_show_alert']['active'])) {
    $settings->patchAppointmentSettings([
        'no_show_alert_enabled' => $beforeEffective['no_show_alert_enabled'],
        'no_show_alert_threshold' => $beforeEffective['no_show_alert_threshold'],
    ], $branchId);
    vNsFail('active_when_enabled', 'no_show_alert.active should be true when enabled and count >= threshold.');
    exit(1);
}
if (trim((string) ($s1['no_show_alert']['message'] ?? '')) === '') {
    $settings->patchAppointmentSettings([
        'no_show_alert_enabled' => $beforeEffective['no_show_alert_enabled'],
        'no_show_alert_threshold' => $beforeEffective['no_show_alert_threshold'],
    ], $branchId);
    vNsFail('message_when_active', 'message should be non-empty when active.');
    exit(1);
}
vNsPass('enabled_threshold_one_triggers_for_fixture_client');

$settings->patchAppointmentSettings(['no_show_alert_enabled' => false], $branchId);
$s2 = $appointmentsProfile->getSummary($clientId);
if (!empty($s2['no_show_alert']['active'])) {
    $settings->patchAppointmentSettings([
        'no_show_alert_enabled' => $beforeEffective['no_show_alert_enabled'],
        'no_show_alert_threshold' => $beforeEffective['no_show_alert_threshold'],
    ], $branchId);
    vNsFail('disabled_suppresses_active', 'When setting disabled, active must be false.');
    exit(1);
}
vNsPass('disabled_setting_forces_inactive_despite_history');

$settings->patchAppointmentSettings([
    'no_show_alert_enabled' => $beforeEffective['no_show_alert_enabled'],
    'no_show_alert_threshold' => $beforeEffective['no_show_alert_threshold'],
], $branchId);

echo "\nAll {$passed} check(s) passed.\n";
