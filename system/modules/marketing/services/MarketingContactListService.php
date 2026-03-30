<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Core\App\Database;
use Modules\Marketing\Repositories\MarketingContactListRepository;

final class MarketingContactListService
{
    public function __construct(
        private Database $db,
        private MarketingContactListRepository $repo
    ) {
    }

    public function isStorageReady(): bool
    {
        return $this->repo->isStorageReady();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listManualListsWithCounts(int $branchId): array
    {
        $rows = $this->repo->listActiveForBranch($branchId);
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
        $counts = $this->repo->memberCountsByListIds($branchId, $ids);
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $out[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'member_count' => (int) ($counts[$id] ?? 0),
                'created_at' => isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
            ];
        }

        return $out;
    }

    public function createList(int $branchId, string $name, ?int $userId): int
    {
        $this->assertName($name);

        return $this->repo->create($branchId, trim($name), $userId);
    }

    public function renameList(int $branchId, int $listId, string $name, ?int $userId): void
    {
        $this->assertName($name);
        $this->assertListExists($branchId, $listId);
        $this->repo->rename($listId, $branchId, trim($name), $userId);
    }

    public function archiveList(int $branchId, int $listId, ?int $userId): void
    {
        $this->assertListExists($branchId, $listId);
        $this->repo->archive($listId, $branchId, $userId);
    }

    /**
     * @param list<int> $clientIds
     */
    public function addContacts(int $branchId, int $listId, array $clientIds, ?int $userId): void
    {
        $this->assertListExists($branchId, $listId);
        $clientIds = $this->normalizeIds($clientIds);
        if ($clientIds === []) {
            return;
        }
        $this->transactional(function () use ($branchId, $listId, $clientIds, $userId): void {
            $this->repo->addMembers($listId, $branchId, $clientIds, $userId);
        });
    }

    /**
     * @param list<int> $clientIds
     */
    public function removeContacts(int $branchId, int $listId, array $clientIds): void
    {
        $this->assertListExists($branchId, $listId);
        $clientIds = $this->normalizeIds($clientIds);
        if ($clientIds === []) {
            return;
        }
        $this->repo->removeMembers($listId, $branchId, $clientIds);
    }

    private function assertListExists(int $branchId, int $listId): void
    {
        if ($listId <= 0 || $this->repo->findActiveForBranch($listId, $branchId) === null) {
            throw new \DomainException('Manual contact list not found in this branch scope.');
        }
    }

    private function assertName(string $name): void
    {
        $trim = trim($name);
        if ($trim === '') {
            throw new \InvalidArgumentException('List name is required.');
        }
        if (mb_strlen($trim) > 160) {
            throw new \InvalidArgumentException('List name must be 160 characters or fewer.');
        }
    }

    /**
     * @param list<int> $clientIds
     * @return list<int>
     */
    private function normalizeIds(array $clientIds): array
    {
        $out = [];
        foreach ($clientIds as $id) {
            $v = (int) $id;
            if ($v > 0) {
                $out[] = $v;
            }
        }

        return array_values(array_unique($out));
    }

    private function transactional(callable $fn): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $fn();
            if ($started) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

