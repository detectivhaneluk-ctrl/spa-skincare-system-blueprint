<?php

declare(strict_types=1);

$container->singleton(\Core\Storage\Contracts\StorageProviderInterface::class, fn ($c) => \Core\Storage\StorageProviderFactory::create(
    $c->get(\Core\App\Config::class)
));
