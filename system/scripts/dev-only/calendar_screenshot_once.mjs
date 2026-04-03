/**
 * One-off: login + calendar screenshot (requires: npm i playwright && npx playwright install chromium in same dir, or run from parent with playwright installed).
 * Usage: node calendar_screenshot_once.mjs <baseUrl> <outPng>
 * Example: node calendar_screenshot_once.mjs http://spa-skincare-system-blueprint.test C:/laragon/www/spa-skincare-system-blueprint/calendar-proof-day.png
 */
import { chromium } from 'playwright';
import { writeFileSync } from 'fs';

const base = (process.argv[2] || 'http://spa-skincare-system-blueprint.test').replace(/\/$/, '');
const out = process.argv[3] || 'calendar-proof-day.png';
const calUrl = `${base}/appointments/calendar/day?branch_id=11&date=2026-05-01`;

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
await page.fill('input[name="email"]', 'tenant-admin-a@example.test');
await page.fill('input[name="password"]', 'TenantAdminA##2026');
await Promise.all([
  page.waitForNavigation({ waitUntil: 'networkidle', timeout: 20000 }),
  page.click('button[type="submit"]'),
]);
await page.goto(calUrl, { waitUntil: 'networkidle' });
await page.waitForTimeout(3000);
await page.screenshot({ path: out, fullPage: true });
console.log('WROTE', out);

const detailOut = out.replace(/\.png$/i, '-detail-drawer.png');
const appt = page.locator('a.ops-block-appt').first();
if (await appt.count()) {
  await appt.click();
  await page.waitForTimeout(2500);
  await page.screenshot({ path: detailOut, fullPage: true });
  console.log('WROTE', detailOut);
}

await browser.close();
