/**
 * STAFF WORKSPACE SMOKE TEST
 * TASK: TEAM-DIRECTORY-WORKSPACE-PRO-01
 *
 * Verifies:
 *   - Login succeeds
 *   - Staff list view loads (HTTP 200, no PHP fatal, table/empty present)
 *   - Search input present (hidden in trash)
 *   - Status tabs present (Active / All incl. inactive / Trash)
 *   - Add Staff CTA present (hidden in trash)
 *   - Bulk action bar present
 *   - Trash view loads correctly
 *   - Inactive view (?active=0) loads
 *   - Sort URL params don't break page
 *   - Existing create/edit flows still 200
 *   - No JS console errors on any view
 */

'use strict';

const { chromium } = require('playwright');
const fs = require('fs');

const BASE  = 'http://spa-skincare-system-blueprint.test';
const EMAIL = 'tenant-admin-a@example.test';
const PASS  = 'password';

const results = [];
function verdict(id, label, status, detail = '') {
    results.push({ id, label, status, detail });
    const icon = status === 'PASS' ? '✓' : status === 'FAIL' ? '✗' : '?';
    console.log(`  [${icon}] ${id.padEnd(4)} | ${status.padEnd(5)} | ${label}${detail ? ' — ' + detail : ''}`);
}

async function shot(page, name) {
    await page.screenshot({ path: `stf-ws-smoke-${name}.png`, fullPage: false });
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    const jsErrors = [];
    page.on('console', msg => { if (msg.type() === 'error') jsErrors.push(msg.text()); });
    page.on('pageerror', err => jsErrors.push('[pageerror] ' + err.message));

    // ── S1: Login ─────────────────────────────────────────────────────────────
    console.log('\n── S1: Login');
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(url => !url.href.includes('/login'), { timeout: 8000 }).catch(() => {});
    const afterLogin = page.url();
    if (afterLogin.includes('/login')) {
        verdict('S1.1', 'Login redirects away from /login', 'FAIL', afterLogin);
        await browser.close();
        return;
    }
    verdict('S1.1', 'Login succeeded', 'PASS');

    // ── S2: Staff list view ───────────────────────────────────────────────────
    console.log('\n── S2: Staff list view');
    jsErrors.length = 0;
    const listResp = await page.goto(`${BASE}/staff`);
    verdict('S2.1', 'List view HTTP 200', listResp.status() === 200 ? 'PASS' : 'FAIL', String(listResp.status()));

    const bodyText = await page.textContent('body');
    const hasFatal = bodyText.includes('Fatal error') || bodyText.includes('Parse error') || bodyText.includes('Uncaught TypeError');
    verdict('S2.2', 'No PHP fatal on list view', hasFatal ? 'FAIL' : 'PASS', hasFatal ? 'fatal in body' : '');

    // Table or empty state
    const tableExists = await page.$('#stf-table');
    const emptyState  = await page.$('.stf-empty');
    verdict('S2.3', 'Table or empty state present', (tableExists || emptyState) ? 'PASS' : 'FAIL');

    // Search input
    const searchInput = await page.$('#stf-search-input');
    verdict('S2.4', 'Search input present', searchInput ? 'PASS' : 'FAIL');

    // Status tabs
    const statusTabs = await page.$$('.stf-status-tab');
    verdict('S2.5', 'Status tabs present (Active / All incl. inactive / Trash)', statusTabs.length >= 3 ? 'PASS' : 'FAIL', `found ${statusTabs.length}`);

    // Add Staff CTA
    const createBtn = await page.$('.stf-create-btn');
    verdict('S2.6', 'Add Staff CTA present', createBtn ? 'PASS' : 'FAIL');

    // Bulk bar
    const bulkBar = await page.$('#stf-bulk-form');
    verdict('S2.7', 'Bulk action bar present', bulkBar ? 'PASS' : 'FAIL');

    // Workspace shell
    const shell = await page.$('.workspace-shell--team');
    verdict('S2.8', 'Team workspace shell rendered', shell ? 'PASS' : 'FAIL');

    verdict('S2.9', 'No JS errors on list view', jsErrors.length === 0 ? 'PASS' : 'FAIL', jsErrors.slice(0, 3).join('; '));
    await shot(page, '01-list-view');

    // ── S3: Sort URLs don't break page ────────────────────────────────────────
    console.log('\n── S3: Sort params');
    jsErrors.length = 0;
    const sortResp = await page.goto(`${BASE}/staff?sort=job_title&dir=asc`);
    verdict('S3.1', 'Sort URL HTTP 200', sortResp.status() === 200 ? 'PASS' : 'FAIL', String(sortResp.status()));
    const sortFatal = (await page.textContent('body')).includes('Fatal error') || (await page.textContent('body')).includes('Parse error');
    verdict('S3.2', 'No PHP fatal on sort URL', sortFatal ? 'FAIL' : 'PASS');
    verdict('S3.3', 'No JS errors on sort URL', jsErrors.length === 0 ? 'PASS' : 'FAIL', jsErrors.slice(0, 3).join('; '));
    await shot(page, '02-sort-view');

    // ── S4: Inactive view ─────────────────────────────────────────────────────
    console.log('\n── S4: Inactive view (?active=0)');
    jsErrors.length = 0;
    const inactiveResp = await page.goto(`${BASE}/staff?active=0`);
    verdict('S4.1', 'Inactive view HTTP 200', inactiveResp.status() === 200 ? 'PASS' : 'FAIL', String(inactiveResp.status()));
    const inactiveFatal = (await page.textContent('body')).includes('Fatal error') || (await page.textContent('body')).includes('Parse error');
    verdict('S4.2', 'No PHP fatal on inactive view', inactiveFatal ? 'FAIL' : 'PASS');

    // "All incl. inactive" tab should be active
    const inactiveTabActive = await page.evaluate(() => {
        const tabs = document.querySelectorAll('.stf-status-tab--active');
        return Array.from(tabs).some(t => t.textContent.includes('incl. inactive'));
    });
    verdict('S4.3', '"All incl. inactive" tab is active', inactiveTabActive ? 'PASS' : 'FAIL');
    verdict('S4.4', 'No JS errors on inactive view', jsErrors.length === 0 ? 'PASS' : 'FAIL', jsErrors.slice(0, 3).join('; '));
    await shot(page, '03-inactive-view');

    // ── S5: Trash view ────────────────────────────────────────────────────────
    console.log('\n── S5: Trash view');
    jsErrors.length = 0;
    const trashResp = await page.goto(`${BASE}/staff?status=trash`);
    verdict('S5.1', 'Trash view HTTP 200', trashResp.status() === 200 ? 'PASS' : 'FAIL', String(trashResp.status()));
    const trashFatal = (await page.textContent('body')).includes('Fatal error') || (await page.textContent('body')).includes('Parse error');
    verdict('S5.2', 'No PHP fatal on trash view', trashFatal ? 'FAIL' : 'PASS');

    // Add Staff CTA should NOT be present in trash
    const noCreateBtn = await page.$('.stf-create-btn');
    verdict('S5.3', 'Add Staff CTA hidden in trash', !noCreateBtn ? 'PASS' : 'FAIL');

    // Search input should NOT be present in trash
    const noSearch = await page.$('#stf-search-input');
    verdict('S5.4', 'Search hidden in trash mode', !noSearch ? 'PASS' : 'FAIL');

    // Trash tab should be active
    const trashTabActive = await page.evaluate(() => {
        const tabs = document.querySelectorAll('.stf-status-tab--active');
        return Array.from(tabs).some(t => t.textContent.includes('Trash'));
    });
    verdict('S5.5', 'Trash tab is active', trashTabActive ? 'PASS' : 'FAIL');
    verdict('S5.6', 'No JS errors on trash view', jsErrors.length === 0 ? 'PASS' : 'FAIL', jsErrors.slice(0, 3).join('; '));
    await shot(page, '04-trash-view');

    // ── S6: Existing flows ────────────────────────────────────────────────────
    console.log('\n── S6: Existing flows');
    const createResp = await page.goto(`${BASE}/staff/create`);
    verdict('S6.1', 'Staff create form loads', createResp.status() === 200 ? 'PASS' : 'FAIL', String(createResp.status()));

    const groupsResp = await page.goto(`${BASE}/staff/groups/admin`);
    verdict('S6.2', 'Staff groups page loads', groupsResp.status() === 200 ? 'PASS' : 'FAIL', String(groupsResp.status()));
    await shot(page, '05-regression');

    // ── Results ───────────────────────────────────────────────────────────────
    console.log('\n── RESULTS ──────────────────────────────────────────────────────────────');
    const passes = results.filter(r => r.status === 'PASS').length;
    const fails  = results.filter(r => r.status === 'FAIL').length;
    console.log(`  PASS: ${passes}  FAIL: ${fails}  TOTAL: ${results.length}`);

    const resultPath = 'stf-workspace-smoke-results.json';
    fs.writeFileSync(resultPath, JSON.stringify({ timestamp: new Date().toISOString(), results }, null, 2));
    console.log(`  Results written to ${resultPath}`);

    if (fails > 0) {
        console.log('\n  FAILED CHECKS:');
        results.filter(r => r.status === 'FAIL').forEach(r => {
            console.log(`    [✗] ${r.id} — ${r.label}: ${r.detail}`);
        });
    }

    await browser.close();
    process.exit(fails > 0 ? 1 : 0);
})();
