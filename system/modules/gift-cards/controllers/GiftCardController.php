<?php

declare(strict_types=1);

namespace Modules\GiftCards\Controllers;

use Core\App\Application;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Contracts\ClientListProvider;
use Core\Permissions\PermissionService;
use Modules\GiftCards\Repositories\GiftCardRepository;
use Modules\GiftCards\Repositories\GiftCardTransactionRepository;
use Modules\GiftCards\Services\GiftCardService;

final class GiftCardController
{
    public function __construct(
        private GiftCardRepository $cards,
        private GiftCardTransactionRepository $transactions,
        private GiftCardService $service,
        private ClientListProvider $clients,
        private BranchDirectory $branchDirectory,
        private BranchContext $branchContext
    ) {
    }

    public function index(): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $branches = $this->getBranches();
        $allowedBranchIds = array_values(array_unique(array_map(static fn (array $b): int => (int) ($b['id'] ?? 0), $branches)));

        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '' && isset($_GET['search'])) {
            $code = trim((string) $_GET['search']);
        }
        $clientName = trim((string) ($_GET['client_name'] ?? ''));
        $filterClientId = max(0, (int) ($_GET['client_id'] ?? 0));
        $status = trim((string) ($_GET['status'] ?? ''));
        if ($status !== '' && !in_array($status, GiftCardService::STATUSES, true)) {
            $status = '';
        }
        $issuedFrom = $this->parseOptionalYmd($_GET['issued_from'] ?? null);
        $issuedTo = $this->parseOptionalYmd($_GET['issued_to'] ?? null);
        if ($issuedFrom !== null && $issuedTo !== null && $issuedFrom > $issuedTo) {
            [$issuedFrom, $issuedTo] = [$issuedTo, $issuedFrom];
        }

        $listBranchRaw = trim((string) ($_GET['list_branch'] ?? ''));
        $scopeMode = GiftCardRepository::INDEX_SCOPE_BRANCH_CARDS;
        $listBranchId = $tenantBranchId;
        if ($listBranchRaw === 'global') {
            $scopeMode = GiftCardRepository::INDEX_SCOPE_GLOBAL_ONLY;
        } elseif ($listBranchRaw !== '' && ctype_digit($listBranchRaw)) {
            $picked = (int) $listBranchRaw;
            if (in_array($picked, $allowedBranchIds, true)) {
                $listBranchId = $picked;
            }
        }

        $filters = [
            'scope_mode' => $scopeMode,
            'code' => $code !== '' ? $code : null,
            'client_name' => $clientName !== '' ? $clientName : null,
            'client_id' => $filterClientId > 0 ? $filterClientId : null,
            'status' => $status !== '' ? $status : null,
            'issued_from' => $issuedFrom,
            'issued_to' => $issuedTo,
        ];

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $total = $this->cards->countInTenantScope($filters, $listBranchId);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page = min($page, $totalPages);
        $giftCards = $this->cards->listInTenantScope($filters, $listBranchId, $perPage, ($page - 1) * $perPage);
        foreach ($giftCards as &$g) {
            $g['current_balance'] = $this->service->getCurrentBalance((int) $g['id']);
            $g['client_display'] = trim(($g['client_first_name'] ?? '') . ' ' . ($g['client_last_name'] ?? '')) ?: '—';
        }
        unset($g);

        $giftCardIndexQuery = [];
        if ($code !== '') {
            $giftCardIndexQuery['code'] = $code;
        }
        if ($clientName !== '') {
            $giftCardIndexQuery['client_name'] = $clientName;
        }
        if ($filterClientId > 0) {
            $giftCardIndexQuery['client_id'] = (string) $filterClientId;
        }
        if ($status !== '') {
            $giftCardIndexQuery['status'] = $status;
        }
        if ($issuedFrom !== null) {
            $giftCardIndexQuery['issued_from'] = $issuedFrom;
        }
        if ($issuedTo !== null) {
            $giftCardIndexQuery['issued_to'] = $issuedTo;
        }
        if ($listBranchRaw === 'global') {
            $giftCardIndexQuery['list_branch'] = 'global';
        } elseif ($listBranchRaw !== '' && ctype_digit($listBranchRaw) && in_array((int) $listBranchRaw, $allowedBranchIds, true)) {
            $giftCardIndexQuery['list_branch'] = (string) (int) $listBranchRaw;
        }

        $listBranchApplied = '';
        if ($scopeMode === GiftCardRepository::INDEX_SCOPE_GLOBAL_ONLY) {
            $listBranchApplied = 'global';
        } elseif ($listBranchId !== $tenantBranchId) {
            $listBranchApplied = (string) $listBranchId;
        }

        $sessionAuth = Application::container()->get(SessionAuth::class);
        $csrf = $sessionAuth->csrfToken();
        $uid = $sessionAuth->id();
        $perm = Application::container()->get(PermissionService::class);
        $canAdjustGiftCards = $uid !== null && $perm->has($uid, 'gift_cards.adjust');
        $canRedeemGiftCards = $uid !== null && $perm->has($uid, 'gift_cards.redeem');
        $canCancelGiftCards = $uid !== null && $perm->has($uid, 'gift_cards.cancel');
        $canCreateGiftCards = $uid !== null && $perm->has($uid, 'gift_cards.create');

        $flash = flash();
        require base_path('modules/gift-cards/views/index.php');
    }

    public function bulkUpdateExpiresAt(): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $sessionAuth = Application::container()->get(SessionAuth::class);
        $tokenName = (string) config('app.csrf_token_name', 'csrf_token');
        if (!$sessionAuth->validateCsrf(trim((string) ($_POST[$tokenName] ?? '')))) {
            flash('error', 'Invalid security token. Please try again.');
            header('Location: /gift-cards' . $this->giftCardIndexRedirectSuffixFromPost());
            exit;
        }

        $redirect = '/gift-cards' . $this->giftCardIndexRedirectSuffixFromPost();
        $clear = !empty($_POST['clear_expiry']);
        $dateRaw = trim((string) ($_POST['bulk_expires_at'] ?? ''));
        if (!$clear && $dateRaw === '') {
            flash('error', 'Choose a new expiration date or select “Clear expiration”.');
            header('Location: ' . $redirect);
            exit;
        }

        $idsRaw = $_POST['gift_card_ids'] ?? [];
        if (!is_array($idsRaw)) {
            $idsRaw = [];
        }
        $expiresParam = $clear ? null : $dateRaw;

        try {
            $result = $this->service->bulkUpdateExpiresAt(
                array_map(static fn ($v): int => (int) $v, $idsRaw),
                $expiresParam,
                $tenantBranchId
            );
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
            header('Location: ' . $redirect);
            exit;
        }

        $n = count($result['updated']);
        $f = count($result['failed']);
        if ($n === 0 && $f === 0) {
            flash('error', 'No gift cards were selected (only active cards on this page can be bulk-updated).');
        } elseif ($f === 0) {
            $actionLabel = $clear ? 'Cleared expiration on' : 'Updated expiration on';
            flash('success', $actionLabel . ' ' . $n . ' gift card(s).');
        } elseif ($n === 0) {
            $detail = $this->formatBulkExpiryFailures($result['failed']);
            flash('error', 'Could not update expiration. ' . $detail);
        } else {
            $detail = $this->formatBulkExpiryFailures($result['failed']);
            flash('success', 'Updated ' . $n . ' gift card(s). Some could not be updated: ' . $detail);
        }

        header('Location: ' . $redirect);
        exit;
    }

    public function show(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $giftCard = $this->cards->findInTenantScope($id, $tenantBranchId);
        if (!$giftCard) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        try {
            $this->service->expireGiftCardIfNeeded(
                $id,
                $tenantBranchId
            );
            $giftCard = $this->cards->findInTenantScope($id, $tenantBranchId) ?? $giftCard;
        } catch (\Throwable) {
            // non-blocking; page can still render stale status if expiry transaction failed
        }
        $transactions = $this->transactions->listByCard($id);
        $currentBalance = $this->service->getCurrentBalance($id);
        $clientDisplay = trim(($giftCard['client_first_name'] ?? '') . ' ' . ($giftCard['client_last_name'] ?? '')) ?: '—';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/gift-cards/views/show.php');
    }

    public function issue(): void
    {
        $this->tenantBranchOrRedirect();
        $giftCard = [
            'status' => 'active',
            'issued_at' => date('Y-m-d\TH:i'),
        ];
        $errors = [];
        $branches = $this->getBranches();
        $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== '' ? (int) $_GET['branch_id'] : null;
        $contextBranch = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        if ($contextBranch !== null) {
            $branchId = $contextBranch;
        }
        $clientOptions = $this->clients->list($branchId);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/gift-cards/views/issue.php');
    }

    public function storeIssue(): void
    {
        $this->tenantBranchOrRedirect();
        $data = $this->parseIssueInput();
        $errors = $this->validateIssue($data);
        if (!empty($errors)) {
            $giftCard = $data;
            $branches = $this->getBranches();
            $clientOptions = $this->clients->list($data['branch_id'] ?? null);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/gift-cards/views/issue.php');
            return;
        }

        try {
            $id = $this->service->issueGiftCard($data);
            flash('success', 'Gift card issued.');
            header('Location: /gift-cards/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $giftCard = $data;
            $branches = $this->getBranches();
            $clientOptions = $this->clients->list($data['branch_id'] ?? null);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/gift-cards/views/issue.php');
        }
    }

    public function redeemForm(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $giftCard = $this->cards->findInTenantScope($id, $tenantBranchId);
        if (!$giftCard) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $currentBalance = $this->service->getCurrentBalance($id);
        $redeem = ['amount' => '', 'notes' => ''];
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/gift-cards/views/redeem.php');
    }

    public function redeem(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $giftCard = $this->cards->findInTenantScope($id, $tenantBranchId);
        if (!$giftCard) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $redeem = [
            'amount' => (float) ($_POST['amount'] ?? 0),
            'notes' => trim($_POST['notes'] ?? '') ?: null,
        ];
        $errors = [];
        if ($redeem['amount'] <= 0) {
            $errors['amount'] = 'Redeem amount must be greater than zero.';
        }
        if (!empty($errors)) {
            $currentBalance = $this->service->getCurrentBalance($id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/gift-cards/views/redeem.php');
            return;
        }
        try {
            $this->service->redeemGiftCard($id, (float) $redeem['amount'], [
                'notes' => $redeem['notes'],
                'branch_id' => $tenantBranchId,
            ]);
            flash('success', 'Gift card redeemed.');
            header('Location: /gift-cards/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $currentBalance = $this->service->getCurrentBalance($id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/gift-cards/views/redeem.php');
        }
    }

    public function adjustForm(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $giftCard = $this->cards->findInTenantScope($id, $tenantBranchId);
        if (!$giftCard) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $currentBalance = $this->service->getCurrentBalance($id);
        $adjustment = ['amount' => '', 'notes' => ''];
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/gift-cards/views/adjust.php');
    }

    public function adjust(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $giftCard = $this->cards->findInTenantScope($id, $tenantBranchId);
        if (!$giftCard) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $adjustment = [
            'amount' => (float) ($_POST['amount'] ?? 0),
            'notes' => trim($_POST['notes'] ?? '') ?: null,
        ];
        $errors = [];
        if ($adjustment['amount'] == 0.0) {
            $errors['amount'] = 'Adjustment amount cannot be zero.';
        }
        if (!empty($errors)) {
            $currentBalance = $this->service->getCurrentBalance($id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/gift-cards/views/adjust.php');
            return;
        }
        try {
            $this->service->adjustGiftCard($id, (float) $adjustment['amount'], [
                'notes' => $adjustment['notes'],
                'branch_id' => $tenantBranchId,
            ]);
            flash('success', 'Gift card adjusted.');
            header('Location: /gift-cards/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $currentBalance = $this->service->getCurrentBalance($id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/gift-cards/views/adjust.php');
        }
    }

    public function cancel(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $giftCard = $this->cards->findInTenantScope($id, $tenantBranchId);
        if (!$giftCard) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $notes = trim($_POST['notes'] ?? '') ?: null;
        try {
            $this->service->cancelGiftCard($id, $notes, $tenantBranchId);
            flash('success', 'Gift card cancelled.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /gift-cards/' . $id);
        exit;
    }

    private function parseIssueInput(): array
    {
        $branchRaw = trim($_POST['branch_id'] ?? '');
        $clientRaw = trim($_POST['client_id'] ?? '');
        $issuedAtRaw = trim($_POST['issued_at'] ?? '');
        $expiresAtRaw = trim($_POST['expires_at'] ?? '');
        return [
            'branch_id' => $branchRaw === '' ? null : (int) $branchRaw,
            'client_id' => $clientRaw === '' ? null : (int) $clientRaw,
            'code' => trim($_POST['code'] ?? '') ?: null,
            'original_amount' => (float) ($_POST['original_amount'] ?? 0),
            'currency' => trim($_POST['currency'] ?? '') ?: null,
            'issued_at' => $issuedAtRaw !== '' ? date('Y-m-d H:i:s', strtotime($issuedAtRaw)) : date('Y-m-d H:i:s'),
            'expires_at' => $expiresAtRaw !== '' ? date('Y-m-d H:i:s', strtotime($expiresAtRaw)) : null,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
        ];
    }

    private function validateIssue(array $data): array
    {
        $errors = [];
        if ((float) ($data['original_amount'] ?? 0) <= 0) {
            $errors['original_amount'] = 'Issue amount must be greater than zero.';
        }
        if (!empty($data['currency']) && strlen((string) $data['currency']) > 10) {
            $errors['currency'] = 'Currency must be 10 chars max.';
        }
        if (!empty($data['code']) && strlen((string) $data['code']) > 100) {
            $errors['code'] = 'Code must be 100 chars max.';
        }
        if (!empty($data['expires_at']) && strtotime((string) $data['expires_at']) <= strtotime((string) $data['issued_at'])) {
            $errors['expires_at'] = 'Expiry must be after issue datetime.';
        }
        return $errors;
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
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

    private function tenantBranchOrRedirect(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            flash('error', 'Tenant branch context is required for gift card routes.');
            header('Location: /gift-cards');
            exit;
        }

        return $branchId;
    }

    private function giftCardIndexRedirectSuffixFromPost(): string
    {
        $keys = ['code', 'client_name', 'client_id', 'status', 'issued_from', 'issued_to', 'list_branch', 'page'];
        $out = [];
        foreach ($keys as $k) {
            $field = 'ret_' . $k;
            if (!isset($_POST[$field])) {
                continue;
            }
            $v = $_POST[$field];
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== '' && $v !== null) {
                $out[$k] = $v;
            }
        }
        $qs = http_build_query($out);

        return $qs !== '' ? ('?' . $qs) : '';
    }

    /**
     * @param list<array{id: int, reason: string}> $failed
     */
    private function formatBulkExpiryFailures(array $failed): string
    {
        $slice = array_slice($failed, 0, 5);
        $parts = [];
        foreach ($slice as $row) {
            $parts[] = '#' . (int) ($row['id'] ?? 0) . ': ' . ($row['reason'] ?? 'unknown error');
        }
        $msg = implode('; ', $parts);
        if (count($failed) > 5) {
            $msg .= ' …';
        }

        return $msg;
    }
}
