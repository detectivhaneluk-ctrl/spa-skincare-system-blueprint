<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Provides appointment data for checkout/invoice prefill.
 * Implementation lives in Appointments module.
 * Use when creating an invoice from an appointment (no direct repository access).
 */
interface AppointmentCheckoutProvider
{
    /**
     * @return array{client_id: int|null, client_name: string, service_id: int|null, service_name: string, service_price: float, branch_id: int|null}|null
     */
    public function getCheckoutPrefill(int $appointmentId): ?array;
}
