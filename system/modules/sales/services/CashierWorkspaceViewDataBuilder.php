<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\Contracts\ClientListProvider;
use Core\Contracts\ServiceListProvider;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Packages\Repositories\PackageRepository;

final class CashierWorkspaceViewDataBuilder
{
    public function __construct(
        private ClientListProvider $clientList,
        private ServiceListProvider $serviceList,
        private ProductRepository $productRepository,
        private MembershipDefinitionRepository $membershipDefinitions,
        private PackageRepository $packages
    ) {
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $errors
     * @return array{invoice: array<string, mixed>, clients: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>, products: array<int, array<string, mixed>>, membershipDefinitions: array<int, array<string, mixed>>, packages: array<int, array<string, mixed>>, errors: array<string, mixed>}
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function activeProductsForCashierBranch(?int $branchId): array
    {
        if ($branchId !== null && $branchId > 0) {
            return $this->productRepository->listActiveForUnifiedCatalogInResolvedOrg($branchId);
        }

        return $this->productRepository->listActiveOrgGlobalCatalogInResolvedOrg();
    }

    public function build(array $invoice, ?int $branchId, array $errors = []): array
    {
        $invoice = array_merge(['status' => 'draft', 'items' => []], $invoice);
        $invoice['branch_id'] = $branchId;
        if (empty($invoice['items']) || !is_array($invoice['items'])) {
            $invoice['items'] = [
                [
                    'item_type' => 'manual',
                    'source_id' => null,
                    'description' => '',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'discount_amount' => 0,
                    'tax_rate' => 0,
                ],
            ];
        }

        $packageRows = [];
        if ($branchId !== null && $branchId > 0) {
            $packageRows = $this->packages->listInTenantScope([], $branchId, 200, 0);
        }

        return [
            'invoice' => $invoice,
            'clients' => $this->clientList->list($branchId),
            'services' => $this->serviceList->list($branchId),
            'products' => $this->activeProductsForCashierBranch($branchId),
            'membershipDefinitions' => $this->membershipDefinitions->listActiveForInvoiceBranch($branchId),
            'packages' => $packageRows,
            'errors' => $errors,
        ];
    }
}
