<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Allows explicit package consumption tied to appointments, without direct package repository access.
 * Implementation lives in Packages module.
 */
interface AppointmentPackageConsumptionProvider
{
    public function consumeForCompletedAppointment(
        int $appointmentId,
        int $clientId,
        int $clientPackageId,
        int $quantity,
        ?int $branchContext = null,
        ?string $notes = null
    ): void;

    public function hasAppointmentConsumption(int $appointmentId, int $clientPackageId): bool;

    /**
     * @return array<int, array{
     *   usage_id:int,
     *   client_package_id:int,
     *   package_name:string,
     *   quantity:int,
     *   remaining_after:int,
     *   branch_id:int|null,
     *   created_at:string
     * }>
     */
    public function listAppointmentConsumptions(int $appointmentId): array;
}
