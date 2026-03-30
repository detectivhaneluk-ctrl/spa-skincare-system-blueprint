<?php

declare(strict_types=1);

namespace Core\App;

use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Throwable;

/**
 * JSON-per-line structured logs via PHP {@see error_log()} — no new infrastructure required.
 * Context is enriched with request id, HTTP path (when applicable), client IP, and tenant scope when the container is available.
 *
 * Record shape includes {@code severity}, {@code event_code} (= category), {@code correlation_id}, {@code log_schema}, {@code @timestamp} for sinks (ECS/Loki/CloudWatch).
 *
 * TRANSPORT-RESIDUAL-01: Primary lines use {@see error_log()} with JSON payloads. Encode failures and pre-bootstrap paths use
 * {@see error_log()} with a minimal JSON fallback ({@code spa_structured_encode_fallback_v1}) — intentional last-resort transport.
 *
 * FOUNDATION-OBSERVABILITY-AND-ALERTING-01: consolidated health issues may emit {@see \Core\Observability\BackendHealthReasonCodes::LOG_EVENT_BACKEND_HEALTH_ISSUE}.
 */
final class StructuredLogger
{
    private const LOG_SCHEMA = 'spa_structured_v3';

    private const ENCODE_FALLBACK_SCHEMA = 'spa_structured_encode_fallback_v1';

    /**
     * @param non-empty-string $level e.g. debug, info, warning, error, critical
     * @param non-empty-string $category dot-separated domain, e.g. public-commerce.fulfillment
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $category, string $message, array $context = []): void
    {
        $message = strlen($message) > 8192 ? substr($message, 0, 8192) . '…' : $message;
        $ts = gmdate('c');
        $record = array_merge(
            [
                'ts' => $ts,
                '@timestamp' => $ts,
                'level' => $level,
                'severity' => $level,
                'category' => $category,
                'event_code' => $category,
                'message' => $message,
                'log_schema' => self::LOG_SCHEMA,
                'spa_application' => 'spa-backend',
            ],
            $this->baseContext(),
            $context
        );
        $this->writeLine($record);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logThrowable(Throwable $e, string $category, array $context = []): void
    {
        $this->log('error', $category, $e->getMessage(), array_merge($context, [
            'exception_class' => $e::class,
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
        ]));
    }

    /** @param array<string, mixed> $record */
    private function writeLine(array $record): void
    {
        try {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $line = json_encode($record, $flags);
        } catch (\JsonException) {
            $fb = json_encode([
                'ts' => gmdate('c'),
                '@timestamp' => gmdate('c'),
                'log_schema' => self::ENCODE_FALLBACK_SCHEMA,
                'severity' => 'error',
                'category' => 'structured_log.encode_failed',
                'event_code' => 'structured_log.encode_failed',
                'message' => 'JSON encode failed for structured log record',
                'failed_category' => (string) ($record['category'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            error_log($fb !== false ? $fb : '[structured-log] encode_failed');

            return;
        }
        error_log($line);
    }

    /** @return array<string, mixed> */
    private function baseContext(): array
    {
        $rid = RequestCorrelation::id();
        $out = [
            'request_id' => $rid,
            'correlation_id' => $rid,
            'sapi' => PHP_SAPI,
        ];
        if (PHP_SAPI === 'cli') {
            return $out;
        }
        $out['http_method'] = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $out['http_path'] = is_string($path) && $path !== '' ? $path : '/';
        try {
            $out['client_ip'] = ClientIp::forRequest();
        } catch (Throwable) {
        }
        try {
            $c = Application::container();
            if ($c->has(SessionAuth::class)) {
                $uid = $c->get(SessionAuth::class)->id();
                if ($uid !== null) {
                    $out['user_id'] = $uid;
                }
            }
            if ($c->has(BranchContext::class)) {
                $bid = $c->get(BranchContext::class)->getCurrentBranchId();
                if ($bid !== null) {
                    $out['branch_id'] = $bid;
                }
            }
            if ($c->has(OrganizationContext::class)) {
                $oid = $c->get(OrganizationContext::class)->getCurrentOrganizationId();
                if ($oid !== null) {
                    $out['organization_id'] = $oid;
                }
            }
        } catch (Throwable) {
        }

        return $out;
    }
}
