import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

/**
 * Verifies the /events page renders actual calendar items, not just an
 * empty calendar shell. Guards against regressions where the page loads
 * but the underlying event query / Alpine data wiring is broken.
 */

test.describe('Events calendar loading', () => {
  test('calendar renders and contains event items for a member', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/events');

    // No server error and the calendar container must be present.
    const body = (await page.locator('body').textContent()) ?? '';
    expect(body).not.toMatch(/Fatal error|internal server error|Whoops/i);
    await expect(page.locator('[data-calendar]')).toBeVisible();

    // Alpine renders event badges into the day cells. NorthlandSeeder seeds
    // ~20 events across recent months, so the current (or an adjacent) month
    // should contain at least one. If today's month is empty, step forward
    // up to six months looking for events before declaring a regression.
    const items = page.locator('[data-event-item]');
    let found = (await items.count()) > 0;
    for (let i = 0; !found && i < 6; i++) {
      await page.locator('[data-calendar] a[href*="/events?year="]').last().click();
      await page.waitForLoadState('domcontentloaded');
      found = (await page.locator('[data-event-item]').count()) > 0;
    }
    expect(found, 'calendar should render at least one event badge within 6 months').toBe(true);
  });

  test('clicking a day with events reveals a detail link', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/events');

    const firstEvent = page.locator('[data-event-item]').first();
    if (!(await firstEvent.count())) {
      test.skip(true, 'No events visible in current month on seeded dataset');
      return;
    }

    // Click the day cell containing the first event (badge itself triggers
    // the parent cell's selectDate via event bubbling).
    await firstEvent.click();

    // Footer list should surface at least one anchor into event detail.
    const detailLink = page.locator('[data-calendar] a[href^="/events/"]').first();
    await expect(detailLink).toBeVisible();
    await detailLink.click();
    await expect(page.locator('body')).toContainText(/location|date|description|starts|ends/i);
  });
});
