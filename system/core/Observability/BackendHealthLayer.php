<?php

declare(strict_types=1);

namespace Core\Observability;

/** Stable probe identifiers for reports, JSON, and log correlation. */
final class BackendHealthLayer
{
    public const SESSION = 'session';

    public const STORAGE = 'storage';

    public const RUNTIME_REGISTRY = 'runtime_execution_registry';

    public const IMAGE_PIPELINE = 'image_pipeline';

    public const SHARED_CACHE = 'shared_cache';
}
