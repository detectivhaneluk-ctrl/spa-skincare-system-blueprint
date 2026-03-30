<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\Organization\OrganizationContext;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Central SQL scope contract for tenant-protected sales data-plane queries.
 * Applies organization-owned branch EXISTS filters when tenant org context is resolved.
 */
final class SalesTenantScope
{
    public function __construct(
        private OrganizationContext $organizationContext,
        private OrganizationRepositoryScope $organizationScope
    ) {
    }

    /**
     * @return array{sql:string,params:list<mixed>}
     */
    public function invoiceClause(string $invoiceAlias = 'i'): array
    {
        return $this->organizationScope->branchColumnOwnedByResolvedOrganizationExistsClause($invoiceAlias, 'branch_id');
    }

    /**
     * @return array{sql:string,params:list<mixed>}
     */
    public function registerSessionClause(string $sessionAlias = 'rs'): array
    {
        return $this->organizationScope->branchColumnOwnedByResolvedOrganizationExistsClause($sessionAlias, 'branch_id');
    }

    /**
     * @return array{sql:string,params:list<mixed>}
     */
    public function cashMovementClause(string $movementAlias = 'cm'): array
    {
        return $this->organizationScope->branchColumnOwnedByResolvedOrganizationExistsClause($movementAlias, 'branch_id');
    }

    public function requiresProtectedTenantContext(): bool
    {
        return $this->organizationContext->getCurrentOrganizationId() !== null;
    }

    public function assertProtectedTenantContextResolved(): void
    {
        if ($this->requiresProtectedTenantContext()) {
            return;
        }

        throw new \DomainException('Protected sales runtime requires resolved tenant organization context.');
    }

    /**
     * Same branch-derived organization basis as {@see invoiceClause()} for invoice-plane mutations without an invoice row alias
     * (e.g. per-organization invoice counters). Fails closed when org is unresolved or not {@see OrganizationContext::MODE_BRANCH_DERIVED}.
     *
     * @throws \Core\Errors\AccessDeniedException
     */
    public function requireBranchDerivedOrganizationIdForInvoicePlane(): int
    {
        return $this->organizationScope->requireBranchDerivedOrganizationIdForDataPlane();
    }

    /**
     * @return array{sql:string,params:list<mixed>}
     */
    public function paymentByInvoiceExistsClause(string $paymentAlias = 'p', string $invoiceAlias = 'si'): array
    {
        $invoiceScope = $this->invoiceClause($invoiceAlias);
        if ($invoiceScope['sql'] === '') {
            return ['sql' => '', 'params' => []];
        }

        $sql = " AND EXISTS (
            SELECT 1
            FROM invoices {$invoiceAlias}
            WHERE {$invoiceAlias}.id = {$paymentAlias}.invoice_id
              AND {$invoiceAlias}.deleted_at IS NULL
              {$invoiceScope['sql']}
        )";

        return ['sql' => $sql, 'params' => $invoiceScope['params']];
    }

    /**
     * @return array{sql:string,params:list<mixed>}
     */
    public function invoiceItemByInvoiceExistsClause(string $itemAlias = 'ii', string $invoiceAlias = 'si'): array
    {
        $invoiceScope = $this->invoiceClause($invoiceAlias);
        if ($invoiceScope['sql'] === '') {
            return ['sql' => '', 'params' => []];
        }

        $sql = " AND EXISTS (
            SELECT 1
            FROM invoices {$invoiceAlias}
            WHERE {$invoiceAlias}.id = {$itemAlias}.invoice_id
              AND {$invoiceAlias}.deleted_at IS NULL
              {$invoiceScope['sql']}
        )";

        return ['sql' => $sql, 'params' => $invoiceScope['params']];
    }
}
