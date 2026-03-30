<?php

declare(strict_types=1);

namespace Modules\Notifications\Transports;

use Core\Contracts\OutboundSmsTransportInterface;

/**
 * Placeholder SMS transport — not registered in DI. SMS is not operationally supported; {@see OutboundChannelPolicy} + dispatch skip path handle legacy rows only.
 */
final class DeferredNoopSmsTransport implements OutboundSmsTransportInterface
{
    public function getName(): string
    {
        return 'deferred_noop';
    }

    public function send(string $toPhone, string $bodyText, ?int $branchId): array
    {
        return [
            'ok' => false,
            'deferred' => true,
            'error' => 'sms_channel_deferred_no_provider_in_repository',
            'detail' => ['to' => $toPhone],
        ];
    }
}
