<?php

declare(strict_types=1);

namespace Modules\Marketing\Controllers;

use Core\App\Application;
use Core\App\Response;
use Core\Auth\AuthService;
use Modules\Marketing\Services\MarketingGiftCardTemplateService;

final class MarketingGiftCardTemplatesController
{
    public function __construct(
        private MarketingGiftCardTemplateService $service,
        private AuthService $auth
    ) {
    }

    public function index(): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return;
        }
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        $storageReady = $this->service->isStorageReady();
        // Post-migration: list/total/pager come from one service read; pre-migration: no DB list query.
        $read = $storageReady
            ? $this->service->listTemplatesForIndex($branchId, $limit, $offset)
            : [
                'rows' => [],
                'total' => 0,
                'limit' => max(1, min(200, $limit > 0 ? $limit : 25)),
                'offset' => max(0, $offset),
            ];
        $flash = flash();
        $title = 'Gift Card Templates';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/marketing/views/gift-card-templates/index.php');
    }

    public function create(): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return;
        }
        $storageReady = $this->service->isStorageReady();
        $cloneCandidates = $storageReady ? $this->service->listCloneCandidates($branchId) : [];
        $flash = flash();
        $title = 'Create Gift Card Template';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/marketing/views/gift-card-templates/create.php');
    }

    public function store(): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            flash('error', 'Branch context is required.');
            header('Location: /marketing/gift-card-templates');
            exit;
        }
        if (!$this->service->isStorageReady()) {
            flash('error', 'Gift card template storage is not initialized. Apply migration 102 first.');
            header('Location: /marketing/gift-card-templates/create');
            exit;
        }
        try {
            $name = trim((string) ($_POST['name'] ?? ''));
            $rawClone = trim((string) ($_POST['clone_source_template_id'] ?? ''));
            $cloneSourceTemplateId = $rawClone !== '' ? (int) $rawClone : null;
            $id = $this->service->createTemplateFromRequest($branchId, $name, $cloneSourceTemplateId, $this->currentUserId());
            flash('success', 'Gift card template created.');
            header('Location: /marketing/gift-card-templates/' . $id . '/edit');
            exit;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            header('Location: /marketing/gift-card-templates/create');
            exit;
        }
    }

    public function edit(int $id): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return;
        }
        $storageReady = $this->service->isStorageReady();
        if (!$storageReady) {
            $template = null;
            $images = [];
            $flash = flash();
            $title = 'Edit Gift Card Template';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/marketing/views/gift-card-templates/edit.php');
            return;
        }
        $template = $this->service->findTemplateForEdit($branchId, $id);
        if ($template === null) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $curImg = isset($template['image_id']) && $template['image_id'] !== null ? (int) $template['image_id'] : null;
        $images = $this->service->listImagesForTemplateEditForm($branchId, $curImg);
        $flash = flash();
        $title = 'Edit Gift Card Template';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/marketing/views/gift-card-templates/edit.php');
    }

    public function update(int $id): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            flash('error', 'Branch context is required.');
            header('Location: /marketing/gift-card-templates');
            exit;
        }
        if (!$this->service->isStorageReady()) {
            flash('error', 'Gift card template storage is not initialized. Apply migration 102 first.');
            header('Location: /marketing/gift-card-templates');
            exit;
        }
        try {
            $name = trim((string) ($_POST['name'] ?? ''));
            $sellInStore = isset($_POST['sell_in_store_enabled']) && (string) $_POST['sell_in_store_enabled'] !== '0';
            $sellOnline = isset($_POST['sell_online_enabled']) && (string) $_POST['sell_online_enabled'] !== '0';
            $imageIdRaw = trim((string) ($_POST['image_id'] ?? ''));
            $imageId = $imageIdRaw !== '' ? (int) $imageIdRaw : null;
            $this->service->updateTemplateMetadata($branchId, $id, $name, $sellInStore, $sellOnline, $imageId, $this->currentUserId());
            flash('success', 'Gift card template updated.');
            header('Location: /marketing/gift-card-templates/' . $id . '/edit');
            exit;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            header('Location: /marketing/gift-card-templates/' . $id . '/edit');
            exit;
        }
    }

    public function archive(int $id): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            flash('error', 'Branch context is required.');
            header('Location: /marketing/gift-card-templates');
            exit;
        }
        if (!$this->service->isStorageReady()) {
            flash('error', 'Gift card template storage is not initialized. Apply migration 102 first.');
            header('Location: /marketing/gift-card-templates');
            exit;
        }
        try {
            $this->service->archiveTemplate($branchId, $id, $this->currentUserId());
            flash('success', 'Gift card template archived.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /marketing/gift-card-templates');
        exit;
    }

    public function images(): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return;
        }
        $storageReady = $this->service->isStorageReady();
        $images = $storageReady ? $this->service->listImages($branchId) : [];
        $flash = flash();
        $title = 'Gift Card Images';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/marketing/views/gift-card-templates/images.php');
    }

    /**
     * Lightweight JSON for image library status/previews (UI polling only).
     */
    public function imagesLibraryStatus(): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', 'Forbidden.');

            return;
        }
        if (!$this->service->isStorageReady()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['images' => [], 'worker_hint' => [
                'worker_process_detected' => 'unknown',
                'probable_block_reason' => 'unknown',
                'block_detail' => '',
                'operator_command' => 'php scripts/dev-only/run_media_image_worker_loop.php',
                'large_fifo_backlog' => false,
                'max_pending_jobs_ahead' => 0,
                'stale_processing_rows_ahead_non_blocking' => 0,
                'processing_now_count' => 0,
                'spawn_last' => null,
                'drain_last' => [
                    'ok' => null,
                    'reason' => null,
                    'detail' => null,
                    'asset_id' => null,
                    'job_id' => null,
                ],
                'resolved_cli_php_binary' => null,
                'resolved_cli_php_source' => 'none',
                'resolved_node_binary' => null,
                'resolved_node_source' => 'none',
                'app_env' => (string) env('APP_ENV', 'production'),
            ]], JSON_UNESCAPED_UNICODE);

            return;
        }
        $payload = $this->service->buildImageLibraryStatusPayload($branchId);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public function uploadImage(): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            if ($this->wantsJson()) {
                Response::jsonPublicApiError(403, 'FORBIDDEN', 'Branch context required.', ['reason' => 'branch_required']);

                return;
            }
            flash('error', 'Branch context is required.');
            header('Location: /marketing/gift-card-templates/images');
            exit;
        }
        if (!$this->service->isStorageReady()) {
            if ($this->wantsJson()) {
                Response::jsonPublicApiError(
                    409,
                    'CONFLICT',
                    'Gift card template storage is not initialized. Apply migration 102 first.',
                    ['reason' => 'storage_not_ready']
                );

                return;
            }
            flash('error', 'Gift card template storage is not initialized. Apply migration 102 first.');
            header('Location: /marketing/gift-card-templates/images');
            exit;
        }
        if (!$this->service->isMediaBackedImageUploadReady()) {
            if ($this->wantsJson()) {
                Response::jsonPublicApiError(
                    409,
                    'CONFLICT',
                    'Gift card image uploads require migrations 103 (media pipeline) and 105 (media bridge).',
                    ['reason' => 'media_pipeline_not_ready']
                );

                return;
            }
            flash('error', 'Gift card image uploads require migrations 103 (media pipeline) and 105 (media bridge).');
            header('Location: /marketing/gift-card-templates/images');
            exit;
        }
        try {
            $imageId = $this->service->uploadImage(
                $branchId,
                is_array($_FILES['image'] ?? null) ? $_FILES['image'] : [],
                isset($_POST['title']) ? (string) $_POST['title'] : null,
                $this->currentUserId()
            );
            if ($this->wantsJson()) {
                $row = null;
                foreach ($this->service->listImages($branchId) as $img) {
                    if ((int) ($img['id'] ?? 0) === $imageId) {
                        $row = $img;
                        break;
                    }
                }
                $this->jsonResponse([
                    'ok' => true,
                    'message' => 'Image received. It is processing in the media pipeline and will appear as selectable when ready.',
                    'image_id' => $imageId,
                    'image' => $row,
                ]);
                return;
            }
            flash('success', 'Image received. It is processing in the media pipeline and will appear as selectable when ready.');
        } catch (\Throwable $e) {
            if ($this->wantsJson()) {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());

                return;
            }
            flash('error', $e->getMessage());
        }
        header('Location: /marketing/gift-card-templates/images');
        exit;
    }

    public function deleteImage(int $id): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            flash('error', 'Branch context is required.');
            header('Location: /marketing/gift-card-templates/images');
            exit;
        }
        if (!$this->service->isStorageReady()) {
            flash('error', 'Gift card template storage is not initialized. Apply migration 102 first.');
            header('Location: /marketing/gift-card-templates/images');
            exit;
        }
        try {
            $result = $this->service->softDeleteImage($branchId, $id, $this->currentUserId());
            $level = ($result['flash_type'] ?? 'success') === 'warning' ? 'warning' : 'success';
            flash($level, (string) ($result['flash_message'] ?? 'Image deleted from the library.'));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /marketing/gift-card-templates/images');
        exit;
    }

    private function currentBranchId(): ?int
    {
        $branchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();

        return $branchId !== null ? (int) $branchId : null;
    }

    private function currentUserId(): ?int
    {
        $user = $this->auth->user();

        return isset($user['id']) ? (int) $user['id'] : null;
    }

    private function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}

