/**
 * SERVICES WORKSPACE SMOKE TEST
 * TASK: CATALOG-SERVICES-WORKSPACE-PRO-FOUNDATION-01
 *
 * Verifies:
 *   - Login succeeds
 *   - Services list view loads (HTTP 200, no PHP fatal)
 *   - List view shows table
 *   - Structure view loads via ?view=structure
 *   - Trash view loads via ?status=trash
 *   - Category filter form is present
 *   - View switcher links are present
 *   - Create CTA is present
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
    await page.screenshot({ path: `svc-ws-smoke-${name}.png`, fullPage: false });
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
    await shot(page, '01-login');

    // ── S2: Services list view ────────────────────────────────────────────────
    console.log('\n── S2: Services list view');
    jsErrors.length = 0;
    const listResp = await page.goto(`${BASE}/services-resources/services`);
    verdict('S2.1', 'List view HTTP 200', listResp.status() === 200 ? 'PASS' : 'FAIL', String(listResp.status()));

    // No PHP fatal
    const bodyText = await page.textContent('body');
    const hasFatal = bodyText && (bodyText.includes('Fatal error') || bodyText.includes('Parse error') || bodyText.includes('Uncaught'));
    verdict('S2.2', 'No PHP fatal on list view', hasFatal ? 'FAIL' : 'PASS', hasFatal ? 'Found fatal/error in body' : '');

    // View switcher buttons exist
    const listViewBtn = await page.$('.svc-view-btn[title="List view"]');
    const structViewBtn = await page.$('.svc-view-btn[title="Structure view"]');
    verdict('S2.3', 'List view switcher buttons present', (listViewBtn && structViewBtn) ? 'PASS' : 'FAIL');

    // Table is present (if services exist)
    const tableExists = await page.$('#svc-table');
    const emptyState = await page.$('.svc-empty');
    verdict('S2.4', 'List view renders table or empty state', (tableExists || emptyState) ? 'PASS' : 'FAIL');

    // Category filter is present
    const catFilter = await page.$('#svc-category-filter');
    verdict('S2.5', 'Category filter present', catFilter ? 'PASS' : 'FAIL');

    // Create CTA present
    const createBtn = await page.$('.svc-create-btn');
    verdict('S2.6', 'Create CTA present', createBtn ? 'PASS' : 'FAIL');

    // Status tabs present
    const statusTabs = await page.$$('.svc-status-tab');
    verdict('S2.7', 'Status tabs (Active/Trash) present', statusTabs.length >= 2 ? 'PASS' : 'FAIL', `found ${statusTabs.length}`);

    // Workspace shell nav present
    const workspaceShell = await page.$('.workspace-shell--services');
    verdict('S2.8', 'Services workspace shell rendered', workspaceShell ? 'PASS' : 'FAIL');

    // JS errors
    verdict('S2.9', 'No JS console errors on list view', jsErrors.length === 0 ? 'PASS' : 'FAIL', jsErrors.slice(0, 3).join('; '));
    await shot(page, '02-list-view');

    // ── S3: Structure view ────────────────────────────────────────────────────
    console.log('\n── S3: Structure view');
    jsErrors.length = 0;
    const structResp = await page.goto(`${BASE}/services-resources/services?view=structure`);
    verdict('S3.1', 'Structure view HTTP 200', structResp.status() === 200 ? 'PASS' : 'FAIL', String(structResp.status()));

    const structFatal = (await page.textContent('body')).includes('Fatal error') || (await page.textContent('body')).includes('Parse error');
    verdict('S3.2', 'No PHP fatal on structure view', structFatal ? 'FAIL' : 'PASS');

    const structView = await page.$('#svc-view-structure');
    verdict('S3.3', 'Structure view container present', structView ? 'PASS' : 'FAIL');

    // Structure view should be visible (not hidden)
    const structHidden = await page.evaluate(() => {
        const el = document.getElementById('svc-view-structure');
        return el ? el.classList.contains('svc-view--hidden') : null;
    });
    verdict('S3.4', 'Structure view not hidden', structHidden === false ? 'PASS' : 'FAIL', `hidden=${structHidden}`);

    // List view should be hidden
    const listHidden = await page.evaluate(() => {
        const el = document.getElementById('svc-view-list');
        return el ? el.classList.contains('svc-view--hidden') : null;
    });
    verdict('S3.5', 'List view hidden in structure mode', listHidden === true ? 'PASS' : 'FAIL', `hidden=${listHidden}`);

    const flowRoot = await page.$('#ollira-svc-flow-root');
    const mapTree = await page.$('#svc-map-tree');
    const structEmpty = await page.$('.svc-empty');
    verdict('S3.6', 'Structure view shows flow/HTML map or empty', (flowRoot || mapTree || structEmpty) ? 'PASS' : 'FAIL', `flow=${!!flowRoot} tree=${!!mapTree}`);

    verdict('S3.7', 'No JS errors on structure view', jsErrors.length === 0 ? 'PASS' : 'FAIL', jsErrors.slice(0, 3).join('; '));
    await shot(page, '03-structure-view');

    // ── S4: Trash view ────────────────────────────────────────────────────────
    console.log('\n── S4: Trash view');
    jsErrors.length = 0;
    const trashResp = await page.goto(`${BASE}/services-resources/services?status=trash`);
    verdict('S4.1', 'Trash view HTTP 200', trashResp.status() === 200 ? 'PASS' : 'FAIL', String(trashResp.status()));

    const trashFatal = (await page.textContent('body')).includes('Fatal error') || (await page.textContent('body')).includes('Parse error');
    verdict('S4.2', 'No PHP fatal on trash view', trashFatal ? 'FAIL' : 'PASS');

    // View switcher should NOT appear in trash view
    const noViewSwitcher = await page.$('.svc-view-switcher');
    verdict('S4.3', 'View switcher hidden in trash mode', !noViewSwitcher ? 'PASS' : 'FAIL');

    // Create CTA should NOT appear in trash view
    const noCreateBtn = await page.$('.svc-create-btn');
    verdict('S4.4', 'Create CTA hidden in trash mode', !noCreateBtn ? 'PASS' : 'FAIL');

    verdict('S4.5', 'No JS errors on trash view', jsErrors.length === 0 ? 'PASS' : 'FAIL', jsErrors.slice(0, 3).join('; '));
    await shot(page, '04-trash-view');

    // ── S5: Category filter (server-side) ─────────────────────────────────────
    console.log('\n── S5: Category filter');
    jsErrors.length = 0;
    // Navigate to structure view with category filter to test URL state preservation
    const catFilterResp = await page.goto(`${BASE}/services-resources/services?view=structure&category=1`);
    verdict('S5.1', 'Category+view URL does not break page', catFilterResp.status() === 200 ? 'PASS' : 'FAIL', String(catFilterResp.status()));

    const catFatal = (await page.textContent('body')).includes('Fatal error') || (await page.textContent('body')).includes('Parse error');
    verdict('S5.2', 'No PHP fatal on category filter', catFatal ? 'FAIL' : 'PASS');

    verdict('S5.3', 'No JS errors on category filter page', jsErrors.length === 0 ? 'PASS' : 'FAIL', jsErrors.slice(0, 3).join('; '));
    await shot(page, '05-cat-filter');

    // ── S6: Existing flows not broken ─────────────────────────────────────────
    console.log('\n── S6: Existing flows (create/categories still work)');
    const createResp = await page.goto(`${BASE}/services-resources/services/create`);
    verdict('S6.1', 'Service create form loads', createResp.status() === 200 ? 'PASS' : 'FAIL', String(createResp.status()));

    const catIndexResp = await page.goto(`${BASE}/services-resources/categories`);
    verdict('S6.2', 'Categories index loads', catIndexResp.status() === 200 ? 'PASS' : 'FAIL', String(catIndexResp.status()));

    await shot(page, '06-regression');

    // ── Results ───────────────────────────────────────────────────────────────
    console.log('\n── RESULTS ──────────────────────────────────────────────────────────────');
    const passes = results.filter(r => r.status === 'PASS').length;
    const fails  = results.filter(r => r.status === 'FAIL').length;
    console.log(`  PASS: ${passes}  FAIL: ${fails}  TOTAL: ${results.length}`);

    const resultPath = 'svc-workspace-smoke-results.json';
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
