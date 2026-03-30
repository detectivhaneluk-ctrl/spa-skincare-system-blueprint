<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

/**
 * Canonical operational truth for {@see OutboundNotificationMessageRepository} + {@see OutboundNotificationDispatchService}.
 *
 * The DB allows {@code channel = 'sms'} (migration `072`) for future use, but no SMS provider or enqueue paths exist
 * in this product phase — only {@code email} is operationally supported end-to-end.
 */
final class OutboundChannelPolicy
{
    /** Terminal {@see OutboundNotificationMessageRepository::finishClaimedSkipped} reason for legacy SMS queue rows. */
    public const SKIP_REASON_SMS_NOT_OPERATIONAL = 'channel_not_operational_sms';

    /**
     * @return list<string>
     */
    public static function operationalChannels(): array
    {
        return ['email'];
    }

    public static function isOperational(string $channel): bool
    {
        $c = strtolower(trim($channel));

        return $c === 'email';
    }

    /**
     * @throws \InvalidArgumentException when {@code channel} is not operationally enqueueable
     */
    public static function assertEnqueueAllowed(string $channel): void
    {
        if (self::isOperational($channel)) {
            return;
        }
        $c = trim($channel);
        throw new \InvalidArgumentException(
            'Outbound channel "' . $c . '" is not operationally supported. Only "email" may be enqueued; '
            . 'SMS is reserved in schema but has no provider in this release.'
        );
    }
}
