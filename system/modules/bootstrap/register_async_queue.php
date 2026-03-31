<?php

declare(strict_types=1);

/**
 * PLT-Q-01: Unified async queue control-plane registration.
 *
 * Registers the canonical control-plane primitives and wires all domain handlers
 * into the handler registry so the worker loop can dispatch without a hard-coded
 * match table.
 *
 * Execution order note: this file must load AFTER register_clients.php,
 * register_media.php, and register_appointments_documents_notifications.php
 * because it depends on ClientMergeJobService, OutboundNotificationDispatchService.
 */

use Core\Runtime\Queue\AsyncJobHandlerRegistry;
use Core\Runtime\Queue\AsyncQueueStatusReader;
use Core\Runtime\Queue\AsyncQueueWorkerLoop;
use Core\Runtime\Queue\RuntimeAsyncJobRepository;
use Modules\Clients\Queue\ClientMergeExecuteHandler;
use Modules\Media\Queue\MediaImagePipelineHandler;
use Modules\Notifications\Queue\NotificationsOutboundDrainHandler;

$container->singleton(AsyncJobHandlerRegistry::class, static function ($c) {
    $registry = new AsyncJobHandlerRegistry();

    $registry->register(
        \Core\Runtime\Queue\RuntimeAsyncJobWorkload::JOB_CLIENTS_MERGE_EXECUTE,
        new ClientMergeExecuteHandler(
            $c->get(\Modules\Clients\Services\ClientMergeJobService::class)
        )
    );

    $registry->register(
        \Core\Runtime\Queue\RuntimeAsyncJobWorkload::JOB_MEDIA_IMAGE_PIPELINE,
        new MediaImagePipelineHandler(SYSTEM_PATH)
    );

    $registry->register(
        \Core\Runtime\Queue\RuntimeAsyncJobWorkload::JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH,
        new NotificationsOutboundDrainHandler(
            $c->get(\Modules\Notifications\Services\OutboundNotificationDispatchService::class)
        )
    );

    return $registry;
});

$container->singleton(AsyncQueueWorkerLoop::class, static fn ($c) => new AsyncQueueWorkerLoop(
    $c->get(RuntimeAsyncJobRepository::class),
    $c->get(AsyncJobHandlerRegistry::class),
));

$container->singleton(AsyncQueueStatusReader::class, static fn ($c) => new AsyncQueueStatusReader(
    $c->get(\Core\App\Database::class)
));
