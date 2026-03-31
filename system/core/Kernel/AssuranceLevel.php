<?php

declare(strict_types=1);

namespace Core\Kernel;

/**
 * Authentication assurance level for the current session.
 *
 * Embedded in TenantContext. Allows protected operations to require a
 * minimum assurance threshold before proceeding (e.g. step-up for deletion).
 *
 * MFA levels (SESSION_MFA, STEP_UP) are future: PLT-MFA-01 (Phase 3).
 * Current sessions are classified as SESSION when authenticated.
 */
enum AssuranceLevel: string
{
    /** No authentication. Anonymous / guest context. */
    case NONE = 'none';

    /** Standard session-based authentication (password only, current baseline). */
    case SESSION = 'session';

    /**
     * Session with MFA verified.
     * Future: PLT-MFA-01. Not yet issued; reserved for forward-compatibility.
     */
    case SESSION_MFA = 'session_mfa';

    /**
     * Step-up / re-authentication completed within this session for a privileged operation.
     * Future: Phase 3 privileged plane closure.
     */
    case STEP_UP = 'step_up';
}
