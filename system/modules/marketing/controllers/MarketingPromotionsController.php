<?php

declare(strict_types=1);

namespace Modules\Marketing\Controllers;

use Core\App\Application;
use Modules\Marketing\Services\MarketingSpecialOfferService;

final class MarketingPromotionsController
{
    public function __construct(private MarketingSpecialOfferService $offers)
    {
    }

    public function specialOffers(): void
    {
        try {
            $storageReady = $this->offers->isStorageReady();
            $filters = [
                'name' => isset($_GET['name']) ? (string) $_GET['name'] : '',
                'code' => isset($_GET['code']) ? (string) $_GET['code'] : '',
                'origin' => isset($_GET['origin']) ? (string) $_GET['origin'] : '',
                'adjustment_type' => isset($_GET['adjustment']) ? (string) $_GET['adjustment'] : '',
                'offer_option' => isset($_GET['options']) ? (string) $_GET['options'] : '',
            ];
            $items = $storageReady ? $this->offers->listForCurrentBranch($filters) : [];
            $editId = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : 0;
            $editingOffer = ($storageReady && $editId > 0) ? $this->offers->getForCurrentBranch($editId) : null;
        } catch (\DomainException) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return;
        }

        $title = 'Special Offers';
        $flash = flash();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $specialOffersAdminOnlyNotice = MarketingSpecialOfferService::ADMIN_ONLY_EXECUTION_MESSAGE;
        require base_path('modules/marketing/views/promotions/special-offers.php');
    }

    public function editSpecialOffer(int $id): void
    {
        header('Location: /marketing/promotions/special-offers?edit_id=' . $id);
        exit;
    }

    public function createSpecialOffer(): void
    {
        try {
            $this->offers->createForCurrentBranch([
                'name' => (string) ($_POST['name'] ?? ''),
                'code' => (string) ($_POST['code'] ?? ''),
                'origin' => (string) ($_POST['origin'] ?? 'manual'),
                'adjustment_type' => (string) ($_POST['adjustment_type'] ?? 'percent'),
                'adjustment_value' => (string) ($_POST['adjustment_value'] ?? '0'),
                'offer_option' => (string) ($_POST['offer_option'] ?? 'all'),
                'start_date' => (string) ($_POST['start_date'] ?? ''),
                'end_date' => (string) ($_POST['end_date'] ?? ''),
            ]);
            flash('success', 'Special offer saved (admin catalog only—not applied to booking, checkout, or invoices yet).');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /marketing/promotions/special-offers');
        exit;
    }

    public function deleteSpecialOffer(int $id): void
    {
        try {
            $this->offers->softDeleteForCurrentBranch($id);
            flash('success', 'Special offer deleted.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /marketing/promotions/special-offers');
        exit;
    }

    public function updateSpecialOffer(int $id): void
    {
        try {
            $this->offers->updateForCurrentBranch($id, [
                'name' => (string) ($_POST['name'] ?? ''),
                'code' => (string) ($_POST['code'] ?? ''),
                'origin' => (string) ($_POST['origin'] ?? 'manual'),
                'adjustment_type' => (string) ($_POST['adjustment_type'] ?? 'percent'),
                'adjustment_value' => (string) ($_POST['adjustment_value'] ?? '0'),
                'offer_option' => (string) ($_POST['offer_option'] ?? 'all'),
                'start_date' => (string) ($_POST['start_date'] ?? ''),
                'end_date' => (string) ($_POST['end_date'] ?? ''),
            ]);
            flash('success', 'Special offer updated (still admin-only until pricing execution is implemented).');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /marketing/promotions/special-offers');
        exit;
    }

    public function toggleSpecialOfferActive(int $id): void
    {
        try {
            $this->offers->toggleActiveForCurrentBranch($id);
            flash('success', 'Legacy “active” flag cleared. Offer remains stored; it is not applied at booking or checkout.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /marketing/promotions/special-offers');
        exit;
    }
}

