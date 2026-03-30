<?php

declare(strict_types=1);

namespace Modules\PublicCommerce\Controllers;

use Core\App\ClientIp;
use Core\App\Response;
use Core\Audit\AuditService;
use Modules\OnlineBooking\Services\PublicBookingAbuseGuardService;
use Modules\PublicCommerce\Services\PublicCommerceService;

/**
 * Thin JSON handlers; branch_id required; rate limits reuse public booking abuse guard buckets.
 */
final class PublicCommerceController
{
    private const RL_CATALOG_IP_WINDOW = 60;
    private const RL_CATALOG_IP_MAX = 40;
    private const RL_WRITE_IP_WINDOW = 60;
    private const RL_WRITE_IP_MAX = 20;

    public function __construct(
        private PublicCommerceService $commerce,
        private PublicBookingAbuseGuardService $abuseGuard,
        private AuditService $audit
    ) {
    }

    public function catalog(): void
    {
        $branchId = (int) ($_GET['branch_id'] ?? 0);
        if ($branchId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'branch_id is required.');
        }
        $rl = $this->abuseGuard->consume(
            'public_commerce_catalog_ip',
            ClientIp::forRequest(),
            self::RL_CATALOG_IP_MAX,
            self::RL_CATALOG_IP_WINDOW
        );
        if (!$rl['ok']) {
            Response::jsonPublicApiError(429, 'TOO_MANY_ATTEMPTS', 'Too many requests. Please try again later.', null, (int) $rl['retry_after']);
        }
        $result = $this->commerce->getCatalog($branchId);
        if (!($result['success'] ?? false) && (($result['public_message'] ?? '') === PublicCommerceService::ERROR_ORGANIZATION_SUSPENDED)) {
            Response::jsonPublicApiError(403, 'ORGANIZATION_SUSPENDED', 'Public commerce is unavailable for this branch.');
        }
        if ($result['success'] ?? false) {
            $this->json($result, 200);
            return;
        }
        Response::jsonPublicApiError(422, 'REQUEST_FAILED', (string) ($result['public_message'] ?? PublicCommerceService::ERROR_GENERIC));
    }

    public function initiate(): void
    {
        $rl = $this->abuseGuard->consume(
            'public_commerce_purchase_ip',
            ClientIp::forRequest(),
            self::RL_WRITE_IP_MAX,
            self::RL_WRITE_IP_WINDOW
        );
        if (!$rl['ok']) {
            Response::jsonPublicApiError(429, 'TOO_MANY_ATTEMPTS', 'Too many requests. Please try again later.', null, (int) $rl['retry_after']);
        }
        $body = $this->readJsonBody();
        $branchId = (int) ($body['branch_id'] ?? 0);
        if ($branchId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', PublicCommerceService::ERROR_GENERIC);
        }
        $result = $this->commerce->initiatePurchase($branchId, $body);
        if (!($result['success'] ?? false) && (($result['public_message'] ?? '') === PublicCommerceService::ERROR_ORGANIZATION_SUSPENDED)) {
            Response::jsonPublicApiError(403, 'ORGANIZATION_SUSPENDED', 'Public commerce is unavailable for this branch.');
        }
        if (!($result['success'] ?? false)) {
            $this->audit->log('public_commerce_purchase_rejected', 'public_commerce_purchase', null, null, $branchId > 0 ? $branchId : null, [
                'reason' => 'initiate_failed',
            ]);
            Response::jsonPublicApiError(422, 'REQUEST_FAILED', (string) ($result['public_message'] ?? PublicCommerceService::ERROR_GENERIC));
        }
        $this->json($result, 200);
    }

    public function finalize(): void
    {
        $rl = $this->abuseGuard->consume(
            'public_commerce_finalize_ip',
            ClientIp::forRequest(),
            self::RL_WRITE_IP_MAX,
            self::RL_WRITE_IP_WINDOW
        );
        if (!$rl['ok']) {
            Response::jsonPublicApiError(429, 'TOO_MANY_ATTEMPTS', 'Too many requests. Please try again later.', null, (int) $rl['retry_after']);
        }
        $body = $this->readJsonBody();
        $result = $this->commerce->finalizePurchase($body);
        if (!($result['success'] ?? false) && (($result['public_message'] ?? '') === PublicCommerceService::ERROR_ORGANIZATION_SUSPENDED)) {
            Response::jsonPublicApiError(403, 'ORGANIZATION_SUSPENDED', 'Public commerce is unavailable for this branch.');
        }
        if ($result['success'] ?? false) {
            $this->json($result, 200);
            return;
        }
        Response::jsonPublicApiError(422, 'REQUEST_FAILED', (string) ($result['public_message'] ?? PublicCommerceService::ERROR_GENERIC));
    }

    public function status(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed. Use POST JSON body with "confirmation_token".');
        }
        $body = $this->readJsonBody();
        $token = trim((string) ($body['confirmation_token'] ?? $_POST['confirmation_token'] ?? ''));
        if ($token === '') {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', PublicCommerceService::ERROR_GENERIC);
        }
        $rl = $this->abuseGuard->consume(
            'public_commerce_status_ip',
            ClientIp::forRequest(),
            self::RL_CATALOG_IP_MAX,
            self::RL_CATALOG_IP_WINDOW
        );
        if (!$rl['ok']) {
            Response::jsonPublicApiError(429, 'TOO_MANY_ATTEMPTS', 'Too many requests. Please try again later.', null, (int) $rl['retry_after']);
        }
        $result = $this->commerce->getPurchaseStatus($token);
        if (!($result['success'] ?? false) && (($result['public_message'] ?? '') === PublicCommerceService::ERROR_ORGANIZATION_SUSPENDED)) {
            Response::jsonPublicApiError(403, 'ORGANIZATION_SUSPENDED', 'Public commerce is unavailable for this branch.');
        }
        if ($result['success'] ?? false) {
            $this->json($result, 200);
            return;
        }
        Response::jsonPublicApiError(404, 'NOT_FOUND', (string) ($result['public_message'] ?? PublicCommerceService::ERROR_GENERIC));
    }

    /** @return array<string, mixed> */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return is_array($_POST) ? $_POST : [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($_POST) ? $_POST : [];
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
