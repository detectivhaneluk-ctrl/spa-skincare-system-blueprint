<?php

declare(strict_types=1);

namespace Modules\Sales\Controllers;

/**
 * Sales module entry: GET {@see /sales} delegates to {@see InvoiceController::staffCheckoutFromSalesRoute()}
 * so staff checkout uses the same preparation path as {@see /sales/invoices/create}.
 */
final class SalesController
{
    public function __construct(private InvoiceController $invoiceController)
    {
    }

    public function index(): void
    {
        $this->invoiceController->staffCheckoutFromSalesRoute();
    }
}
