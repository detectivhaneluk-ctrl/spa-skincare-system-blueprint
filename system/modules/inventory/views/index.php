<?php
$title = 'Inventory';
ob_start();
?>
<div class="inventory-hub">
    <header class="inventory-hub__header">
        <h1 class="inventory-hub__title">Inventory</h1>
        <p class="inventory-hub__lead">Products, stock movements, and supplier records for your business.</p>
    </header>
    <div class="inventory-hub__links">
        <a class="inventory-hub__link" href="/inventory/products">Products</a>
        <a class="inventory-hub__link" href="/inventory/product-categories">Product categories</a>
        <a class="inventory-hub__link" href="/inventory/product-brands">Brands</a>
        <a class="inventory-hub__link" href="/inventory/suppliers">Suppliers</a>
        <a class="inventory-hub__link" href="/inventory/movements">Stock movements</a>
        <a class="inventory-hub__link" href="/inventory/counts">Stock counts</a>
    </div>
</div>
<style>
.inventory-hub__header { margin-bottom: 1.25rem; }
.inventory-hub__title { margin: 0 0 0.25rem; font-size: 1.35rem; font-weight: 700; color: #111827; }
.inventory-hub__lead { margin: 0; font-size: 0.87rem; color: #4b5563; }
.inventory-hub__links { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.inventory-hub__link { padding: 0.5rem 1rem; border: 1px solid #e5e7eb; border-radius: 0.5rem; background: #f9fafb; font-size: 0.87rem; color: #111827; text-decoration: none; }
.inventory-hub__link:hover { background: #f3f4f6; }
</style>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
