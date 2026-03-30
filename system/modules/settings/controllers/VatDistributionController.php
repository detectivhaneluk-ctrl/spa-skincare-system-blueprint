<?php

declare(strict_types=1);

namespace Modules\Settings\Controllers;

use Core\App\Application;
use Core\Audit\AuditService;
use Modules\Sales\Services\VatRateService;
use Modules\Settings\Support\SettingsShellSidebar;

final class VatDistributionController
{
    /** @var list<string> */
    private const MATRIX_DOMAINS = ['products', 'services', 'memberships'];

    public function __construct(private VatRateService $vatRateService)
    {
    }

    public function index(): void
    {
        $vatRates = $this->vatRateService->listActive(null);
        $matrixDomains = self::MATRIX_DOMAINS;
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/vat-distribution-guide.php');
    }

    public function store(): void
    {
        $submitted = is_array($_POST['matrix'] ?? null) ? (array) $_POST['matrix'] : [];
        $audit = Application::container()->get(AuditService::class);

        try {
            $beforeRows = $this->vatRateService->listActive(null);
            $beforeById = [];
            foreach ($beforeRows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $beforeById[$id] = is_array($row['applies_to_json'] ?? null) ? $row['applies_to_json'] : [];
                }
            }

            $summary = $this->vatRateService->bulkUpdateGlobalApplicabilityMatrix($submitted, self::MATRIX_DOMAINS);

            $afterRows = $this->vatRateService->listActive(null);
            $afterById = [];
            foreach ($afterRows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $afterById[$id] = is_array($row['applies_to_json'] ?? null) ? $row['applies_to_json'] : [];
                }
            }

            $audit->log('vat_distribution_matrix_updated', 'vat_rate', null, null, null, [
                'domains' => self::MATRIX_DOMAINS,
                'updated_count' => $summary['updated_count'] ?? 0,
                'before' => $beforeById,
                'after' => $afterById,
            ]);

            flash('success', 'VAT distribution matrix saved.');
            header('Location: /settings/vat-distribution-guide');
            exit;
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /settings/vat-distribution-guide');
            exit;
        }
    }

    /**
     * @return array<string, bool>
     */
    private function sidebarPermissions(): array
    {
        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();

        return SettingsShellSidebar::permissionFlagsForUser($user);
    }
}
