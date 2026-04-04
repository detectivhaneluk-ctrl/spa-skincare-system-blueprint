<?php

declare(strict_types=1);

namespace Modules\Appointments\Controllers;

use Core\App\Response;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Branch\TenantBranchAccessService;
use Core\Errors\AccessDeniedException;
use Core\Organization\OrganizationScopedBranchAssert;
use Modules\Appointments\Services\AppointmentWizardService;
use Modules\Appointments\Services\AppointmentWizardStateService;

/**
 * Handles the full-page appointment creation wizard (Steps 1–4 + review/step5 + commit).
 *
 * Phase 2 changes over Phase 1:
 * - step3Submit now redirects to step4 (payment) instead of review
 * - step4() / step4Submit() methods added (payment contract)
 * - review() becomes step 5 (real review with payment summary)
 * - step2Submit handles action=add_linked for linked-chain continuation
 * - step2AddLine now carries predecessor_index for linked-chain lines
 * - Package mode is fail-closed upstream in the service
 *
 * Entry contract:
 *   - GET  /appointments/wizard                → entry()
 *   - GET  /appointments/wizard/step1          → step1()
 *   - POST /appointments/wizard/step1          → step1Submit()
 *   - GET  /appointments/wizard/step2          → step2()
 *   - POST /appointments/wizard/step2          → step2Submit()
 *   - POST /appointments/wizard/step2/line     → step2AddLine()
 *   - GET  /appointments/wizard/step3          → step3()
 *   - POST /appointments/wizard/step3          → step3Submit()
 *   - GET  /appointments/wizard/step4          → step4()         [NEW Phase 2]
 *   - POST /appointments/wizard/step4          → step4Submit()   [NEW Phase 2]
 *   - GET  /appointments/wizard/review         → review()        [Phase 2: becomes step 5]
 *   - POST /appointments/wizard/commit         → commit()
 *   - GET  /appointments/wizard/client-search  → clientSearch()
 *   - POST /appointments/wizard/cancel         → cancel()
 *
 * Quick drawer, blocked-time, and all other flows are NOT touched by this controller.
 */
