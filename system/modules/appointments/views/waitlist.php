<?php
$title = 'Appointments Waitlist';
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$workspace['shell_modifier'] = 'workspace-shell--waitlist';
ob_start();
?>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>

<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<?php
$waitlistCreateUrl = '/appointments/waitlist/create' . ((!empty($branchId) || !empty($date)) ? '?' . http_build_query(array_filter(['branch_id' => $branchId, 'date' => $date])) : '');
$waitlistCalQ = array_filter(['branch_id' => $branchId ?? null, 'date' => $date ?? null], static fn ($v) => $v !== null && $v !== '');
$waitlistCalendarUrl = '/appointments/calendar/day' . ($waitlistCalQ !== [] ? '?' . http_build_query($waitlistCalQ) : '');
$waitlistListQ = !empty($branchId) ? '?' . http_build_query(['branch_id' => (int) $branchId]) : '';
?>

<div class="appointments-waitlist-page">
<div class="appt-waitlist-op-canvas appt-waitlist-page-canvas">
    <div class="appt-waitlist-page-head">
        <div class="appt-waitlist-page-head__text">
            <h2 class="appt-waitlist-page-title">Waitlist queue</h2>
            <p class="appt-waitlist-page-sub">Filter and work the queue without leaving the appointments workspace.</p>
        </div>
        <nav class="appt-waitlist-secondary-nav" aria-label="Related pages">
            <a href="<?= htmlspecialchars($waitlistCalendarUrl) ?>" class="appt-waitlist-nav-link">Day calendar</a>
            <span class="appt-waitlist-nav-sep" aria-hidden="true">·</span>
            <a href="<?= htmlspecialchars('/appointments' . $waitlistListQ, ENT_QUOTES, 'UTF-8') ?>" class="appt-waitlist-nav-link">Appointments list</a>
            <span class="appt-waitlist-nav-sep" aria-hidden="true">·</span>
            <a href="/appointments/create" class="appt-waitlist-nav-link">New appointment</a>
        </nav>
    </div>

    <p class="appt-waitlist-context appt-waitlist-lede"><strong>Waitlist desk</strong> — filter the queue, then work rows: update status, link an appointment, or <strong>convert</strong> straight to a booking. Add entries when the calendar can’t take them yet.</p>

    <div class="appt-waitlist-toolbar-shell">
    <div class="appt-waitlist-toolbar" role="region" aria-label="Waitlist filters and actions">
        <form method="get" action="/appointments/waitlist" class="appt-waitlist-toolbar__form" id="waitlist-filter-form">
            <div class="appt-waitlist-toolbar__filters">
                <div class="appt-waitlist-filter-cluster" aria-label="Scope">
                    <span class="appt-waitlist-filter-cluster__label">Scope</span>
                    <div class="appt-waitlist-filter-cluster__fields">
                        <div class="appt-waitlist-field">
                            <label class="appt-waitlist-field__label" for="waitlist-date">Date</label>
                            <input type="date" id="waitlist-date" name="date" class="appt-waitlist-field__control" value="<?= htmlspecialchars($date ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="appt-waitlist-field">
                            <label class="appt-waitlist-field__label" for="waitlist-branch">Branch</label>
                            <select id="waitlist-branch" name="branch_id" class="appt-waitlist-field__control">
                                <option value="">All branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= ((int) ($branchId ?? 0) === (int) $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="appt-waitlist-field">
                            <label class="appt-waitlist-field__label" for="waitlist-status">Status</label>
                            <select id="waitlist-status" name="status" class="appt-waitlist-field__control">
                                <option value="">All</option>
                                <?php foreach (['waiting', 'offered', 'matched', 'booked', 'cancelled'] as $st): ?>
                                <option value="<?= $st ?>" <?= (($_GET['status'] ?? '') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="appt-waitlist-filter-cluster" aria-label="Service and staff">
                    <span class="appt-waitlist-filter-cluster__label">Match</span>
                    <div class="appt-waitlist-filter-cluster__fields">
                        <div class="appt-waitlist-field">
                            <label class="appt-waitlist-field__label" for="waitlist-service">Service</label>
                            <select id="waitlist-service" name="service_id" class="appt-waitlist-field__control">
                                <option value="">All services</option>
                                <?php foreach (($services ?? []) as $s): ?>
                                <?php
                                $svcDesc = trim((string)($s['description'] ?? ''));
                                ?>
                                <option value="<?= (int) $s['id'] ?>" <?= ((int) ($_GET['service_id'] ?? 0) === (int) $s['id']) ? 'selected' : '' ?><?php if ($svcDesc !== '') {
                                    echo ' title="' . htmlspecialchars($svcDesc, ENT_QUOTES, 'UTF-8') . '"';
                                } ?>><?= htmlspecialchars((string) $s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span id="waitlist-service-description-hint" class="hint" hidden></span>
                        </div>
                        <div class="appt-waitlist-field">
                            <label class="appt-waitlist-field__label" for="waitlist-staff">Preferred Staff</label>
                            <select id="waitlist-staff" name="preferred_staff_id" class="appt-waitlist-field__control">
                                <option value="">Any staff</option>
                                <?php foreach (($staff ?? []) as $stf): ?>
                                <option value="<?= (int) $stf['id'] ?>" <?= ((int) ($_GET['preferred_staff_id'] ?? 0) === (int) $stf['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(trim((string) ($stf['first_name'] ?? '') . ' ' . (string) ($stf['last_name'] ?? ''))) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="appt-waitlist-field appt-waitlist-field--action">
                            <span class="appt-waitlist-field__label appt-waitlist-field__label--spacer" aria-hidden="true">&nbsp;</span>
                            <button type="submit" class="appt-waitlist-btn appt-waitlist-btn--primary">Filter</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <div class="appt-waitlist-toolbar__actions">
            <div class="appt-waitlist-toolbar__actions-block">
                <span class="appt-waitlist-toolbar__actions-label">Queue</span>
                <a href="<?= htmlspecialchars($waitlistCreateUrl) ?>" class="appt-waitlist-btn appt-waitlist-btn--solid appt-waitlist-btn--cta">Add Waitlist Entry</a>
                <span class="appt-waitlist-toolbar__actions-hint">Captures client + preferences; convert when a slot opens.</span>
            </div>
        </div>
    </div>
    </div>

    <div class="appt-waitlist-results" role="region" aria-label="Waitlist results">
<?php if (!empty($suggestedEntries)): ?>
    <div class="appt-waitlist-suggestions" role="status">
        <span class="appt-waitlist-suggestions__label">Suggested for conversion</span>
        <span class="appt-waitlist-suggestions__meta">Top matches for current filters: <?= count($suggestedEntries) ?></span>
    </div>
<?php endif; ?>

    <div class="appt-waitlist-table-wrap">
    <table class="index-table appt-waitlist-table">
    <thead>
    <tr>
        <th scope="col">ID</th>
        <th scope="col">Client</th>
        <th scope="col">Service</th>
        <th scope="col">Preferred</th>
        <th scope="col">Staff</th>
        <th scope="col">Status</th>
        <th scope="col">Matched Appointment</th>
        <th scope="col">Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($entries)): ?>
    <tr class="appt-waitlist-empty-row">
        <td colspan="8">
            <div class="appt-waitlist-empty" role="status">
                <p class="appt-waitlist-empty__eyebrow">Nothing in this view</p>
                <p class="appt-waitlist-empty__title">No waitlist entries match</p>
                <p class="appt-waitlist-empty__text">Either the queue is clear for these filters, or the scope is too tight. Loosen branch, status, service, or staff — or capture a new request below.</p>
                <div class="appt-waitlist-empty__actions">
                    <a href="<?= htmlspecialchars($waitlistCreateUrl) ?>" class="appt-waitlist-btn appt-waitlist-btn--solid appt-waitlist-empty__cta-primary">Add Waitlist Entry</a>
                    <div class="appt-waitlist-empty__secondary">
                        <a href="/appointments/create" class="appt-waitlist-btn appt-waitlist-btn--ghost">New appointment</a>
                        <a href="#waitlist-filter-form" class="appt-waitlist-btn appt-waitlist-btn--ghost">Adjust filters</a>
                    </div>
                </div>
                <p class="appt-waitlist-empty__tip" role="note">Operational tip: set <strong>Status</strong> to <strong>All</strong> or pick <strong>All branches</strong> to scan the full queue.</p>
            </div>
        </td>
    </tr>
    <?php else: ?>
        <?php foreach ($entries as $e): ?>
        <tr>
            <td>#<?= (int) $e['id'] ?></td>
            <td><?= htmlspecialchars(trim((string) ($e['client_first_name'] ?? '') . ' ' . (string) ($e['client_last_name'] ?? ''))) ?: '—' ?></td>
            <td><?= htmlspecialchars((string) ($e['service_name'] ?? '—')) ?></td>
            <td>
                <?= htmlspecialchars((string) ($e['preferred_date'] ?? '')) ?>
                <?php if (!empty($e['preferred_time_from']) || !empty($e['preferred_time_to'])): ?>
                <br><span class="appt-waitlist-time-range"><?= htmlspecialchars((string) ($e['preferred_time_from'] ?? '')) ?> – <?= htmlspecialchars((string) ($e['preferred_time_to'] ?? '')) ?></span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(trim((string) ($e['staff_first_name'] ?? '') . ' ' . (string) ($e['staff_last_name'] ?? ''))) ?: '—' ?></td>
            <td>
                <span class="appt-waitlist-status"><?= htmlspecialchars((string) ($e['status'] ?? 'waiting')) ?></span>
                <?php if (($e['status'] ?? '') === 'offered' && !empty($e['offer_expires_at'])): ?>
                <br><span class="appt-waitlist-offer-expiry">Offer expires <?= htmlspecialchars((string) $e['offer_expires_at']) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($e['matched_appointment_id'])): ?>
                <a href="/appointments/<?= (int) $e['matched_appointment_id'] ?>" class="appt-waitlist-appt-link">#<?= (int) $e['matched_appointment_id'] ?></a>
                <?php else: ?>
                —
                <?php endif; ?>
            </td>
            <td class="appt-waitlist-actions-cell">
                <form method="post" action="/appointments/waitlist/<?= (int) $e['id'] ?>/status" class="appt-waitlist-inline-form">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <select name="status" class="appt-waitlist-inline-form__select" aria-label="Set status">
                                <?php foreach (['waiting', 'offered', 'matched', 'booked', 'cancelled'] as $st): ?>
                                <option value="<?= $st ?>" <?= (($e['status'] ?? 'waiting') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="appt-waitlist-mini-btn">Set</button>
                </form>
                <form method="post" action="/appointments/waitlist/<?= (int) $e['id'] ?>/link-appointment" class="appt-waitlist-inline-form">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="number" min="1" name="appointment_id" class="appt-waitlist-inline-form__input appt-waitlist-inline-form__input--narrow" placeholder="Appointment ID" aria-label="Appointment ID to link">
                    <button type="submit" class="appt-waitlist-mini-btn">Link</button>
                </form>
                <?php if (($e['status'] ?? 'waiting') !== 'booked' && ($e['status'] ?? 'waiting') !== 'cancelled'): ?>
                <form method="post" action="/appointments/waitlist/<?= (int) $e['id'] ?>/convert" class="appt-waitlist-inline-form appt-waitlist-inline-form--convert">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="branch_id" value="<?= htmlspecialchars((string) ($e['branch_id'] ?? '')) ?>">
                    <input type="hidden" name="client_id" value="<?= htmlspecialchars((string) ($e['client_id'] ?? '')) ?>">
                    <input type="hidden" name="service_id" value="<?= htmlspecialchars((string) ($e['service_id'] ?? '')) ?>">
                    <input type="hidden" name="staff_id" value="<?= htmlspecialchars((string) ($e['preferred_staff_id'] ?? '')) ?>">
                    <input type="datetime-local" name="start_time" class="appt-waitlist-inline-form__input appt-waitlist-inline-form__input--datetime" value="<?= !empty($e['preferred_date']) && !empty($e['preferred_time_from']) ? htmlspecialchars((string) $e['preferred_date'] . 'T' . substr((string) $e['preferred_time_from'], 0, 5)) : '' ?>" required aria-label="Convert start date and time">
                    <button type="submit" class="appt-waitlist-mini-btn appt-waitlist-mini-btn--primary">Convert</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    </table>
    </div>

<?php if (($total ?? 0) > count($entries ?? [])): ?>
    <footer class="appt-waitlist-page-footer">
        <p class="appt-waitlist-pagination">Page <?= (int) ($page ?? 1) ?> · <?= (int) ($total ?? 0) ?> total</p>
    </footer>
<?php endif; ?>
    </div>
</div>
</div>
<script>
(() => {
  const serviceEl = document.getElementById('waitlist-service');
  const serviceHintEl = document.getElementById('waitlist-service-description-hint');
  if (!serviceEl || !serviceHintEl) return;

  const updateServiceDescriptionHint = () => {
    const opt = serviceEl.options[serviceEl.selectedIndex];
    const hint = opt && typeof opt.title === 'string' ? opt.title.trim() : '';
    if (hint) {
      serviceHintEl.textContent = hint;
      serviceHintEl.hidden = false;
      return;
    }
    serviceHintEl.textContent = '';
    serviceHintEl.hidden = true;
  };

  serviceEl.addEventListener('change', updateServiceDescriptionHint);
  updateServiceDescriptionHint();
})();
</script>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
