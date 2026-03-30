<?php
/** @var string $marketingRailActive automations|campaigns|lists|social|more */
$marketingRailActive = $marketingRailActive ?? 'campaigns';
?>
<aside class="marketing-rail" aria-label="Email campaigns">
    <p class="marketing-rail__heading">Email campaigns</p>
    <nav class="marketing-rail__list" aria-label="Email campaigns lane">
        <a class="marketing-rail__link<?= $marketingRailActive === 'automations' ? ' is-active' : '' ?>"
           href="/marketing/automations">Automations</a>
        <a class="marketing-rail__link<?= $marketingRailActive === 'campaigns' ? ' is-active' : '' ?>"
           href="/marketing/campaigns">Campaigns</a>
        <a class="marketing-rail__link<?= $marketingRailActive === 'lists' ? ' is-active' : '' ?>"
           href="/marketing/contact-lists">Contact lists</a>
        <span class="marketing-rail__muted" title="Not available in this release">Social channels</span>
        <span class="marketing-rail__muted marketing-rail__muted--more" title="Not available in this release">More tools</span>
    </nav>
</aside>
