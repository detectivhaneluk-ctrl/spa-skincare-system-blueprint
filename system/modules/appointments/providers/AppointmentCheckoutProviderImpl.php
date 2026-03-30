<?php

declare(strict_types=1);

namespace Modules\Appointments\Providers;

use Core\Contracts\AppointmentCheckoutProvider;
use Core\Contracts\ServiceListProvider;
use Modules\Appointments\Repositories\AppointmentRepository;

/**
 * Provides appointment data for invoice prefill.
 * Uses only contracts (ServiceListProvider) for service price — no direct ServiceRepository access.
 */
final class AppointmentCheckoutProviderImpl implements AppointmentCheckoutProvider
{
    public function __construct(
        private AppointmentRepository $repo,
        private ServiceListProvider $serviceList
    ) {
    }

    public function getCheckoutPrefill(int $appointmentId): ?array
    {
        $apt = $this->repo->find($appointmentId);
        if (!$apt || $apt['deleted_at'] ?? null) return null;
        $clientName = trim(($apt['client_first_name'] ?? '') . ' ' . ($apt['client_last_name'] ?? ''));
        $serviceName = $apt['service_name'] ?? '';
        $servicePrice = 0.0;
        if (!empty($apt['service_id'])) {
            $svc = $this->serviceList->find((int) $apt['service_id']);
            $servicePrice = $svc['price'] ?? 0.0;
        }
        return [
            'client_id' => !empty($apt['client_id']) ? (int) $apt['client_id'] : null,
            'client_name' => $clientName ?: '',
            'service_id' => !empty($apt['service_id']) ? (int) $apt['service_id'] : null,
            'service_name' => $serviceName,
            'service_price' => (float) $servicePrice,
            'branch_id' => !empty($apt['branch_id']) ? (int) $apt['branch_id'] : null,
        ];
    }
}
