<?php

declare(strict_types=1);

$container->singleton(\Modules\Inventory\Repositories\ProductRepository::class, fn ($c) => new \Modules\Inventory\Repositories\ProductRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Inventory\Repositories\ProductCategoryRepository::class, fn ($c) => new \Modules\Inventory\Repositories\ProductCategoryRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Inventory\Repositories\ProductBrandRepository::class, fn ($c) => new \Modules\Inventory\Repositories\ProductBrandRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Inventory\Services\ProductTaxonomyAssignabilityService::class, fn ($c) => new \Modules\Inventory\Services\ProductTaxonomyAssignabilityService(
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductBrandRepository::class),
));
$container->singleton(\Modules\Inventory\Services\ProductTaxonomyLegacyBackfillService::class, fn ($c) => new \Modules\Inventory\Services\ProductTaxonomyLegacyBackfillService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Inventory\Repositories\ProductRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductBrandRepository::class),
));
$container->singleton(\Modules\Inventory\Services\ProductTaxonomyOrphanFkAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductTaxonomyOrphanFkAuditService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Inventory\Repositories\ProductRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductBrandRepository::class),
));
$container->singleton(\Modules\Inventory\Services\ProductCatalogReferenceCoverageAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductCatalogReferenceCoverageAuditService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Inventory\Services\ProductLegacyNormalizedTaxonomyCoherenceAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductLegacyNormalizedTaxonomyCoherenceAuditService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Inventory\Services\ActiveProductDomainReadinessAuditService::class, fn ($c) => new \Modules\Inventory\Services\ActiveProductDomainReadinessAuditService(
    $c->get(\Modules\Inventory\Services\ProductCatalogReferenceCoverageAuditService::class),
    $c->get(\Modules\Inventory\Services\ProductLegacyNormalizedTaxonomyCoherenceAuditService::class),
    $c->get(\Core\App\Database::class),
));
$container->singleton(\Modules\Inventory\Services\ActiveProductInventoryReadinessMatrixAuditService::class, fn ($c) => new \Modules\Inventory\Services\ActiveProductInventoryReadinessMatrixAuditService(
    $c->get(\Modules\Inventory\Services\ActiveProductDomainReadinessAuditService::class),
    $c->get(\Modules\Inventory\Services\ProductNegativeOnHandExposureReportService::class),
    $c->get(\Core\App\Database::class),
));
$container->singleton(\Modules\Inventory\Services\ProductTaxonomyDuplicateCanonicalRelinkService::class, fn ($c) => new \Modules\Inventory\Services\ProductTaxonomyDuplicateCanonicalRelinkService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Inventory\Repositories\ProductRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductBrandRepository::class),
));
$container->singleton(\Modules\Inventory\Services\ProductTaxonomyDuplicateNoncanonicalRetireService::class, fn ($c) => new \Modules\Inventory\Services\ProductTaxonomyDuplicateNoncanonicalRetireService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Inventory\Repositories\ProductRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductBrandRepository::class),
    $c->get(\Core\Branch\BranchContext::class),
));
$container->singleton(\Modules\Inventory\Services\ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService::class, fn ($c) => new \Modules\Inventory\Services\ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Inventory\Repositories\ProductRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductBrandRepository::class),
    $c->get(\Core\Branch\BranchContext::class),
));
$container->singleton(\Modules\Inventory\Services\ProductCategoryDuplicateParentCanonicalRelinkService::class, fn ($c) => new \Modules\Inventory\Services\ProductCategoryDuplicateParentCanonicalRelinkService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Core\Branch\BranchContext::class),
));
$container->singleton(\Modules\Inventory\Services\ProductCategoryTreeIntegrityAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductCategoryTreeIntegrityAuditService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Core\Branch\BranchContext::class),
));
$container->singleton(\Modules\Inventory\Services\ProductCategoryTreeCycleClusterAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductCategoryTreeCycleClusterAuditService(
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
));
$container->singleton(\Modules\Inventory\Services\ProductCategoryTreeCycleClusterSafeBreakService::class, fn ($c) => new \Modules\Inventory\Services\ProductCategoryTreeCycleClusterSafeBreakService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Modules\Inventory\Services\ProductCategoryTreeCycleClusterAuditService::class),
));
$container->singleton(\Modules\Inventory\Services\ProductCategoryTreePostRepairConsolidatedRecheckService::class, fn ($c) => new \Modules\Inventory\Services\ProductCategoryTreePostRepairConsolidatedRecheckService(
    $c->get(\Modules\Inventory\Services\ProductCategoryTreeIntegrityAuditService::class),
    $c->get(\Modules\Inventory\Services\ProductCategoryTreeCycleClusterAuditService::class),
));
$container->singleton(\Modules\Inventory\Services\ProductCategoryService::class, fn ($c) => new \Modules\Inventory\Services\ProductCategoryService(
    $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductRepository::class),
    $c->get(\Core\Audit\AuditService::class),
    $c->get(\Core\Branch\BranchContext::class),
));
$container->singleton(\Modules\Inventory\Services\ProductBrandService::class, fn ($c) => new \Modules\Inventory\Services\ProductBrandService(
    $c->get(\Modules\Inventory\Repositories\ProductBrandRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductRepository::class),
    $c->get(\Core\Audit\AuditService::class),
    $c->get(\Core\Branch\BranchContext::class),
));
$container->singleton(\Modules\Inventory\Repositories\SupplierRepository::class, fn ($c) => new \Modules\Inventory\Repositories\SupplierRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Inventory\Repositories\StockMovementRepository::class, fn ($c) => new \Modules\Inventory\Repositories\StockMovementRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class)));
$container->singleton(\Modules\Inventory\Repositories\InventoryCountRepository::class, fn ($c) => new \Modules\Inventory\Repositories\InventoryCountRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Inventory\Services\StockMovementService::class, fn ($c) => new \Modules\Inventory\Services\StockMovementService($c->get(\Modules\Inventory\Repositories\StockMovementRepository::class), $c->get(\Modules\Inventory\Repositories\ProductRepository::class), $c->get(\Core\App\Database::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Inventory\Services\ProductStockLedgerReconciliationService::class, fn ($c) => new \Modules\Inventory\Services\ProductStockLedgerReconciliationService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Inventory\Services\ProductGlobalSkuBranchAttributionAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductGlobalSkuBranchAttributionAuditService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Inventory\Services\ProductStockMovementOriginClassificationReportService::class, fn ($c) => new \Modules\Inventory\Services\ProductStockMovementOriginClassificationReportService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Inventory\Services\ProductStockMovementReferenceIntegrityAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductStockMovementReferenceIntegrityAuditService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Inventory\Services\ProductStockMovementClassificationDriftAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductStockMovementClassificationDriftAuditService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Inventory\Services\ProductStockQualityConsolidatedAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductStockQualityConsolidatedAuditService(
    $c->get(\Modules\Inventory\Services\ProductStockLedgerReconciliationService::class),
    $c->get(\Modules\Inventory\Services\ProductGlobalSkuBranchAttributionAuditService::class),
    $c->get(\Modules\Inventory\Services\ProductStockMovementOriginClassificationReportService::class),
    $c->get(\Modules\Inventory\Services\ProductStockMovementReferenceIntegrityAuditService::class),
    $c->get(\Modules\Inventory\Services\ProductStockMovementClassificationDriftAuditService::class),
));
$container->singleton(\Modules\Inventory\Services\ProductStockQualitySnapshotComparisonService::class, fn () => new \Modules\Inventory\Services\ProductStockQualitySnapshotComparisonService());
$container->singleton(\Modules\Inventory\Services\ProductStockQualityPreflightAdvisoryService::class, fn ($c) => new \Modules\Inventory\Services\ProductStockQualityPreflightAdvisoryService(
    $c->get(\Modules\Inventory\Services\ProductStockQualitySnapshotComparisonService::class),
));
$container->singleton(\Modules\Inventory\Services\ActiveProductOperationalGateAuditService::class, fn ($c) => new \Modules\Inventory\Services\ActiveProductOperationalGateAuditService(
    $c->get(\Modules\Inventory\Services\ActiveProductInventoryReadinessMatrixAuditService::class),
    $c->get(\Modules\Inventory\Services\ProductStockQualityConsolidatedAuditService::class),
    $c->get(\Modules\Inventory\Services\ProductStockQualityPreflightAdvisoryService::class),
));
$container->singleton(\Modules\Inventory\Services\ProductStockHealthContractCoherenceAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductStockHealthContractCoherenceAuditService(
    $c->get(\Modules\Inventory\Services\ProductStockQualityConsolidatedAuditService::class),
    $c->get(\Modules\Inventory\Services\ProductStockQualitySnapshotComparisonService::class),
    $c->get(\Modules\Inventory\Services\ProductStockQualityPreflightAdvisoryService::class),
));
$container->singleton(\Modules\Inventory\Services\ProductInvoiceStockSettlementDrilldownService::class, fn ($c) => new \Modules\Inventory\Services\ProductInvoiceStockSettlementDrilldownService($c->get(\Core\App\Database::class), $c->get(\Modules\Inventory\Repositories\StockMovementRepository::class)));
$container->singleton(\Modules\Inventory\Services\ProductNegativeOnHandExposureReportService::class, fn ($c) => new \Modules\Inventory\Services\ProductNegativeOnHandExposureReportService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Inventory\Services\ProductInvoiceRefundReturnSettlementVisibilityAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductInvoiceRefundReturnSettlementVisibilityAuditService($c->get(\Core\App\Database::class), $c->get(\Modules\Inventory\Repositories\StockMovementRepository::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Inventory\Services\ProductInternalUsageServiceConsumptionBoundaryAuditService::class, fn ($c) => new \Modules\Inventory\Services\ProductInternalUsageServiceConsumptionBoundaryAuditService($c->get(\Core\App\Database::class)));
// Invoice stock settlement enforces Modules\Inventory\Services\InvoiceProductStockBranchContract on product lines (mirrored early in InvoiceService).
$container->singleton(\Modules\Inventory\Services\InvoiceStockSettlementService::class, fn ($c) => new \Modules\Inventory\Services\InvoiceStockSettlementService($c->get(\Core\App\Database::class), $c->get(\Modules\Sales\Repositories\InvoiceRepository::class), $c->get(\Modules\Sales\Repositories\InvoiceItemRepository::class), $c->get(\Modules\Inventory\Repositories\ProductRepository::class), $c->get(\Modules\Inventory\Repositories\StockMovementRepository::class), $c->get(\Modules\Inventory\Services\StockMovementService::class)));
$container->singleton(\Core\Contracts\InvoiceStockSettlementProvider::class, fn ($c) => new \Modules\Inventory\Providers\InvoiceStockSettlementProviderImpl(fn () => $c->get(\Modules\Inventory\Services\InvoiceStockSettlementService::class)));
$container->singleton(\Modules\Inventory\Services\ProductService::class, fn ($c) => new \Modules\Inventory\Services\ProductService($c->get(\Modules\Inventory\Repositories\ProductRepository::class), $c->get(\Modules\Inventory\Services\StockMovementService::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Modules\Inventory\Services\ProductTaxonomyAssignabilityService::class)));
$container->singleton(\Modules\Inventory\Services\SupplierService::class, fn ($c) => new \Modules\Inventory\Services\SupplierService($c->get(\Modules\Inventory\Repositories\SupplierRepository::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Inventory\Services\InventoryCountService::class, fn ($c) => new \Modules\Inventory\Services\InventoryCountService($c->get(\Modules\Inventory\Repositories\InventoryCountRepository::class), $c->get(\Modules\Inventory\Repositories\ProductRepository::class), $c->get(\Modules\Inventory\Services\StockMovementService::class), $c->get(\Core\App\Database::class), $c->get(\Core\Permissions\PermissionService::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Inventory\Controllers\InventoryController::class, fn () => new \Modules\Inventory\Controllers\InventoryController());
$container->singleton(\Modules\Inventory\Controllers\ProductController::class, fn ($c) => new \Modules\Inventory\Controllers\ProductController($c->get(\Modules\Inventory\Repositories\ProductRepository::class), $c->get(\Modules\Inventory\Services\ProductService::class), $c->get(\Core\Branch\BranchDirectory::class), $c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class), $c->get(\Modules\Inventory\Repositories\ProductBrandRepository::class)));
$container->singleton(\Modules\Inventory\Controllers\ProductCategoryController::class, fn ($c) => new \Modules\Inventory\Controllers\ProductCategoryController($c->get(\Modules\Inventory\Repositories\ProductCategoryRepository::class), $c->get(\Modules\Inventory\Services\ProductCategoryService::class), $c->get(\Core\Branch\BranchDirectory::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Inventory\Controllers\ProductBrandController::class, fn ($c) => new \Modules\Inventory\Controllers\ProductBrandController($c->get(\Modules\Inventory\Repositories\ProductBrandRepository::class), $c->get(\Modules\Inventory\Services\ProductBrandService::class), $c->get(\Core\Branch\BranchDirectory::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Inventory\Controllers\SupplierController::class, fn ($c) => new \Modules\Inventory\Controllers\SupplierController($c->get(\Modules\Inventory\Repositories\SupplierRepository::class), $c->get(\Modules\Inventory\Services\SupplierService::class), $c->get(\Core\Branch\BranchDirectory::class)));
$container->singleton(\Modules\Inventory\Controllers\StockMovementController::class, fn ($c) => new \Modules\Inventory\Controllers\StockMovementController($c->get(\Modules\Inventory\Repositories\StockMovementRepository::class), $c->get(\Modules\Inventory\Services\StockMovementService::class), $c->get(\Modules\Inventory\Repositories\ProductRepository::class), $c->get(\Core\Branch\BranchDirectory::class)));
$container->singleton(\Modules\Inventory\Controllers\InventoryCountController::class, fn ($c) => new \Modules\Inventory\Controllers\InventoryCountController($c->get(\Modules\Inventory\Repositories\InventoryCountRepository::class), $c->get(\Modules\Inventory\Services\InventoryCountService::class), $c->get(\Modules\Inventory\Repositories\ProductRepository::class), $c->get(\Core\Branch\BranchDirectory::class)));

