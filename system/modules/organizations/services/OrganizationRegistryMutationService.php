<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use InvalidArgumentException;
use Modules\Organizations\Repositories\OrganizationRegistryMutationRepository;
use Modules\Organizations\Repositories\OrganizationRegistryReadRepository;
use Modules\Organizations\Repositories\PlatformControlPlaneReadRepository;
use RuntimeException;

/**
 * Minimal organization registry mutation facade (F-37 §7 phase-1 scope; F-37 S4 backend slice).
 *
 * **Scope:** Control-plane / global — no {@code OrganizationContext} tenant scoping; callers must enforce
 * {@code platform.organizations.manage} / {@code organizations.profile.manage} in future HTTP waves.
 */
final class OrganizationRegistryMutationService
{
    public function __construct(
        private OrganizationRegistryMutationRepository $mutationRepository,
        private OrganizationRegistryReadRepository $readRepository,
        private PlatformControlPlaneReadRepository $controlPlaneReads,
    ) {
    }

    /**
     * @param array{name: string, code?: string|null} $payload {@code name} required; {@code code} optional (null or omitted = null)
     *
     * @return array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}
     */
    public function createOrganization(array $payload): array
    {
        if (!isset($payload['name']) || !is_string($payload['name'])) {
            throw new InvalidArgumentException('Organization name is required.');
        }

        $name = trim($payload['name']);
        if ($name === '') {
            throw new InvalidArgumentException('Organization name must not be empty.');
        }
        if (strlen($name) > 255) {
            throw new InvalidArgumentException('Organization name exceeds 255 characters.');
        }

        $code = null;
        if (array_key_exists('code', $payload)) {
            if ($payload['code'] !== null && !is_string($payload['code'])) {
                throw new InvalidArgumentException('Organization code must be a string or null.');
            }
            $code = $payload['code'] === null ? null : trim((string) $payload['code']);
            if ($code === '') {
                $code = null;
            }
            if ($code !== null && strlen($code) > 50) {
                throw new InvalidArgumentException('Organization code exceeds 50 characters.');
            }
        }

        if ($code !== null && $this->mutationRepository->findOrganizationIdByCode($code) !== null) {
            throw new InvalidArgumentException('Organization code is already in use.');
        }

        $id = $this->mutationRepository->insertOrganization($name, $code);
        $row = $this->readRepository->findById($id);
        if ($row === null) {
            throw new RuntimeException('Organization was created but could not be reloaded.');
        }

        return $row;
    }

    /**
     * Phase-1 profile fields only: {@code name}, {@code code} (F-37 §7). Keys omitted are unchanged;
     * {@code code} may be set to null to clear. Other keys are ignored.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}|null
     */
    public function updateOrganizationProfile(int $organizationId, array $payload): ?array
    {
        if ($organizationId <= 0) {
            return null;
        }

        $existing = $this->readRepository->findById($organizationId);
        if ($existing === null) {
            return null;
        }
        if (!empty($existing['deleted_at'])) {
            throw new InvalidArgumentException('Cannot edit an archived salon.');
        }

        $patch = [];
        if (array_key_exists('name', $payload)) {
            if (!is_string($payload['name'])) {
                throw new InvalidArgumentException('Organization name must be a string.');
            }
            $n = trim($payload['name']);
            if ($n === '') {
                throw new InvalidArgumentException('Organization name must not be empty.');
            }
            if (strlen($n) > 255) {
                throw new InvalidArgumentException('Organization name exceeds 255 characters.');
            }
            $patch['name'] = $n;
        }

        if (array_key_exists('code', $payload)) {
            if ($payload['code'] !== null && !is_string($payload['code'])) {
                throw new InvalidArgumentException('Organization code must be a string or null.');
            }
            $c = $payload['code'] === null ? null : trim((string) $payload['code']);
            if ($c === '') {
                $c = null;
            }
            if ($c !== null && strlen($c) > 50) {
                throw new InvalidArgumentException('Organization code exceeds 50 characters.');
            }
            if ($c !== null) {
                $owner = $this->mutationRepository->findOrganizationIdByCode($c);
                if ($owner !== null && $owner !== $organizationId) {
                    throw new InvalidArgumentException('Organization code is already in use.');
                }
            }
            $patch['code'] = $c;
        }

        if ($patch === []) {
            return $existing;
        }

        $this->mutationRepository->updateProfile($organizationId, $patch);

        return $this->readRepository->findById($organizationId);
    }

    /**
     * Sets {@code suspended_at} to a non-null timestamp (F-37 §2.1). Idempotent for already-suspended rows.
     *
     * @return array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}|null
     */
    public function suspendOrganization(int $organizationId): ?array
    {
        if ($organizationId <= 0) {
            return null;
        }
        $row = $this->readRepository->findById($organizationId);
        if ($row === null) {
            return null;
        }
        if (!empty($row['deleted_at'])) {
            throw new InvalidArgumentException('Cannot suspend an archived salon.');
        }
        $this->mutationRepository->setSuspendedAtToNow($organizationId);

        return $this->readRepository->findById($organizationId);
    }

    /**
     * Clears {@code suspended_at} (reactivate / unsuspend per F-37 §2.1).
     *
     * @return array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}|null
     */
    public function reactivateOrganization(int $organizationId): ?array
    {
        if ($organizationId <= 0) {
            return null;
        }
        $row = $this->readRepository->findById($organizationId);
        if ($row === null) {
            return null;
        }
        if (!empty($row['deleted_at'])) {
            throw new InvalidArgumentException('Cannot reactivate an archived salon.');
        }
        $this->mutationRepository->setSuspendedAtToNull($organizationId);

        return $this->readRepository->findById($organizationId);
    }

    /**
     * Soft-archive (sets {@code deleted_at}). Blocked when non-deleted branches exist.
     *
     * @return array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}|null
     */
    public function archiveOrganization(int $organizationId): ?array
    {
        if ($organizationId <= 0) {
            return null;
        }
        $row = $this->readRepository->findById($organizationId);
        if ($row === null) {
            return null;
        }
        if (!empty($row['deleted_at'])) {
            return $row;
        }
        $branches = $this->controlPlaneReads->countNonDeletedBranchesForOrganization($organizationId);
        if ($branches > 0) {
            throw new InvalidArgumentException(
                'Cannot archive while this salon has branches. Remove or deactivate branches first.'
            );
        }
        $this->mutationRepository->setDeletedAtToNow($organizationId);

        return $this->readRepository->findById($organizationId);
    }
}
