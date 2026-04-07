<?php

declare(strict_types=1);

$__clientsWsTab = $clientsWorkspaceActiveTab ?? 'list';
$__session = \Core\App\Application::container()->get(\Core\Auth\SessionAuth::class);
$__uid = $__session->id();
$__perm = \Core\App\Application::container()->get(\Core\Permissions\PermissionService::class);
$__canMarketingTab = $__uid !== null && $__perm->has($__uid, 'marketing.view');

$__tabs = [
    ['id' => 'list', 'label' => 'Manage Clients', 'url' => '/clients'],
];
if ($__canMarketingTab) {
    $__tabs[] = ['id' => 'marketing', 'label' => 'Marketing', 'url' => '/marketing/campaigns'];
}
$__tabs = array_merge($__tabs, [
    ['id' => 'new', 'label' => 'New Client', 'url' => '/clients/create'],
    ['id' => 'duplicates', 'label' => 'Duplicate Search', 'url' => '/clients/duplicates'],
    ['id' => 'custom_fields', 'label' => 'Client Fields', 'url' => '/clients/custom-fields'],
    ['id' => 'registrations', 'label' => 'Web Registrations', 'url' => '/clients/registrations'],
]);
$workspace = [
    'active_tab' => $__clientsWsTab,
    'tabs' => $__tabs,
];
