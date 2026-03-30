<?php

declare(strict_types=1);

namespace Modules\Notifications\Repositories;

use Core\App\Database;

final class OutboundNotificationAttemptRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $detailJson
     */
    public function insert(int $messageId, int $attemptNo, string $transport, string $status, ?string $errorText, ?array $detailJson): int
    {
        $detailStored = null;
        if ($detailJson !== null) {
            $detailStored = json_encode($detailJson, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $this->db->insert('outbound_notification_attempts', [
            'message_id' => $messageId,
            'attempt_no' => $attemptNo,
            'transport' => $transport,
            'status' => $status,
            'error_text' => $errorText,
            'detail_json' => $detailStored,
        ]);

        return $this->db->lastInsertId();
    }

    public function nextAttemptNo(int $messageId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(MAX(attempt_no), 0) + 1 AS n FROM outbound_notification_attempts WHERE message_id = ?',
            [$messageId]
        );

        return max(1, (int) ($row['n'] ?? 1));
    }
}
