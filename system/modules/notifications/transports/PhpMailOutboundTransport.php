<?php

declare(strict_types=1);

namespace Modules\Notifications\Transports;

use Core\Contracts\OutboundMailTransportInterface;

/**
 * Uses PHP {@see mail()} when selected. Success only indicates the function returned true — actual delivery depends on server MTA.
 */
final class PhpMailOutboundTransport implements OutboundMailTransportInterface
{
    public function getName(): string
    {
        return 'php_mail';
    }

    public function successMessageStatus(): string
    {
        return 'handoff_accepted';
    }

    public function send(string $to, string $subject, string $bodyText, ?int $branchId): array
    {
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $ok = @mail($to, $subject, $bodyText, $headers);
        if (!$ok) {
            return ['ok' => false, 'error' => 'php_mail_returned_false', 'detail' => ['to' => $to]];
        }

        return [
            'ok' => true,
            'detail' => [
                'transport' => 'php_mail',
                'note' => 'mail() accepted by PHP; remote delivery depends on MTA configuration',
            ],
        ];
    }
}
