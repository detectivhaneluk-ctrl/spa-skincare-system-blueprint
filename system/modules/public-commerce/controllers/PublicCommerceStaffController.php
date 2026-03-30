<?php

declare(strict_types=1);

namespace Modules\PublicCommerce\Controllers;

use Modules\PublicCommerce\Services\PublicCommerceService;

/**
 * Staff-only operational endpoints for public-commerce lifecycle (auth + sales.* permissions on routes).
 */
final class PublicCommerceStaffController
{
    public function __construct(private PublicCommerceService $commerce)
    {
    }

    public function listAwaitingVerification(): void
    {
        $rows = $this->commerce->listStaffAwaitingVerificationQueue(100);
        $this->json(['success' => true, 'data' => ['purchases' => $rows]], 200);
    }

    public function syncFulfillment(int $invoiceId): void
    {
        $result = $this->commerce->staffTrustedFulfillmentSync($invoiceId);
        if ($result['ok']) {
            $this->json(['success' => true, 'data' => $result['data'] ?? []], 200);
            return;
        }
        $code = (string) ($result['error_code'] ?? 'error');
        $status = match ($code) {
            'not_found', 'not_public_commerce' => 404,
            'unauthenticated' => 401,
            default => 422,
        };
        $this->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => (string) ($result['message'] ?? 'Request could not be completed.'),
            ],
        ], $status);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
