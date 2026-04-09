<?php

declare(strict_types=1);

$__clientsWsTab = $clientsWorkspaceActiveTab ?? 'list';
$__session = \Core\App\Application::container()->get(\Core\Auth\SessionAuth::class);
$__uid = $__session->id();
$__perm = \Core\App\Application::container()->get(\Core\Permissions\PermissionService::class);
$__canMarketingTab = $__uid !== null && $__perm->has($__uid, 'marketing.view');
$__canIntakeTab = $__uid !== null && $__perm->has($__uid, 'intake.view');

/* Primary strip: list, marketing, client fields, registrations. New Client = toolbar CTA + drawer. */
$__tabs = [
    ['id' => 'list', 'label' => 'Manage Clients', 'url' => '/clients'],
];
if ($__canMarketingTab) {
    $__tabs[] = ['id' => 'marketing', 'label' => 'Marketing', 'url' => '/marketing/campaigns'];
}
$__tabs[] = ['id' => 'custom_fields', 'label' => 'Form composer', 'url' => '/clients/custom-fields'];
$__tabs[] = ['id' => 'registrations', 'label' => 'Web Registrations', 'url' => '/clients/registrations'];
$__tabsMore = [];
if ($__canIntakeTab) {
    $__tabsMore[] = ['id' => 'intake', 'label' => 'Consultation Forms', 'url' => '/intake/templates'];
}
$workspace = [
    'active_tab' => $__clientsWsTab,
    'tabs' => $__tabs,
    'tabs_more' => $__tabsMore,
];
