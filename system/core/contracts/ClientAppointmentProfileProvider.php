<?php

declare(strict_types=1);

namespace Core\Contracts;

interface ClientAppointmentProfileProvider
{
    /**
     * @return array{
     *   total:int,
     *   scheduled:int,
     *   confirmed:int,
     *   in_progress:int,
     *   completed:int,
     *   cancelled:int,
     *   no_show:int,
     *   no_show_alert_enabled:bool,
     *   no_show_alert_threshold:int,
     *   no_show_alert_triggered:bool,
     *   no_show_alert: array{
     *     active: bool,
     *     code: string,
     *     severity: string,
     *     settings_enabled: bool,
     *     recorded_no_show_count: int,
     *     threshold: int,
     *     message: string
     *   },
     *   last_start_at: string|null,
     *   first_start_at: string|null
     * }
     */
    public function getSummary(int $clientId): array;

    /**
     * @return array<int, array{
     *   id:int,
     *   start_at:string,
     *   end_at:string,
     *   status:string,
     *   service_name:string|null,
     *   staff_name:string|null,
     *   room_name:string|null
     * }>
     */
    public function listRecent(int $clientId, int $limit = 10): array;

    /**
     * Client résumé / profile: paginated, filtered appointment rows for one client.
     * Scoping matches {@see listRecent()} (profile access + organization branch EXISTS on `appointments`).
     *
     * @param array{
     *   status?:string|null,
     *   date_mode?:string,
     *   date_from?:string|null,
     *   date_to?:string|null,
     *   page?:int,
     *   per_page?:int
     * } $query
     *
     * @return array{
     *   items: list<array{
     *     id:int,
     *     start_at:string,
     *     end_at:string,
     *     created_at:string,
     *     status:string,
     *     service_name:string|null,
     *     staff_name:string|null,
     *     room_name:string|null
     *   }>,
     *   total:int,
     *   page:int,
     *   per_page:int
     * }
     */
    public function listForClientProfile(int $clientId, array $query): array;
}
