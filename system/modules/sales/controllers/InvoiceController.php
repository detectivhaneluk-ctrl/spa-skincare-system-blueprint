<?php

declare(strict_types=1);

namespace Modules\Sales\Controllers;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Auth\AuthService;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Contracts\AppointmentCheckoutProvider;
use Core\Contracts\ClientListProvider;
use Core\Contracts\GiftCardAvailabilityProvider;
use Core\Contracts\InvoiceGiftCardRedemptionProvider;
use Core\Contracts\ServiceListProvider;
use Core\Permissions\PermissionService;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Memberships\Services\MembershipSaleService;
use Modules\Sales\Repositories\InvoiceItemRepository;
use Modules\Sales\Repositories\InvoiceRepository;
use Modules\Sales\Repositories\PaymentRepository;
use Modules\Sales\Services\CashierInvoiceLineType;
use Modules\Sales\Services\CashierLineItemParser;
use Modules\Sales\Services\CashierLineItemValidator;
use Modules\Sales\Services\InvoiceService;
use Modules\Sales\Services\ReceiptInvoicePresentationService;
use Modules\Sales\Services\SalesTenantScope;

final class InvoiceController
{
    private const MEMBERSHIP_NOTES_MAX = 8000;

    public function __construct(
        private InvoiceRepository $repo,
        private InvoiceItemRepository $itemRepo,
        private PaymentRepository $paymentRepo,
        private InvoiceService $service,
        private ClientListProvider $clientList,
        private ServiceListProvider $serviceList,
        private AppointmentCheckoutProvider $appointmentCheckout,
        private GiftCardAvailabilityProvider $giftCardAvailability,
        private InvoiceGiftCardRedemptionProvider $giftCardRedemption,
        private MembershipSaleService $membershipSaleService,
        private MembershipDefinitionRepository $membershipDefinitions,
        private ClientRepository $clientRepository,
        private BranchContext $branchContext,
        private AuditService $audit,
        private BranchDirectory $branchDirectory,
        private SalesTenantScope $tenantScope,
        private ReceiptInvoicePresentationService $receiptInvoicePresentation,
        private CashierLineItemValidator $cashierLineValidator
    ) {
    }

    public function index(): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $branches = $this->getBranches();
        $allowedBranchIds = array_values(array_unique(array_map(static fn (array $b): int => (int) ($b['id'] ?? 0), $branches)));
        $contextBranch = $this->branchContext->getCurrentBranchId();

        $branchFilterExplicit = array_key_exists('branch_id', $_GET);
        if ($branchFilterExplicit) {
            $rawBranch = $_GET['branch_id'];
            if ($rawBranch === '' || $rawBranch === null) {
                $branchId = null;
            } else {
                $bid = (int) $rawBranch;
                $branchId = in_array($bid, $allowedBranchIds, true) ? $bid : null;
            }
        } else {
            $branchId = $contextBranch;
        }

        $status = trim((string) ($_GET['status'] ?? ''));
        $validStatuses = ['draft', 'open', 'partial', 'paid', 'cancelled', 'refunded'];
        if ($status !== '' && !in_array($status, $validStatuses, true)) {
            $status = '';
        }
        $status = $status !== '' ? $status : null;

        $invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
        $invoiceNumber = $invoiceNumber !== '' ? mb_substr($invoiceNumber, 0, 50) : null;

        $clientName = trim((string) ($_GET['client_name'] ?? ''));
        $clientName = $clientName !== '' ? mb_substr($clientName, 0, 100) : null;

        $clientPhone = trim((string) ($_GET['client_phone'] ?? ''));
        $clientPhone = $clientPhone !== '' ? mb_substr($clientPhone, 0, 50) : null;

        $issuedFrom = $this->parseOptionalYmd($_GET['issued_from'] ?? null);
        $issuedTo = $this->parseOptionalYmd($_GET['issued_to'] ?? null);
        if ($issuedFrom !== null && $issuedTo !== null && $issuedFrom > $issuedTo) {
            [$issuedFrom, $issuedTo] = [$issuedTo, $issuedFrom];
        }

        $perPageAllowed = [10, 20, 50];
        $perPage = (int) ($_GET['per_page'] ?? 20);
        if (!in_array($perPage, $perPageAllowed, true)) {
            $perPage = 20;
        }

