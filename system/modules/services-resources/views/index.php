<?php
$title = 'Catalog';
ob_start();
?>
<div class="catalog-hub">
    <header class="catalog-hub__header">
        <h1 class="catalog-hub__title">Catalog</h1>
        <p class="catalog-hub__lead">Everything that can be booked or sold — services, packages, memberships, and gift cards. Spaces and equipment are listed here because they are assigned to services.</p>
    </header>

    <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
    <div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
    <?php endif; ?>

    <div class="catalog-hub__grid">

        <div class="catalog-hub-card">
            <h2 class="catalog-hub-card__title">Services</h2>
            <p class="catalog-hub-card__desc">The treatments and services your business offers. Organised by category. Each service sets duration, price, and which staff can deliver it.</p>
            <div class="catalog-hub-card__links">
                <a class="catalog-hub-card__link" href="/services-resources/services">View services</a>
                <a class="catalog-hub-card__link catalog-hub-card__link--secondary" href="/services-resources/categories">Categories</a>
            </div>
        </div>

        <div class="catalog-hub-card">
            <h2 class="catalog-hub-card__title">Packages</h2>
            <p class="catalog-hub-card__desc">Pre-paid session bundles. Create package plans, then assign them to clients or sell through checkout.</p>
            <div class="catalog-hub-card__links">
                <a class="catalog-hub-card__link" href="/packages">View packages</a>
                <a class="catalog-hub-card__link catalog-hub-card__link--secondary" href="/packages/client-packages">Client packages</a>
            </div>
        </div>

        <div class="catalog-hub-card">
            <h2 class="catalog-hub-card__title">Memberships</h2>
            <p class="catalog-hub-card__desc">Recurring membership plans. Create plans here, then enrol clients. Default renewal and grace settings are in Admin.</p>
            <div class="catalog-hub-card__links">
                <a class="catalog-hub-card__link" href="/memberships">View membership plans</a>
                <a class="catalog-hub-card__link catalog-hub-card__link--secondary" href="/memberships/client-memberships">Client memberships</a>
            </div>
        </div>

        <div class="catalog-hub-card">
            <h2 class="catalog-hub-card__title">Gift Cards</h2>
            <p class="catalog-hub-card__desc">Stored-value gift cards. Issue, redeem, and adjust balances. Public gift card sales are controlled under Admin &rsaquo; Online Channels.</p>
            <div class="catalog-hub-card__links">
                <a class="catalog-hub-card__link" href="/gift-cards">View gift cards</a>
                <a class="catalog-hub-card__link catalog-hub-card__link--secondary" href="/gift-cards/issue">Issue gift card</a>
            </div>
        </div>

        <div class="catalog-hub-card">
            <h2 class="catalog-hub-card__title">Spaces</h2>
            <p class="catalog-hub-card__desc">Treatment rooms and bookable spaces. Assign spaces to services to control room availability on the calendar.</p>
            <div class="catalog-hub-card__links">
                <a class="catalog-hub-card__link" href="/services-resources/rooms">View spaces</a>
            </div>
        </div>

        <div class="catalog-hub-card">
            <h2 class="catalog-hub-card__title">Equipment</h2>
            <p class="catalog-hub-card__desc">Equipment resources used during services. Assign equipment to services to track resource usage.</p>
            <div class="catalog-hub-card__links">
                <a class="catalog-hub-card__link" href="/services-resources/equipment">View equipment</a>
            </div>
        </div>

    </div>
</div>
<style>
.catalog-hub { max-width: 72rem; }
.catalog-hub__header { margin-bottom: 1.5rem; }
.catalog-hub__title { margin: 0 0 0.3rem; font-size: 1.5rem; font-weight: 700; color: #111827; }
.catalog-hub__lead { margin: 0; font-size: 0.9rem; color: #4b5563; line-height: 1.5; max-width: 52rem; }
.catalog-hub__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); gap: 1rem; }
.catalog-hub-card { padding: 1.1rem 1.15rem; border: 1px solid #e5e7eb; border-radius: 0.75rem; background: #fff; }
.catalog-hub-card__title { margin: 0 0 0.3rem; font-size: 1rem; font-weight: 600; color: #111827; }
.catalog-hub-card__desc { margin: 0 0 0.85rem; font-size: 0.84rem; color: #4b5563; line-height: 1.45; }
.catalog-hub-card__links { display: flex; flex-wrap: wrap; gap: 0.4rem 0.75rem; }
.catalog-hub-card__link { font-size: 0.85rem; color: #2563eb; text-decoration: none; }
.catalog-hub-card__link:hover { text-decoration: underline; }
.catalog-hub-card__link--secondary { color: #4b5563; }
</style>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
