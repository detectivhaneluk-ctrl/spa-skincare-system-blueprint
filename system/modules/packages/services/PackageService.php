<?php

declare(strict_types=1);

namespace Modules\Packages\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Packages\Repositories\ClientPackageRepository;
use Modules\Packages\Support\PackageEntitlementSnapshot;
use Modules\Packages\Repositories\PackageRepository;
use Modules\Packages\Repositories\PackageUsageRepository;

final class PackageService
{
    public const PACKAGE_STATUSES = ['active', 'inactive'];
    public const CLIENT_PACKAGE_STATUSES = ['active', 'used', 'expired', 'cancelled'];
    public const USAGE_TYPES = ['use', 'adjustment', 'reverse', 'expire', 'cancel'];

    public function __construct(
        private PackageRepository $packages,
        private ClientPackageRepository $clientPackages,
        private PackageUsageRepository $usages,
        private Database $db,
        private AuditService $audit,
        private BranchContext $branchContext,
        private ClientRepository $clients
    ) {
    }

    public function createPackage(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $tenantBranchId = $this->requirePositiveBranchId($data['branch_id'] ?? $this->branchContext->getCurrentBranchId());
            $data['branch_id'] = $tenantBranchId;
            $totalSessions = (int) ($data['total_sessions'] ?? 0);
            if ($totalSessions <= 0) {
                throw new \DomainException('total_sessions must be greater than zero.');
            }
            if (empty($data['name']) || trim((string) $data['name']) === '') {
                throw new \DomainException('Package name is required.');
            }
            if (isset($data['validity_days']) && $data['validity_days'] !== null && (int) $data['validity_days'] === 0) {
                throw new \DomainException('validity_days must be greater than zero when provided.');
            }
            $userId = $this->currentUserId();
            $id = $this->packages->create([
                'branch_id' => $tenantBranchId,
                'name' => trim((string) $data['name']),
                'description' => $data['description'] ?? null,
                'status' => in_array($data['status'] ?? 'active', self::PACKAGE_STATUSES, true) ? $data['status'] : 'active',
                'total_sessions' => $totalSessions,
                'validity_days' => isset($data['validity_days']) && $data['validity_days'] !== null ? (int) $data['validity_days'] : null,
                'price' => array_key_exists('price', $data) ? $data['price'] : null,
                'public_online_eligible' => !empty($data['public_online_eligible']) ? 1 : 0,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $created = $this->packages->findInTenantScope($id, $tenantBranchId);
            $this->audit->log('package_created', 'package', $id, $userId, $created['branch_id'] ?? null, [
                'package' => $created,
            ]);
            return $id;
        }, 'package create');
    }

