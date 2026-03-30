<?php
$title = 'Appointment Day Calendar';
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$workspace['shell_modifier'] = 'workspace-shell--calendar';
$calDateRaw = $date ?? date('Y-m-d');
$calDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $calDateRaw) ? (string) $calDateRaw : date('Y-m-d');
$calDt = new DateTimeImmutable($calDate);
$prevDate = $calDt->modify('-1 day')->format('Y-m-d');
$nextDate = $calDt->modify('+1 day')->format('Y-m-d');
$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$calDayUrl = static function (string $d) use ($branchId): string {
    $q = ['date' => $d];
    if ($branchId !== null) {
        $q['branch_id'] = (int) $branchId;
    }
    return '/appointments/calendar/day?' . http_build_query($q);
};
$sidebarMonth = $calDt->format('F Y');
$sidebarWeekday = $calDt->format('l');
$sidebarDayNum = $calDt->format('j');
$isViewingToday = $calDate === $todayDate;
ob_start();
?>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<div class="appointments-workspace-page">
<div class="calendar-workspace">
    <div class="calendar-op-canvas">
    <div class="calendar-workspace-layout">
        <aside class="calendar-sidebar" aria-label="Calendar quick navigation">
            <div class="calendar-sidebar-card calendar-sidebar-card--viewing<?= $isViewingToday ? ' calendar-sidebar-card--is-today' : '' ?>">
                <div class="calendar-sidebar-dateplate" aria-hidden="true"></div>
                <div class="calendar-sidebar-dateblock">
                    <p class="calendar-sidebar-kicker"><?= htmlspecialchars($sidebarMonth) ?></p>
                    <p class="calendar-sidebar-daynum"><?= htmlspecialchars($sidebarDayNum) ?></p>
                    <p class="calendar-sidebar-weekday"><?= htmlspecialchars($sidebarWeekday) ?></p>
                </div>
                <?php if ($isViewingToday): ?>
                <span class="calendar-pill calendar-pill--today">Today</span>
                <?php else: ?>
                <span class="calendar-pill calendar-pill--other-day">Selected day</span>
                <?php endif; ?>
                <div class="calendar-sidebar-nav" role="group" aria-label="Change day">
                    <a class="calendar-nav-btn" href="<?= htmlspecialchars($calDayUrl($prevDate)) ?>"><span class="calendar-nav-icon" aria-hidden="true">←</span><span>Prev</span></a>
                    <a class="calendar-nav-btn calendar-nav-btn--primary" href="<?= htmlspecialchars($calDayUrl($todayDate)) ?>"><span class="calendar-nav-icon calendar-nav-icon--dot" aria-hidden="true">·</span><span>Today</span></a>
                    <a class="calendar-nav-btn" href="<?= htmlspecialchars($calDayUrl($nextDate)) ?>"><span>Next</span><span class="calendar-nav-icon" aria-hidden="true">→</span></a>
                </div>
                <p class="calendar-sidebar-foot">Change date or branch in the toolbar; the schedule grid reloads for this day.</p>
            </div>
        </aside>
        <div class="calendar-workspace-primary">
            <div class="calendar-op-utilities-stack">
            <div class="calendar-op-utilities-row">
                <header class="calendar-toolbar calendar-toolbar--utilities" role="group" aria-label="Calendar scope">
                <form method="get" action="/appointments/calendar/day" class="calendar-toolbar-form" id="calendar-filter-form">
                    <div class="calendar-toolbar-fields">
                        <div class="calendar-field">
                            <label for="calendar-date">Date</label>
                            <input type="date" id="calendar-date" name="date" value="<?= htmlspecialchars($calDate) ?>" required>
                        </div>
                        <div class="calendar-field calendar-field--grow">
                            <label for="calendar-branch">Branch</label>
                            <select id="calendar-branch" name="branch_id">
                                <option value="">All branches</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= ((int)($branchId ?? 0) === (int)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="calendar-toolbar-submit">
                            <button type="submit" class="calendar-btn calendar-btn--primary">Apply</button>
                        </div>
                    </div>
                </form>
                <div class="calendar-toolbar-aside">
                    <a href="/appointments" class="calendar-toolbar-link calendar-toolbar-link--with-leading" title="Appointments list view"><span class="calendar-toolbar-link__ic" aria-hidden="true">›</span><span>List view</span></a>
                </div>
                </header>
            </div>
            <div class="calendar-op-meta-row">
                <p class="calendar-toolbar-hint">Staff columns · appointments and blocked time by length</p>
                <div id="calendar-status" class="calendar-load-status" role="status" aria-live="polite">Loading day calendar…</div>
            </div>
            <div id="calendar-branch-hours-indicator" class="calendar-branch-hours-indicator" role="status" aria-live="polite"></div>
            </div>
            <div class="calendar-op-grid-wrap">
            <div class="calendar-grid-surface">
                <div id="calendar-day-wrap" class="calendar-day-wrap"></div>
            </div>
            </div>
        </div>
    </div>
    </div>

    <section class="calendar-blocked-panel calendar-op-secondary" aria-labelledby="calendar-blocked-heading">
        <header class="calendar-blocked-head">
            <h2 id="calendar-blocked-heading" class="calendar-blocked-title">Blocked slots</h2>
            <p class="calendar-blocked-lead">Add or remove blocks for the selected date and scope. Uses the same branch filter as the calendar above.</p>
        </header>

        <form method="post" action="/appointments/blocked-slots" class="entity-form calendar-blocked-form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="calendar-blocked-form-grid">
                <div class="form-row">
                    <label for="blocked-branch-id">Branch</label>
                    <select id="blocked-branch-id" name="branch_id">
                        <option value="">—</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= ((int)($branchId ?? 0) === (int)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="blocked-staff-id">Staff *</label>
                    <select id="blocked-staff-id" name="staff_id" required>
                        <option value="">—</option>
                        <?php foreach ($staffOptions as $st): ?>
                        <option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="blocked-title">Title / Reason *</label>
                    <input type="text" id="blocked-title" name="title" required maxlength="150" value="Blocked">
                </div>
                <div class="form-row">
                    <label for="blocked-date">Date *</label>
                    <input type="date" id="blocked-date" name="block_date" required value="<?= htmlspecialchars($calDate) ?>">
                </div>
                <div class="form-row">
                    <label for="blocked-start">Start Time *</label>
                    <input type="time" id="blocked-start" name="start_time" required>
                </div>
                <div class="form-row">
                    <label for="blocked-end">End Time *</label>
                    <input type="time" id="blocked-end" name="end_time" required>
                </div>
                <div class="form-row calendar-blocked-field--full">
                    <label for="blocked-notes">Notes</label>
                    <textarea id="blocked-notes" name="notes" rows="2"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="calendar-btn calendar-btn--primary">Add blocked slot</button>
            </div>
        </form>

        <div class="calendar-blocked-list">
            <h3 class="calendar-blocked-subtitle" id="calendar-blocked-list-heading">Blocks for this date</h3>
            <div class="calendar-blocked-table-scroll" role="region" aria-labelledby="calendar-blocked-list-heading">
                <table class="index-table calendar-blocked-table">
                    <thead>
                    <tr>
                        <th scope="col">Staff</th>
                        <th scope="col">Title</th>
                        <th scope="col">Date</th>
                        <th scope="col">Time</th>
                        <th scope="col">Notes</th>
                        <th scope="col">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($blockedSlots)): ?>
                    <tr><td colspan="6"><span class="hint">No blocked slots for selected date/scope.</span></td></tr>
                    <?php else: ?>
                        <?php foreach ($blockedSlots as $bs): ?>
                        <tr>
                            <td><?= htmlspecialchars(trim(($bs['staff_first_name'] ?? '') . ' ' . ($bs['staff_last_name'] ?? ''))) ?: '—' ?></td>
                            <td><?= htmlspecialchars($bs['title'] ?? 'Blocked') ?></td>
                            <td><?= htmlspecialchars($bs['block_date'] ?? '') ?></td>
                            <td><?= htmlspecialchars((string) ($bs['start_time'] ?? '')) ?> – <?= htmlspecialchars((string) ($bs['end_time'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($bs['notes'] ?? '')) ?></td>
                            <td>
                                <form method="post" action="/appointments/blocked-slots/<?= (int) $bs['id'] ?>/delete" class="calendar-blocked-delete-form" onsubmit="return confirm('Delete blocked slot?')">
                                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="date" value="<?= htmlspecialchars($date ?? '') ?>">
                                    <input type="hidden" name="branch_id" value="<?= htmlspecialchars((string) ($branchId ?? '')) ?>">
                                    <button type="submit" class="calendar-btn calendar-btn--danger calendar-btn--small">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
</div>

<script>
(() => {
  const dateEl = document.getElementById('calendar-date');
  const branchEl = document.getElementById('calendar-branch');
  const statusEl = document.getElementById('calendar-status');
  const branchHoursIndicatorEl = document.getElementById('calendar-branch-hours-indicator');
  const wrap = document.getElementById('calendar-day-wrap');
  const PIXELS_PER_MINUTE = 1.4;
  const MIN_BLOCK_HEIGHT = 20;
  const MAX_TITLE_LENGTH = 48;
  const MAX_META_LENGTH = 56;

  function toMinutes(hhmm) {
    const [h, m] = String(hhmm || '00:00').split(':').map(Number);
    if (!Number.isFinite(h) || !Number.isFinite(m)) return 0;
    return (h * 60) + m;
  }

  function minutesFromDateTime(dt) {
    const hhmm = String(dt || '').slice(11, 16);
    return toMinutes(hhmm);
  }

  function safeLabel(text, maxLen) {
    const s = text != null ? String(text).trim() : '';
    if (maxLen && s.length > maxLen) return s.slice(0, maxLen) + '\u2026';
    return s;
  }

  function blockPlacement(startMins, endMins, dayStart, dayEnd, step) {
    const start = Number(startMins);
    const end = Number(endMins);
    if (!Number.isFinite(start) || !Number.isFinite(end)) return null;
    const safeEnd = dayEnd > dayStart ? dayEnd : dayStart + step;
    const clampedStart = Math.max(dayStart, Math.min(start, safeEnd));
    const clampedEnd = Math.max(dayStart, Math.min(end, safeEnd));
    if (clampedEnd <= clampedStart) return null;
    const top = Math.max(0, (clampedStart - dayStart) * PIXELS_PER_MINUTE);
    const height = Math.max(MIN_BLOCK_HEIGHT, (clampedEnd - clampedStart) * PIXELS_PER_MINUTE);
    return { top: Number(top) || 0, height: Number(height) || MIN_BLOCK_HEIGHT };
  }

  function fmtTime(totalMinutes) {
    const safe = Math.max(0, Math.floor(totalMinutes));
    const hh = String(Math.floor(safe / 60)).padStart(2, '0');
    const mm = String(safe % 60).padStart(2, '0');
    return hh + ':' + mm;
  }

  function fmtFromDt(dt) {
    const t = String(dt || '').slice(11, 16);
    return /^\d{2}:\d{2}$/.test(t) ? t : '';
  }

  function timeRangeLabel(startDt, endDt) {
    const a = fmtFromDt(startDt);
    const b = fmtFromDt(endDt);
    if (a && b) return a + '\u2013' + b;
    return a || b || '';
  }

  function buildTimeMarks(startMins, endMins, stepMin) {
    const marks = [];
    for (let cur = startMins; cur <= endMins; cur += stepMin) {
      marks.push(cur);
    }
    return marks;
  }

  function buildCalendarViewModel(payload) {
    if (!payload || typeof payload !== 'object') payload = {};
    const staff = Array.isArray(payload.staff) ? payload.staff : [];
    const grouped = payload.appointments_by_staff && typeof payload.appointments_by_staff === 'object' ? payload.appointments_by_staff : {};
    const calDisp = payload.appointment_calendar_display && typeof payload.appointment_calendar_display === 'object' ? payload.appointment_calendar_display : {};
    const LABEL_MODES = new Set(['client_and_service', 'service_and_client', 'service_only', 'client_only']);
    const serviceLabelMode = LABEL_MODES.has(calDisp.label_mode) ? calDisp.label_mode : 'client_and_service';
    const seriesLabelMode = LABEL_MODES.has(calDisp.series_label_mode) ? calDisp.series_label_mode : serviceLabelMode;
    const blocked = payload.blocked_by_staff && typeof payload.blocked_by_staff === 'object' ? payload.blocked_by_staff : {};
    const grid = payload.time_grid && typeof payload.time_grid === 'object' ? payload.time_grid : {};
    const branchHours = payload.branch_operating_hours && typeof payload.branch_operating_hours === 'object'
      ? payload.branch_operating_hours
      : {};
    const closureDate = payload.closure_date && typeof payload.closure_date === 'object'
      ? payload.closure_date
      : {};
    const step = Number(grid.slot_minutes || 30);
    const dayStart = toMinutes(grid.day_start || '09:00');
    const dayEnd = toMinutes(grid.day_end || '18:00');
    const safeEnd = dayEnd > dayStart ? dayEnd : dayStart + step;
    const range = safeEnd - dayStart;

    const columns = staff.map((s) => {
      const sid = String(s.id);
      const appts = Array.isArray(grouped[sid]) ? grouped[sid] : [];
      const blocks = Array.isArray(blocked[sid]) ? blocked[sid] : [];
      const items = [];

      appts.forEach((a) => {
        const start = minutesFromDateTime(a.start_at);
        const end = minutesFromDateTime(a.end_at);
        const placement = blockPlacement(start, end, dayStart, dayEnd, step);
        if (!placement) return;
        const seriesId = a.series_id != null && String(a.series_id).trim() !== '' ? Number(a.series_id) : 0;
        const isSeriesLinked = seriesId > 0;
        const showStartTime = isSeriesLinked
          ? calDisp.series_show_start_time !== false
          : calDisp.show_start_time !== false;
        const labelMode = isSeriesLinked ? seriesLabelMode : serviceLabelMode;
        const serviceLine = safeLabel(a.service_name || 'Service', MAX_META_LENGTH);
        const clientLine = safeLabel(a.client_name || ('Appointment #' + (a.id ?? '')), MAX_TITLE_LENGTH) || safeLabel('Appointment', MAX_TITLE_LENGTH);
        let labelPrimary = clientLine;
        let metaLine = serviceLine;
        if (labelMode === 'client_and_service') {
          labelPrimary = clientLine;
          metaLine = serviceLine;
        } else if (labelMode === 'service_and_client') {
          labelPrimary = serviceLine;
          metaLine = clientLine;
        } else if (labelMode === 'service_only') {
          labelPrimary = serviceLine;
          metaLine = '';
        } else if (labelMode === 'client_only') {
          labelPrimary = clientLine;
          metaLine = '';
        }
        const endOnly = fmtFromDt(a.end_at);
        const timeLabel = showStartTime
          ? timeRangeLabel(a.start_at, a.end_at)
          : (endOnly ? ('Ends ' + endOnly) : '');
        const statusLine = safeLabel(a.status || 'scheduled', 32);
        const prebooked = !!(a.display_flags && a.display_flags.prebooked);
        const ns = a.client_no_show_alert && typeof a.client_no_show_alert === 'object' ? a.client_no_show_alert : null;
        const noShowAlert = !!(ns && ns.active);
        const noShowTitle = noShowAlert && ns.message ? String(ns.message) : '';
        items.push({
          kind: 'appointment',
          id: Number(a.id || 0),
          top: placement.top,
          height: placement.height,
          timeLabel,
          title: labelPrimary,
          meta: metaLine,
          statusLabel: statusLine,
          prebooked,
          noShowAlert,
          noShowTitle,
          link: '/appointments/' + (a.id ?? '')
        });
      });

      blocks.forEach((b) => {
        const start = minutesFromDateTime(b.start_at);
        const end = minutesFromDateTime(b.end_at);
        const placement = blockPlacement(start, end, dayStart, dayEnd, step);
        if (!placement) return;
        const labelPrimary = safeLabel(b.title || 'Blocked', MAX_TITLE_LENGTH) || safeLabel('Blocked', MAX_TITLE_LENGTH);
        const labelMeta = safeLabel(b.notes, MAX_META_LENGTH);
        items.push({
          kind: 'blocked',
          id: Number(b.id || 0),
          top: placement.top,
          height: placement.height,
          timeLabel: timeRangeLabel(b.start_at, b.end_at),
          title: labelPrimary,
          meta: labelMeta,
          link: null
        });
      });

      return {
        id: Number(s.id || 0),
        label: ((s.first_name || '') + ' ' + (s.last_name || '')).trim() || ('Staff #' + s.id),
        items
      };
    });

    return {
      columns,
      start: dayStart,
      end: safeEnd,
      step,
      marks: buildTimeMarks(dayStart, safeEnd, step),
      height: range * PIXELS_PER_MINUTE,
      branchHours: {
        available: !!branchHours.branch_hours_available,
        isClosedDay: !!branchHours.is_closed_day,
        openTime: typeof branchHours.open_time === 'string' ? branchHours.open_time : null,
        closeTime: typeof branchHours.close_time === 'string' ? branchHours.close_time : null,
        outOfHoursAppointments: Number(branchHours.out_of_hours_appointments || 0)
      },
      closureDate: {
        storageReady: !!closureDate.storage_ready,
        active: !!closureDate.active,
        title: closureDate.title ? String(closureDate.title) : null,
        notes: closureDate.notes ? String(closureDate.notes) : null,
        recordsVisibleCount: Number(closureDate.records_visible_count || 0)
      }
    };
  }

  function renderCalendar(payload) {
    wrap.innerHTML = '';
    const vm = buildCalendarViewModel(payload);
    renderBranchHoursIndicator(vm.branchHours, vm.closureDate);
    if (!vm.columns.length) {
      wrap.innerHTML = '<p class="calendar-empty-hint">No active staff for this branch and date.</p>';
      return;
    }

    const root = document.createElement('div');
    root.className = 'ops-calendar';

    const head = document.createElement('div');
    head.className = 'ops-calendar-head';
    const headTime = document.createElement('div');
    headTime.className = 'ops-time-head';
    headTime.textContent = 'Time';
    head.appendChild(headTime);
    vm.columns.forEach((col) => {
      const h = document.createElement('div');
      h.className = 'ops-staff-head';
      const inner = document.createElement('div');
      inner.className = 'ops-staff-head-inner';
      const name = document.createElement('div');
      name.className = 'ops-staff-head-name';
      name.textContent = col.label;
      inner.appendChild(name);
      h.appendChild(inner);
      head.appendChild(h);
    });
    root.appendChild(head);

    const body = document.createElement('div');
    body.className = 'ops-calendar-body';
    body.style.height = vm.height + 'px';

    const labelsCol = document.createElement('div');
    labelsCol.className = 'ops-time-labels';
    vm.marks.forEach((mark) => {
      const row = document.createElement('div');
      row.className = 'ops-time-label';
      row.style.top = ((mark - vm.start) * PIXELS_PER_MINUTE) + 'px';
      row.textContent = fmtTime(mark);
      labelsCol.appendChild(row);
    });
    labelsCol.addEventListener('click', () => {
      wrap.querySelectorAll('.ops-lane--selected').forEach((el) => el.classList.remove('ops-lane--selected'));
    });
    body.appendChild(labelsCol);

    const laneWrap = document.createElement('div');
    laneWrap.className = 'ops-lanes';
    vm.columns.forEach((col) => {
      const lane = document.createElement('div');
      lane.className = 'ops-lane';
      lane.setAttribute('role', 'presentation');

      vm.marks.forEach((mark) => {
        const line = document.createElement('div');
        line.className = 'ops-grid-line';
        line.style.top = ((mark - vm.start) * PIXELS_PER_MINUTE) + 'px';
        lane.appendChild(line);
      });

      const envelope = branchEnvelopeForLane(vm.branchHours, vm.start, vm.end);
      if (envelope !== null) {
        if (envelope.beforeHeight > 0) {
          const before = document.createElement('div');
          before.className = 'ops-lane-offhours ops-lane-offhours--before';
          before.style.height = envelope.beforeHeight + 'px';
          lane.appendChild(before);
        }
        if (envelope.afterHeight > 0) {
          const after = document.createElement('div');
          after.className = 'ops-lane-offhours ops-lane-offhours--after';
          after.style.top = envelope.afterTop + 'px';
          after.style.height = envelope.afterHeight + 'px';
          lane.appendChild(after);
        }
      }

      col.items
        .sort((a, b) => {
          const byTop = (Number(a.top) || 0) - (Number(b.top) || 0);
          if (byTop !== 0) return byTop;
          const kindOrder = (a.kind === 'blocked' ? 1 : 0) - (b.kind === 'blocked' ? 1 : 0);
          if (kindOrder !== 0) return kindOrder;
          return (Number(a.id) || 0) - (Number(b.id) || 0);
        })
        .forEach((item) => {
          const topPx = Math.max(0, Number(item.top) || 0);
          const heightPx = Math.max(MIN_BLOCK_HEIGHT, Number(item.height) || MIN_BLOCK_HEIGHT);
          const block = document.createElement(item.link ? 'a' : 'div');
          block.className = 'ops-block ' + (item.kind === 'blocked' ? 'ops-block-blocked' : 'ops-block-appt');
          if (item.kind === 'appointment' && item.prebooked) {
            block.classList.add('ops-block-appt--prebooked');
          }
          if (item.kind === 'appointment' && item.noShowAlert) {
            block.classList.add('ops-block-appt--no-show-alert');
            if (item.noShowTitle) {
              block.setAttribute('title', item.noShowTitle);
            }
          }
          block.setAttribute('data-block-type', item.kind === 'blocked' ? 'blocked' : 'appointment');
          if (item.statusLabel) {
            block.setAttribute('data-status', String(item.statusLabel).toLowerCase().replace(/\s+/g, '-').slice(0, 40));
          }
          block.style.top = topPx + 'px';
          block.style.height = heightPx + 'px';
          if (item.link) {
            block.href = item.link;
          }
          if (item.timeLabel) {
            const timeEl = document.createElement('div');
            timeEl.className = 'ops-block-time';
            timeEl.textContent = item.timeLabel;
            block.appendChild(timeEl);
          }
          if (item.kind === 'blocked') {
            const kindEl = document.createElement('div');
            kindEl.className = 'ops-block-kind';
            kindEl.textContent = 'Blocked';
            block.appendChild(kindEl);
          }
          const ttl = document.createElement('div');
          ttl.className = 'ops-block-title';
          ttl.textContent = safeLabel(item.title, MAX_TITLE_LENGTH) || (item.kind === 'blocked' ? 'Blocked' : 'Appointment');
          block.appendChild(ttl);
          if (item.meta) {
            const meta = document.createElement('div');
            meta.className = 'ops-block-meta';
            meta.textContent = safeLabel(item.meta, MAX_META_LENGTH);
            block.appendChild(meta);
          }
          if (item.kind === 'appointment' && item.statusLabel) {
            const st = document.createElement('div');
            st.className = 'ops-block-status';
            st.textContent = safeLabel(item.statusLabel, 32);
            block.appendChild(st);
          }
          lane.appendChild(block);
        });

      lane.addEventListener('click', (e) => {
        if (e.target.closest('.ops-block')) return;
        if (lane.classList.contains('ops-lane--selected')) {
          lane.classList.remove('ops-lane--selected');
          return;
        }
        wrap.querySelectorAll('.ops-lane--selected').forEach((el) => el.classList.remove('ops-lane--selected'));
        lane.classList.add('ops-lane--selected');
      });

      laneWrap.appendChild(lane);
    });

    body.appendChild(laneWrap);
    root.appendChild(body);
    wrap.appendChild(root);
  }

  function renderBranchHoursIndicator(meta, closureMeta) {
    if (!branchHoursIndicatorEl) return;
    const closureStorageReady = !!(closureMeta && closureMeta.storageReady);
    const closureActive = !!(closureMeta && closureMeta.active);
    const closureTitle = closureMeta && closureMeta.title ? String(closureMeta.title).trim() : '';
    const closureNotes = closureMeta && closureMeta.notes ? String(closureMeta.notes).trim() : '';
    const closureVisibleRecords = Number(closureMeta && closureMeta.recordsVisibleCount ? closureMeta.recordsVisibleCount : 0);

    if (!closureStorageReady) {
      branchHoursIndicatorEl.textContent = 'Closure-date storage is not available yet. Calendar uses existing operating-hours data only.';
      branchHoursIndicatorEl.className = 'calendar-branch-hours-indicator calendar-branch-hours-indicator--missing';
      return;
    }

    if (closureActive) {
      const titlePart = closureTitle !== '' ? (' ' + closureTitle + '.') : '';
      const notesPart = closureNotes !== '' ? (' ' + closureNotes) : '';
      const anomalyPart = closureVisibleRecords > 0
        ? (' ' + closureVisibleRecords + ' existing record(s) are still visible for review.')
        : '';
      branchHoursIndicatorEl.textContent = 'Closed day (closure date).' + titlePart + notesPart + anomalyPart;
      branchHoursIndicatorEl.className = 'calendar-branch-hours-indicator calendar-branch-hours-indicator--closed';
      return;
    }

    const available = !!(meta && meta.available);
    const isClosed = !!(meta && meta.isClosedDay);
    const openTime = meta && meta.openTime ? String(meta.openTime).slice(0, 5) : '';
    const closeTime = meta && meta.closeTime ? String(meta.closeTime).slice(0, 5) : '';
    const anomalies = Number(meta && meta.outOfHoursAppointments ? meta.outOfHoursAppointments : 0);
    if (!available) {
      branchHoursIndicatorEl.textContent = 'Opening hours not configured for this branch/day.';
      branchHoursIndicatorEl.className = 'calendar-branch-hours-indicator calendar-branch-hours-indicator--missing';
      return;
    }
    if (isClosed) {
      branchHoursIndicatorEl.textContent = anomalies > 0
        ? ('Closed today. ' + anomalies + ' existing appointment(s) found on a closed day.')
        : 'Closed today.';
      branchHoursIndicatorEl.className = 'calendar-branch-hours-indicator calendar-branch-hours-indicator--closed';
      return;
    }
    const base = (openTime && closeTime)
      ? ('Branch hours: ' + openTime + '-' + closeTime)
      : 'Opening hours not configured for this branch/day.';
    const suffix = anomalies > 0 ? (' | ' + anomalies + ' appointment(s) outside branch hours.') : '';
    branchHoursIndicatorEl.textContent = base + suffix;
    branchHoursIndicatorEl.className = 'calendar-branch-hours-indicator calendar-branch-hours-indicator--open';
  }

  function branchEnvelopeForLane(meta, dayStart, dayEnd) {
    if (!meta || !meta.available) return null;
    const range = Math.max(1, (dayEnd - dayStart));
    if (meta.isClosedDay) {
      return {
        beforeHeight: range * PIXELS_PER_MINUTE,
        afterTop: range * PIXELS_PER_MINUTE,
        afterHeight: 0
      };
    }
    const open = toMinutes(meta.openTime || '');
    const close = toMinutes(meta.closeTime || '');
    if (!Number.isFinite(open) || !Number.isFinite(close) || close <= open) {
      return null;
    }
    const openClamped = Math.max(dayStart, Math.min(open, dayEnd));
    const closeClamped = Math.max(dayStart, Math.min(close, dayEnd));
    return {
      beforeHeight: Math.max(0, (openClamped - dayStart) * PIXELS_PER_MINUTE),
      afterTop: Math.max(0, (closeClamped - dayStart) * PIXELS_PER_MINUTE),
      afterHeight: Math.max(0, (dayEnd - closeClamped) * PIXELS_PER_MINUTE)
    };
  }

  async function load() {
    const date = dateEl.value;
    if (!date) return;
    const params = new URLSearchParams();
    params.set('date', date);
    if (branchEl.value) params.set('branch_id', branchEl.value);
    statusEl.textContent = 'Loading day calendar…';
    try {
      const res = await fetch('/calendar/day?' + params.toString(), {headers: {'Accept': 'application/json'}});
      const payload = await res.json();
      // BKM-008: success payloads include contract fields only; errors may be a string (422) or
      // { message } (auth/HTTP JSON). Avoid truthy non-string `error` and property access on non-objects.
      const payloadError = payload && typeof payload === 'object' ? payload.error : undefined;
      const errMsg =
        typeof payloadError === 'string'
          ? payloadError
          : payloadError && typeof payloadError === 'object' && typeof payloadError.message === 'string'
            ? payloadError.message
            : null;
      if (!res.ok || errMsg) {
        statusEl.textContent = errMsg || 'Failed to load calendar.';
        wrap.innerHTML = '';
        return;
      }
      statusEl.textContent = '';
      renderCalendar(payload);
    } catch (e) {
      statusEl.textContent = 'Could not load calendar data.';
      wrap.innerHTML = '';
    }
  }

  document.getElementById('calendar-filter-form').addEventListener('submit', (e) => {
    e.preventDefault();
    load();
  });

  load();
})();
</script>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
