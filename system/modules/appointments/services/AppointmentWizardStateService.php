<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\Auth\SessionAuth;

/**
 * Manages server-side wizard state for the full-page appointment creation wizard.
 * State is stored in $_SESSION under a versioned key so it survives multi-step navigation.
 *
 * The quick-drawer flow NEVER touches this service.
 * Blocked-time flow NEVER touches this service.
 */
final class AppointmentWizardStateService
{
    public const SESSION_KEY = 'appointment_wizard_v1';
    public const VERSION     = 1;

    public function __construct(
        private SessionAuth $sessionAuth,
    ) {
    }

    /**
     * Read current wizard state from session. Returns null if no valid state exists.
     *
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        $this->sessionAuth->startSession();
        $raw = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($raw) || (int) ($raw['version'] ?? 0) !== self::VERSION) {
            return null;
        }

        return $raw;
    }

    /**
     * Initialise a fresh wizard state for the given branch, optionally prefilling from GET context.
     * Overwrites any existing wizard state.
     *
     * @param array<string, mixed> $prefill  Optional GET params (date, etc.) carried from the referral URL.
     * @return array<string, mixed>
     */
    public function init(int $branchId, array $prefill = []): array
    {
        $rawDate = (string) ($prefill['date'] ?? '');
        $date    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) === 1 ? $rawDate : null;

        $state = [
            'version'    => self::VERSION,
            'branch_id'  => $branchId,
            'flow'       => 'full_page_wizard',
            'step'       => 0,
            'started_at' => time(),
            'booking_mode'               => 'standalone',   // 'standalone' | 'linked_chain'
            'pending_chain_continuation' => null,            // set during linked add-another flow
            'search'     => [
                'mode'                => 'service',
                'guests'              => 1,
                'category_id'         => null,
                'service_id'          => null,
                'package_id'          => null,
                'date_mode'           => 'exact',
                'date'                => $date,
                'date_from'           => null,
                'date_to'             => null,
                'staff_id'            => null,
                'room_id'             => null,
                'include_freelancers' => false,
            ],
            'availability_results' => [],
            'service_lines'        => [],
            'client'               => [
                'mode'      => 'existing',
                'client_id' => null,
                'draft'     => [],
            ],
            'payment'  => ['mode' => 'none'],  // replaced by real state after step 4
            'checksum' => '',
        ];

        $state['checksum'] = $this->buildChecksum($state);
        $this->save($state);

        return $state;
    }

    /**
     * Persist wizard state to session.
     *
     * @param array<string, mixed> $state
     */
    public function save(array $state): void
    {
        $this->sessionAuth->startSession();
        $_SESSION[self::SESSION_KEY] = $state;
    }

    /**
     * Clear wizard state from session.
     */
    public function clear(): void
    {
        $this->sessionAuth->startSession();
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Get wizard state and verify it belongs to the expected branch.
     * Returns null (and clears state) if missing, corrupt, or wrong branch.
     *
     * @return array<string, mixed>|null
     */
    public function getValidForBranch(int $branchId): ?array
    {
        $state = $this->get();
        if ($state === null) {
            return null;
        }
        if ((int) ($state['branch_id'] ?? 0) !== $branchId) {
            $this->clear();

            return null;
        }

        return $state;
    }

    /**
     * Build a deterministic SHA-256 checksum over the branch + service_lines.
     * Used for stale-detection at commit time.
     *
     * @param array<string, mixed> $state
     */
    public function buildChecksum(array $state): string
    {
        $parts = [
            (int) ($state['branch_id'] ?? 0),
            json_encode($state['service_lines'] ?? [], JSON_THROW_ON_ERROR),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Rebuild and store a fresh checksum after mutating service_lines. Mutates $state in place.
     *
     * @param array<string, mixed> $state
     */
    public function refreshChecksum(array &$state): void
    {
        $state['checksum'] = $this->buildChecksum($state);
    }
}
