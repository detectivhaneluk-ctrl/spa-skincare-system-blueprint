<?php

declare(strict_types=1);

namespace Modules\Clients\Support;

/**
 * Registration create validation failed; {@see $errors} maps field keys to messages.
 */
final class ClientRegistrationValidationException extends \DomainException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('Registration validation failed.');
    }
}