final class AppointmentWizardController
{
    public function __construct(
        private AppointmentWizardStateService $stateService,
        private AppointmentWizardService $wizardService,
        private SessionAuth $sessionAuth,
        private BranchContext $branchContext,
        private BranchDirectory $branchDirectory,
        private TenantBranchAccessService $tenantBranchAccess,
        private OrganizationScopedBranchAssert $orgBranchAssert,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Entry
    // ──────────────────────────────────────────────────────────────────────────

    public function entry(): void
    {
        $branchId = $this->resolveBranchFromGetOrFail();

        $existing = $this->stateService->getValidForBranch($branchId);
        if ($existing === null) {
            $this->stateService->init($branchId, $_GET);
        }

        $qs = '?' . http_build_query(['branch_id' => $branchId]);
        header('Location: /appointments/wizard/step1' . $qs);
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 1 — Availability Search
    // ──────────────────────────────────────────────────────────────────────────

    public function step1(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        $continuation = $state['pending_chain_continuation'] ?? null;
        $csrf         = $this->sessionAuth->csrfToken();
        $errors       = [];
        $flash        = flash();
        $categories   = $this->wizardService->getCategories($branchId);
        $services     = $this->wizardService->getServices($branchId);
        $staff        = $this->wizardService->getStaff($branchId);
        $rooms        = $this->wizardService->getRooms($branchId);
        $branchName   = $this->resolveBranchName($branchId);
        $workspace    = $this->buildWorkspace($branchId, $branchName);
        $title        = 'New Appointment — Step 1';

        require base_path('modules/appointments/views/wizard/step1.php');
    }

    public function step1Submit(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        $data   = $_POST;
        $errors = $this->wizardService->validateStep1($data, $branchId);

        if (!empty($errors)) {
            $continuation = $state['pending_chain_continuation'] ?? null;
            $csrf         = $this->sessionAuth->csrfToken();
            $flash        = [];
            $categories   = $this->wizardService->getCategories($branchId);
            $services     = $this->wizardService->getServices($branchId);
            $staff        = $this->wizardService->getStaff($branchId);
            $rooms        = $this->wizardService->getRooms($branchId);
            $branchName   = $this->resolveBranchName($branchId);
            $workspace    = $this->buildWorkspace($branchId, $branchName);
            $title        = 'New Appointment — Step 1';

            $state['search'] = array_merge($state['search'] ?? [], $this->extractSearchFromPost($data));
            require base_path('modules/appointments/views/wizard/step1.php');

            return;
        }

        $state['search'] = array_merge($state['search'] ?? [], $this->extractSearchFromPost($data));

        // Run availability search (pass continuation context if present for linked-chain).
        $continuation                    = $state['pending_chain_continuation'] ?? null;
        $availabilityResults             = $this->wizardService->runSearch($state['search'], $branchId, $continuation);
        $state['availability_results']   = $availabilityResults;
        $state['step']                   = max((int) ($state['step'] ?? 0), 1);
        $this->stateService->save($state);

        $qs = '?' . http_build_query(['branch_id' => $branchId]);

        // If no results found, stay on step 1 with a clear message rather than
        // routing to step 2 (which would show "No slots — go back" dead-end).
        if (empty($availabilityResults)) {
            flash('info', 'No available slots found for your search criteria. Try a different date, service, or staff filter.');
            header('Location: /appointments/wizard/step1' . $qs);
            exit;
        }

        header('Location: /appointments/wizard/step2' . $qs);
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 2 — Resource Allocation (linked-chain aware)
    // ──────────────────────────────────────────────────────────────────────────

    public function step2(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        if (empty($state['availability_results'] ?? [])) {
            flash('info', 'Please run a search first.');
            $qs = '?' . http_build_query(['branch_id' => $branchId]);
            header('Location: /appointments/wizard/step1' . $qs);
            exit;
        }

        $csrf       = $this->sessionAuth->csrfToken();
        $errors     = [];
        $flash      = flash();
        $branchName = $this->resolveBranchName($branchId);
        $workspace  = $this->buildWorkspace($branchId, $branchName);
        $title      = 'New Appointment — Step 2';

        require base_path('modules/appointments/views/wizard/step2.php');
    }

    /**
     * POST step 2: handles 'continue', 'remove_{index}', and 'add_linked' actions.
     *
     * add_linked: activates linked-chain mode, sets pending_chain_continuation,
     * then redirects to step1 so the user searches for a continuation service.
     */
    public function step2Submit(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        $action = (string) ($_POST['action'] ?? 'continue');
        $qs     = '?' . http_build_query(['branch_id' => $branchId]);

        // ── Remove a service line ──────────────────────────────────────────
        if (str_starts_with($action, 'remove_')) {
            $removeIndex = (int) substr($action, 7);
            $lines       = $state['service_lines'] ?? [];

            $lines = $this->wizardService->detachSuccessors($lines, $removeIndex);
            unset($lines[$removeIndex]);
            $state['service_lines'] = array_values($lines);
            foreach ($state['service_lines'] as $i => $line) {
                $state['service_lines'][$i]['index'] = $i;
            }
            // If all lines removed, reset booking_mode to standalone.
            if (empty($state['service_lines'])) {
                $state['booking_mode'] = 'standalone';
            }
            $this->stateService->refreshChecksum($state);
            $this->stateService->save($state);

            header('Location: /appointments/wizard/step2' . $qs);
            exit;
        }

        // ── Add another service (linked-chain) ────────────────────────────
        if ($action === 'add_linked') {
            $lines = $state['service_lines'] ?? [];
            if (empty($lines)) {
                flash('error', 'Please add at least one service before adding a linked continuation service.');
                header('Location: /appointments/wizard/step2' . $qs);
                exit;
            }

            $continuation = $this->wizardService->buildContinuationContext($lines);
            if ($continuation === null) {
                flash('error', 'Cannot determine continuation time from current service lines. Please ensure all lines have valid slots.');
                header('Location: /appointments/wizard/step2' . $qs);
                exit;
            }

            $predecessorIndex                    = count($lines) - 1;
            $state['booking_mode']               = 'linked_chain';
            $state['pending_chain_continuation'] = array_merge($continuation, [
                'predecessor_index' => $predecessorIndex,
            ]);
            $this->stateService->save($state);

            header('Location: /appointments/wizard/step1' . $qs);
            exit;
        }

        // ── Continue to step 3 ────────────────────────────────────────────
        $errors = [];
        if (empty($state['service_lines'] ?? [])) {
            $errors['service_lines'] = 'Please select at least one available time slot before continuing.';
        }

        if (!empty($errors)) {
            $csrf       = $this->sessionAuth->csrfToken();
            $flash      = [];
            $branchName = $this->resolveBranchName($branchId);
            $workspace  = $this->buildWorkspace($branchId, $branchName);
            $title      = 'New Appointment — Step 2';
            require base_path('modules/appointments/views/wizard/step2.php');

            return;
        }

        $state['step'] = max((int) ($state['step'] ?? 0), 2);
        $this->stateService->save($state);

        header('Location: /appointments/wizard/step3' . $qs);
        exit;
    }

    /**
     * POST step 2 add line: append a selected availability result as a new service_line.
     * Carries predecessor_index from pending_chain_continuation if linked mode is active.
     */
    public function step2AddLine(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        $availabilityResults = $state['availability_results'] ?? [];
        $errors              = $this->wizardService->validateStep2Selection($_POST, $availabilityResults);
        $qs                  = '?' . http_build_query(['branch_id' => $branchId]);

        if (!empty($errors)) {
            flash('error', reset($errors));
            header('Location: /appointments/wizard/step2' . $qs);
            exit;
        }

        $resultKey       = trim((string) ($_POST['result_key'] ?? ''));
        $lockToStaff     = !empty($_POST['lock_to_staff']);
        $requested       = !empty($_POST['requested']);
        $lineIndex       = count($state['service_lines'] ?? []);

        // Resolve predecessor_index from pending_chain_continuation if set.
        $pendingCont      = $state['pending_chain_continuation'] ?? null;
        $predecessorIndex = $pendingCont !== null
            ? (int) ($pendingCont['predecessor_index'] ?? 0)
            : null;

        $line = $this->wizardService->buildServiceLine(
            $resultKey,
            $availabilityResults,
            $lineIndex,
            $branchId,
            $lockToStaff,
            $requested,
            $predecessorIndex
        );

        if ($line === null) {
            flash('error', 'Could not build service line. Please try again.');
            header('Location: /appointments/wizard/step2' . $qs);
            exit;
        }

        $state['service_lines'][] = $line;

        // Clear pending continuation now that the line has been added.
        $state['pending_chain_continuation'] = null;

        $this->stateService->refreshChecksum($state);
        $this->stateService->save($state);

        header('Location: /appointments/wizard/step2' . $qs);
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 3 — Customer Attach / Draft
    // ──────────────────────────────────────────────────────────────────────────

    public function step3(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        if (empty($state['service_lines'] ?? [])) {
            flash('info', 'Please select a time slot first.');
            $qs = '?' . http_build_query(['branch_id' => $branchId]);
            header('Location: /appointments/wizard/step2' . $qs);
            exit;
        }

        $csrf       = $this->sessionAuth->csrfToken();
        $errors     = [];
        $flash      = flash();
        $branchName = $this->resolveBranchName($branchId);
        $workspace  = $this->buildWorkspace($branchId, $branchName);
        $title      = 'New Appointment — Step 3';

        require base_path('modules/appointments/views/wizard/step3.php');
    }

    /**
     * POST step 3: validate customer info, save to state, redirect to step 4 (payment).
     */
    public function step3Submit(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        $data   = $_POST;
        $errors = $this->wizardService->validateStep3Client($data);

        if (!empty($errors)) {
            $csrf       = $this->sessionAuth->csrfToken();
            $flash      = [];
            $branchName = $this->resolveBranchName($branchId);
            $workspace  = $this->buildWorkspace($branchId, $branchName);
            $title      = 'New Appointment — Step 3';
            require base_path('modules/appointments/views/wizard/step3.php');

            return;
        }

        $state['client'] = $this->wizardService->buildClientState($data);
        $state['step']   = max((int) ($state['step'] ?? 0), 3);
        $this->stateService->save($state);

        $qs = '?' . http_build_query(['branch_id' => $branchId]);
        header('Location: /appointments/wizard/step4' . $qs);
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 4 — Payment (Phase 2: new real step)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET step 4: show payment mode selection with totals snapshot from service_lines.
     */
    public function step4(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        if ((int) ($state['step'] ?? 0) < 3) {
            flash('info', 'Please complete your customer details before the payment step.');
            $qs = '?' . http_build_query(['branch_id' => $branchId]);
            header('Location: /appointments/wizard/step3' . $qs);
            exit;
        }

        $csrf         = $this->sessionAuth->csrfToken();
        $errors       = [];
        $flash        = flash();
        $branchName   = $this->resolveBranchName($branchId);
        $workspace    = $this->buildWorkspace($branchId, $branchName);
        $title        = 'New Appointment — Step 4';
        $totals       = $this->wizardService->getPaymentTotals($state);
        $paymentModes = AppointmentWizardService::PAYMENT_MODES;
        $paymentLabels = AppointmentWizardService::PAYMENT_MODE_LABELS;

        require base_path('modules/appointments/views/wizard/step4.php');
    }

    /**
     * POST step 4: validate payment mode, save to state, redirect to review (step 5).
     */
    public function step4Submit(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        $data   = $_POST;
        $errors = $this->wizardService->validateStep4Payment($data);

        if (!empty($errors)) {
            $csrf          = $this->sessionAuth->csrfToken();
            $flash         = [];
            $branchName    = $this->resolveBranchName($branchId);
            $workspace     = $this->buildWorkspace($branchId, $branchName);
            $title         = 'New Appointment — Step 4';
            $totals        = $this->wizardService->getPaymentTotals($state);
            $paymentModes  = AppointmentWizardService::PAYMENT_MODES;
            $paymentLabels = AppointmentWizardService::PAYMENT_MODE_LABELS;
            require base_path('modules/appointments/views/wizard/step4.php');

            return;
        }

        $state['payment'] = $this->wizardService->buildPaymentState($data, $state);
        $state['step']    = max((int) ($state['step'] ?? 0), 4);
        $this->stateService->refreshChecksum($state);
        $this->stateService->save($state);

        $qs = '?' . http_build_query(['branch_id' => $branchId]);
        header('Location: /appointments/wizard/review' . $qs);
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Review / Step 5
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET review (step 5): show full booking summary including payment, then "Confirm and Book".
     */
    public function review(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        if ((int) ($state['step'] ?? 0) < 4) {
            flash('info', 'Please complete the payment step before reviewing.');
            $qs = '?' . http_build_query(['branch_id' => $branchId]);
            header('Location: /appointments/wizard/step4' . $qs);
            exit;
        }

        $csrf         = $this->sessionAuth->csrfToken();
        $errors       = [];
        $flash        = flash();
        $branchName   = $this->resolveBranchName($branchId);
        $workspace    = $this->buildWorkspace($branchId, $branchName);
        $title        = 'New Appointment — Step 5 Review';
        $paymentLabels = AppointmentWizardService::PAYMENT_MODE_LABELS;

        require base_path('modules/appointments/views/wizard/review.php');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Commit
    // ──────────────────────────────────────────────────────────────────────────

    public function commit(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        $userId = $this->sessionAuth->id();
        if ($userId === null || $userId <= 0) {
            flash('error', 'Authentication required.');
            header('Location: /login');
            exit;
        }

        $currentChecksum  = $this->stateService->buildChecksum($state);
        $recordedChecksum = (string) ($state['checksum'] ?? '');
        if ($currentChecksum !== $recordedChecksum) {
            flash('error', 'Your booking session may have changed in another tab. Please review and try again.');
            $qs = '?' . http_build_query(['branch_id' => $branchId]);
            header('Location: /appointments/wizard/review' . $qs);
            exit;
        }

        try {
            $this->wizardService->revalidateForCommit($state);
            $appointmentId = $this->wizardService->commit($state, $userId);
        } catch (\DomainException | \InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $qs = '?' . http_build_query(['branch_id' => $branchId]);
            header('Location: /appointments/wizard/review' . $qs);
            exit;
        } catch (\Throwable) {
            flash('error', 'Could not create appointment. Please try again.');
            $qs = '?' . http_build_query(['branch_id' => $branchId]);
            header('Location: /appointments/wizard/review' . $qs);
            exit;
        }

        $this->stateService->clear();
        flash('success', 'Appointment created successfully.');
        header('Location: /appointments/' . $appointmentId);
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AJAX: Client Search
    // ──────────────────────────────────────────────────────────────────────────

    public function clientSearch(): void
    {
        $state    = $this->requireWizardStateOrRedirect();
        $branchId = (int) $state['branch_id'];
        $this->assertWizardBranchAccess($branchId);

        $query   = trim((string) ($_GET['q'] ?? ''));
        $results = $query !== '' ? $this->wizardService->searchClients($query, $branchId) : [];

        Response::jsonSuccess(['clients' => $results]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Cancel
    // ──────────────────────────────────────────────────────────────────────────

    public function cancel(): void
    {
        $this->stateService->clear();
        flash('info', 'Appointment creation cancelled.');
        header('Location: /appointments/calendar/day');
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function requireWizardStateOrRedirect(): array
    {
        $state = $this->stateService->get();
        if ($state === null) {
            flash('info', 'Your booking session has expired. Please start again.');
            header('Location: /appointments/wizard');
            exit;
        }

        $branchId = (int) ($state['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $this->stateService->clear();
            flash('error', 'Invalid wizard state — missing branch. Please start again.');
            header('Location: /appointments/wizard');
            exit;
        }

        return $state;
    }

    private function resolveBranchFromGetOrFail(): int
    {
        $raw = trim((string) ($_GET['branch_id'] ?? ''));
        if ($raw !== '') {
            $id = (int) $raw;
            if ($id <= 0) {
                flash('error', 'Invalid branch.');
                header('Location: /dashboard');
                exit;
            }
            $this->assertWizardBranchAccess($id);

            return $id;
        }

        $ctx = $this->branchContext->getCurrentBranchId();
        if ($ctx === null || $ctx <= 0) {
            flash('error', 'Please select a branch before creating an appointment.');
            header('Location: /dashboard');
            exit;
        }

        $this->assertWizardBranchAccess((int) $ctx);

        return (int) $ctx;
    }

    private function assertWizardBranchAccess(int $branchId): void
    {
        $userId = $this->sessionAuth->id();
        if ($userId === null || $userId <= 0) {
            flash('error', 'Authentication required.');
            header('Location: /login');
            exit;
        }

        try {
            $this->orgBranchAssert->assertBranchOwnedByResolvedOrganization($branchId);
        } catch (AccessDeniedException | \DomainException) {
            flash('error', 'Branch access denied.');
            header('Location: /dashboard');
            exit;
        }

        $allowed = $this->tenantBranchAccess->allowedBranchIdsForUser($userId);
        if (!in_array($branchId, $allowed, true)) {
            flash('error', 'You do not have access to the requested branch.');
            header('Location: /dashboard');
            exit;
        }
    }

    private function resolveBranchName(int $branchId): string
    {
        $branches = $this->branchDirectory->getActiveBranchesForSelection();
        foreach ($branches as $branch) {
            if ((int) ($branch['id'] ?? 0) === $branchId) {
                return (string) ($branch['name'] ?? '');
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWorkspace(int $branchId, string $branchName): array
    {
        return [
            'shell_modifier'      => 'workspace-shell--create',
            'new_appointment_url' => '/appointments/wizard?branch_id=' . $branchId,
            'can_create'          => false,
            'tabs'                => [],
            'active_tab'          => '',
        ];
    }

    /**
     * Extract and normalise search criteria fields from POST data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractSearchFromPost(array $data): array
    {
        return [
            'mode'                => (string) ($data['mode'] ?? 'service'),
            'service_id'          => (int) ($data['service_id'] ?? 0) ?: null,
            'category_id'         => (int) ($data['category_id'] ?? 0) ?: null,
            'guests'              => max(1, (int) ($data['guests'] ?? 1)),
            'date_mode'           => (string) ($data['date_mode'] ?? 'exact'),
            'date'                => (string) ($data['date'] ?? ''),
            'date_from'           => (string) ($data['date_from'] ?? ''),
            'date_to'             => (string) ($data['date_to'] ?? ''),
            'staff_id'            => (int) ($data['staff_id'] ?? 0) ?: null,
            'room_id'             => (int) ($data['room_id'] ?? 0) ?: null,
            'include_freelancers' => !empty($data['include_freelancers']),
        ];
    }
}
