<?php

declare(strict_types=1);

namespace Modules\Notifications\Transports;

use Core\App\Config;
use Core\Contracts\OutboundMailTransportInterface;

/**
 * Native socket SMTP (TLS on 587 via STARTTLS, or implicit SSL on 465). No third-party mail SDK.
 * Success means the server accepted the message handoff (typically 250 after DATA) — not inbox delivery.
 */
final class SmtpOutboundMailTransport implements OutboundMailTransportInterface
{
    public function __construct(private Config $config)
    {
    }

    public function getName(): string
    {
        return 'smtp_socket';
    }

    public function successMessageStatus(): string
    {
        return 'handoff_accepted';
    }

    public function send(string $to, string $subject, string $bodyText, ?int $branchId): array
    {
        $host = trim((string) $this->config->get('outbound.smtp_host', ''));
        if ($host === '') {
            return ['ok' => false, 'error' => 'smtp_host_not_configured'];
        }
        $fromEmail = trim((string) $this->config->get('outbound.smtp_from_email', ''));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'smtp_from_email_invalid_or_missing'];
        }
        $port = (int) $this->config->get('outbound.smtp_port', 587);
        $port = $port > 0 && $port <= 65535 ? $port : 587;
        $enc = strtolower(trim((string) $this->config->get('outbound.smtp_encryption', 'tls')));
        $timeout = (int) $this->config->get('outbound.smtp_timeout_seconds', 30);
        $timeout = max(5, min(120, $timeout));
        $user = (string) $this->config->get('outbound.smtp_username', '');
        $pass = (string) $this->config->get('outbound.smtp_password', '');
        $verifySsl = $this->config->get('outbound.smtp_verify_ssl', true);
        $verifySsl = filter_var($verifySsl, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verifySsl === null) {
            $verifySsl = true;
        }
        $fromName = trim((string) $this->config->get('outbound.smtp_from_name', ''));
        $ehloHost = trim((string) $this->config->get('outbound.smtp_ehlo_hostname', 'localhost'));
        if ($ehloHost === '') {
            $ehloHost = 'localhost';
        }

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
                'allow_self_signed' => !$verifySsl,
            ],
        ]);

        try {
            if ($enc === 'ssl') {
                $fp = @stream_socket_client(
                    'ssl://' . $host . ':' . $port,
                    $errno,
                    $errstr,
                    $timeout,
                    STREAM_CLIENT_CONNECT,
                    $ctx
                );
                if ($fp === false) {
                    return ['ok' => false, 'error' => 'smtp_connect_failed', 'detail' => ['errno' => $errno, 'message' => $errstr]];
                }
                stream_set_timeout($fp, $timeout);
                $this->expect($fp, [220]);
                $this->ehlo($fp, $ehloHost);
            } else {
                $fp = @stream_socket_client(
                    'tcp://' . $host . ':' . $port,
                    $errno,
                    $errstr,
                    $timeout,
                    STREAM_CLIENT_CONNECT,
                    $ctx
                );
                if ($fp === false) {
                    return ['ok' => false, 'error' => 'smtp_connect_failed', 'detail' => ['errno' => $errno, 'message' => $errstr]];
                }
                stream_set_timeout($fp, $timeout);
                $this->expect($fp, [220]);
                $ehlo = $this->ehlo($fp, $ehloHost);
                $wantStartTls = $enc !== 'none' && $enc !== 'ssl' && ($enc === 'tls' || $enc === '');
                if ($wantStartTls && $this->linesMentionStartTls($ehlo)) {
                    fwrite($fp, "STARTTLS\r\n");
                    $this->expect($fp, [220]);
                    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                        fclose($fp);

                        return ['ok' => false, 'error' => 'smtp_starttls_failed'];
                    }
                    $this->ehlo($fp, $ehloHost);
                }
            }

            if ($user !== '') {
                fwrite($fp, "AUTH LOGIN\r\n");
                $this->expect($fp, [334]);
                fwrite($fp, base64_encode($user) . "\r\n");
                $this->expect($fp, [334]);
                fwrite($fp, base64_encode($pass) . "\r\n");
                $this->expect($fp, [235]);
            }

            fwrite($fp, 'MAIL FROM:<' . $fromEmail . ">\r\n");
            $this->expect($fp, [250, 251]);
            fwrite($fp, 'RCPT TO:<' . $to . ">\r\n");
            $this->expect($fp, [250, 251, 252]);
            fwrite($fp, "DATA\r\n");
            $this->expect($fp, [354]);

            $fromHeader = $fromName !== ''
                ? $this->encodeHeaderName($fromName) . ' <' . $fromEmail . '>'
                : $fromEmail;
            $headers = "From: {$fromHeader}\r\n"
                . "To: <{$to}>\r\n"
                . 'Subject: ' . $this->encodeHeaderSubject($subject) . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n";

            fwrite($fp, $headers . "\r\n");
            foreach (preg_split("/\r\n|\n|\r/", $bodyText) as $line) {
                if ($line !== '' && $line[0] === '.') {
                    $line = '.' . $line;
                }
                fwrite($fp, $line . "\r\n");
            }
            fwrite($fp, ".\r\n");
            $this->expect($fp, [250]);
            fwrite($fp, "QUIT\r\n");
            fclose($fp);
        } catch (\Throwable $e) {
            if (isset($fp) && is_resource($fp)) {
                @fclose($fp);
            }

            return ['ok' => false, 'error' => 'smtp_dialog_failed', 'detail' => ['message' => $e->getMessage()]];
        }

        return [
            'ok' => true,
            'detail' => [
                'transport' => 'smtp_socket',
                'remote_delivered' => false,
                'note' => 'SMTP server accepted message handoff; delivery to recipient inbox is not guaranteed by this layer',
            ],
        ];
    }

    /**
     * @param resource $fp
     * @return list<string>
     */
    private function ehlo($fp, string $host): array
    {
        fwrite($fp, 'EHLO ' . $host . "\r\n");

        return $this->readMultilineReply($fp);
    }

    /**
     * @param resource $fp
     * @param list<int> $codes
     * @return list<string>
     */
    private function expect($fp, array $codes): array
    {
        $lines = $this->readMultilineReply($fp);
        $last = end($lines);
        if ($last === false) {
            throw new \RuntimeException('empty_smtp_reply');
        }
        $code = (int) substr($last, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('unexpected_smtp_code:' . $code . ':' . trim($last));
        }

        return $lines;
    }

    /**
     * @param resource $fp
     * @return list<string>
     */
    private function readMultilineReply($fp): array
    {
        $lines = [];
        while (true) {
            $line = fgets($fp, 8192);
            if ($line === false) {
                throw new \RuntimeException('smtp_read_failed');
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $ehloLines
     */
    private function linesMentionStartTls(array $ehloLines): bool
    {
        foreach ($ehloLines as $line) {
            if (stripos($line, 'STARTTLS') !== false) {
                return true;
            }
        }

        return false;
    }

    private function encodeHeaderSubject(string $subject): string
    {
        if (preg_match('/[^\x20-\x7E]/', $subject)) {
            return '=?UTF-8?B?' . base64_encode($subject) . '?=';
        }

        return $subject;
    }

    private function encodeHeaderName(string $name): string
    {
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return '=?UTF-8?B?' . base64_encode($name) . '?=';
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
    }
}
