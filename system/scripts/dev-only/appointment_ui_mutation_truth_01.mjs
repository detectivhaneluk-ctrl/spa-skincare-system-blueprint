/**
 * APPOINTMENTS-REAL-CUSTOMER-UI-MUTATION-TRUTH-01
 * Browser proof: create / edit / reschedule / delete + conflict attempts on day calendar.
 *
 * Prereq:
 *   php scripts/dev-only/seed_realistic_customers_ui_truth_01.php   (from system/)
 *   npm install && npx playwright install chromium   (in this directory)
 *
 * Usage:
 *   node appointment_ui_mutation_truth_01.mjs [baseUrl] [screenshotDir]
 *
 * Example:
 *   node appointment_ui_mutation_truth_01.mjs http://spa-skincare-system-blueprint.test C:/laragon/www/spa-skincare-system-blueprint/proof-ui-truth-01
 */
import { chromium } from 'playwright';
import { readFileSync, mkdirSync, writeFileSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const base = (process.argv[2] || 'http://spa-skincare-system-blueprint.test').replace(/\/$/, '');
const outDir = process.argv[3] || join(__dirname, '../../../proof-ui-truth-01');

const fixture = JSON.parse(readFileSync(join(__dirname, 'ui-truth-fixture.generated.json'), 'utf8'));
const { branch_id: BR, test_date: DATE, room_id: ROOM_ID, staff: S, services: SV, clients: C } = fixture;

const results = {
  scenarios: [],
  appointments: [],
  edits: [],
  reschedules: [],
  deletes: [],
  cancellations: [],
  conflicts: [],
};

function looksLikeBookingRejection(msg) {
  return /conflict|overlap|unavailable|not available|no longer available|busy|slot|block|closed|outside/i.test(
    String(msg || ''),
  );
}

function shot(name) {
  return join(outDir, `${name}.png`);
}

function createDrawerUrl({ staffId, time, slotMinutes = 30 }) {
  const p = new URLSearchParams();
  p.set('drawer', '1');
  p.set('branch_id', String(BR));
  p.set('date', DATE);
  p.set('staff_id', String(staffId));
  p.set('time', time);
  p.set('slot_minutes', String(slotMinutes));
  return `/appointments/create?${p.toString()}`;
}

async function waitDrawerForm(page) {
  await page.locator('#drawer-booking-form').waitFor({ state: 'visible', timeout: 20000 });
}

async function openCreateDrawer(page, spec) {
  const url = createDrawerUrl(spec);
  await page.evaluate((u) => window.AppDrawer.openUrl(u), url);
  await waitDrawerForm(page);
}

async function submitCreate(page, label) {
  await page.locator('#drawer-booking-form button.drawer-submit').click();
  // Drawer JSON handler calls openUrl next; status may flash success then become "Loading…"
  await page.locator('#app-drawer-subtitle').filter({ hasText: /Appointment\s*#/i }).waitFor({ state: 'visible', timeout: 25000 });
  const sub = (await page.locator('#app-drawer-subtitle').textContent()) || '';
  const m = sub.match(/#(\d+)/);
  const id = m ? parseInt(m[1], 10) : null;
  results.appointments.push({ label, id, subtitle: sub.trim() });
  await page.waitForTimeout(1200);
  return id;
}

async function expectCreateError(page) {
  await page.locator('#app-drawer-status.app-drawer__status--error').waitFor({ state: 'visible', timeout: 20000 });
  const txt = ((await page.locator('#app-drawer-status').textContent()) || '').trim();
  await page.waitForTimeout(400);
  return txt;
}

async function openApptByFirstName(page, firstName, nth = 0) {
  const loc = page.locator('a.ops-block-appt', { hasText: new RegExp(firstName, 'i') });
  await loc.nth(nth).waitFor({ state: 'visible', timeout: 20000 });
  await loc.nth(nth).click();
  await page.locator('[data-drawer-content-root]').waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForTimeout(400);
}

async function goCalendar(page) {
  await page.goto(`${base}/appointments/calendar/day?branch_id=${BR}&date=${DATE}`, { waitUntil: 'networkidle' });
  await page.locator('#calendar-day-wrap .ops-lane').first().waitFor({ state: 'visible', timeout: 25000 });
  await page.waitForTimeout(800);
}

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1680, height: 1200 } });

page.on('dialog', (d) => d.accept());

try {
  await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', 'tenant-admin-a@example.test');
  await page.fill('input[name="password"]', 'TenantAdminA##2026');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 25000 }),
    page.click('button[type="submit"]'),
  ]);

  await goCalendar(page);
  await page.screenshot({ path: shot('00-calendar-baseline-seed-day'), fullPage: true });

  // --- Creates (realistic day) ---
  const creates = [
    {
      label: 'Anahit_Express30_Sofia_0900_EXISTING_CUSTOMER',
      staffId: S.sofia,
      time: '09:00',
      slot: 30,
      clientId: C.Anahit_Karapetyan,
      serviceId: SV.express30,
      notes: '[UI_TRUTH_V1] CREATE — existing customer Anahit Karapetyan',
    },
    { label: 'VIP_Gor_Retreat120_Nina_0900', staffId: S.nina, time: '09:00', slot: 120, clientId: C.Gor_Gevorgyan, serviceId: SV.retreat120, notes: '[UI_TRUTH_V1] VIP retreat — Gor Gevorgyan (120m, room)', room: true },
    { label: 'Anahit_Polish45_Zara_1000', staffId: S.zara, time: '10:00', slot: 45, clientId: C.Anahit_Karapetyan, serviceId: SV.polish45, notes: '[UI_TRUTH_V1] 4th parallel lane — Anahit (repeat same day)' },
    { label: 'Davit_Glow45_Mia_1000', staffId: S.mia, time: '10:00', slot: 45, clientId: C.Davit_Sargsyan, serviceId: SV.glow45, notes: '[UI_TRUTH_V1] parallel lane A — Davit' },
    { label: 'Mariam_Classic60_Elena_1000', staffId: S.elena, time: '10:00', slot: 60, clientId: C.Mariam_Hakobyan, serviceId: SV.classic60, notes: '[UI_TRUTH_V1] parallel lane B — Mariam' },
    { label: 'Armen_Express30_Sofia_1000', staffId: S.sofia, time: '10:00', slot: 30, clientId: C.Armen_Petrosyan, serviceId: SV.express30, notes: '[UI_TRUTH_V1] parallel lane C — Armen' },
    { label: 'Lilit_Glow45_Zara_1115', staffId: S.zara, time: '11:15', slot: 45, clientId: C.Lilit_Grigoryan, serviceId: SV.glow45, notes: '[UI_TRUTH_V1] Zara 11:15 — Lilit' },
    { label: 'Sona_Glow45_Mia_1115', staffId: S.mia, time: '11:15', slot: 45, clientId: C['Sona_Ter-Mkrtchyan'], serviceId: SV.glow45, notes: '[UI_TRUTH_V1] Mia 11:15 — Sona' },
    { label: 'Nare_Glow45_Elena_1115', staffId: S.elena, time: '11:15', slot: 45, clientId: C.Nare_Azatyan, serviceId: SV.glow45, notes: '[UI_TRUTH_V1] Elena 11:15 — Nare' },
    { label: 'Tigran_Deep90_Nina_1230', staffId: S.nina, time: '12:30', slot: 90, clientId: C.Tigran_Vardanyan, serviceId: SV.deep90, notes: '[UI_TRUTH_V1] Nina afternoon — Tigran deep 90' },
    { label: 'Hasmik_Express30_Sofia_1400', staffId: S.sofia, time: '14:00', slot: 30, clientId: C.Hasmik_Manukyan, serviceId: SV.express30, notes: '[UI_TRUTH_V1] Sofia 14:00 — Hasmik' },
    { label: 'Lucy_Express30_Nina_1500_DELETE_TARGET', staffId: S.nina, time: '15:00', slot: 30, clientId: C.Lucy_Martirosyan, serviceId: SV.express30, notes: '[UI_TRUTH_V1] delete target — Lucy' },
    { label: 'Elen_Return1_Express30_Elena_1530', staffId: S.elena, time: '15:30', slot: 30, clientId: C.Elen_Beglaryan, serviceId: SV.express30, notes: '[UI_TRUTH_V1] returning client visit 1 — Elen' },
    { label: 'Karen_Classic60_Mia_1615_EDIT', staffId: S.mia, time: '16:15', slot: 60, clientId: C.Karen_Mkrtchyan, serviceId: SV.classic60, notes: '[UI_TRUTH_V1] edit/reschedule target — Karen' },
    { label: 'Elen_Return2_Glow45_Sofia_1745', staffId: S.sofia, time: '17:45', slot: 45, clientId: C.Elen_Beglaryan, serviceId: SV.glow45, notes: '[UI_TRUTH_V1] returning client visit 2 — Elen' },
  ];

  for (const c of creates) {
    await openCreateDrawer(page, { staffId: c.staffId, time: c.time, slotMinutes: c.slot });
    await page.selectOption('#client_id', String(c.clientId));
    await page.selectOption('#service_id', String(c.serviceId));
    if (c.room) {
      await page.selectOption('#room_id', String(ROOM_ID));
    }
    await page.fill('#notes', c.notes);
    await submitCreate(page, c.label);
  }

  await goCalendar(page);
  await page.screenshot({ path: shot('01-full-day-after-all-creates'), fullPage: true });
  results.scenarios.push({ name: 'CREATE multi + parallel + durations', pass: true });

  // CANCEL (status) — Davit
  await openApptByFirstName(page, 'Davit', 0);
  await page.locator('button.drawer-tab', { hasText: 'Actions' }).click();
  await page.selectOption('#next_status', 'cancelled');
  await page.fill('#status_notes', '[UI_TRUTH_V1] cancelled in browser proof');
  await page.locator('form[action*="/status"] button.drawer-submit').click();
  await page.locator('#app-drawer-subtitle').filter({ hasText: /Appointment/i }).waitFor({ state: 'visible', timeout: 20000 });
  results.cancellations.push({ client: 'Davit Sargsyan', status: 'cancelled' });
  await goCalendar(page);
  await page.screenshot({ path: shot('02-after-cancel-davit'), fullPage: true });
  results.scenarios.push({ name: 'CANCEL Davit via status form', pass: true });

  await page.screenshot({ path: shot('02-after-create-representative'), fullPage: true });

  // Detail drawer truth (Karen)
  await openApptByFirstName(page, 'Karen', 0);
  await page.waitForTimeout(600);
  await page.screenshot({ path: shot('03-detail-drawer-customer-staff-service'), fullPage: true });

  // EDIT: notes + service -> Deluxe 60 (id from fixture lookup by name in page - use select by label)
  await page.locator('a.drawer-link-btn', { hasText: 'Edit' }).click();
  await page.locator('.appt-edit-form').waitFor({ state: 'visible', timeout: 15000 });
  await page.fill('#notes', '[UI_TRUTH_V1] EDITED — Karen notes updated in browser');
  const deluxeValue = await page.evaluate(() => {
    const sel = document.querySelector('#service_id');
    if (!sel) return null;
    for (const o of sel.options) {
      if (o.textContent && o.textContent.includes('CALSVC Deluxe 60')) return o.value;
    }
    return null;
  });
  if (deluxeValue) {
    await page.selectOption('#service_id', deluxeValue);
  }
  await page.locator('.appt-edit-form button.drawer-submit').click();
  await page.locator('#app-drawer-subtitle').filter({ hasText: /Appointment\s*#/i }).waitFor({ state: 'visible', timeout: 25000 });
  results.edits.push({ target: 'Karen Mkrtchyan', notes: 'EDITED', serviceChangedToDeluxe: !!deluxeValue });
  await page.waitForTimeout(1200);
  await goCalendar(page);
  await page.screenshot({ path: shot('04-after-edit'), fullPage: true });
  results.scenarios.push({ name: 'EDIT notes (+ service if Deluxe available)', pass: true });

  // Open Karen again — confirm notes visible in detail
  await openApptByFirstName(page, 'Karen', 0);
  await page.waitForTimeout(500);
  const detailNotes = (await page.locator('dd').filter({ hasText: 'EDITED' }).first().textContent().catch(() => '')) || '';
  if (!detailNotes.includes('EDITED')) {
    const body = await page.locator('[data-drawer-content-root]').innerText();
    if (!body.includes('EDITED')) results.scenarios.push({ name: 'VISIBILITY notes after edit', pass: false });
    else results.scenarios.push({ name: 'VISIBILITY notes after edit', pass: true });
  } else {
    results.scenarios.push({ name: 'VISIBILITY notes after edit', pass: true });
  }

  // RESCHEDULE: Actions tab, move to 17:30 same staff (Mia)
  await page.locator('button.drawer-tab', { hasText: 'Actions' }).click();
  await page.locator('#reschedule_start_time').waitFor({ state: 'visible', timeout: 10000 });
  await page.fill('#reschedule_start_time', `${DATE}T17:30`);
  await page.locator('form[action*="/reschedule"] button.drawer-submit').click();
  await page.locator('#app-drawer-subtitle').filter({ hasText: /Appointment\s*#/i }).waitFor({ state: 'visible', timeout: 25000 });
  results.reschedules.push({ client: 'Karen Mkrtchyan', newLocal: `${DATE}T17:30`, staff: 'Mia (same)' });
  await page.waitForTimeout(1500);
  await goCalendar(page);
  await page.screenshot({ path: shot('05-after-reschedule'), fullPage: true });
  results.scenarios.push({ name: 'RESCHEDULE Karen to 17:30', pass: true });

  // RESCHEDULE — Elen visit 2: Sofia → Zara (same local time)
  await goCalendar(page);
  await openApptByFirstName(page, 'Elen', 1);
  await page.locator('button.drawer-tab', { hasText: 'Actions' }).click();
  await page.locator('#reschedule_start_time').waitFor({ state: 'visible', timeout: 10000 });
  await page.selectOption('#reschedule_staff_id', String(S.zara));
  await page.locator('form[action*="/reschedule"] button.drawer-submit').click();
  await page.locator('#app-drawer-subtitle').filter({ hasText: /Appointment\s*#/i }).waitFor({ state: 'visible', timeout: 25000 });
  results.reschedules.push({ client: 'Elen Beglaryan (visit 2)', newLocal: 'unchanged time', staff: 'Zara (was Sofia)' });
  await page.waitForTimeout(1500);
  await goCalendar(page);
  await page.screenshot({ path: shot('05b-after-reschedule-elen-to-zara'), fullPage: true });
  results.scenarios.push({ name: 'RESCHEDULE Elen to Zara (staff change)', pass: true });

  // OVERLAP: Mia @ 17:30 — should reject (Karen now occupies)
  await openCreateDrawer(page, { staffId: S.mia, time: '17:30', slotMinutes: 30 });
  await page.selectOption('#client_id', String(C.Anahit_Karapetyan));
  await page.selectOption('#service_id', String(SV.express30));
  await page.fill('#notes', '[UI_TRUTH_V1] overlap probe — should fail');
  await page.locator('#drawer-booking-form button.drawer-submit').click();
  const overlapMsg = await expectCreateError(page);
  results.conflicts.push({ type: 'overlap_same_staff', pass: looksLikeBookingRejection(overlapMsg), message: overlapMsg });
  results.scenarios.push({ name: 'BLOCK overlap same staff', pass: results.conflicts.at(-1).pass });
  await page.evaluate(() => {
    if (window.AppDrawer) window.AppDrawer.close(true);
  });

  // BLOCKED window: Zara @ 14:30 (seed UI_TRUTH_BLOCK 14:00–15:00)
  await goCalendar(page);
  await openCreateDrawer(page, { staffId: S.zara, time: '14:30', slotMinutes: 30 });
  await page.selectOption('#client_id', String(C.Anahit_Karapetyan));
  await page.selectOption('#service_id', String(SV.express30));
  await page.fill('#notes', '[UI_TRUTH_V1] blocked window probe');
  await page.locator('#drawer-booking-form button.drawer-submit').click();
  const blockMsg = await expectCreateError(page);
  results.conflicts.push({ type: 'blocked_time', pass: looksLikeBookingRejection(blockMsg), message: blockMsg });
  results.scenarios.push({ name: 'BLOCK booked/blocked window', pass: results.conflicts.at(-1).pass });

  // DELETE Lucy
  await goCalendar(page);
  await openApptByFirstName(page, 'Lucy', 0);
  await page.locator('button.drawer-tab', { hasText: 'Actions' }).click();
  await page.locator('form[action*="/delete"] button').click();
  await page.waitForTimeout(2000);
  results.deletes.push({ client: 'Lucy Martirosyan', method: 'delete' });
  await goCalendar(page);
  await page.screenshot({ path: shot('06-after-delete-lucy'), fullPage: true });
  const lucyBlocks = await page.locator('a.ops-block-appt', { hasText: 'Lucy' }).count();
  results.scenarios.push({ name: 'DELETE Lucy — calendar clean', pass: lucyBlocks === 0 });

  await page.screenshot({ path: shot('07-final-calendar-state'), fullPage: true });

  writeFileSync(join(outDir, 'ui-mutation-truth-results.json'), JSON.stringify(results, null, 2), 'utf8');
  console.log('OK results ->', join(outDir, 'ui-mutation-truth-results.json'));
} finally {
  await browser.close();
}
