<?php
/**
 * Wraps any services-resources view inside the Settings shell.
 * Call after ob_start() + content output, passing $__svcActiveSection if needed.
 * Usage:
 *   $__svcActiveSection = 'services'; // or 'categories', 'rooms', 'equipment', 'catalog'
 *   require base_path('modules/services-resources/views/partials/settings-shell-wrap.php');
 */
$settingsWorkspaceContent = (string) ob_get_clean();
$activeSettingsSection = $__svcActiveSection ?? 'catalog';
$settingsPageTitle = 'Settings';
$settingsPageSubtitle = 'Catalog definitions, policies, and defaults.';
$settingsFlash = $flash ?? null;
$onlineBookingBranchId = 0;
$appointmentsBranchId = 0;
extract(\Modules\Settings\Support\SettingsShellSidebar::permissionFlagsForUser(
    \Core\App\Application::container()->get(\Core\Auth\AuthService::class)->user()
), EXTR_SKIP);
ob_start();
require base_path('modules/settings/views/partials/shell.php');
$content = ob_get_clean();
require shared_path('layout/base.php');
