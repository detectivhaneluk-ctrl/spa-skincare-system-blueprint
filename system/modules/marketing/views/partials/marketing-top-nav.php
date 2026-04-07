<?php
/** @var string $marketingTopActive email_campaigns|automated|promotions|gift_cards|contact_lists|loyalty|surveys */
$marketingTopActive = $marketingTopActive ?? 'email_campaigns';
$top = [
    ['id' => 'email_campaigns', 'href' => '/marketing/campaigns', 'label' => 'Campaigns'],
    ['id' => 'automated', 'href' => '/marketing/automations', 'label' => 'Automations'],
    ['id' => 'promotions', 'href' => '/marketing/promotions/special-offers', 'label' => 'Promotions'],
    ['id' => 'gift_cards', 'href' => '/marketing/gift-card-templates', 'label' => 'Gift card templates'],
    ['id' => 'contact_lists', 'href' => '/marketing/contact-lists', 'label' => 'Contact lists'],
    ['id' => 'loyalty', 'href' => null, 'label' => 'Loyalty'],
    ['id' => 'surveys', 'href' => null, 'label' => 'Surveys'],
];
?>
<nav class="marketing-top-nav" aria-label="Marketing sections">
    <div class="marketing-top-nav__list" role="list">
        <?php foreach ($top as $item): ?>
        <div class="marketing-top-nav__item" role="listitem">
            <?php if ($item['href'] !== null): ?>
            <a class="marketing-top-nav__link<?= ($marketingTopActive === $item['id']) ? ' is-active' : '' ?>"
               href="<?= htmlspecialchars($item['href']) ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php else: ?>
            <span class="marketing-top-nav__muted<?= ($marketingTopActive === $item['id']) ? ' is-active' : '' ?>" title="Coming soon"><?= htmlspecialchars($item['label']) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</nav>