    public function updatePackage(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $tenantBranchId = $this->requirePositiveBranchId($this->branchContext->getCurrentBranchId());
            $current = $this->packages->findInTenantScope($id, $tenantBranchId);
            if (!$current) {
                throw new \RuntimeException('Package not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null);
            if (array_key_exists('total_sessions', $data) && (int) $data['total_sessions'] <= 0) {
                throw new \DomainException('total_sessions must be greater than zero.');
            }
            if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
                throw new \DomainException('Package name is required.');
            }
            if (array_key_exists('validity_days', $data) && $data['validity_days'] !== null && (int) $data['validity_days'] === 0) {
                throw new \DomainException('validity_days must be greater than zero when provided.');
            }
            if (array_key_exists('status', $data) && !in_array((string) $data['status'], self::PACKAGE_STATUSES, true)) {
                throw new \DomainException('Invalid package status.');
            }
            $userId = $this->currentUserId();
            $payload = $data;
            $payload['updated_by'] = $userId;
            if (isset($payload['name'])) {
                $payload['name'] = trim((string) $payload['name']);
            }
            if (array_key_exists('total_sessions', $payload)) {
                $payload['total_sessions'] = (int) $payload['total_sessions'];
            }
            if (array_key_exists('validity_days', $payload) && $payload['validity_days'] !== null) {
                $payload['validity_days'] = (int) $payload['validity_days'];
            }
            if (array_key_exists('public_online_eligible', $payload)) {
                $payload['public_online_eligible'] = !empty($payload['public_online_eligible']) ? 1 : 0;
            }
            $this->packages->updateInTenantScope($id, $tenantBranchId, $payload);
            $updated = $this->packages->findInTenantScope($id, $tenantBranchId);
            $this->audit->log('package_updated', 'package', $id, $userId, $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => $updated,
            ]);
        }, 'package update');
    }

    public function assignPackageToClient(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $packageId = (int) ($data['package_id'] ?? 0);
            $clientId = (int) ($data['client_id'] ?? 0);
            $assigned = (int) ($data['assigned_sessions'] ?? 0);

            if ($packageId <= 0 || $clientId <= 0) {
                throw new \DomainException('package_id and client_id are required.');
            }
            if ($assigned <= 0) {
                throw new \DomainException('assigned_sessions must be greater than zero.');
            }

            $branchId = $this->requirePositiveBranchId(
                array_key_exists('branch_id', $data) && $data['branch_id'] !== null && $data['branch_id'] !== ''
                    ? (int) $data['branch_id']
                    : $this->branchContext->getCurrentBranchId()
            );
            $data['branch_id'] = $branchId;

            if ($this->clients->findLiveForUpdateOnBranch($clientId, $branchId) === null) {
                throw new \DomainException('Client not found or not assignable on this branch.');
            }

            $package = $this->packages->findInTenantScope($packageId, $branchId);
            if (!$package || ($package['status'] ?? '') !== 'active') {
                throw new \DomainException('Package must exist and be active for assignment.');
            }

            $this->assertPackageRowBranchMatches(['branch_id' => $package['branch_id'] ?? null], $branchId, 'Package assignment branch mismatch.');

            $snapIn = isset($data['package_entitlement_snapshot']) && is_array($data['package_entitlement_snapshot'])
                ? $data['package_entitlement_snapshot']
                : null;
            if ($snapIn !== null) {
                if ((int) ($snapIn['package_id'] ?? 0) !== $packageId) {
                    throw new \DomainException('Package entitlement snapshot does not match package.');
                }
                $snapBranch = (int) ($snapIn['package_branch_id'] ?? 0);
                if ($snapBranch !== (int) ($package['branch_id'] ?? 0)) {
                    throw new \DomainException('Package entitlement snapshot branch mismatch.');
                }
                $snapSessions = (int) ($snapIn['total_sessions'] ?? 0);
                if ($snapSessions !== $assigned) {
                    throw new \DomainException('assigned_sessions must match the sold package snapshot.');
                }
                if ((string) ($snapIn['package_status'] ?? '') !== 'active') {
                    throw new \DomainException('Package was not active at purchase time.');
                }
                $pkgSnapArr = $snapIn;
            } else {
                $pkgSnapArr = PackageEntitlementSnapshot::fromPackageRow($package, (int) $package['branch_id']);
            }
            $snapshotJson = PackageEntitlementSnapshot::encode($pkgSnapArr);

            $assignedAt = !empty($data['assigned_at']) ? (string) $data['assigned_at'] : date('Y-m-d H:i:s');
            $startsAt = $data['starts_at'] ?? $assignedAt;
            $expiresAt = $data['expires_at'] ?? null;
            $validityDays = isset($pkgSnapArr['validity_days']) && $pkgSnapArr['validity_days'] !== null && $pkgSnapArr['validity_days'] !== ''
                ? (int) $pkgSnapArr['validity_days']
                : null;
            if ($expiresAt === null && $validityDays !== null && $validityDays > 0 && $startsAt) {
                $expiresAt = date('Y-m-d H:i:s', strtotime((string) $startsAt . ' +' . $validityDays . ' days'));
            }
            if ($expiresAt !== null && strtotime((string) $expiresAt) <= strtotime((string) $assignedAt)) {
                throw new \DomainException('expires_at must be after assigned_at.');
            }

            $userId = $this->currentUserId();
            $clientPackageId = $this->clientPackages->create([
                'package_id' => $packageId,
                'client_id' => $clientId,
                'branch_id' => $branchId,
                'assigned_sessions' => $assigned,
                'remaining_sessions' => $assigned,
                'assigned_at' => $assignedAt,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'package_snapshot_json' => $snapshotJson,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->usages->create([
                'client_package_id' => $clientPackageId,
                'branch_id' => $branchId,
                'usage_type' => 'adjustment',
                'quantity' => $assigned,
                'remaining_after' => $assigned,
                'reference_type' => 'assignment',
                'reference_id' => $clientPackageId,
                'notes' => 'Initial assignment',
                'created_by' => $userId,
            ]);

            $this->audit->log('package_assigned', 'client_package', $clientPackageId, $userId, $branchId !== null ? (int) $branchId : null, [
                'package_id' => $packageId,
                'client_id' => $clientId,
                'assigned_sessions' => $assigned,
                'assigned_at' => $assignedAt,
                'expires_at' => $expiresAt,
            ]);

            return $clientPackageId;
        }, 'package assign');
    }

    public function usePackageSession(int $clientPackageId, int $quantity, array $context = []): void
    {
        if ($quantity <= 0) {
            throw new \DomainException('Use quantity must be greater than zero.');
        }
        $this->transactional(function () use ($clientPackageId, $quantity, $context): void {
            $branchId = $this->requirePositiveBranchId($context['branch_id'] ?? $this->branchContext->getCurrentBranchId());
            $cp = $this->clientPackages->findForUpdateInTenantScope($clientPackageId, $branchId);
            if (!$cp) {
                throw new \RuntimeException('Client package not found.');
            }
            $this->assertPackageRowBranchMatches($cp, $branchId, 'Branch mismatch for package usage.');
            $this->expireClientPackageIfNeededFromRow($cp, $branchId);
            $cp = $this->clientPackages->findForUpdateInTenantScope($clientPackageId, $branchId);
            if (!$cp) {
                throw new \RuntimeException('Client package not found.');
            }
            if (($cp['status'] ?? '') !== 'active') {
                throw new \DomainException('Only active client packages can be used.');
            }
            $currentRemaining = $this->getRemainingSessions((int) $cp['id'], $branchId);
            if ($quantity > $currentRemaining) {
                throw new \DomainException('Use quantity exceeds remaining sessions.');
            }
            $newRemaining = $currentRemaining - $quantity;
            $newStatus = $newRemaining <= 0 ? 'used' : 'active';
            $userId = $this->currentUserId();

            $this->usages->create([
                'client_package_id' => (int) $cp['id'],
                'branch_id' => $cp['branch_id'] ?? null,
                'usage_type' => 'use',
                'quantity' => $quantity,
                'remaining_after' => $newRemaining,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'notes' => $context['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->clientPackages->updateInTenantScope((int) $cp['id'], $branchId, [
                'remaining_sessions' => $newRemaining,
                'status' => $newStatus,
                'updated_by' => $userId,
            ]);

            $this->audit->log('package_used', 'client_package', (int) $cp['id'], $userId, $cp['branch_id'] !== null ? (int) $cp['branch_id'] : null, [
                'used_quantity' => $quantity,
                'remaining_before' => $currentRemaining,
                'remaining_after' => $newRemaining,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
            ]);
        }, 'package use');
    }

    public function adjustPackageSessions(int $clientPackageId, int $delta, array $context = []): void
    {
        if ($delta === 0) {
            throw new \DomainException('Adjustment quantity cannot be zero.');
        }
        $this->transactional(function () use ($clientPackageId, $delta, $context): void {
            $branchId = $this->requirePositiveBranchId($context['branch_id'] ?? $this->branchContext->getCurrentBranchId());
            $cp = $this->clientPackages->findForUpdateInTenantScope($clientPackageId, $branchId);
            if (!$cp) {
                throw new \RuntimeException('Client package not found.');
            }
            $this->assertPackageRowBranchMatches($cp, $branchId, 'Branch mismatch for package adjustment.');
            $this->expireClientPackageIfNeededFromRow($cp, $branchId);
            $cp = $this->clientPackages->findForUpdateInTenantScope($clientPackageId, $branchId);
            if (!$cp) {
                throw new \RuntimeException('Client package not found.');
            }
            if (($cp['status'] ?? '') === 'cancelled') {
                throw new \DomainException('Cancelled client package cannot be adjusted.');
            }
            $currentRemaining = $this->getRemainingSessions((int) $cp['id'], $branchId);
            $assignedSessions = (int) ($cp['assigned_sessions'] ?? 0);
            $newRemaining = $currentRemaining + $delta;
            if ($newRemaining < 0) {
                throw new \DomainException('Adjustment cannot make remaining sessions negative.');
            }
            if ($newRemaining > $assignedSessions) {
                throw new \DomainException('Adjustment cannot increase remaining above assigned sessions.');
            }
            $newStatus = $newRemaining <= 0 ? 'used' : (($cp['status'] ?? '') === 'expired' ? 'expired' : 'active');
            $userId = $this->currentUserId();

            $this->usages->create([
                'client_package_id' => (int) $cp['id'],
                'branch_id' => $cp['branch_id'] ?? null,
                'usage_type' => 'adjustment',
                'quantity' => $delta,
                'remaining_after' => $newRemaining,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'notes' => $context['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->clientPackages->updateInTenantScope((int) $cp['id'], $branchId, [
                'remaining_sessions' => $newRemaining,
                'status' => $newStatus,
                'updated_by' => $userId,
            ]);

            $this->audit->log('package_adjusted', 'client_package', (int) $cp['id'], $userId, $cp['branch_id'] !== null ? (int) $cp['branch_id'] : null, [
                'adjustment' => $delta,
                'remaining_before' => $currentRemaining,
                'remaining_after' => $newRemaining,
            ]);
        }, 'package adjust');
    }

    public function reversePackageUsage(int $clientPackageId, int $usageId, array $context = []): void
    {
        $this->transactional(function () use ($clientPackageId, $usageId, $context): void {
            $branchId = $this->requirePositiveBranchId($context['branch_id'] ?? $this->branchContext->getCurrentBranchId());
            $cp = $this->clientPackages->findForUpdateInTenantScope($clientPackageId, $branchId);
            if (!$cp) {
                throw new \RuntimeException('Client package not found.');
            }
            $this->assertPackageRowBranchMatches($cp, $branchId, 'Branch mismatch for package reverse.');
            $original = $this->usages->find($usageId);
            if (!$original || (int) $original['client_package_id'] !== (int) $cp['id']) {
                throw new \DomainException('Usage row not found for this client package.');
            }
            if (in_array($original['usage_type'], ['reverse', 'cancel', 'expire'], true)) {
                throw new \DomainException('This usage row cannot be reversed.');
            }
            if (($original['reference_type'] ?? null) === 'assignment') {
                throw new \DomainException('Assignment seed usage cannot be reversed directly.');
            }
            if ($this->usages->findReverseForUsage($usageId)) {
                throw new \DomainException('Usage row already reversed.');
            }

            $currentRemaining = $this->getRemainingSessions((int) $cp['id'], $branchId);
            $assignedSessions = (int) ($cp['assigned_sessions'] ?? 0);

            $originalEffect = match ($original['usage_type']) {
                'use' => -abs((int) $original['quantity']),
                'adjustment' => (int) $original['quantity'],
                default => 0,
            };
            $reverseDelta = -$originalEffect;
            if ($reverseDelta === 0) {
                throw new \DomainException('Resolved reverse delta is zero; reverse is invalid.');
            }
            $newRemaining = $currentRemaining + $reverseDelta;
            if ($newRemaining < 0) {
                throw new \DomainException('Reverse cannot make remaining sessions negative.');
            }
            if ($newRemaining > $assignedSessions) {
                throw new \DomainException('Reverse cannot increase remaining above assigned sessions.');
            }

            $newStatus = ($cp['status'] ?? '') === 'expired' ? 'expired' : ($newRemaining <= 0 ? 'used' : 'active');
            $userId = $this->currentUserId();
            $newUsageId = $this->usages->create([
                'client_package_id' => (int) $cp['id'],
                'branch_id' => $cp['branch_id'] ?? null,
                'usage_type' => 'reverse',
                'quantity' => $reverseDelta,
                'remaining_after' => $newRemaining,
                'reference_type' => 'package_usage',
                'reference_id' => $usageId,
                'notes' => $context['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->clientPackages->updateInTenantScope((int) $cp['id'], $branchId, [
                'remaining_sessions' => $newRemaining,
                'status' => $newStatus,
                'updated_by' => $userId,
            ]);

            $this->audit->log('package_usage_reversed', 'client_package', (int) $cp['id'], $userId, $cp['branch_id'] !== null ? (int) $cp['branch_id'] : null, [
                'usage_id' => $usageId,
                'reverse_usage_id' => $newUsageId,
                'reverse_delta' => $reverseDelta,
                'remaining_before' => $currentRemaining,
                'remaining_after' => $newRemaining,
            ]);
        }, 'package reverse');
    }

    public function cancelClientPackage(int $clientPackageId, ?string $notes = null, ?int $branchContext = null): void
    {
        $branchContext = $this->requirePositiveBranchId($branchContext ?? $this->branchContext->getCurrentBranchId());
        $this->transactional(function () use ($clientPackageId, $notes, $branchContext): void {
            $cp = $this->clientPackages->findForUpdateInTenantScope($clientPackageId, $branchContext);
            if (!$cp) {
                throw new \RuntimeException('Client package not found.');
            }
            $this->assertPackageRowBranchMatches($cp, $branchContext, 'Branch mismatch for package cancel.');
            if (($cp['status'] ?? '') === 'cancelled') {
                return;
            }
            if (($cp['status'] ?? '') === 'expired') {
                throw new \DomainException('Expired client package cannot be cancelled.');
            }
            $remaining = $this->getRemainingSessions((int) $cp['id'], $branchContext);
            $userId = $this->currentUserId();

            $this->usages->create([
                'client_package_id' => (int) $cp['id'],
                'branch_id' => $cp['branch_id'] ?? null,
                'usage_type' => 'cancel',
                'quantity' => 0,
                'remaining_after' => $remaining,
                'reference_type' => null,
                'reference_id' => null,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $this->clientPackages->updateInTenantScope((int) $cp['id'], $branchContext, [
                'status' => 'cancelled',
                'updated_by' => $userId,
                'notes' => $notes ?: ($cp['notes'] ?? null),
            ]);

            $this->audit->log('package_cancelled', 'client_package', (int) $cp['id'], $userId, $cp['branch_id'] !== null ? (int) $cp['branch_id'] : null, [
                'remaining_at_cancel' => $remaining,
                'notes' => $notes,
            ]);
        }, 'package cancel');
    }

    public function expireClientPackageIfNeeded(int $clientPackageId, ?int $branchContext = null): bool
    {
        $branchContext = $this->requirePositiveBranchId($branchContext ?? $this->branchContext->getCurrentBranchId());
        return $this->transactional(function () use ($clientPackageId, $branchContext): bool {
            $cp = $this->clientPackages->findForUpdateInTenantScope($clientPackageId, $branchContext);
            if (!$cp) {
                throw new \RuntimeException('Client package not found.');
            }
            return $this->expireClientPackageIfNeededFromRow($cp, $branchContext);
        }, 'package expire check');
    }

    public function getRemainingSessions(int $clientPackageId, ?int $branchContext = null): int
    {
        $latest = $this->usages->latestForClientPackage($clientPackageId);
        if ($latest) {
            return (int) ($latest['remaining_after'] ?? 0);
        }
        $resolvedBranchId = $this->requirePositiveBranchId($branchContext ?? $this->branchContext->getCurrentBranchId());
        $cp = $this->clientPackages->findInTenantScope($clientPackageId, $resolvedBranchId);
        return (int) ($cp['remaining_sessions'] ?? 0);
    }

    public function listEligibleClientPackages(int $clientId, ?int $branchContext = null): array
    {
        if ($branchContext === null || $branchContext <= 0) {
            return [];
        }
        if ($this->clients->find($clientId) === null) {
            return [];
        }
        $rows = $this->clientPackages->listEligibleForClientInTenantScope($clientId, $branchContext);
        $eligible = [];
        foreach ($rows as $row) {
            $remaining = $this->getRemainingSessions((int) $row['client_package_id'], $branchContext);
            if ($remaining <= 0) {
                continue;
            }
            $eligible[] = [
                'client_package_id' => (int) $row['client_package_id'],
                'package_id' => (int) $row['package_id'],
                'package_name' => (string) ($row['package_name'] ?? ''),
                'branch_id' => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
                'status' => $remaining <= 0 ? 'used' : (string) ($row['status'] ?? 'active'),
                'assigned_sessions' => (int) ($row['assigned_sessions'] ?? 0),
                'remaining_sessions' => $remaining,
                'expires_at' => $row['expires_at'] ?? null,
            ];
        }
        return $eligible;
    }

    public function hasAppointmentConsumption(int $appointmentId, int $clientPackageId): bool
    {
        return $this->usages->existsUsageByReference($clientPackageId, 'use', 'appointment', $appointmentId);
    }

    public function consumeForCompletedAppointment(
        int $appointmentId,
        int $clientId,
        int $clientPackageId,
        int $quantity,
        ?int $branchContext = null,
        ?string $notes = null
    ): void {
        if ($quantity <= 0) {
            throw new \DomainException('Appointment package quantity must be greater than zero.');
        }

        $this->transactional(function () use ($appointmentId, $clientId, $clientPackageId, $quantity, $branchContext, $notes): void {
            $operationBranch = $this->requirePositiveBranchId($branchContext ?? $this->branchContext->getCurrentBranchId());
            $cp = $this->clientPackages->findForUpdateInTenantScope($clientPackageId, $operationBranch);
            if (!$cp) {
                throw new \RuntimeException('Client package not found.');
            }
            if ((int) ($cp['client_id'] ?? 0) !== $clientId) {
                throw new \DomainException('Client package does not belong to this appointment client.');
            }
            $this->assertPackageRowBranchMatches($cp, $operationBranch, 'Branch mismatch for appointment package consumption.');

            if ($this->usages->existsUsageByReference((int) $cp['id'], 'use', 'appointment', $appointmentId)) {
                throw new \DomainException('This appointment already consumed sessions from the selected package.');
            }

            $this->expireClientPackageIfNeededFromRow($cp, $operationBranch);
            $cp = $this->clientPackages->findForUpdateInTenantScope($clientPackageId, $operationBranch);
            if (!$cp) {
                throw new \RuntimeException('Client package not found.');
            }
            if (($cp['status'] ?? '') !== 'active') {
                throw new \DomainException('Only active client packages can be consumed for appointments.');
            }

            $currentRemaining = $this->getRemainingSessions((int) $cp['id'], $operationBranch);
            if ($quantity > $currentRemaining) {
                throw new \DomainException('Appointment quantity exceeds remaining sessions.');
            }

            $newRemaining = $currentRemaining - $quantity;
            $newStatus = $newRemaining <= 0 ? 'used' : 'active';
            $userId = $this->currentUserId();

            $this->usages->create([
                'client_package_id' => (int) $cp['id'],
                'branch_id' => $cp['branch_id'] ?? null,
                'usage_type' => 'use',
                'quantity' => $quantity,
                'remaining_after' => $newRemaining,
                'reference_type' => 'appointment',
                'reference_id' => $appointmentId,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $this->clientPackages->updateInTenantScope((int) $cp['id'], $operationBranch, [
                'remaining_sessions' => $newRemaining,
                'status' => $newStatus,
                'updated_by' => $userId,
            ]);

            $this->audit->log('package_used', 'client_package', (int) $cp['id'], $userId, $cp['branch_id'] !== null ? (int) $cp['branch_id'] : null, [
                'used_quantity' => $quantity,
                'remaining_before' => $currentRemaining,
                'remaining_after' => $newRemaining,
                'reference_type' => 'appointment',
                'reference_id' => $appointmentId,
            ]);
        }, 'appointment package consume');
    }

    private function expireClientPackageIfNeededFromRow(array $cp, int $branchContext): bool
    {
        $this->assertPackageRowBranchMatches($cp, $branchContext, 'Branch mismatch for package expiry.');
        if (($cp['status'] ?? '') !== 'active') {
            return false;
        }
        if (empty($cp['expires_at'])) {
            return false;
        }
        if (strtotime((string) $cp['expires_at']) > time()) {
            return false;
        }

        $remaining = $this->getRemainingSessions((int) $cp['id'], $branchContext);
        $userId = $this->currentUserId();
        $this->usages->create([
            'client_package_id' => (int) $cp['id'],
            'branch_id' => $cp['branch_id'] ?? null,
            'usage_type' => 'expire',
            'quantity' => 0,
            'remaining_after' => $remaining,
            'reference_type' => null,
            'reference_id' => null,
            'notes' => 'Auto-expired by expires_at',
            'created_by' => $userId,
        ]);

        $this->clientPackages->updateInTenantScope((int) $cp['id'], $branchContext, [
            'status' => 'expired',
            'updated_by' => $userId,
        ]);

        $this->audit->log('package_expired', 'client_package', (int) $cp['id'], $userId, $cp['branch_id'] !== null ? (int) $cp['branch_id'] : null, [
            'expired_at' => date('Y-m-d H:i:s'),
            'remaining_at_expiry' => $remaining,
        ]);
        return true;
    }

    private function assertPackageRowBranchMatches(array $row, ?int $branchContext, string $message): void
    {
        if (($row['branch_id'] ?? null) !== null && $branchContext === null) {
            throw new \DomainException('Branch context is required for branch-owned package operation.');
        }
        if (($row['branch_id'] ?? null) !== null && $branchContext !== null && (int) $row['branch_id'] !== (int) $branchContext) {
            throw new \DomainException($message);
        }
    }

    private function requirePositiveBranchId(?int $branchId): int
    {
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for package operation.');
        }

        return $branchId;
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $pdo = $this->db->connection();
        $startedTransaction = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }
            $result = $callback();
            if ($startedTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'packages.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Package operation failed.');
        }
    }
}
