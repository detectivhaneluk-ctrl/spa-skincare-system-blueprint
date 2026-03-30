<?php

declare(strict_types=1);

/**
 * A-007 read-only: marketing automations must not imply in-app scheduling; scheduler acknowledgment is explicit.
 *
 * Run from project root: php system/scripts/read-only/verify_marketing_automation_scheduler_honesty_a007_01.php
 */
$systemRoot = dirname(__DIR__, 2);

function src(string $relativeFromSystem): string
{
    global $systemRoot;

    return (string) file_get_contents($systemRoot . '/' . $relativeFromSystem);
}

$settings = src('core/app/SettingsService.php');
$ctrl = src('modules/marketing/controllers/MarketingAutomationController.php');
$view = src('modules/marketing/views/automations/index.php');
$routes = src('routes/web/register_marketing.php');
$exec = src('scripts/marketing_automations_execute.php');
$ops = src('docs/MARKETING-AUTOMATIONS-SCHEDULER-ENTRYPOINT-OPS.md');

$checks = [
    'SettingsService: scheduler ack key + getters' => str_contains($settings, 'MARKETING_AUTOMATIONS_SCHEDULER_ACK_KEY')
        && str_contains($settings, 'getMarketingAutomationsSchedulerAcknowledged')
        && str_contains($settings, 'setMarketingAutomationsSchedulerAcknowledged'),
    'SettingsService: key in MARKETING_KEYS' => str_contains($settings, "'marketing.automations_external_scheduler_acknowledged'"),
    'MarketingAutomationController: saves acknowledgment' => str_contains($ctrl, 'saveSchedulerAcknowledgment')
        && str_contains($ctrl, 'setMarketingAutomationsSchedulerAcknowledged'),
    'Automations view: external scheduler truth' => str_contains($view, 'Execution depends on an external scheduler')
        && str_contains($view, 'marketing_automations_execute.php'),
    'Routes: scheduler-acknowledgment POST' => str_contains($routes, '/marketing/automations/scheduler-acknowledgment')
        && str_contains($routes, 'saveSchedulerAcknowledgment'),
    'CLI script docblock: not web-driven' => str_contains($exec, 'The web app does not invoke this file'),
    'Ops note: repo scheduler contract + verify command' => str_contains($ops, 'marketing_automations_execute.php')
        && str_contains($ops, 'verify_marketing_automation_scheduler_honesty_a007_01.php'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
