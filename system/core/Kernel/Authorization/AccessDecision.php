<?php

declare(strict_types=1);

namespace Core\Kernel\Authorization;

/**
 * Immutable result of a single authorization check.
 *
 * Callers receive an AccessDecision and decide how to act:
 * - Check isAllowed() / isDenied() for conditional branching.
 * - Call orThrow() to throw AuthorizationException on denial (most common for service entry points).
 *
 * The reason string is always set and is suitable for structured logging / audit records.
 */
final class AccessDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
    ) {
    }

    public static function allow(string $reason = 'policy_allowed'): self
    {
        return new self(true, $reason);
    }

    public static function deny(string $reason = 'policy_denied'): self
    {
        return new self(false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isDenied(): bool
    {
        return !$this->allowed;
    }

    /**
     * Enforce the decision: throw AuthorizationException on denial.
     *
     * @throws AuthorizationException
     */
    public function orThrow(): void
    {
        if (!$this->allowed) {
            throw new AuthorizationException($this->reason);
        }
    }
}
