<?php

declare(strict_types=1);

namespace Modules\Packages\Providers;

use Core\Contracts\AppointmentPackageConsumptionProvider;
use Modules\Packages\Repositories\PackageUsageRepository;
use Modules\Packages\Services\PackageService;

final class AppointmentPackageConsumptionProviderImpl implements AppointmentPackageConsumptionProvider
{
    public function __construct(
        private PackageService $service,
        private PackageUsageRepository $usages
    ) {
    }

    public function consumeForCompletedAppointment(
        int $appointmentId,
        int $clientId,
        int $clientPackageId,
        int $quantity,
        ?int $branchContext = null,
        ?string $notes = null
    ): void {
        $this->service->consumeForCompletedAppointment(
            $appointmentId,
            $clientId,
            $clientPackageId,
            $quantity,
            $branchContext,
            $notes
        );
    }

    public function hasAppointmentConsumption(int $appointmentId, int $clientPackageId): bool
    {
        return $this->service->hasAppointmentConsumption($appointmentId, $clientPackageId);
    }

    public function listAppointmentConsumptions(int $appointmentId): array
    {
        return array_map(
            static fn (array $row): array => [
                'usage_id' => (int) $row['usage_id'],
                'client_package_id' => (int) $row['client_package_id'],
                'package_name' => (string) ($row['package_name'] ?? ''),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'remaining_after' => (int) ($row['remaining_after'] ?? 0),
                'branch_id' => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ],
            $this->usages->listAppointmentConsumptions($appointmentId)
        );
    }
}
