<?php

declare(strict_types=1);

namespace Modules\Memberships\Controllers;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Memberships\Services\MembershipSaleService;

/**
 * HTTP entry for initial membership sale → {@see MembershipSaleService::createSaleAndInvoice} (invoice + activation truth unchanged).
 */
final class MembershipSaleController
{
    private const NOTES_MAX_LEN = 8000;

    public function __construct(
        private MembershipSaleService $saleService,
        private ClientRepository $clients,
        private BranchContext $branchContext,
        private AuditService $audit
    ) {
    }

    public function store(): void
    {
        $definitionId = (int) ($_POST['membership_definition_id'] ?? 0);
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($definitionId <= 0 || $clientId <= 0) {
            $this->denyValidation('membership_definition_id and client_id are required.');
            return;
        }

        if ($startsAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsAt) !== 1) {
            $this->denyValidation('starts_at must be empty or a Y-m-d date.');
            return;
        }

        if (strlen($notes) > self::NOTES_MAX_LEN) {
            $this->denyValidation('notes are too long.');
            return;
        }

        $client = $this->clients->find($clientId);
        if (!$client) {
            $this->audit->log('membership_initial_sale_http_denied', 'membership_sale', null, $this->actorId(), null, [
                'reason' => 'client_not_found',
                'client_id' => $clientId,
                'membership_definition_id' => $definitionId,
            ]);
            $this->failClientOrBranch('Client not found.');
            return;
        }

        try {
            $clientBranch = isset($client['branch_id']) && $client['branch_id'] !== '' && $client['branch_id'] !== null
                ? (int) $client['branch_id']
                : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($clientBranch);
        } catch (\DomainException $e) {
            $this->audit->log('membership_initial_sale_http_denied', 'membership_sale', null, $this->actorId(), null, [
                'reason' => 'client_branch_mismatch',
                'client_id' => $clientId,
                'membership_definition_id' => $definitionId,
            ]);
            $this->failClientOrBranch($e->getMessage());
            return;
        }

        $payload = [
            'membership_definition_id' => $definitionId,
            'client_id' => $clientId,
            'starts_at' => $startsAt,
        ];
        if ($notes !== '') {
            $payload['notes'] = $notes;
        }

        try {
            $result = $this->saleService->createSaleAndInvoice($payload);
        } catch (\DomainException $e) {
            $this->audit->log('membership_initial_sale_http_denied', 'membership_sale', null, $this->actorId(), null, [
                'reason' => 'service_domain',
                'message' => $e->getMessage(),
                'client_id' => $clientId,
                'membership_definition_id' => $definitionId,
            ]);
            $this->respondError($e->getMessage(), 422);
            return;
        } catch (\Throwable $e) {
            $this->audit->log('membership_initial_sale_http_error', 'membership_sale', null, $this->actorId(), null, [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'client_id' => $clientId,
                'membership_definition_id' => $definitionId,
            ]);
            $this->respondError('Could not create membership sale.', 500);
            return;
        }

        $invoiceId = (int) $result['invoice_id'];
        $redirect = '/sales/invoices/' . $invoiceId;

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'membership_sale_id' => (int) $result['membership_sale_id'],
                'invoice_id' => $invoiceId,
                'redirect' => $redirect,
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        flash('success', 'Membership sale created. Invoice opened for payment.');
        header('Location: ' . $redirect);
        exit;
    }

    private function denyValidation(string $message): void
    {
        $this->audit->log('membership_initial_sale_http_denied', 'membership_sale', null, $this->actorId(), null, [
            'reason' => 'validation',
            'message' => $message,
        ]);
        $this->respondError($message, 422);
    }

    private function failClientOrBranch(string $message): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'MEMBERSHIP_INITIAL_SALE_DENIED',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        flash('error', $message);
        header('Location: /memberships/client-memberships');
        exit;
    }

    private function respondError(string $message, int $httpCode): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($httpCode);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => $httpCode === 422 ? 'MEMBERSHIP_INITIAL_SALE_INVALID' : 'MEMBERSHIP_INITIAL_SALE_ERROR',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        flash('error', $message);
        header('Location: /memberships/client-memberships');
        exit;
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    private function actorId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }
}
