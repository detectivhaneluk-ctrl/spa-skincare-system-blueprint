<?php

declare(strict_types=1);

namespace Modules\Documents\Controllers;

use Core\App\Application;
use Core\App\Response;
use Modules\Documents\Services\ConsentService;
use Modules\Documents\Services\DocumentService;

/**
 * Minimal document/consent endpoints. Branch scope comes from BranchContext
 * (set by BranchContextMiddleware from session + authorized request branch_id).
 * No UI; JSON for reuse by check-in and booking.
 */
final class DocumentController
{
    public function __construct(
        private ConsentService $consentService,
        private DocumentService $documentService
    )
    {
    }

    /**
     * List consent definitions for branch (scope from BranchContext / branch_id request via middleware).
     */
    public function listDefinitions(): void
    {
        $branchId = $this->queryBranchId();
        $activeOnly = !isset($_GET['all']) || $_GET['all'] === '' || $_GET['all'] === '0';
        $list = $this->consentService->listDefinitions($branchId, $activeOnly);
        $this->json(['success' => true, 'data' => $list]);
    }

    /**
     * Create a document definition. POST: code, name, description?, valid_duration_days?, is_active?
     */
    public function createDefinition(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');

            return;
        }
        $code = trim((string) ($_POST['code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($code === '') {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Code is required.');

            return;
        }
        try {
            $id = $this->consentService->createDefinition([
                'code' => $code,
                'name' => $name !== '' ? $name : $code,
                'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                'valid_duration_days' => isset($_POST['valid_duration_days']) && $_POST['valid_duration_days'] !== '' ? (int) $_POST['valid_duration_days'] : null,
                'is_active' => !isset($_POST['is_active']) || $_POST['is_active'] === '1' || $_POST['is_active'] === 'on',
            ], $this->queryBranchId());
            $this->json(['success' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            $status = $e instanceof \DomainException || $e instanceof \InvalidArgumentException ? 422 : 500;
            $code = $status === 422 ? 'VALIDATION_FAILED' : 'SERVER_ERROR';
            Response::jsonPublicApiError($status, $code, $e->getMessage());
        }
    }

    /**
     * List consents for a client. GET /documents/clients/{id}/consents (branch scope via middleware).
     */
    public function listClientConsents(int $clientId): void
    {
        if ($clientId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Invalid client id.');

            return;
        }
        $list = $this->consentService->listClientConsents($clientId, $this->queryBranchId());
        $this->json(['success' => true, 'data' => $list]);
    }

    /**
     * Record client consent as signed. POST: document_definition_id, notes?
     */
    public function signClientConsent(int $clientId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');

            return;
        }
        if ($clientId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Invalid client id.');

            return;
        }
        $defId = isset($_POST['document_definition_id']) ? (int) $_POST['document_definition_id'] : 0;
        if ($defId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'document_definition_id is required.');

            return;
        }
        try {
            $this->consentService->recordSigned(
                $clientId,
                $defId,
                $this->queryBranchId(),
                trim((string) ($_POST['notes'] ?? '')) ?: null
            );
            $this->json(['success' => true]);
        } catch (\Throwable $e) {
            $status = $e instanceof \DomainException || $e instanceof \InvalidArgumentException ? 422 : 500;
            $code = $status === 422 ? 'VALIDATION_FAILED' : 'SERVER_ERROR';
            Response::jsonPublicApiError($status, $code, $e->getMessage());
        }
    }

    /**
     * Check consent status for a client and service (e.g. before booking). GET ?service_id= (branch via middleware).
     */
    public function checkClientConsents(int $clientId): void
    {
        if ($clientId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Invalid client id.');

            return;
        }
        $serviceId = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;
        if ($serviceId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'service_id is required.');

            return;
        }
        $check = $this->consentService->checkClientConsentsForService($clientId, $serviceId, $this->queryBranchId());
        $this->json(['success' => true, 'data' => $check]);
    }

    /**
     * Upload/register a document file and link it to an internal owner.
     */
    public function uploadDocument(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');

            return;
        }
        try {
            $result = $this->documentService->registerUpload(
                $_FILES['file'] ?? [],
                [
                    'owner_type' => trim((string) ($_POST['owner_type'] ?? '')),
                    'owner_id' => (int) ($_POST['owner_id'] ?? 0),
                ]
            );
            $this->json(['success' => true, 'data' => $result], 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to upload document.');
        }
    }

    public function listOwnerDocuments(): void
    {
        $ownerType = trim((string) ($_GET['owner_type'] ?? ''));
        $ownerId = (int) ($_GET['owner_id'] ?? 0);
        try {
            $list = $this->documentService->listByOwner($ownerType, $ownerId);
            $this->json(['success' => true, 'data' => $list]);
        } catch (\InvalidArgumentException|\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to list documents.');
        }
    }

    public function showDocument(int $id): void
    {
        try {
            $meta = $this->documentService->showMetadata($id);
            $this->json(['success' => true, 'data' => $meta]);
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to read document metadata.');
        }
    }

    /**
     * Authenticated internal file download only (session + documents.view). No JSON body; exits after stream.
     */
    public function downloadDocument(int $id): void
    {
        try {
            $this->documentService->deliverAuthenticatedDownload($id);
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to download document.');
        }
    }

    public function relinkDocument(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');

            return;
        }
        try {
            $this->documentService->relink($id, [
                'owner_type' => trim((string) ($_POST['owner_type'] ?? '')),
                'owner_id' => (int) ($_POST['owner_id'] ?? 0),
            ]);
            $this->json(['success' => true]);
        } catch (\InvalidArgumentException|\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to relink document.');
        }
    }

    public function detachDocument(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');

            return;
        }
        try {
            $this->documentService->detach(
                $id,
                trim((string) ($_POST['owner_type'] ?? '')),
                (int) ($_POST['owner_id'] ?? 0)
            );
            $this->json(['success' => true]);
        } catch (\InvalidArgumentException|\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to detach document.');
        }
    }

    public function archiveDocument(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');

            return;
        }
        try {
            $this->documentService->archive($id);
            $this->json(['success' => true]);
        } catch (\DomainException|\InvalidArgumentException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to archive document.');
        }
    }

    public function deleteDocument(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');

            return;
        }
        try {
            $this->documentService->deleteSoft($id);
            $this->json(['success' => true]);
        } catch (\DomainException|\InvalidArgumentException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', $e->getMessage());
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to delete document.');
        }
    }

    private function queryBranchId(): ?int
    {
        return Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
