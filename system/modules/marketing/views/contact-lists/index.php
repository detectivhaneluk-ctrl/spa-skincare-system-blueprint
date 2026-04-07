<?php

declare(strict_types=1);

$title = $title ?? 'Contact Lists';
$mainClass = 'marketing-contact-lists-page';
$marketingTopActive = 'contact_lists';
$marketingRailActive = 'lists';
$read = is_array($read ?? null) ? $read : [];
$contacts = is_array($read['contacts'] ?? null) ? $read['contacts'] : [];
$total = (int) ($read['total'] ?? 0); // in-scope result count (includes search)
$search = $search ?? '';
$smartDefs = is_array($smartDefs ?? null) ? $smartDefs : [];
$smartCounts = is_array($smartCounts ?? null) ? $smartCounts : [];
$manualLists = is_array($manualLists ?? null) ? $manualLists : [];
$manualListStorageReady = isset($manualListStorageReady) ? (bool) $manualListStorageReady : true;
$selectedAudienceState = (string) ($selectedAudienceState ?? \Modules\Marketing\Services\MarketingContactAudienceService::AUDIENCE_ALL_CONTACTS);
$selectedAudienceLabel = (string) ($selectedAudienceLabel ?? 'All Contacts');
$selectedAudienceTotal = (int) ($selectedAudienceTotal ?? $total);
$activeManualId = 0;
if (str_starts_with($selectedAudienceState, 'manual:')) {
    $activeManualId = (int) substr($selectedAudienceState, 7);
}
$hasManualLists = $manualListStorageReady && $manualLists !== [];
ob_start();
?>
<div class="marketing-module">
    <?php require base_path('modules/marketing/views/partials/marketing-top-nav.php'); ?>
    <div class="marketing-module__body">
        <?php require base_path('modules/marketing/views/partials/marketing-email-rail.php'); ?>
        <div class="marketing-module__workspace">
            <?php if (!empty($flash) && is_array($flash)): $type = (string) array_key_first($flash); ?>
            <div class="flash flash-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars((string) ($flash[$type] ?? '')) ?></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:290px 1fr;gap:14px;">
                <aside class="entity-form" style="max-height:74vh;overflow:auto;">
                    <div style="display:flex;gap:6px;align-items:center;margin-bottom:8px;">
                        <details style="width:100%;">
                            <summary class="marketing-btn marketing-btn--secondary" style="display:inline-block;cursor:pointer;">+ New Manual List</summary>
                            <form method="post" action="/marketing/contact-lists/manual-lists/create" style="display:flex;gap:6px;align-items:center;margin-top:6px;">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
                                <input type="hidden" name="return_selected" value="<?= htmlspecialchars($selectedAudienceState) ?>">
                                <input type="hidden" name="return_q" value="<?= htmlspecialchars($search) ?>">
                                <input type="text" name="name" required maxlength="160" placeholder="List name" <?= !$manualListStorageReady ? 'disabled' : '' ?>>
                                <button type="submit" class="marketing-btn marketing-btn--primary" <?= !$manualListStorageReady ? 'disabled' : '' ?>>Create</button>
                            </form>
                        </details>
                    </div>

                    <div style="font-weight:600;margin-bottom:6px;">All Contacts</div>
                    <nav style="display:flex;flex-direction:column;gap:5px;margin-bottom:10px;">
                        <?php
                        $allKey = \Modules\Marketing\Services\MarketingContactAudienceService::AUDIENCE_ALL_CONTACTS;
                        $allCount = (int) ($smartCounts[$allKey] ?? 0);
                        ?>
                        <a href="/marketing/contact-lists?selected=<?= htmlspecialchars($allKey) ?>"
                           class="<?= $selectedAudienceState === $allKey ? 'marketing-btn marketing-btn--secondary' : '' ?>"
                           style="<?= $selectedAudienceState !== $allKey ? 'padding:6px 0;' : '' ?>">
                            All Contacts (<?= $allCount ?>)
                        </a>
                    </nav>

                    <div style="font-weight:600;margin-bottom:6px;">Smart Lists</div>
                    <nav style="display:flex;flex-direction:column;gap:5px;margin-bottom:10px;">
                        <?php foreach ($smartDefs as $def): ?>
                        <?php $key = (string) ($def['key'] ?? ''); ?>
                        <?php if ($key === \Modules\Marketing\Services\MarketingContactAudienceService::AUDIENCE_ALL_CONTACTS) { continue; } ?>
                        <a href="/marketing/contact-lists?selected=<?= htmlspecialchars(rawurlencode($key)) ?>"
                           class="<?= $selectedAudienceState === $key ? 'marketing-btn marketing-btn--secondary' : '' ?>"
                           style="<?= $selectedAudienceState !== $key ? 'padding:6px 0;' : '' ?>">
                            <?= htmlspecialchars((string) ($def['label'] ?? $key)) ?> (<?= (int) ($smartCounts[$key] ?? 0) ?>)
                        </a>
                        <?php endforeach; ?>
                    </nav>

                    <div style="font-weight:600;margin-bottom:6px;">Manual Lists</div>
                    <?php if (!$manualListStorageReady): ?>
                    <p class="hint" style="margin-top:0;">Manual list storage not initialized. Run migrations.</p>
                    <?php elseif ($manualLists === []): ?>
                    <p class="hint" style="margin-top:0;">No manual lists yet.</p>
                    <?php else: ?>
                    <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:6px;">
                        <?php foreach ($manualLists as $list): ?>
                        <?php $listId = (int) ($list['id'] ?? 0); ?>
                        <?php $selectedState = 'manual:' . $listId; ?>
                        <li>
                            <a href="/marketing/contact-lists?selected=<?= htmlspecialchars($selectedState) ?>"
                               class="<?= $selectedAudienceState === $selectedState ? 'marketing-btn marketing-btn--secondary' : '' ?>"
                               style="<?= $selectedAudienceState !== $selectedState ? 'padding:4px 0;display:block;' : 'display:block;' ?>">
                                <?= htmlspecialchars((string) ($list['name'] ?? 'Manual list')) ?> (<?= (int) ($list['member_count'] ?? 0) ?>)
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($activeManualId > 0): ?>
                    <?php
                    $activeManual = null;
                    foreach ($manualLists as $candidate) {
                        if ((int) ($candidate['id'] ?? 0) === $activeManualId) {
                            $activeManual = $candidate;
                            break;
                        }
                    }
                    ?>
                    <?php if (is_array($activeManual)): ?>
                    <details style="margin-top:8px;">
                        <summary class="marketing-btn marketing-btn--secondary" style="display:inline-block;cursor:pointer;">Manage Active Manual List</summary>
                        <div class="entity-form" style="margin-top:6px;">
                            <form method="post" action="/marketing/contact-lists/manual-lists/rename" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
                                <input type="hidden" name="list_id" value="<?= $activeManualId ?>">
                                <input type="hidden" name="return_selected" value="<?= htmlspecialchars($selectedAudienceState) ?>">
                                <input type="hidden" name="return_q" value="<?= htmlspecialchars($search) ?>">
                                <input type="text" name="name" value="<?= htmlspecialchars((string) ($activeManual['name'] ?? '')) ?>" maxlength="160" required>
                                <button type="submit" class="marketing-btn marketing-btn--secondary">Rename</button>
                            </form>
                            <form method="post" action="/marketing/contact-lists/manual-lists/archive" style="margin-top:6px;" onsubmit="return confirm('Archive this manual list?');">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
                                <input type="hidden" name="list_id" value="<?= $activeManualId ?>">
                                <input type="hidden" name="return_selected" value="<?= htmlspecialchars($selectedAudienceState) ?>">
                                <input type="hidden" name="return_q" value="<?= htmlspecialchars($search) ?>">
                                <button type="submit" class="marketing-btn marketing-btn--secondary">Archive</button>
                            </form>
                        </div>
                    </details>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </aside>

                <section class="entity-form">
                    <header style="margin-bottom:10px;">
                        <h2 style="margin:0;"><?= htmlspecialchars($selectedAudienceLabel) ?></h2>
                        <p class="hint" style="margin:4px 0 0;">
                            <?= $total ?> result<?= $total === 1 ? '' : 's' ?>
                            <?php if ($search !== ''): ?>
                                (from <?= $selectedAudienceTotal ?> in this audience)
                            <?php endif; ?>
                        </p>
                    </header>

                    <form class="marketing-toolbar" method="get" action="/marketing/contact-lists" role="search">
                        <input type="hidden" name="selected" value="<?= htmlspecialchars($selectedAudienceState) ?>">
                        <label class="marketing-toolbar__search">
                            <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                                   placeholder="Search first name, last name, email, mobile" maxlength="200" autocomplete="off">
                        </label>
                        <button type="submit" class="marketing-btn marketing-btn--secondary">Search</button>
                    </form>

                    <form method="post" action="/marketing/contact-lists/manual-lists/members/add" class="entity-form" style="margin:10px 0;">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
                        <input type="hidden" name="return_selected" value="<?= htmlspecialchars($selectedAudienceState) ?>">
                        <input type="hidden" name="return_q" value="<?= htmlspecialchars($search) ?>">
                        <div id="selected-actions" style="display:none;gap:8px;align-items:center;flex-wrap:wrap;">
                            <?php if ($hasManualLists): ?>
                            <label>
                                Add selected to list
                                <select name="list_id">
                                    <option value="">Choose manual list</option>
                                    <?php foreach ($manualLists as $list): ?>
                                    <option value="<?= (int) ($list['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($list['name'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit" class="marketing-btn marketing-btn--primary">Add Selected</button>
                            <?php endif; ?>

                            <?php if ($activeManualId > 0 && $hasManualLists): ?>
                            <button type="submit"
                                    formaction="/marketing/contact-lists/manual-lists/members/remove"
                                    name="list_id"
                                    value="<?= $activeManualId ?>"
                                    class="marketing-btn marketing-btn--secondary">
                                Remove Selected from This List
                            </button>
                            <?php endif; ?>
                        </div>

                        <div class="marketing-table-wrap" style="margin-top:10px;">
                            <table class="index-table marketing-campaigns-table">
                                <thead>
                                <tr>
                                    <th><input type="checkbox" onclick="document.querySelectorAll('.js-contact-select').forEach(cb => cb.checked = this.checked)"></th>
                                    <th>Last Name</th>
                                    <th>First Name</th>
                                    <th>Email</th>
                                    <th>Mobile Phone Number</th>
                                    <th>Marketing Communications</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($contacts === []): ?>
                                <tr><td colspan="6"><span class="hint">No contacts found in <?= htmlspecialchars($selectedAudienceLabel) ?><?= $search !== '' ? ' for this search' : '' ?>.</span></td></tr>
                                <?php else: ?>
                                <?php foreach ($contacts as $contact): ?>
                                <?php
                                $clientId = (int) ($contact['client_id'] ?? 0);
                                $emailEligible = !empty($contact['email_marketing_eligible']);
                                $smsEligible = !empty($contact['sms_marketing_eligible']);
                                ?>
                                <tr>
                                    <td><input class="js-contact-select" type="checkbox" name="contact_ids[]" value="<?= $clientId ?>"></td>
                                    <td><?= htmlspecialchars((string) ($contact['last_name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($contact['first_name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($contact['email'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($contact['mobile_phone'] ?? '')) ?></td>
                                    <td>
                                        Email: <?= $emailEligible ? 'Eligible' : 'Not Eligible' ?>
                                        |
                                        Text: <?= $smsEligible ? 'Eligible' : 'Not Eligible' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    const rowChecks = Array.from(document.querySelectorAll('.js-contact-select'));
    const actions = document.getElementById('selected-actions');
    if (!actions) return;
    const refresh = () => {
        const selected = rowChecks.some((cb) => cb.checked);
        actions.style.display = selected ? 'flex' : 'none';
    };
    rowChecks.forEach((cb) => cb.addEventListener('change', refresh));
    refresh();
})();
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>

