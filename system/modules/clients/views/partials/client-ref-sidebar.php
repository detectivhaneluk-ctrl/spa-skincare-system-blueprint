<?php
/** @var int $clientId */
/** @var array<string, mixed> $salesSummary */
/** @var string $accountStatus */
/** @var int $mergedIntoId */
/** @var bool $clientRefDedicatedAppointments */
/** @var bool $clientRefDedicatedDetails */
/** @var bool $clientRefSidebarContactCard */
$sidebarAppts = !empty($clientRefDedicatedAppointments);
$sidebarDetails = !empty($clientRefDedicatedDetails);
$sidebarQuickfacts = $sidebarAppts || $sidebarDetails || !empty($clientRefSidebarContactCard);
?>
        <aside class="client-ref-sidebar<?= $sidebarAppts ? ' client-ref-sidebar--appointments' : '' ?><?= $sidebarDetails ? ' client-ref-sidebar--details' : '' ?><?= !empty($clientRefSidebarContactCard) ? ' client-ref-sidebar--client-tab' : '' ?>" aria-label="Client summary">
            <form method="get" action="/clients" class="client-ref-sidebar-search">
                <label class="client-ref-sidebar-label" for="client-ref-search">Search clients</label>
                <input type="text" id="client-ref-search" name="search" value="" placeholder="Name, email, phone" autocomplete="off">
                <button type="submit">Search</button>
            </form>
            <p class="client-ref-back"><a href="/clients">← Back to list</a></p>
            <?php
            $clientRefPhotoUrl = isset($clientRefPrimaryPhotoUrl) && is_string($clientRefPrimaryPhotoUrl) && $clientRefPrimaryPhotoUrl !== ''
                ? $clientRefPrimaryPhotoUrl
                : null;
            ?>
            <div class="client-ref-avatar<?= $clientRefPhotoUrl !== null ? ' client-ref-avatar--photo' : '' ?>" aria-hidden="true">
                <?php if ($clientRefPhotoUrl !== null): ?>
                <img src="<?= htmlspecialchars($clientRefPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" width="96" height="96" loading="lazy" decoding="async">
                <?php else: ?>
                <span>Photo</span>
                <?php endif; ?>
            </div>

            <?php if ($sidebarQuickfacts && isset($client) && is_array($client)): ?>
            <div class="client-ref-sidebar-quickfacts">
                <h2 class="client-ref-sidebar-heading client-ref-sidebar-heading--inline">Contact</h2>
                <dl class="client-ref-sidebar-dl client-ref-sidebar-dl--compact">
                    <dt>Phone</dt>
                    <dd><?= htmlspecialchars((string) ($client['phone'] ?? '—')) ?></dd>
                    <dt>Email</dt>
                    <dd><?php $em = trim((string) ($client['email'] ?? '')); ?><?= $em !== '' ? htmlspecialchars($em) : '—' ?></dd>
                </dl>
            </div>
            <?php endif; ?>

            <?php if (!empty($sidebarLayoutKeys) && isset($fieldCatalog, $client)): ?>
            <?php
            $customFieldValues = $customFieldValues ?? ($customFieldValuesRows ?? []);
            $customFieldDefinitions = $customFieldDefinitions ?? [];
            require base_path('modules/clients/views/partials/client-sidebar-layout-fields.php');
            ?>
            <?php endif; ?>

            <h2 class="client-ref-sidebar-heading">Sales (summary)</h2>
            <dl class="client-ref-sidebar-dl">
                <dt>Invoices</dt>
                <dd><?= (int) ($salesSummary['invoice_count'] ?? 0) ?></dd>
                <dt>Billed</dt>
                <dd><?= ($salesSummary['total_billed'] ?? null) === null ? '—' : number_format((float) $salesSummary['total_billed'], 2) ?></dd>
                <dt>Paid</dt>
                <dd><?= ($salesSummary['total_paid'] ?? null) === null ? '—' : number_format((float) $salesSummary['total_paid'], 2) ?></dd>
                <dt>Balance due</dt>
                <dd><?= ($salesSummary['total_due'] ?? null) === null ? '—' : number_format((float) $salesSummary['total_due'], 2) ?></dd>
                <dt>Payments</dt>
                <dd><?= (int) ($salesSummary['payment_count'] ?? 0) ?></dd>
            </dl>

            <h2 class="client-ref-sidebar-heading">Account</h2>
            <dl class="client-ref-sidebar-dl">
                <dt>Status</dt>
                <dd><?= htmlspecialchars($accountStatus) ?><?php if ($mergedIntoId > 0): ?> · <a href="/clients/<?= $mergedIntoId ?>">Open</a><?php endif; ?></dd>
            </dl>
        </aside>
