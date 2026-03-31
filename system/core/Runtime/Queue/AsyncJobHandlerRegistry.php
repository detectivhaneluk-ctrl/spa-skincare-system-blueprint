<?php

declare(strict_types=1);

namespace Core\Runtime\Queue;

/**
 * Canonical job handler registry (PLT-Q-01).
 *
 * Maps job_type strings to {@see AsyncJobHandlerInterface} instances.
 * The registry is the single source of truth for which handlers are wired
 * into the control-plane; the worker loop consults it at dispatch time.
 *
 * Registration is done once at bootstrap via {@see register()}.
 * Duplicate registration for the same job_type is a programming error and throws.
 *
 * Built-in no-op types that the worker accepts without a handler:
 *   noop, media.ping, docs.ping, notify.ping
 * These are test/smoke types and must never be added as real handlers.
 *
 * @see AsyncQueueWorkerLoop
 * @see AsyncJobHandlerInterface
 */
final class AsyncJobHandlerRegistry
{
    /** @var array<string, AsyncJobHandlerInterface> */
    private array $handlers = [];

    /**
     * Built-in no-op job types: processed silently without a handler.
     * Adding a handler for one of these types overrides the no-op and is allowed.
     */
    public const NOOP_TYPES = ['noop', 'media.ping', 'docs.ping', 'notify.ping'];

    public function register(string $jobType, AsyncJobHandlerInterface $handler): void
    {
        $jobType = trim($jobType);
        if ($jobType === '') {
            throw new \InvalidArgumentException('job_type must not be empty.');
        }
        if (array_key_exists($jobType, $this->handlers)) {
            throw new \LogicException('Handler already registered for job_type: ' . $jobType);
        }
        $this->handlers[$jobType] = $handler;
    }

    public function get(string $jobType): ?AsyncJobHandlerInterface
    {
        return $this->handlers[$jobType] ?? null;
    }

    public function has(string $jobType): bool
    {
        return array_key_exists($jobType, $this->handlers);
    }

    /**
     * @return list<string>
     */
    public function registeredTypes(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Returns true if the job_type is one of the built-in no-op smoke types.
     */
    public function isNoop(string $jobType): bool
    {
        return in_array($jobType, self::NOOP_TYPES, true);
    }
}
