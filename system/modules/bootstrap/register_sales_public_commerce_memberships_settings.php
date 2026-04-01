<?php

declare(strict_types=1);

$container->singleton(\Modules\Sales\Services\SalesTenantScope::class, fn ($c) => new \Modules\Sales\Services\SalesTenantScope($c->get(\Core\Organization\OrganizationContext::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Sales\Repositories\InvoiceRepository::class, fn ($c) => new \Modules\Sales\Repositories\InvoiceRepository($c->get(\Core\App\Database::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class)));
$container->singleton(\Modules\Sales\Repositories\InvoiceItemRepository::class, fn ($c) => new \Modules\Sales\Repositories\InvoiceItemRepository($c->get(\Core\App\Database::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class)));
$container->singleton(\Modules\Sales\Services\SalesLineDomainBoundaryTruthAuditService::class, fn ($c) => new \Modules\Sales\Services\SalesLineDomainBoundaryTruthAuditService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Sales\Services\SalesLineInventoryImpactTruthAuditService::class, fn ($c) => new \Modules\Sales\Services\SalesLineInventoryImpactTruthAuditService($c->get(\Modules\Sales\Services\SalesLineDomainBoundaryTruthAuditService::class), $c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Sales\Services\SalesLineLifecycleConsistencyTruthAuditService::class, fn ($c) => new \Modules\Sales\Services\SalesLineLifecycleConsistencyTruthAuditService($c->get(\Modules\Sales\Services\SalesLineInventoryImpactTruthAuditService::class)));
$container->singleton(\Modules\Sales\Services\InvoiceDomainCompositionTruthAuditService::class, fn ($c) => new \Modules\Sales\Services\InvoiceDomainCompositionTruthAuditService($c->get(\Modules\Sales\Services\SalesLineLifecycleConsistencyTruthAuditService::class)));
$container->singleton(\Modules\Sales\Services\InvoiceOperationalGateTruthAuditService::class, fn ($c) => new \Modules\Sales\Services\InvoiceOperationalGateTruthAuditService($c->get(\Modules\Sales\Services\SalesLineInventoryImpactTruthAuditService::class), $c->get(\Modules\Sales\Services\SalesLineLifecycleConsistencyTruthAuditService::class), $c->get(\Modules\Sales\Services\InvoiceDomainCompositionTruthAuditService::class)));
$container->singleton(\Modules\Sales\Services\InvoicePaymentSettlementTruthAuditService::class, fn ($c) => new \Modules\Sales\Services\InvoicePaymentSettlementTruthAuditService($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Sales\Services\InvoiceFinancialRollupTruthAuditService::class, fn ($c) => new \Modules\Sales\Services\InvoiceFinancialRollupTruthAuditService($c->get(\Core\App\Database::class), $c->get(\Modules\Sales\Services\SalesLineLifecycleConsistencyTruthAuditService::class)));
$container->singleton(\Modules\Sales\Repositories\PaymentRepository::class, fn ($c) => new \Modules\Sales\Repositories\PaymentRepository($c->get(\Core\App\Database::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class)));
$container->singleton(\Modules\Sales\Repositories\PaymentMethodRepository::class, fn ($c) => new \Modules\Sales\Repositories\PaymentMethodRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Sales\Repositories\VatRateRepository::class, fn ($c) => new \Modules\Sales\Repositories\VatRateRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Sales\Repositories\RegisterSessionRepository::class, fn ($c) => new \Modules\Sales\Repositories\RegisterSessionRepository($c->get(\Core\App\Database::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class)));
$container->singleton(\Modules\Sales\Repositories\CashMovementRepository::class, fn ($c) => new \Modules\Sales\Repositories\CashMovementRepository($c->get(\Core\App\Database::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class)));
$container->singleton(\Core\Contracts\ReceiptPrintDispatchProvider::class, fn () => new \Core\Contracts\NoopReceiptPrintDispatchProvider());
$container->singleton(\Core\Contracts\MembershipInvoiceSettlementProvider::class, fn ($c) => new \Modules\Memberships\Providers\MembershipInvoiceSettlementProvider(
    fn () => $c->get(\Modules\Memberships\Services\MembershipBillingService::class),
    fn () => $c->get(\Modules\Memberships\Services\MembershipSaleService::class)
));
$container->singleton(\Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository::class, fn ($c) => new \Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Organization\OrganizationRepositoryScope::class)
));
$container->singleton(\Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService::class, fn ($c) => new \Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService(
    $c->get(\Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository::class),
    $c->get(\Modules\Sales\Repositories\InvoiceRepository::class),
    $c->get(\Core\Audit\AuditService::class),
));
$container->singleton(\Core\Contracts\PublicCommerceFulfillmentReconciler::class, fn ($c) => new \Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconciler(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Audit\AuditService::class),
    $c->get(\Modules\Sales\Repositories\InvoiceRepository::class),
    $c->get(\Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository::class),
    $c->get(\Modules\Memberships\Repositories\MembershipSaleRepository::class),
    $c->get(\Modules\Packages\Services\PackageService::class),
    $c->get(\Modules\GiftCards\Services\GiftCardService::class),
    $c->get(\Modules\Memberships\Services\MembershipService::class),
));
$container->singleton(\Modules\PublicCommerce\Services\PublicCommerceFulfillmentRepairService::class, fn ($c) => new \Modules\PublicCommerce\Services\PublicCommerceFulfillmentRepairService(
    $c->get(\Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository::class),
    $c->get(\Core\Contracts\PublicCommerceFulfillmentReconciler::class),
    $c->get(\Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService::class),
    $c->get(\Core\Audit\AuditService::class),
));
$container->singleton(\Modules\PublicCommerce\Services\PublicCommerceService::class, fn ($c) => new \Modules\PublicCommerce\Services\PublicCommerceService(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\App\SettingsService::class),
    $c->get(\Core\Audit\AuditService::class),
    $c->get(\Modules\Clients\Services\PublicClientResolutionService::class),
    $c->get(\Modules\Sales\Repositories\InvoiceRepository::class),
    $c->get(\Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository::class),
    $c->get(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class),
    $c->get(\Modules\Packages\Repositories\PackageRepository::class),
    $c->get(\Core\Branch\BranchContext::class),
    $c->get(\Core\Auth\SessionAuth::class),
    $c->get(\Core\Contracts\PublicCommerceFulfillmentReconciler::class),
    $c->get(\Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService::class),
    $c->get(\Core\Organization\OrganizationLifecycleGate::class),
    $c->get(\Core\Organization\OrganizationContext::class)
));
$container->singleton(\Core\Contracts\PublicCommerceFulfillmentSync::class, fn ($c) => $c->get(\Modules\PublicCommerce\Services\PublicCommerceService::class));
$container->singleton(\Modules\Sales\Services\CashierLineDomainEffectsApplier::class, fn ($c) => new \Modules\Sales\Services\CashierLineDomainEffectsApplier($c->get(\Modules\GiftCards\Services\GiftCardService::class), $c->get(\Modules\Packages\Services\PackageService::class), $c->get(\Modules\Sales\Repositories\InvoiceItemRepository::class)));
$container->singleton(\Modules\Sales\Services\CashierLineItemValidator::class, fn ($c) => new \Modules\Sales\Services\CashierLineItemValidator($c->get(\Modules\Inventory\Repositories\ProductRepository::class), $c->get(\Core\Contracts\ServiceListProvider::class), $c->get(\Modules\Packages\Repositories\PackageRepository::class), $c->get(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class), $c->get(\Modules\Clients\Repositories\ClientRepository::class)));
$container->singleton(\Modules\Sales\Services\InvoiceService::class, fn ($c) => new \Modules\Sales\Services\InvoiceService($c->get(\Modules\Sales\Repositories\InvoiceRepository::class), $c->get(\Modules\Sales\Repositories\InvoiceItemRepository::class), $c->get(\Modules\Sales\Repositories\PaymentRepository::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\App\Database::class), $c->get(\Core\Contracts\InvoiceGiftCardRedemptionProvider::class), $c->get(\Core\Contracts\GiftCardAvailabilityProvider::class), $c->get(\Core\Kernel\RequestContextHolder::class), $c->get(\Core\Organization\OrganizationScopedBranchAssert::class), $c->get(\Core\Contracts\ServiceListProvider::class), $c->get(\Modules\Sales\Services\VatRateService::class), $c->get(\Core\App\SettingsService::class), $c->get(\Core\Contracts\MembershipInvoiceSettlementProvider::class), $c->get(\Core\Contracts\InvoiceStockSettlementProvider::class), $c->get(\Core\Contracts\ReceiptPrintDispatchProvider::class), $c->get(\Modules\Inventory\Repositories\ProductRepository::class), $c->get(\Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService::class), $c->get(\Modules\Sales\Services\CashierLineDomainEffectsApplier::class), fn () => $c->get(\Core\Contracts\PublicCommerceFulfillmentReconciler::class), $c->get(\Core\Kernel\Authorization\AuthorizerInterface::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Memberships\Repositories\MembershipBillingCycleRepository::class, fn ($c) => new \Modules\Memberships\Repositories\MembershipBillingCycleRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Memberships\Services\MembershipBillingService::class, fn ($c) => new \Modules\Memberships\Services\MembershipBillingService($c->get(\Core\App\Database::class), $c->get(\Modules\Memberships\Repositories\ClientMembershipRepository::class), $c->get(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class), $c->get(\Modules\Memberships\Repositories\MembershipBillingCycleRepository::class), $c->get(\Modules\Sales\Services\InvoiceService::class), $c->get(\Modules\Sales\Repositories\InvoiceRepository::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\App\SettingsService::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class), $c->get(\Core\Organization\OutOfBandLifecycleGuard::class)));
$container->singleton(\Modules\Memberships\Services\MembershipLifecycleService::class, fn ($c) => new \Modules\Memberships\Services\MembershipLifecycleService($c->get(\Core\App\Database::class), $c->get(\Modules\Memberships\Repositories\ClientMembershipRepository::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class), $c->get(\Core\App\SettingsService::class), $c->get(\Modules\Memberships\Services\MembershipBillingService::class), $c->get(\Core\Organization\OutOfBandLifecycleGuard::class)));
$container->singleton(\Modules\Memberships\Services\MembershipService::class, fn ($c) => new \Modules\Memberships\Services\MembershipService($c->get(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class), $c->get(\Modules\Memberships\Repositories\ClientMembershipRepository::class), $c->get(\Modules\Clients\Repositories\ClientRepository::class), $c->get(\Modules\Memberships\Repositories\MembershipBenefitUsageRepository::class), $c->get(\Core\App\Database::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\App\SettingsService::class), $c->get(\Modules\Notifications\Services\NotificationService::class), $c->get(\Modules\Notifications\Services\OutboundTransactionalNotificationService::class), $c->get(\Modules\Memberships\Services\MembershipBillingService::class), $c->get(\Modules\Memberships\Services\MembershipLifecycleService::class), $c->get(\Core\Organization\OutOfBandLifecycleGuard::class)));
$container->singleton(\Modules\Memberships\Repositories\MembershipSaleRepository::class, fn ($c) => new \Modules\Memberships\Repositories\MembershipSaleRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Memberships\Services\MembershipSaleService::class, fn ($c) => new \Modules\Memberships\Services\MembershipSaleService($c->get(\Core\App\Database::class), $c->get(\Modules\Memberships\Repositories\MembershipSaleRepository::class), $c->get(\Modules\Clients\Repositories\ClientRepository::class), $c->get(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class), $c->get(\Modules\Sales\Services\InvoiceService::class), $c->get(\Modules\Sales\Repositories\InvoiceRepository::class), $c->get(\Modules\Memberships\Services\MembershipService::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class), $c->get(\Core\App\SettingsService::class), fn () => $c->get(\Core\Contracts\PublicCommerceFulfillmentReconciler::class), $c->get(\Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService::class)));
$container->singleton(\Modules\Memberships\Services\MembershipRefundReviewService::class, fn ($c) => new \Modules\Memberships\Services\MembershipRefundReviewService($c->get(\Modules\Memberships\Repositories\MembershipSaleRepository::class), $c->get(\Modules\Memberships\Repositories\MembershipBillingCycleRepository::class), $c->get(\Modules\Memberships\Services\MembershipSaleService::class), $c->get(\Modules\Memberships\Services\MembershipBillingService::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Audit\AuditService::class)));
$container->singleton(\Modules\Memberships\Controllers\MembershipDefinitionController::class, fn ($c) => new \Modules\Memberships\Controllers\MembershipDefinitionController($c->get(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class), $c->get(\Modules\Memberships\Services\MembershipService::class), $c->get(\Core\Branch\BranchDirectory::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Memberships\Controllers\ClientMembershipController::class, fn ($c) => new \Modules\Memberships\Controllers\ClientMembershipController($c->get(\Modules\Memberships\Repositories\ClientMembershipRepository::class), $c->get(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class), $c->get(\Modules\Memberships\Services\MembershipService::class), $c->get(\Core\Contracts\ClientListProvider::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\App\SettingsService::class), $c->get(\Core\Branch\BranchDirectory::class), $c->get(\Modules\Clients\Repositories\ClientRepository::class)));
$container->singleton(\Modules\Memberships\Controllers\MembershipSaleController::class, fn ($c) => new \Modules\Memberships\Controllers\MembershipSaleController($c->get(\Modules\Memberships\Services\MembershipSaleService::class), $c->get(\Modules\Clients\Repositories\ClientRepository::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Audit\AuditService::class)));
$container->singleton(\Modules\Memberships\Controllers\MembershipLifecycleController::class, fn ($c) => new \Modules\Memberships\Controllers\MembershipLifecycleController($c->get(\Modules\Memberships\Services\MembershipLifecycleService::class), $c->get(\Core\Audit\AuditService::class)));
$container->singleton(\Modules\Memberships\Controllers\MembershipRefundReviewController::class, fn ($c) => new \Modules\Memberships\Controllers\MembershipRefundReviewController($c->get(\Modules\Memberships\Services\MembershipRefundReviewService::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Branch\BranchDirectory::class)));
$container->singleton(\Modules\Sales\Services\PaymentMethodService::class, fn ($c) => new \Modules\Sales\Services\PaymentMethodService($c->get(\Modules\Sales\Repositories\PaymentMethodRepository::class), $c->get(\Core\Kernel\RequestContextHolder::class)));
$container->singleton(\Modules\Sales\Services\VatRateService::class, fn ($c) => new \Modules\Sales\Services\VatRateService($c->get(\Modules\Sales\Repositories\VatRateRepository::class), $c->get(\Core\Kernel\RequestContextHolder::class)));
$container->singleton(\Modules\Sales\Services\RegisterSessionService::class, fn ($c) => new \Modules\Sales\Services\RegisterSessionService($c->get(\Modules\Sales\Repositories\RegisterSessionRepository::class), $c->get(\Modules\Sales\Repositories\CashMovementRepository::class), $c->get(\Modules\Sales\Repositories\PaymentRepository::class), $c->get(\Core\App\Database::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Kernel\RequestContextHolder::class), $c->get(\Core\Kernel\Authorization\AuthorizerInterface::class)));
$container->singleton(\Modules\Sales\Services\ReceiptInvoicePresentationService::class, fn ($c) => new \Modules\Sales\Services\ReceiptInvoicePresentationService($c->get(\Core\App\SettingsService::class), $c->get(\Core\App\Database::class), $c->get(\Modules\Inventory\Repositories\ProductRepository::class)));
$container->singleton(\Modules\Sales\Services\PaymentService::class, fn ($c) => new \Modules\Sales\Services\PaymentService($c->get(\Modules\Sales\Repositories\PaymentRepository::class), $c->get(\Modules\Sales\Repositories\InvoiceRepository::class), $c->get(\Modules\Sales\Repositories\RegisterSessionRepository::class), $c->get(\Modules\Sales\Services\InvoiceService::class), $c->get(\Core\Contracts\InvoiceGiftCardRedemptionProvider::class), $c->get(\Modules\Sales\Services\PaymentMethodService::class), $c->get(\Core\App\SettingsService::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\App\Database::class), $c->get(\Core\Kernel\RequestContextHolder::class), $c->get(\Core\Organization\OrganizationScopedBranchAssert::class), $c->get(\Modules\Notifications\Services\NotificationService::class), $c->get(\Core\Contracts\MembershipInvoiceSettlementProvider::class), $c->get(\Core\Contracts\ReceiptPrintDispatchProvider::class), $c->get(\Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService::class), fn () => $c->get(\Core\Contracts\PublicCommerceFulfillmentReconciler::class), $c->get(\Core\Kernel\Authorization\AuthorizerInterface::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\PublicCommerce\Controllers\PublicCommerceController::class, fn ($c) => new \Modules\PublicCommerce\Controllers\PublicCommerceController($c->get(\Modules\PublicCommerce\Services\PublicCommerceService::class), $c->get(\Modules\OnlineBooking\Services\PublicBookingAbuseGuardService::class), $c->get(\Core\Audit\AuditService::class)));
$container->singleton(\Modules\PublicCommerce\Controllers\PublicCommerceStaffController::class, fn ($c) => new \Modules\PublicCommerce\Controllers\PublicCommerceStaffController($c->get(\Modules\PublicCommerce\Services\PublicCommerceService::class)));
$container->singleton(\Core\Contracts\ClientSalesProfileProvider::class, fn ($c) => new \Modules\Sales\Providers\ClientSalesProfileProviderImpl(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\App\SettingsService::class),
    $c->get(\Modules\Clients\Services\ClientProfileAccessService::class),
    $c->get(\Modules\Sales\Services\SalesTenantScope::class),
));
$container->singleton(\Core\Contracts\CatalogSellableReadModelProvider::class, fn ($c) => new \Modules\Sales\Providers\CatalogSellableReadModelProviderImpl(
    $c->get(\Modules\ServicesResources\Repositories\ServiceRepository::class),
    $c->get(\Modules\Inventory\Repositories\ProductRepository::class),
));
$container->singleton(\Modules\Sales\Services\CashierWorkspaceViewDataBuilder::class, fn ($c) => new \Modules\Sales\Services\CashierWorkspaceViewDataBuilder(
    $c->get(\Core\Contracts\ClientListProvider::class),
    $c->get(\Core\Contracts\ServiceListProvider::class),
    $c->get(\Modules\Inventory\Repositories\ProductRepository::class),
    $c->get(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class),
    $c->get(\Modules\Packages\Repositories\PackageRepository::class)
));
$container->singleton(\Modules\Sales\Controllers\RegisterController::class, fn ($c) => new \Modules\Sales\Controllers\RegisterController($c->get(\Modules\Sales\Repositories\RegisterSessionRepository::class), $c->get(\Modules\Sales\Repositories\CashMovementRepository::class), $c->get(\Modules\Sales\Services\RegisterSessionService::class), $c->get(\Core\Branch\BranchDirectory::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class)));
$container->singleton(\Modules\Sales\Controllers\InvoiceController::class, fn ($c) => new \Modules\Sales\Controllers\InvoiceController($c->get(\Modules\Sales\Repositories\InvoiceRepository::class), $c->get(\Modules\Sales\Repositories\InvoiceItemRepository::class), $c->get(\Modules\Sales\Repositories\PaymentRepository::class), $c->get(\Modules\Sales\Services\InvoiceService::class), $c->get(\Core\Contracts\ClientListProvider::class), $c->get(\Core\Contracts\ServiceListProvider::class), $c->get(\Core\Contracts\AppointmentCheckoutProvider::class), $c->get(\Core\Contracts\GiftCardAvailabilityProvider::class), $c->get(\Core\Contracts\InvoiceGiftCardRedemptionProvider::class), $c->get(\Modules\Memberships\Services\MembershipSaleService::class), $c->get(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class), $c->get(\Modules\Clients\Repositories\ClientRepository::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Branch\BranchDirectory::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class), $c->get(\Modules\Sales\Services\ReceiptInvoicePresentationService::class), $c->get(\Modules\Sales\Services\CashierLineItemValidator::class)));
$container->singleton(\Modules\Sales\Controllers\SalesController::class, fn ($c) => new \Modules\Sales\Controllers\SalesController($c->get(\Modules\Sales\Controllers\InvoiceController::class)));
$container->singleton(\Modules\Sales\Controllers\PaymentController::class, fn ($c) => new \Modules\Sales\Controllers\PaymentController($c->get(\Modules\Sales\Repositories\InvoiceRepository::class), $c->get(\Modules\Sales\Repositories\PaymentRepository::class), $c->get(\Modules\Sales\Services\PaymentService::class), $c->get(\Modules\Sales\Services\PaymentMethodService::class), $c->get(\Core\App\SettingsService::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Settings\Repositories\BranchOperatingHoursRepository::class, fn ($c) => new \Modules\Settings\Repositories\BranchOperatingHoursRepository($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Settings\Services\BranchOperatingHoursService::class, fn ($c) => new \Modules\Settings\Services\BranchOperatingHoursService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Settings\Repositories\BranchOperatingHoursRepository::class),
    $c->get(\Core\Kernel\RequestContextHolder::class),
    $c->get(\Core\Kernel\Authorization\AuthorizerInterface::class)
));
$container->singleton(\Modules\Settings\Repositories\BranchClosureDateRepository::class, fn ($c) => new \Modules\Settings\Repositories\BranchClosureDateRepository($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Settings\Repositories\AppointmentCancellationReasonRepository::class, fn ($c) => new \Modules\Settings\Repositories\AppointmentCancellationReasonRepository(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Organization\OrganizationContext::class)
));
$container->singleton(\Modules\Settings\Repositories\PriceModificationReasonRepository::class, fn ($c) => new \Modules\Settings\Repositories\PriceModificationReasonRepository(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Organization\OrganizationContext::class)
));
$container->singleton(\Modules\Settings\Services\BranchClosureDateService::class, fn ($c) => new \Modules\Settings\Services\BranchClosureDateService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Settings\Repositories\BranchClosureDateRepository::class),
    $c->get(\Core\Audit\AuditService::class),
    $c->get(\Core\Auth\SessionAuth::class),
    $c->get(\Core\Kernel\RequestContextHolder::class),
    $c->get(\Core\Kernel\Authorization\AuthorizerInterface::class)
));
$container->singleton(\Modules\Settings\Services\AppointmentCancellationReasonService::class, fn ($c) => new \Modules\Settings\Services\AppointmentCancellationReasonService(
    $c->get(\Modules\Settings\Repositories\AppointmentCancellationReasonRepository::class),
    $c->get(\Core\Audit\AuditService::class),
    $c->get(\Core\Auth\SessionAuth::class),
    $c->get(\Core\Kernel\RequestContextHolder::class),
    $c->get(\Core\Kernel\Authorization\AuthorizerInterface::class)
));
$container->singleton(\Modules\Settings\Services\PriceModificationReasonService::class, fn ($c) => new \Modules\Settings\Services\PriceModificationReasonService(
    $c->get(\Modules\Settings\Repositories\PriceModificationReasonRepository::class),
    $c->get(\Core\Audit\AuditService::class),
    $c->get(\Core\Auth\SessionAuth::class),
    $c->get(\Core\Kernel\RequestContextHolder::class),
    $c->get(\Core\Kernel\Authorization\AuthorizerInterface::class)
));
// Main settings workspace ({@see system/routes/web/register_settings.php}); container-only Dispatcher (A-002).
$container->singleton(\Modules\Settings\Controllers\SettingsController::class, static fn () => new \Modules\Settings\Controllers\SettingsController());
$container->singleton(\Modules\Settings\Controllers\PaymentMethodsController::class, fn ($c) => new \Modules\Settings\Controllers\PaymentMethodsController($c->get(\Modules\Sales\Services\PaymentMethodService::class)));
$container->singleton(\Modules\Settings\Controllers\PriceModificationReasonsController::class, fn ($c) => new \Modules\Settings\Controllers\PriceModificationReasonsController($c->get(\Modules\Settings\Services\PriceModificationReasonService::class)));
$container->singleton(\Modules\Settings\Controllers\VatRatesController::class, fn ($c) => new \Modules\Settings\Controllers\VatRatesController($c->get(\Modules\Sales\Services\VatRateService::class)));
$container->singleton(\Modules\Settings\Controllers\VatDistributionController::class, fn ($c) => new \Modules\Settings\Controllers\VatDistributionController($c->get(\Modules\Sales\Services\VatRateService::class)));

