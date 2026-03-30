<?php

declare(strict_types=1);

namespace Core\Runtime\Jobs;

/**
 * Another exclusive run holds the active slot and it is not stale yet.
 */
final class RuntimeExecutionConflictException extends \RuntimeException
{
}
