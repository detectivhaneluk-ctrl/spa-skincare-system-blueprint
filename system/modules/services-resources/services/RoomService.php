<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\ServicesResources\Repositories\RoomRepository;

final class RoomService
{
    public function __construct(
        private RoomRepository $repo,
        private AuditService $audit,
        private BranchContext $branchContext
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $id = $this->repo->create($data);
            $this->audit->log('room_created', 'room', $id, $this->userId(), $data['branch_id'] ?? null, [
                'room' => $data,
            ]);
            return $id;
        }, 'room create');
    }

    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $current = $this->repo->find($id);
            if (!$current) throw new \RuntimeException('Not found');
            $this->branchContext->assertBranchMatchOrGlobalEntity($current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null);
            $this->repo->update($id, $data);
            $this->audit->log('room_updated', 'room', $id, $this->userId(), $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => array_merge($current, $data),
            ]);
        }, 'room update');
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $room = $this->repo->find($id);
            if (!$room) throw new \RuntimeException('Not found');
            $this->branchContext->assertBranchMatchOrGlobalEntity($room['branch_id'] !== null && $room['branch_id'] !== '' ? (int) $room['branch_id'] : null);
            $this->repo->softDelete($id);
            $this->audit->log('room_deleted', 'room', $id, $this->userId(), $room['branch_id'] ?? null, [
                'room' => $room,
            ]);
        }, 'room delete');
    }

    private function userId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $db = Application::container()->get(\Core\App\Database::class);
        $pdo = $db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $callback();
            if ($started) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'services_resources.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Room operation failed.');
        }
    }
}
