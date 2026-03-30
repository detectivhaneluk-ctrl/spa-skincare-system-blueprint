<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

use Core\App\SettingsService;
use Core\Audit\AuditService;
use Modules\Notifications\Repositories\NotificationRepository;

/**
 * Internal notifications: create on events; list/mark-read for admin/user.
 * Read state is per-user via notification_reads table; one user marking read does not affect others.
 * Outbound queue: {@see OutboundTransactionalNotificationService} + {@see OutboundNotificationDispatchService}; operational channel is email only ({@see OutboundChannelPolicy}).
 */
final class NotificationService
{
    public function __construct(
        private NotificationRepository $repo,
        private SettingsService $settings,
        private AuditService $audit
    ) {
    }

    /**
     * Create a notification. branch_id nullable = global; user_id nullable = branch-level (all staff).
     * When org-default notification toggles (A-005; {@see SettingsService::shouldEmitInAppNotificationForType}) disallow this `type` prefix, returns 0 and writes `notification_suppressed_by_settings` audit (no row).
     *
     * @param array{branch_id?: int|null, user_id?: int|null, type: string, title: string, message?: string|null, entity_type?: string|null, entity_id?: int|null} $data
     * @return int New notification id, or 0 when suppressed by settings
     */
    public function create(array $data): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        $type = trim((string) ($data['type'] ?? 'info'));
        if ($title === '' || $type === '') {
            throw new \InvalidArgumentException('type and title are required.');
        }
        $branchIdForPolicy = null;
        if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null && $data['branch_id'] !== '') {
            $branchIdForPolicy = (int) $data['branch_id'];
        }
        if (!$this->settings->shouldEmitInAppNotificationForType($type, $branchIdForPolicy)) {
            $this->audit->log('notification_suppressed_by_settings', 'notification', null, null, $branchIdForPolicy, [
                'notification_type' => $type,
                'title' => $title,
            ]);

            return 0;
        }
        return $this->repo->create([
            'branch_id' => $data['branch_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'type' => $type,
            'title' => $title,
            'message' => isset($data['message']) ? trim((string) $data['message']) : null,
            'entity_type' => isset($data['entity_type']) ? trim((string) $data['entity_type']) : null,
            'entity_id' => isset($data['entity_id']) && $data['entity_id'] !== '' ? (int) $data['entity_id'] : null,
        ]);
    }

    public function existsByTypeEntityAndTitle(string $type, string $entityType, int $entityId, string $title): bool
    {
        return $this->repo->existsByTypeEntityAndTitle($type, $entityType, $entityId, $title);
    }

    /**
     * List notifications for a user: those targeting this user or branch-level (user_id NULL) for their branch.
     * is_read in each row is per-user (from notification_reads).
     *
     * @param array{is_read?: bool|null} $filters
     * @return array<int, array>
     */
    public function listForUser(?int $userId, ?int $branchId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        if ($userId === null) {
            return $this->repo->list(array_filter(['branch_id' => $branchId] + $filters), $limit, $offset);
        }
        return $this->repo->listForUser($userId, $branchId, $filters, $limit, $offset);
    }

    public function countForUser(?int $userId, ?int $branchId, array $filters = []): int
    {
        if ($userId === null) {
            return $this->repo->count(array_filter(['branch_id' => $branchId] + $filters));
        }
        return $this->repo->countForUser($userId, $branchId, $filters);
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->repo->list($filters, $limit, $offset);
    }

    public function count(array $filters = []): int
    {
        return $this->repo->count($filters);
    }

    public function find(int $id): ?array
    {
        return $this->repo->find($id);
    }

    /**
     * Mark the notification as read for the given user only. Does not affect other users.
     */
    public function markReadByUser(int $notificationId, int $userId): void
    {
        $this->repo->markReadByUser($notificationId, $userId);
    }

    /**
     * Mark all notifications visible to the user (in the branch context) as read for this user only.
     */
    public function markAllReadForUser(int $userId, ?int $branchId): void
    {
        $this->repo->markAllReadByUser($userId, $branchId);
    }
}
