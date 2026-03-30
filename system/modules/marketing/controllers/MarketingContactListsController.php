<?php

declare(strict_types=1);

namespace Modules\Marketing\Controllers;

use Core\App\Application;
use Core\App\Response;
use Core\Auth\AuthService;
use Modules\Marketing\Services\MarketingContactAudienceService;
use Modules\Marketing\Services\MarketingContactListService;

final class MarketingContactListsController
{
    public function __construct(
        private MarketingContactAudienceService $audienceService,
        private MarketingContactListService $listService,
        private AuthService $auth
    ) {
    }

    public function index(): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return;
        }
        $selection = $this->resolveSelectionFromRequest();
        $audienceKey = $selection['audience_key'];
        $manualListId = $selection['manual_list_id'];
        $search = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($search) > 200) {
            $search = mb_substr($search, 0, 200);
        }

        $manualListStorageReady = $this->listService->isStorageReady();
        if (!$manualListStorageReady) {
            if ($audienceKey === MarketingContactAudienceService::AUDIENCE_MANUAL_LIST) {
                $audienceKey = MarketingContactAudienceService::AUDIENCE_ALL_CONTACTS;
                $manualListId = null;
            }
        }

        $read = $this->audienceService->readAudience($branchId, $audienceKey, $manualListId, $search, 200, 0);
        $smartDefs = $this->audienceService->smartListDefinitions();
        $smartCounts = $this->audienceService->smartListCounts($branchId);
        $manualLists = $manualListStorageReady ? $this->listService->listManualListsWithCounts($branchId) : [];
        $selectedAudienceState = $this->toSelectedState($audienceKey, $manualListId);
        $selectedAudienceLabel = $this->selectedAudienceLabel($selectedAudienceState, $smartDefs, $manualLists);
        $selectedAudienceTotal = $this->audienceService->readAudience($branchId, $audienceKey, $manualListId, '', 1, 0)['total'] ?? 0;
        $flash = flash();
        $title = 'Contact Lists';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();

        require base_path('modules/marketing/views/contact-lists/index.php');
    }

    public function audienceRead(): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', 'Branch context required.', ['reason' => 'branch_required']);

            return;
        }
        $selection = $this->resolveSelectionFromRequest();
        $audienceKey = $selection['audience_key'];
        $manualListId = $selection['manual_list_id'];
        $search = trim((string) ($_GET['q'] ?? ''));
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $manualListStorageReady = $this->listService->isStorageReady();
        if (!$manualListStorageReady && $audienceKey === MarketingContactAudienceService::AUDIENCE_MANUAL_LIST) {
            $audienceKey = MarketingContactAudienceService::AUDIENCE_ALL_CONTACTS;
            $manualListId = null;
        }
        $payload = $this->audienceService->readAudience($branchId, $audienceKey, $manualListId, $search, $limit, $offset);
        $payload['manual_list_storage_ready'] = $manualListStorageReady;
        $payload['selected_state'] = $this->toSelectedState($audienceKey, $manualListId);
        $this->json($payload);
    }

    public function createManualList(): void
    {
        $this->handleMutation(function (int $branchId, ?int $userId): void {
            $name = trim((string) ($_POST['name'] ?? ''));
            $id = $this->listService->createList($branchId, $name, $userId);
            flash('success', 'Manual list created.');
            header('Location: /marketing/contact-lists?selected=manual:' . $id);
            exit;
        });
    }

    public function renameManualList(): void
    {
        $this->handleMutation(function (int $branchId, ?int $userId): void {
            $listId = (int) ($_POST['list_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $this->listService->renameList($branchId, $listId, $name, $userId);
            flash('success', 'Manual list renamed.');
            $this->redirectBack();
        });
    }

    public function archiveManualList(): void
    {
        $this->handleMutation(function (int $branchId, ?int $userId): void {
            $listId = (int) ($_POST['list_id'] ?? 0);
            $this->listService->archiveList($branchId, $listId, $userId);
            flash('success', 'Manual list archived.');
            header('Location: /marketing/contact-lists');
            exit;
        });
    }

    public function addContactsToList(): void
    {
        $this->handleMutation(function (int $branchId, ?int $userId): void {
            $listId = (int) ($_POST['list_id'] ?? 0);
            $ids = $this->contactIdsFromPost();
            $this->listService->addContacts($branchId, $listId, $ids, $userId);
            flash('success', 'Contacts added to manual list.');
            $this->redirectBack();
        });
    }

    public function removeContactsFromList(): void
    {
        $this->handleMutation(function (int $branchId, ?int $userId): void {
            $listId = (int) ($_POST['list_id'] ?? 0);
            $ids = $this->contactIdsFromPost();
            $this->listService->removeContacts($branchId, $listId, $ids);
            flash('success', 'Contacts removed from manual list.');
            $this->redirectBack();
        });
    }

    /**
     * @return list<int>
     */
    private function contactIdsFromPost(): array
    {
        $raw = $_POST['contact_ids'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $v = (int) $id;
            if ($v > 0) {
                $out[] = $v;
            }
        }

        return array_values(array_unique($out));
    }

    private function currentBranchId(): ?int
    {
        $branchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();

        return $branchId !== null ? (int) $branchId : null;
    }

    private function currentUserId(): ?int
    {
        $user = $this->auth->user();

        return isset($user['id']) ? (int) $user['id'] : null;
    }

    private function redirectBack(): void
    {
        $selected = rawurlencode((string) ($_POST['return_selected'] ?? MarketingContactAudienceService::AUDIENCE_ALL_CONTACTS));
        $q = trim((string) ($_POST['return_q'] ?? ''));
        $parts = ['/marketing/contact-lists?selected=' . $selected];
        if ($q !== '') {
            $parts[] = 'q=' . rawurlencode($q);
        }
        header('Location: ' . implode('&', $parts));
        exit;
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function handleMutation(callable $fn): void
    {
        $branchId = $this->currentBranchId();
        if ($branchId === null) {
            flash('error', 'Branch context is required.');
            header('Location: /marketing/contact-lists');
            exit;
        }

        try {
            $fn($branchId, $this->currentUserId());
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            $this->redirectBack();
        }
    }

    /**
     * @return array{audience_key:string,manual_list_id:int|null}
     */
    private function resolveSelectionFromRequest(): array
    {
        $selected = trim((string) ($_GET['selected'] ?? ''));
        if ($selected !== '') {
            if (str_starts_with($selected, 'manual:')) {
                $listId = (int) substr($selected, 7);
                if ($listId > 0) {
                    return [
                        'audience_key' => MarketingContactAudienceService::AUDIENCE_MANUAL_LIST,
                        'manual_list_id' => $listId,
                    ];
                }
            }
            $resolved = $this->audienceService->resolveAudienceKey($selected);
            if ($resolved !== MarketingContactAudienceService::AUDIENCE_MANUAL_LIST) {
                return ['audience_key' => $resolved, 'manual_list_id' => null];
            }
        }

        $audienceKey = $this->audienceService->resolveAudienceKey((string) ($_GET['audience'] ?? ''));
        $manualListId = isset($_GET['list_id']) ? (int) $_GET['list_id'] : null;
        if ($audienceKey === MarketingContactAudienceService::AUDIENCE_MANUAL_LIST && ($manualListId === null || $manualListId <= 0)) {
            return [
                'audience_key' => MarketingContactAudienceService::AUDIENCE_ALL_CONTACTS,
                'manual_list_id' => null,
            ];
        }

        return ['audience_key' => $audienceKey, 'manual_list_id' => $manualListId];
    }

    private function toSelectedState(string $audienceKey, ?int $manualListId): string
    {
        if ($audienceKey === MarketingContactAudienceService::AUDIENCE_MANUAL_LIST && $manualListId !== null && $manualListId > 0) {
            return 'manual:' . $manualListId;
        }

        return $audienceKey;
    }

    /**
     * @param list<array<string,mixed>> $smartDefs
     * @param list<array<string,mixed>> $manualLists
     */
    private function selectedAudienceLabel(string $selectedState, array $smartDefs, array $manualLists): string
    {
        if (str_starts_with($selectedState, 'manual:')) {
            $wanted = (int) substr($selectedState, 7);
            foreach ($manualLists as $list) {
                if ((int) ($list['id'] ?? 0) === $wanted) {
                    return (string) ($list['name'] ?? 'Manual List');
                }
            }
            return 'Manual List';
        }
        foreach ($smartDefs as $def) {
            if ((string) ($def['key'] ?? '') === $selectedState) {
                return (string) ($def['label'] ?? $selectedState);
            }
        }

        return 'All Contacts';
    }
}

