<?php

declare(strict_types=1);

namespace Modules\Media\Controllers;

use Core\App\Response;
use Modules\Media\Services\MediaAssetUploadService;

/**
 * Foundation wave: JSON upload gateway only ({@see MediaAssetUploadService::acceptUpload()}).
 */
final class MediaAssetController
{
    public function __construct(
        private MediaAssetUploadService $uploadService,
    ) {
    }

    public function upload(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');
        }
        try {
            $result = $this->uploadService->acceptUpload(is_array($_FILES['image'] ?? null) ? $_FILES['image'] : []);
            $this->json(['success' => true, 'data' => $result], 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Upload failed.');
        }
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
