<?php

declare(strict_types=1);

namespace Modules\Sales\Controllers;

use Core\App\Application;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Modules\Sales\Repositories\InvoiceRepository;
use Modules\Sales\Repositories\PaymentRepository;
use Modules\Sales\Services\PaymentMethodService;
use Modules\Sales\Services\PaymentService;
use Modules\Sales\Services\SalesTenantScope;

final class PaymentController
{
    public function __construct(
        private InvoiceRepository $invoiceRepo,
        private PaymentRepository $paymentRepo,
        private PaymentService $service,
        private PaymentMethodService $paymentMethodService,
        private SettingsService $settingsService,
        private SalesTenantScope $tenantScope,
        private BranchContext $branchContext,
    ) {
    }

    public function create(int $invoiceId): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $invoice = $this->invoiceRepo->find($invoiceId);
        if (!$invoice) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccessForInvoice($invoice)) {
            return;
        }
        $branchId = isset($invoice['branch_id']) && $invoice['branch_id'] !== '' && $invoice['branch_id'] !== null ? (int) $invoice['branch_id'] : null;
        $paymentMethods = $this->paymentMethodService->listForPaymentForm($branchId);
        $paymentSettings = $this->settingsService->getPaymentSettings(null);
        $balanceDue = (float) $invoice['total_amount'] - (float) $invoice['paid_amount'];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $validCodes = array_column($paymentMethods, 'code');
        $resolvedDefault = $this->paymentMethodService->resolveDefaultForRecordedPayment(
            $branchId,
            (string) ($paymentSettings['default_method_code'] ?? 'cash')
        );
        $payment = [
            'invoice_id' => $invoiceId,
            'payment_method' => $resolvedDefault ?? '',
        ];
        require base_path('modules/sales/views/payments/create.php');
    }

    public function store(int $invoiceId): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $invoice = $this->invoiceRepo->find($invoiceId);
        if (!$invoice) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccessForInvoice($invoice)) {
            return;
        }
        $data = $this->parseInput($invoiceId, $invoice);
        $data['invoice_id'] = $invoiceId;
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $branchId = isset($invoice['branch_id']) && $invoice['branch_id'] !== '' && $invoice['branch_id'] !== null ? (int) $invoice['branch_id'] : null;
            $paymentMethods = $this->paymentMethodService->listForPaymentForm($branchId);
            $paymentSettings = $this->settingsService->getPaymentSettings(null);
            $balanceDue = (float) $invoice['total_amount'] - (float) $invoice['paid_amount'];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $payment = $data;
            require base_path('modules/sales/views/payments/create.php');
            return;
        }
        try {
            $this->service->create($data);
            flash('success', 'Payment recorded.');
            header('Location: /sales/invoices/' . $invoiceId);
            exit;
        } catch (\Throwable $e) {
            $branchId = isset($invoice['branch_id']) && $invoice['branch_id'] !== '' && $invoice['branch_id'] !== null ? (int) $invoice['branch_id'] : null;
            $paymentMethods = $this->paymentMethodService->listForPaymentForm($branchId);
            $paymentSettings = $this->settingsService->getPaymentSettings(null);
            $errors['_general'] = $e->getMessage();
            $balanceDue = (float) $invoice['total_amount'] - (float) $invoice['paid_amount'];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $payment = $data;
            require base_path('modules/sales/views/payments/create.php');
        }
    }

    public function refund(int $id): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $payment = $this->paymentRepo->findInInvoicePlane($id);
        if (!$payment) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $invoiceId = (int) ($payment['invoice_id'] ?? 0);
        $invoice = $invoiceId > 0 ? $this->invoiceRepo->find($invoiceId) : null;
        if (!$invoice) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccessForInvoice($invoice)) {
            return;
        }
        $amount = (float) ($_POST['amount'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        try {
            $this->service->refund($id, $amount, $notes);
            flash('success', 'Payment refunded.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /sales/invoices/' . $invoiceId);
        exit;
    }

    /**
     * @param array<string, mixed> $invoice Row from {@see InvoiceRepository::find()} after org + branch gates in {@see store()}.
     */
    private function parseInput(int $invoiceId, array $invoice): array
    {
        $branchId = isset($invoice['branch_id']) && $invoice['branch_id'] !== '' && $invoice['branch_id'] !== null ? (int) $invoice['branch_id'] : null;
        $methods = $this->paymentMethodService->listForPaymentForm($branchId);
        $validCodes = array_column($methods, 'code');
        $submitted = trim((string) ($_POST['payment_method'] ?? ''));
        $paymentSettings = $this->settingsService->getPaymentSettings(null);
        $fallback = $this->paymentMethodService->resolveDefaultForRecordedPayment(
            $branchId,
            (string) ($paymentSettings['default_method_code'] ?? 'cash')
        ) ?? '';
        $payment_method = in_array($submitted, $validCodes, true) ? $submitted : $fallback;
        return [
            // Route is canonical: never trust POST invoice_id (branch gate above is for URL invoice only).
            'invoice_id' => $invoiceId,
            'payment_method' => $payment_method,
            'amount' => (float) ($_POST['amount'] ?? 0),
            'status' => in_array($_POST['status'] ?? '', ['pending', 'completed', 'failed', 'refunded', 'voided'], true) ? $_POST['status'] : 'completed',
            'transaction_reference' => trim($_POST['transaction_reference'] ?? '') ?: null,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if ((float) ($data['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Amount must be greater than 0.';
        }
        return $errors;
    }

    private function ensureProtectedTenantScope(): bool
    {
        if ($this->tenantScope->requiresProtectedTenantContext()) {
            return true;
        }
        Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);

        return false;
    }

    /**
     * Same branch gate as {@see InvoiceController::ensureBranchAccess}: org-scoped repo finds must still match session branch when set.
     *
     * @param array<string, mixed> $invoice
     */
    private function ensureBranchAccessForInvoice(array $invoice): bool
    {
        try {
            $branchId = isset($invoice['branch_id']) && $invoice['branch_id'] !== '' && $invoice['branch_id'] !== null ? (int) $invoice['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);

            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);

            return false;
        }
    }
}
