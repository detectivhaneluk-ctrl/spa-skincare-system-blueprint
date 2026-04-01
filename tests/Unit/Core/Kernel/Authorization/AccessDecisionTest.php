<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Kernel\Authorization;

use Core\Kernel\Authorization\AccessDecision;
use Core\Kernel\Authorization\AuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AccessDecision immutable value object.
 *
 * Verifies the allow/deny construction, query methods,
 * and fail-closed orThrow() contract.
 */
final class AccessDecisionTest extends TestCase
{
    public function testAllowIsAllowed(): void
    {
        $decision = AccessDecision::allow('founder_policy');

        self::assertTrue($decision->isAllowed());
        self::assertFalse($decision->isDenied());
        self::assertSame('founder_policy', $decision->reason);
    }

    public function testDenyIsDenied(): void
    {
        $decision = AccessDecision::deny('permission_missing');

        self::assertFalse($decision->isAllowed());
        self::assertTrue($decision->isDenied());
        self::assertSame('permission_missing', $decision->reason);
    }

    public function testAllowOrThrowDoesNotThrow(): void
    {
        $decision = AccessDecision::allow('ok');

        // Must not throw
        $decision->orThrow();
        $this->addToAssertionCount(1);
    }

    public function testDenyOrThrowThrowsAuthorizationException(): void
    {
        $decision = AccessDecision::deny('tenant_permission_denied: sales.view');

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('tenant_permission_denied: sales.view');
        $decision->orThrow();
    }

    public function testDefaultAllowReason(): void
    {
        $decision = AccessDecision::allow();

        self::assertSame('policy_allowed', $decision->reason);
    }

    public function testDefaultDenyReason(): void
    {
        $decision = AccessDecision::deny();

        self::assertSame('policy_denied', $decision->reason);
    }
}