        $filters = array_filter([
            'branch_id' => $branchId,
            'status' => $status,
            'invoice_number' => $invoiceNumber,
            'client_name' => $clientName,
            'client_phone' => $clientPhone,
            'issued_from' => $issuedFrom,
            'issued_to' => $issuedTo,
        ], fn ($v) => $v !== null && $v !== '');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = $this->repo->count($filters);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page = min($page, $totalPages);
        $invoices = $this->repo->list($filters, $perPage, ($page - 1) * $perPage);

        $ordersListQuery = [];
        if ($branchFilterExplicit) {
            $ordersListQuery['branch_id'] = $branchId === null ? '' : (string) $branchId;
        }
        if ($status !== null) {
            $ordersListQuery['status'] = $status;
        }
        if ($invoiceNumber !== null) {
            $ordersListQuery['invoice_number'] = $invoiceNumber;
        }
        if ($clientName !== null) {
            $ordersListQuery['client_name'] = $clientName;
        }
        if ($clientPhone !== null) {
            $ordersListQuery['client_phone'] = $clientPhone;
        }
        if ($issuedFrom !== null) {
            $ordersListQuery['issued_from'] = $issuedFrom;
        }
        if ($issuedTo !== null) {
            $ordersListQuery['issued_to'] = $issuedTo;
        }
        if ($perPage !== 20) {
            $ordersListQuery['per_page'] = (string) $perPage;
        }

        $branchListValue = $branchFilterExplicit ? ($branchId === null ? '' : (string) $branchId) : null;
        $listBranchId = $branchId;

        foreach ($invoices as &$i) {
            $i['client_display'] = trim(($i['client_first_name'] ?? '') . ' ' . ($i['client_last_name'] ?? '')) ?: '—';
            $bal = (float) ($i['total_amount'] ?? 0) - (float) ($i['paid_amount'] ?? 0);
            $st = (string) ($i['status'] ?? '');
            $paid = (float) ($i['paid_amount'] ?? 0);
            $i['invoice_editable'] = in_array($st, ['draft', 'open', 'partial'], true);
            $i['invoice_deletable'] = !in_array($st, ['paid', 'partial', 'refunded'], true) && $paid <= 0.0;
            $i['balance_due'] = number_format($bal, 2);
            $i['total_amount'] = number_format((float) ($i['total_amount'] ?? 0), 2);
            $i['paid_amount'] = number_format($paid, 2);
        }
        unset($i);

