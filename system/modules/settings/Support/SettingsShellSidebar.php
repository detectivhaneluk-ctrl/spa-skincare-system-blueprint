<?php

declare(strict_types=1);

namespace Modules\Settings\Support;

use Core\App\Application;
use Core\Permissions\PermissionService;

/**
 * Single place for Settings shell sidebar permission flags (main Settings, VAT guide, payment methods, VAT rates).
 */
final class SettingsShellSidebar
{
    /** @return array<string, bool> */
    public static function permissionFlagsForUser(?array $user): array
    {
        $defaults = [
            'canViewSettingsLink' => false,
            'canViewBranchesLink' => false,
            'canViewGiftCardsLink' => false,
            'canViewPackagesLink' => false,
            'canViewMembershipsLink' => false,
            'canManageMembershipsLink' => false,
            'canViewPayrollLink' => false,
            'canViewServicesResourcesLink' => false,
            'canViewPaymentMethodsLink' => false,
            'canViewPriceModificationReasonsLink' => false,
            'canViewVatRatesLink' => false,
            'canViewStaffLink' => false,
            'canCreateStaffLink' => false,
            'canViewReportsLink' => false,
        ];
        if ($user === null) {
            return $defaults;
        }
        $perm = Application::container()->get(PermissionService::class);
        $uid = (int) $user['id'];

        return [
            'canViewSettingsLink' => $perm->has($uid, 'settings.view'),
            'canViewBranchesLink' => $perm->has($uid, 'branches.view'),
            'canViewGiftCardsLink' => $perm->has($uid, 'gift_cards.view'),
            'canViewPackagesLink' => $perm->has($uid, 'packages.view'),
            'canViewMembershipsLink' => $perm->has($uid, 'memberships.view'),
            'canManageMembershipsLink' => $perm->has($uid, 'memberships.manage'),
            'canViewPayrollLink' => $perm->has($uid, 'payroll.view'),
            'canViewServicesResourcesLink' => $perm->has($uid, 'services-resources.view'),
            'canViewPaymentMethodsLink' => $perm->has($uid, 'payment_methods.view'),
            'canViewPriceModificationReasonsLink' => $perm->has($uid, 'price_modification_reasons.view'),
            'canViewVatRatesLink' => $perm->has($uid, 'vat_rates.view'),
            'canViewStaffLink' => $perm->has($uid, 'staff.view'),
            'canCreateStaffLink' => $perm->has($uid, 'staff.create'),
            'canViewReportsLink' => $perm->has($uid, 'reports.view'),
        ];
    }
}
