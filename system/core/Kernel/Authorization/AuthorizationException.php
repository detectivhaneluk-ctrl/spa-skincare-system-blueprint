<?php

declare(strict_types=1);

namespace Core\Kernel\Authorization;

/**
 * Thrown by AuthorizerInterface::requireAuthorized() when an access decision is DENY.
 *
 * Controllers and service entry points should translate this to an HTTP 403 response.
 * Do NOT catch this silently inside service logic — let it propagate.
 */
final class AuthorizationException extends \RuntimeException
{
    public function __construct(string $reason = 'Access denied by policy')
    {
        parent::__construct($reason);
    }
}
