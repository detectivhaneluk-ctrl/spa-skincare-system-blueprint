<?php

declare(strict_types=1);

namespace Core\Contracts;

interface ClientPackageProfileProvider
{
    /**
     * @return array{total:int,active:int,used:int,expired:int,cancelled:int,total_remaining_sessions:int}
     */
    public function getSummary(int $clientId): array;

    /**
     * @return array<int, array{
     *   id:int,
     *   package_name:string,
     *   status:string,
     *   assigned_sessions:int,
     *   remaining_sessions:int,
     *   expires_at:string|null,
     *   created_at:string
     * }>
     */
    public function listRecent(int $clientId, int $limit = 10): array;
}
