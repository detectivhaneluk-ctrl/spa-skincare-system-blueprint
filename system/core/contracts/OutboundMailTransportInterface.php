<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Pluggable transactional email transport. Implementations must not claim remote inbox delivery.
 */
interface OutboundMailTransportInterface
{
    public function getName(): string;

    /**
     * Message row status when {@see send} returns ok: true (dispatch worker persists this).
     *
     * @return 'captured_locally'|'handoff_accepted'
     */
    public function successMessageStatus(): string;

    /**
     * @return array{ok: bool, error?: string, detail?: array<string, mixed>}
     */
    public function send(string $to, string $subject, string $bodyText, ?int $branchId): array;
}
