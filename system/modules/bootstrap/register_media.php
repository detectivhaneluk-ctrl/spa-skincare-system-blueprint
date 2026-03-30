<?php

declare(strict_types=1);

$container->singleton(\Modules\Media\Services\MediaImageSignatureValidator::class, fn () => new \Modules\Media\Services\MediaImageSignatureValidator());
$container->singleton(\Modules\Media\Repositories\MediaAssetRepository::class, fn ($c) => new \Modules\Media\Repositories\MediaAssetRepository($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Media\Repositories\MediaJobRepository::class, fn ($c) => new \Modules\Media\Repositories\MediaJobRepository($c->get(\Core\App\Database::class), $c->get(\Core\Runtime\Queue\RuntimeAsyncJobRepository::class)));
$container->singleton(\Modules\Media\Repositories\MediaAssetVariantRepository::class, fn ($c) => new \Modules\Media\Repositories\MediaAssetVariantRepository($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Media\Services\MediaUploadWorkerDevTrigger::class, fn () => new \Modules\Media\Services\MediaUploadWorkerDevTrigger());
$container->singleton(\Modules\Media\Services\MediaImageLibraryStatusPayloadBuilder::class, fn ($c) => new \Modules\Media\Services\MediaImageLibraryStatusPayloadBuilder(
    $c->get(\Core\App\Database::class)
));
$container->singleton(\Modules\Media\Services\MediaAssetUploadService::class, fn ($c) => new \Modules\Media\Services\MediaAssetUploadService(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\App\Config::class),
    $c->get(\Core\Branch\BranchContext::class),
    $c->get(\Core\Organization\OrganizationContext::class),
    $c->get(\Modules\Media\Services\MediaImageSignatureValidator::class),
    $c->get(\Modules\Media\Repositories\MediaAssetRepository::class),
    $c->get(\Modules\Media\Repositories\MediaJobRepository::class),
    $c->get(\Modules\Media\Services\MediaUploadWorkerDevTrigger::class),
    $c->get(\Core\Storage\Contracts\StorageProviderInterface::class)
));
$container->singleton(\Modules\Media\Controllers\MediaAssetController::class, fn ($c) => new \Modules\Media\Controllers\MediaAssetController(
    $c->get(\Modules\Media\Services\MediaAssetUploadService::class)
));
