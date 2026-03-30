<?php

declare(strict_types=1);

namespace Modules\Notifications\Controllers;

use Core\App\Application;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Modules\Notifications\Services\NotificationService;

/**
 * Minimal list and mark-read for internal notifications. No heavy UI.
 */
final class NotificationController
{
    public function __construct(
        private NotificationService $service,
        private BranchContext $branchContext
    ) {
    }

    public function index(): void
    {
        $userId = Application::container()->get(SessionAuth::class)->id();
        $branchId = $this->branchContext->getCurrentBranchId();
        $unreadOnly = isset($_GET['unread']) && (string) $_GET['unread'] === '1';
        $filters = $unreadOnly ? ['is_read' => false] : [];
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $list = $this->service->listForUser($userId, $branchId, $filters, $limit, $offset);
        $total = $this->service->countForUser($userId, $branchId, $filters);
        header('Content-Type: application/json');
        echo json_encode([
            'notifications' => $list,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function markRead(int $id): void
    {
        $userId = Application::container()->get(SessionAuth::class)->id();
        $branchId = $this->branchContext->getCurrentBranchId();
        $row = $this->service->find($id);
        if (!$row) {
            header('Content-Type: application/json');
            http_response_code(404);
            echo json_encode(['error' => 'Notification not found']);
            return;
        }
        $rowUserId = isset($row['user_id']) && $row['user_id'] !== '' ? (int) $row['user_id'] : null;
        $rowBranchId = isset($row['branch_id']) && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null;
        $visible = ($rowUserId === null || $rowUserId === $userId) && ($rowBranchId === null || $rowBranchId === $branchId);
        if (!$visible) {
            header('Content-Type: application/json');
            http_response_code(404);
            echo json_encode(['error' => 'Notification not found']);
            return;
        }
        if ($userId === null) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Not authenticated']);
            return;
        }
        $this->service->markReadByUser($id, $userId);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    public function markAllRead(): void
    {
        $userId = Application::container()->get(SessionAuth::class)->id();
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($userId === null) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Not authenticated']);
            return;
        }
        $this->service->markAllReadForUser($userId, $branchId);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }
}
