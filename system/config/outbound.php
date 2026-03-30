<?php

declare(strict_types=1);

$verifySslRaw = env('OUTBOUND_SMTP_VERIFY_SSL', true);
$smtpVerifySsl = is_bool($verifySslRaw)
    ? $verifySslRaw
    : filter_var((string) $verifySslRaw, FILTER_VALIDATE_BOOLEAN);

/**
 * Outbound notification transports (no third-party mail SDKs in-repo).
 *
 * - log: append-only file under storage/ (default; no remote delivery).
 * - php_mail: PHP mail() — handoff to local MTA only; not inbox-guaranteed.
 * - smtp: native socket SMTP (TLS/STARTTLS or implicit SSL). Configure host + from email; optional AUTH.
 *
 * Dispatch: rows are claimed pending→processing (082+), stale processing is reclaimed, transport failures retry with backoff.
 */
return [
    'mail_transport' => env('OUTBOUND_MAIL_TRANSPORT', 'log'),
    'mail_log_path' => env('OUTBOUND_MAIL_LOG_PATH', 'logs/outbound_mail.log'),
    'dispatch_stale_claim_minutes' => (int) env('OUTBOUND_DISPATCH_STALE_CLAIM_MINUTES', 15),
    'mail_max_attempts' => (int) env('OUTBOUND_MAIL_MAX_ATTEMPTS', 5),
    'mail_retry_base_seconds' => (int) env('OUTBOUND_MAIL_RETRY_BASE_SECONDS', 60),
    'smtp_host' => env('OUTBOUND_SMTP_HOST', ''),
    'smtp_port' => (int) env('OUTBOUND_SMTP_PORT', 587),
    'smtp_username' => env('OUTBOUND_SMTP_USERNAME', ''),
    'smtp_password' => env('OUTBOUND_SMTP_PASSWORD', ''),
    'smtp_encryption' => env('OUTBOUND_SMTP_ENCRYPTION', 'tls'),
    'smtp_from_email' => env('OUTBOUND_SMTP_FROM_EMAIL', ''),
    'smtp_from_name' => env('OUTBOUND_SMTP_FROM_NAME', ''),
    'smtp_timeout_seconds' => (int) env('OUTBOUND_SMTP_TIMEOUT_SECONDS', 30),
    'smtp_verify_ssl' => $smtpVerifySsl,
    'smtp_ehlo_hostname' => env('OUTBOUND_SMTP_EHLO_HOSTNAME', 'localhost'),
];
