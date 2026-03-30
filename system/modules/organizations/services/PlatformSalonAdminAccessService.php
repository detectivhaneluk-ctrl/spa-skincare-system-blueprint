<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\Auth\UserAccessShapeService;

/**
 * Primary admin + access-shape read model for a salon account (founder control plane).
 */
final class PlatformSalonAdminAccessService
{
    public function __construct(
        private UserAccessShapeService $accessShape
    ) {
    }

    /**
     * @param array<string, mixed>|null $primaryAdmin from registry batch (id, email, name, deleted_at, password_changed_at, role_code)
     * @return array{user:?array<string, mixed>, shape:?array<string, mixed>}
     */
    public function resolve(?array $primaryAdmin): array
    {
        if ($primaryAdmin === null || (int) ($primaryAdmin['id'] ?? 0) <= 0) {
            return ['user' => null, 'shape' => null];
        }
        $uid = (int) $primaryAdmin['id'];
        $shapes = $this->accessShape->evaluateForUserIds([$uid]);
        $shape = $shapes[$uid] ?? null;

        $user = [
            'id' => $uid,
            'email' => (string) ($primaryAdmin['email'] ?? ''),
            'name' => (string) ($primaryAdmin['name'] ?? ''),
            'role_code' => (string) ($primaryAdmin['role_code'] ?? ''),
            'deleted_at' => isset($primaryAdmin['deleted_at']) && $primaryAdmin['deleted_at'] !== null && $primaryAdmin['deleted_at'] !== ''
                ? (string) $primaryAdmin['deleted_at'] : null,
            'password_changed_at' => isset($primaryAdmin['password_changed_at']) && $primaryAdmin['password_changed_at'] !== null && $primaryAdmin['password_changed_at'] !== ''
                ? (string) $primaryAdmin['password_changed_at'] : null,
        ];

        return ['user' => $user, 'shape' => is_array($shape) ? $shape : null];
    }
}
