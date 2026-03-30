<?php

declare(strict_types=1);

$__clientsWsTab = $clientsWorkspaceActiveTab ?? 'list';
$workspace = [
    'active_tab' => $__clientsWsTab,
    'tabs' => [
        ['id' => 'list', 'label' => 'Manage Clients', 'url' => '/clients'],
        ['id' => 'new', 'label' => 'New Client', 'url' => '/clients/create'],
        ['id' => 'duplicates', 'label' => 'Duplicate Search', 'url' => '/clients/duplicates'],
        ['id' => 'custom_fields', 'label' => 'Client Fields', 'url' => '/clients/custom-fields'],
        ['id' => 'registrations', 'label' => 'Web Registrations', 'url' => '/clients/registrations'],
    ],
];
