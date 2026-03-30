<?php

declare(strict_types=1);

/**
 * Shared campaign create/edit body (POST target and mode differ).
 *
 * @var string $campaignFormMode 'create'|'edit'
 * @var string $formAction
 * @var array<string, mixed> $campaign
 * @var array<string, string> $errors
 * @var string $csrf
 * @var list<string> $segmentKeys
 * @var list<array<string, mixed>> $branches
 */

use Modules\Marketing\Services\MarketingSegmentEvaluator;

$campaignFormMode = $campaignFormMode ?? 'create';
$formAction = $formAction ?? '/marketing/campaigns';
$campaign = $campaign ?? [];
$errors = $errors ?? [];
$segmentKeys = $segmentKeys ?? [];
$branches = $branches ?? [];
$csrfName = (string) config('app.csrf_token_name', 'csrf_token');

$fieldErrors = array_filter(
    $errors,
    static fn ($k) => $k !== '_general',
    ARRAY_FILTER_USE_KEY
);
?>
<form class="marketing-form" method="post" action="<?= htmlspecialchars($formAction) ?>">
    <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">

    <?php if ($campaignFormMode === 'edit'): ?>
    <?php
    $bid = $campaign['branch_id'] ?? null;
    $branchHidden = $bid !== null && $bid !== '' ? (string) (int) $bid : '';
    ?>
    <input type="hidden" name="branch_id" value="<?= htmlspecialchars($branchHidden) ?>">
    <?php endif; ?>

    <?php if ($campaignFormMode === 'create'): ?>
    <input type="hidden" name="status" value="draft">
    <?php endif; ?>

    <?php if (!empty($errors['_general'])): ?>
    <div class="marketing-form__alert marketing-form__alert--error" role="alert">
        <?= htmlspecialchars((string) $errors['_general']) ?>
    </div>
    <?php endif; ?>

    <?php if ($fieldErrors !== []): ?>
    <div class="marketing-form__summary" role="alert" aria-labelledby="campaign-form-errors-title">
        <p id="campaign-form-errors-title" class="marketing-form__summary-title">Fix the following before saving:</p>
        <ul class="marketing-form__summary-list">
            <?php foreach ($fieldErrors as $ek => $msg): ?>
            <li><a href="#field-<?= htmlspecialchars((string) $ek) ?>"><?= htmlspecialchars((string) $msg) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <section class="marketing-form__section" aria-labelledby="campaign-section-basics">
        <h2 id="campaign-section-basics" class="marketing-form__section-title">Campaign</h2>

        <div class="marketing-form__field<?= !empty($errors['name']) ? ' marketing-form__field--error' : '' ?>" id="field-name">
            <label class="marketing-form__label" for="campaign_name">Campaign name</label>
            <input class="marketing-form__input" type="text" id="campaign_name" name="name" required maxlength="200"
                   value="<?= htmlspecialchars((string) ($campaign['name'] ?? '')) ?>"
                   autocomplete="off">
            <?php if (!empty($errors['name'])): ?>
            <p class="marketing-form__error" id="campaign_name-error"><?= htmlspecialchars($errors['name']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($campaignFormMode === 'create'): ?>
        <div class="marketing-form__field">
            <label class="marketing-form__label" for="campaign_branch">Branch</label>
            <select class="marketing-form__select" id="campaign_branch" name="branch_id">
                <option value="">All branches</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?= (int) $b['id'] ?>" <?= (isset($campaign['branch_id']) && (int) $campaign['branch_id'] === (int) $b['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($b['name'] ?? '')) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p class="marketing-form__hint">Audience and sends respect branch scope and organization rules.</p>
        </div>
        <?php endif; ?>

        <div class="marketing-form__field">
            <span class="marketing-form__label">Channel</span>
            <p class="marketing-form__readonly">Email <span class="marketing-form__hint-inline">(only channel available)</span></p>
        </div>

        <?php if ($campaignFormMode === 'edit'): ?>
        <div class="marketing-form__field<?= !empty($errors['status']) ? ' marketing-form__field--error' : '' ?>" id="field-status">
            <label class="marketing-form__label" for="campaign_status">Status</label>
            <select class="marketing-form__select" id="campaign_status" name="status">
                <option value="draft" <?= (($campaign['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
                <option value="archived" <?= (($campaign['status'] ?? '') === 'archived') ? 'selected' : '' ?>>Archived</option>
            </select>
            <?php if (!empty($errors['status'])): ?>
            <p class="marketing-form__error"><?= htmlspecialchars($errors['status']) ?></p>
            <?php endif; ?>
            <p class="marketing-form__hint">Archived campaigns cannot be edited or run.</p>
        </div>
        <?php else: ?>
        <div class="marketing-form__field">
            <span class="marketing-form__label">Status</span>
            <p class="marketing-form__readonly">Draft <span class="marketing-form__hint-inline">(new campaigns start as drafts)</span></p>
        </div>
        <?php endif; ?>
    </section>

    <section class="marketing-form__section" aria-labelledby="campaign-section-audience">
        <h2 id="campaign-section-audience" class="marketing-form__section-title">Audience</h2>
        <p class="marketing-form__lede">Choose a segment. The list is defined by rules, not a manual contact list.</p>

        <div class="marketing-form__field<?= !empty($errors['segment_key']) ? ' marketing-form__field--error' : '' ?>" id="field-segment_key">
            <label class="marketing-form__label" for="segment_key">Segment</label>
            <select class="marketing-form__select" id="segment_key" name="segment_key" required>
                <?php foreach ($segmentKeys as $sk): ?>
                <option
                    value="<?= htmlspecialchars($sk) ?>"
                    data-desc="<?= htmlspecialchars(MarketingSegmentEvaluator::segmentDescriptionForUi($sk)) ?>"
                    <?= (($campaign['segment_key'] ?? '') === $sk) ? 'selected' : '' ?>
                ><?= htmlspecialchars(MarketingSegmentEvaluator::segmentLabelForUi($sk)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['segment_key'])): ?>
            <p class="marketing-form__error"><?= htmlspecialchars($errors['segment_key']) ?></p>
            <?php endif; ?>
            <p class="marketing-form__hint" id="segment_description" aria-live="polite"></p>
        </div>

        <div class="marketing-form__seg" data-segment-match="<?= htmlspecialchars(MarketingSegmentEvaluator::SEGMENT_DORMANT_NO_RECENT_COMPLETED) ?>" hidden>
            <div class="marketing-form__field">
                <label class="marketing-form__label" for="dormant_days">Dormant window (days)</label>
                <input class="marketing-form__input marketing-form__input--narrow" type="number" id="dormant_days" name="dormant_days" min="1" max="3650" inputmode="numeric"
                       value="<?= (int) ($campaign['dormant_days'] ?? 90) ?>">
                <p class="marketing-form__hint">No completed appointment in this many days.</p>
            </div>
        </div>

        <div class="marketing-form__seg" data-segment-match="<?= htmlspecialchars(MarketingSegmentEvaluator::SEGMENT_BIRTHDAY_UPCOMING) ?>" hidden>
            <div class="marketing-form__field">
                <label class="marketing-form__label" for="lookahead_days">Birthday lookahead (days)</label>
                <input class="marketing-form__input marketing-form__input--narrow" type="number" id="lookahead_days" name="lookahead_days" min="1" max="366" inputmode="numeric"
                       value="<?= (int) ($campaign['lookahead_days'] ?? 14) ?>">
                <p class="marketing-form__hint">Include clients whose birthday is within this window.</p>
            </div>
        </div>

        <div class="marketing-form__seg" data-segment-match="<?= htmlspecialchars(MarketingSegmentEvaluator::SEGMENT_WAITLIST_ENGAGED_RECENT) ?>" hidden>
            <div class="marketing-form__field">
                <label class="marketing-form__label" for="recent_days">Recent activity window (days)</label>
                <input class="marketing-form__input marketing-form__input--narrow" type="number" id="recent_days" name="recent_days" min="1" max="3650" inputmode="numeric"
                       value="<?= (int) ($campaign['recent_days'] ?? 30) ?>">
                <p class="marketing-form__hint">Waitlist engagement must fall within this window.</p>
            </div>
        </div>
    </section>

    <section class="marketing-form__section" aria-labelledby="campaign-section-message">
        <h2 id="campaign-section-message" class="marketing-form__section-title">Email content</h2>

        <div class="marketing-form__field<?= !empty($errors['subject']) ? ' marketing-form__field--error' : '' ?>" id="field-subject">
            <label class="marketing-form__label" for="campaign_subject">Subject</label>
            <input class="marketing-form__input" type="text" id="campaign_subject" name="subject" required maxlength="500"
                   value="<?= htmlspecialchars((string) ($campaign['subject'] ?? '')) ?>">
            <p class="marketing-form__hint">Placeholders: <code>{{first_name}}</code>, <code>{{last_name}}</code></p>
            <?php if (!empty($errors['subject'])): ?>
            <p class="marketing-form__error"><?= htmlspecialchars($errors['subject']) ?></p>
            <?php endif; ?>
        </div>

        <div class="marketing-form__field<?= !empty($errors['body_text']) ? ' marketing-form__field--error' : '' ?>" id="field-body_text">
            <label class="marketing-form__label" for="campaign_body">Body <span class="marketing-form__hint-inline">(plain text)</span></label>
            <textarea class="marketing-form__textarea" id="campaign_body" name="body_text" rows="12" required><?= htmlspecialchars((string) ($campaign['body_text'] ?? '')) ?></textarea>
            <?php if (!empty($errors['body_text'])): ?>
            <p class="marketing-form__error"><?= htmlspecialchars($errors['body_text']) ?></p>
            <?php endif; ?>
        </div>
    </section>

    <div class="marketing-form__actions">
        <button type="submit" class="marketing-btn marketing-btn--primary"><?= $campaignFormMode === 'create' ? 'Create campaign' : 'Save changes' ?></button>
        <?php if ($campaignFormMode === 'create'): ?>
        <a class="marketing-btn marketing-btn--secondary" href="/marketing/campaigns">Back to campaigns</a>
        <?php else: ?>
        <a class="marketing-btn marketing-btn--secondary" href="/marketing/campaigns/<?= (int) ($campaign['id'] ?? 0) ?>">Cancel</a>
        <?php endif; ?>
    </div>
</form>

<script>
(function () {
  var sel = document.getElementById('segment_key');
  var descEl = document.getElementById('segment_description');
  if (!sel || !descEl) return;

  function syncSegment() {
    var v = sel.value;
    var opt = sel.options[sel.selectedIndex];
    var d = opt ? opt.getAttribute('data-desc') : '';
    descEl.textContent = d || '';

    document.querySelectorAll('.marketing-form__seg').forEach(function (el) {
      var m = el.getAttribute('data-segment-match');
      el.hidden = (m !== v);
    });
  }

  sel.addEventListener('change', syncSegment);
  syncSegment();
})();
</script>
