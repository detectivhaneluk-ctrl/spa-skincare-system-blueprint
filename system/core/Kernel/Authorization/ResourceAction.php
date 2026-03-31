<?php

declare(strict_types=1);

namespace Core\Kernel\Authorization;

/**
 * Business-level resource action vocabulary.
 *
 * Actions are named as {resource-domain}:{verb} at the business level — not HTTP verbs.
 * This vocabulary is the canonical list of operations that require authorization checks.
 *
 * Architecture contracts (FOUNDATION-A2):
 * - Every action here must map to at least one policy rule before it can be ALLOW.
 * - DenyAllAuthorizer denies all actions until explicit policy implementations exist.
 * - New tenant-owned operations must add an action here before exposing the operation.
 * - Do not add "ADMIN_BYPASS" or "SKIP_AUTH" entries — use PrincipalKind-based policy rules instead.
 *
 * Naming convention: lowercase domain, colon separator, lowercase verb.
 * Use the left side of the colon to match the resource type in ResourceRef.
 */
enum ResourceAction: string
{
    // -- Appointments --
    case APPOINTMENT_VIEW       = 'appointment:view';
    case APPOINTMENT_CREATE     = 'appointment:create';
    case APPOINTMENT_MODIFY     = 'appointment:modify';
    case APPOINTMENT_CANCEL     = 'appointment:cancel';

    // -- Clients --
    case CLIENT_VIEW            = 'client:view';
    case CLIENT_CREATE          = 'client:create';
    case CLIENT_MODIFY          = 'client:modify';
    case CLIENT_DELETE          = 'client:delete';

    // -- Profile images (FOUNDATION-A5 pilot lane target) --
    case PROFILE_IMAGE_UPLOAD   = 'profile-image:upload';
    case PROFILE_IMAGE_DELETE   = 'profile-image:delete';

    // -- Services and resources --
    case SERVICE_VIEW           = 'service:view';
    case SERVICE_MANAGE         = 'service:manage';

    // -- Staff --
    case STAFF_VIEW             = 'staff:view';
    case STAFF_MANAGE           = 'staff:manage';

    // -- Sales / invoices --
    case INVOICE_VIEW           = 'invoice:view';
    case INVOICE_CREATE         = 'invoice:create';
    case INVOICE_EDIT           = 'invoice:edit';
    case INVOICE_DELETE         = 'invoice:delete';
    case INVOICE_VOID           = 'invoice:void';
    case INVOICE_PAY            = 'invoice:pay';

    // -- Packages and memberships --
    case MEMBERSHIP_VIEW        = 'membership:view';
    case MEMBERSHIP_MANAGE      = 'membership:manage';

    // -- Branch settings (branch-owned) --
    case BRANCH_SETTINGS_VIEW   = 'branch-settings:view';
    case BRANCH_SETTINGS_MANAGE = 'branch-settings:manage';

    // -- Platform / founder operations --
    case PLATFORM_SUPPORT_ENTRY = 'platform:support-entry';
    case PLATFORM_ORG_MANAGE    = 'platform:org-manage';
}
