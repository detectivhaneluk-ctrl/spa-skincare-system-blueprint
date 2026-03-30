<?php

declare(strict_types=1);

namespace Core\Errors;

/**
 * Domain failure with a stable public code + safe operator-facing message.
 * Internal detail stays in the exception message for logging only.
 */
final class SafeDomainException extends \RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        public readonly string $publicMessage,
        string $internalMessage = '',
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($internalMessage !== '' ? $internalMessage : $publicMessage);
    }
}
