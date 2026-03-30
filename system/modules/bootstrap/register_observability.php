<?php

declare(strict_types=1);

$container->singleton(\Core\Observability\BackendHealthCollector::class, fn ($c) => new \Core\Observability\BackendHealthCollector(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\App\Config::class),
    $c->get(\Core\Runtime\Jobs\RuntimeExecutionRegistry::class),
    $c->get(\Core\Storage\Contracts\StorageProviderInterface::class),
    $c->get(\Core\Contracts\SharedCacheInterface::class),
    $c->get(\Core\Runtime\Cache\SharedCacheMetrics::class),
));
