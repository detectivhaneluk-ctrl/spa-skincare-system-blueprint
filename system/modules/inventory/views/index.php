<?php
$title = 'Inventory';
ob_start();
?>
<h1>Inventory</h1>
<p class="hint">Foundation module for products, suppliers, stock movements, and inventory counts.</p>
<div class="inventory-links">
    <a class="btn" href="/inventory/products">Products</a>
    <a class="btn" href="/inventory/product-categories">Product categories</a>
    <a class="btn" href="/inventory/product-brands">Product brands</a>
    <a class="btn" href="/inventory/suppliers">Suppliers</a>
    <a class="btn" href="/inventory/movements">Stock Movements</a>
    <a class="btn" href="/inventory/counts">Inventory Counts</a>
</div>
<p class="hint">
    Branch behavior is explicit: use branch filters to scope records.
    Global records are managed with branch set to "Global".
</p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
