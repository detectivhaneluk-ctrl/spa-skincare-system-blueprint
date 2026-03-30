<?php

declare(strict_types=1);

namespace Modules\Inventory\Controllers;

final class InventoryController
{
    public function index(): void
    {
        require base_path('modules/inventory/views/index.php');
    }
}