        $authUser = Application::container()->get(AuthService::class)->user();
        $uid = $authUser !== null ? (int) ($authUser['id'] ?? 0) : 0;
        $perm = Application::container()->get(PermissionService::class);
        $canEditInvoice = $uid > 0 && $perm->has($uid, 'sales.edit');
        $canDeleteInvoice = $uid > 0 && $perm->has($uid, 'sales.delete');
        $canCreateInvoice = $uid > 0 && $perm->has($uid, 'sales.create');

        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/sales/views/invoices/index.php');
    }

    /**
     * GET /sales — same cashier workspace preparation as {@see create()} (friendly entry URL).
     */
    public function staffCheckoutFromSalesRoute(): void
    {
        $this->renderNewSaleCashierWorkspace('Sales · Checkout');
    }

    public function create(): void
    {
        $this->renderNewSaleCashierWorkspace('Staff Checkout');
    }

    /**
     * Canonical new-sale cashier GET: appointment prefill, branch context, shared view-data build, {@see invoices/create.php}.
     */
    private function renderNewSaleCashierWorkspace(string $pageTitle): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $appointmentId = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : null;
        $prefill = $appointmentId ? $this->appointmentCheckout->getCheckoutPrefill($appointmentId) : null;
        $branchId = $prefill['branch_id'] ?? (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' ? (int) $_GET['branch_id'] : null);
        $contextBranch = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        if ($contextBranch !== null) {
            $branchId = $contextBranch;
        }

        $prefillClientId = null;
        if ($prefill !== null && isset($prefill['client_id'])) {
            $cid = (int) $prefill['client_id'];
            $prefillClientId = $cid > 0 ? $cid : null;
        } else {
            $directCid = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
            if ($directCid > 0) {
                $clientRow = $this->clientRepository->findLiveReadableForProfile($directCid, $branchId);
                if ($clientRow !== null) {
                    $prefillClientId = $directCid;
                }
            }
        }

        $invoice = [
            'status' => 'draft',
            'client_id' => $prefillClientId,
            'appointment_id' => $appointmentId,
            'branch_id' => $branchId,
            'items' => [],
        ];
        if ($prefill) {
            $invoice['items'][] = [
                'item_type' => 'service',
                'source_id' => $prefill['service_id'],
                'description' => $prefill['service_name'] ?: 'Service',
                'quantity' => 1,
                'unit_price' => $prefill['service_price'] ?? 0,
                'discount_amount' => 0,
                'tax_rate' => 0,
            ];
        }
        if (empty($invoice['items'])) {
            $invoice['items'][] = ['item_type' => 'manual', 'source_id' => null, 'description' => '', 'quantity' => 1, 'unit_price' => 0, 'discount_amount' => 0, 'tax_rate' => 0];
        }
        $viewData = $this->buildCashierWorkspaceViewData($invoice, $branchId, []);
        $invoice = $viewData['invoice'];
        $clients = $viewData['clients'];
        $services = $viewData['services'];
        $products = $viewData['products'];
        $membershipDefinitions = $viewData['membershipDefinitions'];
        $packages = $viewData['packages'];
        $errors = $viewData['errors'];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $title = $pageTitle;
        $salesWorkspaceActiveTab = 'staff_checkout';
        require base_path('modules/sales/views/invoices/create.php');
    }

    public function store(): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $data = $this->parseInput();
        $membershipLineIntent = $this->extractStandaloneMembershipLineCheckout($data['items'] ?? []);
        if ($membershipLineIntent !== null) {
            $selectedDef = (int) ($data['membership_definition_id'] ?? 0);
            if ($selectedDef > 0 && $selectedDef !== $membershipLineIntent['definition_id']) {
                $this->renderCreateForm($data, [
                    'membership_definition_id' => 'Membership plan selection conflicts with membership line.',
                ]);
                return;
            }
            $data['membership_definition_id'] = $membershipLineIntent['definition_id'];
            $lineStarts = trim((string) ($membershipLineIntent['starts_at'] ?? ''));
            if ($lineStarts !== '') {
                $data['membership_starts_at'] = $lineStarts;
            }
            $data['items'] = [
                [
                    'item_type' => CashierInvoiceLineType::MANUAL,
                    'source_id' => null,
                    'description' => '',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'discount_amount' => 0,
                    'tax_rate' => 0,
                    'line_meta' => null,
                ],
            ];
        }
        if (((int) ($data['membership_definition_id'] ?? 0)) > 0) {
            if ($this->hasMeaningfulInvoiceLines($data['items'] ?? [])) {
                $errors = [
                    'membership_definition_id' => 'Membership checkout is standalone. Remove other draft lines before continuing.',
                ];
                $this->renderCreateForm($data, $errors);
                return;
            }
            $this->storeMembershipViaStaffCheckout($data);
            return;
        }
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $this->renderCreateForm($data, $errors);
            return;
        }
        try {
            $id = $this->service->create($data);
            flash('success', 'Invoice created.');
            header('Location: /sales/invoices/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $this->renderCreateForm($data, $errors);
        }
    }

    public function show(int $id): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $invoice = $this->repo->find($id);
        if (!$invoice) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($invoice)) {
            return;
        }
        $items = $this->itemRepo->getByInvoiceId($id);
        $payments = $this->paymentRepo->listByInvoiceIdInInvoicePlane($id);
        $refundableByPaymentId = [];
        foreach ($payments as $p) {
            if (($p['entry_type'] ?? 'payment') !== 'payment' || ($p['status'] ?? '') !== 'completed') {
                continue;
            }
            $paid = round((float) ($p['amount'] ?? 0), 2);
            $refunded = $this->paymentRepo->getCompletedRefundedTotalForParentPaymentInInvoicePlane((int) $p['id']);
            $refundableByPaymentId[(int) $p['id']] = max(0.0, round($paid - $refunded, 2));
        }
        $eligibleGiftCards = [];
        if (!empty($invoice['client_id'])) {
            $eligibleGiftCards = $this->giftCardAvailability->listUsableForClient(
                (int) $invoice['client_id'],
                $invoice['branch_id'] !== null ? (int) $invoice['branch_id'] : null
            );
        }
        $giftCardRedemptions = $this->giftCardRedemption->listInvoiceRedemptions($id);
        $invoice['client_display'] = trim(($invoice['client_first_name'] ?? '') . ' ' . ($invoice['client_last_name'] ?? '')) ?: '—';
        $invoice['balance_due'] = (float) ($invoice['total_amount'] ?? 0) - (float) ($invoice['paid_amount'] ?? 0);
        $branchId = isset($invoice['branch_id']) && $invoice['branch_id'] !== '' && $invoice['branch_id'] !== null ? (int) $invoice['branch_id'] : null;
        $settingsService = Application::container()->get(\Core\App\SettingsService::class);
        $invoice['establishment'] = $settingsService->getEstablishmentSettings($branchId);
        $clientRow = null;
        if (!empty($invoice['client_id'])) {
            $clientRow = $this->clientRepository->findLiveReadableForProfile((int) $invoice['client_id'], $branchId);
        }
        $receiptPresentation = $this->receiptInvoicePresentation->buildForInvoiceShow(
            $branchId,
            $invoice['establishment'],
            $clientRow,
            $items,
            $payments
        );
        $invoice['receipt_notes'] = (string) ($receiptPresentation['presentation']['receipt_message'] ?? '');
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/sales/views/invoices/show.php');
    }

    public function edit(int $id): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $invoice = $this->repo->find($id);
        if (!$invoice) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($invoice)) {
            return;
        }
        if (!in_array($invoice['status'], ['draft', 'open', 'partial'], true)) {
            flash('error', 'Cannot edit invoice in status: ' . $invoice['status']);
            header('Location: /sales/invoices/' . $id);
            exit;
        }
        $items = $this->itemRepo->getByInvoiceId($id);
        $invoice['items'] = $items;
        $this->renderInvoiceEditCashierWorkspace($invoice, []);
    }

    public function update(int $id): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $invoice = $this->repo->find($id);
        if (!$invoice) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($invoice)) {
            return;
        }
        $data = $this->parseInput();
        $data['invoice_number'] = $invoice['invoice_number'];
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $invoice = array_merge($invoice, $data);
            $invoice['items'] = $data['items'] ?? [];
            $this->renderEditForm($id, $invoice, $errors);
            return;
        }
        try {
            $this->service->update($id, $data);
            flash('success', 'Invoice updated.');
            header('Location: /sales/invoices/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $invoice = array_merge($invoice, $data);
            $invoice['items'] = $data['items'] ?? [];
            $this->renderEditForm($id, $invoice, $errors);
        }
    }

    public function destroy(int $id): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $invoice = $this->repo->find($id);
        if (!$invoice) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($invoice)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Invoice deleted.');
        header('Location: /sales/invoices');
        exit;
    }

    public function cancel(int $id): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $invoice = $this->repo->find($id);
        if (!$invoice) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($invoice)) {
            return;
        }
        $this->service->cancel($id);
        flash('success', 'Invoice cancelled.');
        header('Location: /sales/invoices/' . $id);
        exit;
    }

    public function redeemGiftCard(int $id): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $invoice = $this->repo->find($id);
        if (!$invoice) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($invoice)) {
            return;
        }

        $giftCardId = (int) ($_POST['gift_card_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        if ($giftCardId <= 0) {
            flash('error', 'Please select a gift card.');
            header('Location: /sales/invoices/' . $id);
            exit;
        }
        if ($amount <= 0) {
            flash('error', 'Redeem amount must be greater than zero.');
            header('Location: /sales/invoices/' . $id);
            exit;
        }

        try {
            $this->service->redeemGiftCardPayment($id, $giftCardId, $amount, $notes);
            flash('success', 'Gift card redeemed and payment recorded.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /sales/invoices/' . $id);
        exit;
    }

    private function parseInput(): array
    {
        $parser = new CashierLineItemParser();
        $items = $parser->parseItemsFromPost($_POST);
        if ($items === []) {
            $items[] = [
                'item_type' => CashierInvoiceLineType::MANUAL,
                'source_id' => null,
                'description' => '',
                'quantity' => 1,
                'unit_price' => 0,
                'discount_amount' => 0,
                'tax_rate' => 0,
                'line_meta' => null,
            ];
        }
        $branchId = trim($_POST['branch_id'] ?? '') ? (int) $_POST['branch_id'] : null;
        $clientId = trim($_POST['client_id'] ?? '') ? (int) $_POST['client_id'] : null;
        $appointmentId = trim($_POST['appointment_id'] ?? '') ? (int) $_POST['appointment_id'] : null;
        return [
            'client_id' => $clientId,
            'appointment_id' => $appointmentId,
            'branch_id' => $branchId,
            'status' => 'draft',
            'discount_amount' => (float) ($_POST['discount_amount'] ?? 0),
            'tax_amount' => (float) ($_POST['tax_amount'] ?? 0),
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'items' => $items,
            'membership_definition_id' => (int) ($_POST['membership_definition_id'] ?? 0),
            'membership_starts_at' => trim((string) ($_POST['membership_starts_at'] ?? '')),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        $invoiceContext = [
            'branch_id' => $data['branch_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
        ];
        foreach ($this->cashierLineValidator->validate($data['items'] ?? [], $invoiceContext) as $key => $message) {
            $errors[$key] = $message;
        }
        $hasValidLine = false;
        foreach ($data['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['item_type'] ?? '');
            if (in_array($type, [
                CashierInvoiceLineType::GIFT_CARD,
                CashierInvoiceLineType::GIFT_VOUCHER,
                CashierInvoiceLineType::SERIES,
                CashierInvoiceLineType::CLIENT_ACCOUNT,
                CashierInvoiceLineType::TIP,
                CashierInvoiceLineType::MEMBERSHIP,
            ], true)) {
                $hasValidLine = true;
                break;
            }
            $desc = trim((string) ($item['description'] ?? ''));
            $qty = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['unit_price'] ?? 0);
            if (($desc !== '' && ($qty > 0 || $price > 0)) || $price > 0) {
                $hasValidLine = true;
                break;
            }
        }
        if (!$hasValidLine && !isset($errors['items'])) {
            $errors['items'] = 'Add at least one line item.';
        }
        return $errors;
    }

    /**
     * @param list<array<string, mixed>> $items Parsed cashier lines
     * @return array{definition_id: int, starts_at: string}|null
     */
    private function extractStandaloneMembershipLineCheckout(array $items): ?array
    {
        $items = array_values(array_filter($items, static fn ($row) => is_array($row)));
        if (count($items) !== 1) {
            return null;
        }
        $only = $items[0];
        if (($only['item_type'] ?? '') !== CashierInvoiceLineType::MEMBERSHIP) {
            return null;
        }
        $meta = $only['line_meta'] ?? null;
        $starts = is_array($meta) ? trim((string) ($meta['membership_starts_at'] ?? '')) : '';

        return [
            'definition_id' => (int) ($only['source_id'] ?? 0),
            'starts_at' => $starts,
        ];
    }

    private function renderCreateForm(array $data, array $errors): void
    {
        $branchId = $data['branch_id'] ?? null;
        $normalizedBranchId = isset($branchId) && $branchId !== '' && $branchId !== null ? (int) $branchId : null;
        $viewData = $this->buildCashierWorkspaceViewData($data, $normalizedBranchId, $errors);
        $invoice = $viewData['invoice'];
        $clients = $viewData['clients'];
        $services = $viewData['services'];
        $products = $viewData['products'];
        $membershipDefinitions = $viewData['membershipDefinitions'];
        $packages = $viewData['packages'];
        $errors = $viewData['errors'];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/sales/views/invoices/create.php');
        exit;
    }

    /**
     * Canonical staff checkout path for membership: same {@see MembershipSaleService::createSaleAndInvoice} as POST /memberships/sales.
     *
     * @param array<string, mixed> $data from {@see parseInput()}
     */
    private function storeMembershipViaStaffCheckout(array $data): void
    {
        $errors = [];
        $defId = (int) ($data['membership_definition_id'] ?? 0);
        $clientId = (int) ($data['client_id'] ?? 0);
        if ($clientId <= 0) {
            $errors['client_id'] = 'Client is required to sell a membership.';
        }
        if ($defId <= 0) {
            $errors['membership_definition_id'] = 'Select a membership plan.';
        }
        $startsAt = trim((string) ($data['membership_starts_at'] ?? ''));
        if ($startsAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsAt) !== 1) {
            $errors['membership_starts_at'] = 'Membership start date must be empty or YYYY-MM-DD.';
        }
        $extraNotes = isset($data['notes']) ? trim((string) $data['notes']) : '';
        if (strlen($extraNotes) > self::MEMBERSHIP_NOTES_MAX) {
            $errors['notes'] = 'Notes are too long for membership checkout.';
        }

        $readBranch = $this->branchContext->getCurrentBranchId()
            ?? (isset($data['branch_id']) && $data['branch_id'] !== null && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null);
        $client = $clientId > 0 ? $this->clientRepository->findLiveReadableForProfile($clientId, $readBranch) : null;
        if ($clientId > 0 && !$client) {
            $errors['client_id'] = 'Client not found or not available for this location.';
        }

        if (!empty($errors)) {
            $this->renderCreateForm($data, $errors);
            return;
        }

        $branchForAudit = $this->branchContext->getCurrentBranchId()
            ?? (isset($data['branch_id']) && $data['branch_id'] !== null && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null);

        $actorId = Application::container()->get(\Core\Auth\SessionAuth::class)->auditActorUserId() ?? 0;
        $this->audit->log('membership_sale_staff_checkout_initiated', 'membership_sale', null, $actorId, $branchForAudit, [
            'client_id' => $clientId,
            'membership_definition_id' => $defId,
        ]);

        $payload = [
            'membership_definition_id' => $defId,
            'client_id' => $clientId,
            'starts_at' => $startsAt,
        ];
        $formBranch = $data['branch_id'] ?? null;
        if ($formBranch !== null && $formBranch !== '' && (int) $formBranch > 0) {
            $payload['branch_id'] = (int) $formBranch;
        }
        if ($extraNotes !== '') {
            $payload['notes'] = $extraNotes;
        }

        try {
            $result = $this->membershipSaleService->createSaleAndInvoice($payload, ['staff_invoice_checkout' => true]);
        } catch (\DomainException $e) {
            $errors['_general'] = $e->getMessage();
            $this->renderCreateForm($data, $errors);
            return;
        } catch (\Throwable $e) {
            $errors['_general'] = 'Could not create membership sale.';
            $this->renderCreateForm($data, $errors);
            return;
        }

        $invoiceId = (int) $result['invoice_id'];
        flash('success', 'Membership sale created. Invoice opened for payment.');
        header('Location: /sales/invoices/' . $invoiceId);
        exit;
    }

    private function renderEditForm(int $id, array $invoice, array $errors): void
    {
        $this->renderInvoiceEditCashierWorkspace($invoice, $errors);
        exit;
    }

    /**
     * Edit cashier GET and validation re-render: same {@see buildCashierWorkspaceViewData} path as create/new-sale.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $errors
     */
    private function renderInvoiceEditCashierWorkspace(array $invoice, array $errors): void
    {
        $branchId = $this->resolveCashierBranchIdForCashier($invoice);
        $viewData = $this->buildCashierWorkspaceViewData($invoice, $branchId, $errors);
        $invoice = $viewData['invoice'];
        $clients = $viewData['clients'];
        $services = $viewData['services'];
        $products = $viewData['products'];
        $membershipDefinitions = $viewData['membershipDefinitions'];
        $packages = $viewData['packages'];
        $errors = $viewData['errors'];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/sales/views/invoices/edit.php');
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function resolveCashierBranchIdForCashier(array $invoice): ?int
    {
        if (!isset($invoice['branch_id']) || $invoice['branch_id'] === '' || $invoice['branch_id'] === null) {
            return null;
        }

        return (int) $invoice['branch_id'];
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $errors
     * @return array{invoice: array<string, mixed>, clients: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>, products: array<int, array<string, mixed>>, membershipDefinitions: array<int, array<string, mixed>>, packages: array<int, array<string, mixed>>, errors: array<string, mixed>}
     */
    private function buildCashierWorkspaceViewData(array $invoice, ?int $branchId, array $errors): array
    {
        $builder = Application::container()->get(\Modules\Sales\Services\CashierWorkspaceViewDataBuilder::class);

        return $builder->build($invoice, $branchId, $errors);
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    private function ensureProtectedTenantScope(): bool
    {
        if ($this->tenantScope->requiresProtectedTenantContext()) {
            return true;
        }
        Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);

        return false;
    }

    private function parseOptionalYmd(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        if ($dt !== false && $dt->format('Y-m-d') === $s) {
            return $s;
        }
        $t = strtotime($s);
        if ($t === false) {
            return null;
        }

        return date('Y-m-d', $t);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function hasMeaningfulInvoiceLines(array $items): bool
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $desc = trim((string) ($item['description'] ?? ''));
            $qty = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['unit_price'] ?? 0);
            if (($desc !== '' && ($qty > 0 || $price > 0)) || $price > 0) {
                return true;
            }
        }

        return false;
    }
}
