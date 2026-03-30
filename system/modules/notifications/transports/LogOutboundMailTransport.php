<?php

declare(strict_types=1);

namespace Modules\Notifications\Transports;

use Core\App\Config;
use Core\Contracts\OutboundMailTransportInterface;

/**
 * Writes RFC5322-ish lines to a local log file. Does not perform remote SMTP/API delivery.
 */
final class LogOutboundMailTransport implements OutboundMailTransportInterface
{
    public function __construct(private Config $config)
    {
    }

    public function getName(): string
    {
        return 'local_log';
    }

    public function successMessageStatus(): string
    {
        return 'captured_locally';
    }

    public function send(string $to, string $subject, string $bodyText, ?int $branchId): array
    {
        $rel = trim((string) $this->config->get('outbound.mail_log_path', 'logs/outbound_mail.log'));
        if ($rel === '') {
            $rel = 'logs/outbound_mail.log';
        }
        $path = SYSTEM_PATH . '/storage/' . ltrim(str_replace(['..', '\\'], ['', '/'], $rel), '/');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'cannot_create_log_dir', 'detail' => ['dir' => $dir]];
        }
        $sep = str_repeat('-', 60) . "\n";
        $line = $sep
            . date('c') . ' branch=' . ($branchId === null ? 'null' : (string) $branchId) . "\n"
            . 'To: ' . $to . "\n"
            . 'Subject: ' . $subject . "\n\n"
            . $bodyText . "\n";
        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'log_write_failed', 'detail' => ['path' => $path]];
        }

        return [
            'ok' => true,
            'detail' => [
                'transport' => 'local_log',
                'remote_delivered' => false,
                'log_path' => $path,
            ],
        ];
    }
}
