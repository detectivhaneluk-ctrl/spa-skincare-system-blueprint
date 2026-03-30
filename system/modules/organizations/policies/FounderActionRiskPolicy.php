<?php

declare(strict_types=1);

namespace Modules\Organizations\Policies;

/**
 * Central risk map for platform control-plane (founder) mutations.
 * FOUNDATION-PLATFORM-INVARIANTS-AND-FOUNDER-RISK-ENGINE-01.
 */
final class FounderActionRiskPolicy
{
    public const LEVEL_LOW = 'low';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_HIGH = 'high';
    public const LEVEL_CRITICAL = 'critical';

    public const ACTION_PLATFORM_MANAGE_FALLBACK = 'platform.manage.fallback';

    public const ACTION_SECURITY_KILL_SWITCH = 'platform.security.kill_switch';

    public const ACTION_ORG_REGISTRY_CREATE = 'platform.org_registry.create';
    public const ACTION_ORG_REGISTRY_UPDATE = 'platform.org_registry.update';
    public const ACTION_ORG_SUSPEND = 'platform.org.suspend';
    public const ACTION_ORG_REACTIVATE = 'platform.org.reactivate';

    public const ACTION_SALON_CREATE = 'platform.salon.create';
    public const ACTION_SALON_UPDATE = 'platform.salon.update';
    public const ACTION_SALON_ARCHIVE = 'platform.salon.archive';

    public const ACTION_GLOBAL_BRANCH_CREATE = 'platform.global_branch.create';
    public const ACTION_GLOBAL_BRANCH_UPDATE = 'platform.global_branch.update';
    public const ACTION_GLOBAL_BRANCH_DEACTIVATE = 'platform.global_branch.deactivate';

    public const ACTION_SALON_BRANCH_CREATE = 'platform.salon_branch.create';
    public const ACTION_SALON_BRANCH_UPDATE = 'platform.salon_branch.update';

    public const ACTION_SALON_PEOPLE_CREATE = 'platform.salon_people.create';

    public const ACTION_ACCESS_REPAIR = 'platform.access.repair';
    public const ACTION_ACCESS_USER_ACTIVATE = 'platform.access.user_activate';
    public const ACTION_ACCESS_USER_DEACTIVATE = 'platform.access.user_deactivate';
    public const ACTION_ACCESS_MEMBERSHIP_SUSPEND = 'platform.access.membership_suspend';
    public const ACTION_ACCESS_MEMBERSHIP_UNSUSPEND = 'platform.access.membership_unsuspend';
    public const ACTION_ACCESS_CANONICALIZE_PLATFORM_PRINCIPAL = 'platform.access.canonicalize_platform_principal';
    public const ACTION_ACCESS_PROVISION_ADMIN = 'platform.access.provision_admin';
    public const ACTION_ACCESS_PROVISION_STAFF = 'platform.access.provision_staff';

    public const ACTION_SALON_ADMIN_EMAIL = 'platform.salon_admin.email';
    public const ACTION_SALON_ADMIN_PASSWORD = 'platform.salon_admin.password';
    public const ACTION_SALON_ADMIN_DISABLE_LOGIN = 'platform.salon_admin.disable_login';
    public const ACTION_SALON_ADMIN_ENABLE_LOGIN = 'platform.salon_admin.enable_login';

    public const ACTION_GUIDED_REPAIR_BLOCKED_USER = 'platform.guided_repair.blocked_user';
    public const ACTION_GUIDED_REPAIR_ORG_RECOVERY = 'platform.guided_repair.org_recovery';

    /** Privileged support-entry session start (founder → tenant acting); same MFA bar as CRITICAL mutations. */
    public const ACTION_SUPPORT_ENTRY_START = 'platform.support_entry.start';

    /**
     * @return self::LEVEL_*
     */
    public function levelForAction(string $actionKey): string
    {
        return match ($actionKey) {
            self::ACTION_SUPPORT_ENTRY_START,
            self::ACTION_SECURITY_KILL_SWITCH,
            self::ACTION_ACCESS_PROVISION_ADMIN,
            self::ACTION_ACCESS_PROVISION_STAFF,
            self::ACTION_ACCESS_CANONICALIZE_PLATFORM_PRINCIPAL => self::LEVEL_CRITICAL,

            self::ACTION_ORG_SUSPEND,
            self::ACTION_ORG_REACTIVATE,
            self::ACTION_SALON_ARCHIVE,
            self::ACTION_GLOBAL_BRANCH_DEACTIVATE,
            self::ACTION_ACCESS_USER_DEACTIVATE,
            self::ACTION_ACCESS_USER_ACTIVATE,
            self::ACTION_ACCESS_REPAIR,
            self::ACTION_ACCESS_MEMBERSHIP_SUSPEND,
            self::ACTION_ACCESS_MEMBERSHIP_UNSUSPEND,
            self::ACTION_SALON_ADMIN_EMAIL,
            self::ACTION_SALON_ADMIN_PASSWORD,
            self::ACTION_SALON_ADMIN_DISABLE_LOGIN,
            self::ACTION_SALON_ADMIN_ENABLE_LOGIN,
            self::ACTION_GUIDED_REPAIR_BLOCKED_USER,
            self::ACTION_GUIDED_REPAIR_ORG_RECOVERY => self::LEVEL_HIGH,

            self::ACTION_ORG_REGISTRY_CREATE,
            self::ACTION_ORG_REGISTRY_UPDATE,
            self::ACTION_SALON_CREATE,
            self::ACTION_SALON_UPDATE,
            self::ACTION_GLOBAL_BRANCH_CREATE,
            self::ACTION_GLOBAL_BRANCH_UPDATE,
            self::ACTION_SALON_BRANCH_CREATE,
            self::ACTION_SALON_BRANCH_UPDATE,
            self::ACTION_SALON_PEOPLE_CREATE => self::LEVEL_MEDIUM,

            default => self::LEVEL_MEDIUM,
        };
    }

    /**
     * When the user has enrolled TOTP, require a fresh code (or recent MFA session) for these levels.
     *
     * @param self::LEVEL_* $level
     */
    public function requiresTotpWhenEnrolled(string $level): bool
    {
        return $level === self::LEVEL_HIGH || $level === self::LEVEL_CRITICAL;
    }
}
