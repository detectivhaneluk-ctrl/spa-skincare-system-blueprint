<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Modules\Organizations\Repositories\PlatformFounderAuditReadRepository;

/**
 * Founder security overview: access-audit listing + platform-wide public-surface kill switches.
 * FOUNDER-AUDIT-VISIBILITY-AND-PUBLIC-SURFACE-CONTROL-01.
 */
final class PlatformFounderSecurityService
{
    public function __construct(
        private Database $db,
        private SettingsService $settings,
        private AuditService $audit,
        private PlatformFounderAuditReadRepository $auditReads,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccessPlaneAuditEvents(int $limit = 200): array
    {
        return $this->auditReads->listAccessPlaneEvents($limit);
    }

    /**
     * @return array{kill_online_booking: bool, kill_anonymous_public_apis: bool, kill_public_commerce: bool}
     */
    public function getPublicSurfaceKillSwitchState(): array
    {
        return $this->settings->getPlatformFounderPublicSurfaceKillSwitches();
    }

    /**
     * @param array{kill_online_booking: bool, kill_anonymous_public_apis: bool, kill_public_commerce: bool} $state
     * @param array<string, mixed>|null $auditExtra merged into audit metadata
     */
    public function applyPublicSurfaceKillSwitches(int $actorUserId, array $state, ?array $auditExtra = null): void
    {
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Invalid actor.');
        }
        $normalized = [
            'kill_online_booking' => !empty($state['kill_online_booking']),
            'kill_anonymous_public_apis' => !empty($state['kill_anonymous_public_apis']),
            'kill_public_commerce' => !empty($state['kill_public_commerce']),
        ];
        $this->db->transaction(function () use ($actorUserId, $normalized, $auditExtra): void {
            $before = $this->settings->getPlatformFounderPublicSurfaceKillSwitches();
            $this->settings->setPlatformFounderPublicSurfaceKillSwitches($normalized);
            $this->audit->log('founder_public_surface_kill_switches_updated', 'settings', null, $actorUserId, null, array_merge([
                'before' => $before,
                'after' => $normalized,
            ], $auditExtra ?? []));
        });
    }
}
