import { test, expect } from '@playwright/test';

/**
 * Remote smoke test against the public demo site.
 * Uses absolute URLs so it runs independent of the local baseURL.
 *
 * Opt-in credentials (skip login if not provided):
 *   DEMO_EMAIL / DEMO_PASSWORD
 */

const DEMO_BASE = 'https://demo.scoutkeeper.net';
const DEMO_EMAIL = process.env.DEMO_EMAIL ?? '';
const DEMO_PASSWORD = process.env.DEMO_PASSWORD ?? '';

test.describe('Demo site smoke', () => {
  test('calendar items load on /events', async ({ page }) => {
    // Hit the events page directly; if the site requires auth, log in first.
    await page.goto(`${DEMO_BASE}/events`, { waitUntil: 'domcontentloaded' });

    if (/\/login/.test(page.url())) {
      test.skip(
        !DEMO_EMAIL || !DEMO_PASSWORD,
        'DEMO_EMAIL / DEMO_PASSWORD env vars required for authenticated smoke'
      );

      await page.fill('input[name="email"]', DEMO_EMAIL);
      await page.fill('input[name="password"]', DEMO_PASSWORD);
      await page.click('form[action="/login"] button[type="submit"]');
      await page.waitForURL((u) => !/\/login/.test(u.pathname), { timeout: 10_000 });
      await page.goto(`${DEMO_BASE}/events`, { waitUntil: 'domcontentloaded' });
    }

    // Page must not surface a server error.
    const bodyText = (await page.locator('body').textContent()) ?? '';
    expect(bodyText).not.toMatch(/Fatal error|internal server error|Whoops/i);

    // A calendar/event surface should be present. We accept any of the
    // common markers rendered by FullCalendar, the Twig calendar component,
    // or the legacy list view — so the test stays resilient to UI reskins.
    const calendarMarkers = page.locator(
      [
        '[data-calendar]',
        '.fc',                       // FullCalendar root
        '.calendar',
        'table.calendar',
        'a[href*="/events/"]',       // at least one event link
        'main :text-matches("event|camp|meeting|hike", "i")',
      ].join(', ')
    );
    await expect(calendarMarkers.first()).toBeVisible({ timeout: 10_000 });

    // At least one actual event entry should be reachable from this page.
    // If the demo has zero events this will fail loudly — that is intentional.
    const eventLinks = page.locator('a[href*="/events/"]:not([href*="ical"]):not([href$="/events"])');
    expect(await eventLinks.count(), 'at least one event item should render').toBeGreaterThan(0);
  });
});
