<?php
/** @var string $marketingTopActive suite|listing|social|email_campaigns|automated|promotions|loyalty|gift_cards|surveys|branding|resources */
$marketingTopActive = $marketingTopActive ?? 'suite';
$top = [
    ['id' => 'suite', 'href' => '/marketing/campaigns', 'label' => 'Marketing suite'],
    ['id' => 'listing', 'href' => null, 'label' => 'Directory listing'],
    ['id' => 'social', 'href' => null, 'label' => 'Facebook / Twitter'],
    ['id' => 'email_campaigns', 'href' => '/marketing/campaigns', 'label' => 'Email campaigns'],
    ['id' => 'automated', 'href' => '/marketing/automations', 'label' => 'Automated emails'],
    ['id' => 'promotions', 'href' => '/marketing/promotions/special-offers', 'label' => 'Promotions'],
    ['id' => 'loyalty', 'href' => null, 'label' => 'Loyalty program'],
    ['id' => 'gift_cards', 'href' => '/marketing/gift-card-templates', 'label' => 'Gift card templates'],
    ['id' => 'surveys', 'href' => null, 'label' => 'Surveys'],
    ['id' => 'branding', 'href' => null, 'label' => 'Logos & colors'],
    ['id' => 'resources', 'href' => null, 'label' => 'Resources'],
];
?>
<nav class="marketing-top-nav" aria-label="Marketing suite sections">
    <div class="marketing-top-nav__list" role="list">
        <?php foreach ($top as $item): ?>
        <div class="marketing-top-nav__item" role="listitem">
            <?php if ($item['href'] !== null): ?>
            <a class="marketing-top-nav__link<?= ($marketingTopActive === $item['id']) ? ' is-active' : '' ?>"
               href="<?= htmlspecialchars($item['href']) ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php else: ?>
            <span class="marketing-top-nav__muted<?= ($marketingTopActive === $item['id']) ? ' is-active' : '' ?>" title="Not available in this release"><?= htmlspecialchars($item['label']) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</nav>
