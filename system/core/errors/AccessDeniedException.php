<?php

declare(strict_types=1);

namespace Core\Errors;

/**
 * Expected authorization / tenant branch-or-organization scope denial.
 * {@see HttpErrorHandler::handleException} maps this to HTTP 403 (non-debug) without message-string heuristics.
 */
final class AccessDeniedException extends \DomainException
{
}
