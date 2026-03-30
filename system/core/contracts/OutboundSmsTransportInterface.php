<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * SMS transport placeholder. Real provider wiring is deferred; implementations must not claim delivery without a provider.
 */
interface OutboundSmsTransportInterface
{
    public function getName(): string;

    /**
     * @return array{ok: bool, deferred?: bool, error?: string, detail?: array<string, mixed>}
     */
    public function send(string $toPhone, string $bodyText, ?int $branchId): array;
}
