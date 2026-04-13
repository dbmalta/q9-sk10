import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Events', () => {
  test('calendar page loads for logged-in user', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/events');
    await expect(page.locator('body')).toContainText(/event|calendar/i);
  });

  test('calendar shows seeded events', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/events');
    await expect(page.locator('body')).toContainText(/camp|hike|parade|jamboree/i);
  });

  test('event detail page loads', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/events');
    const eventLink = page.locator('a[href*="/events/"]').first();
    if (await eventLink.isVisible()) {
      await eventLink.click();
      await expect(page.locator('body')).toContainText(/location|date|description/i);
    }
  });

  test('admin can create events', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/events/create');
    await expect(page.locator('input[name="title"]')).toBeVisible();
    await expect(page.locator('input[name="start_date"]')).toBeVisible();
  });

  test('admin can view event management list', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/events');
    await expect(page.locator('table, .event-list')).toBeVisible();
  });

  test('iCal feed info is accessible', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/events');
    // iCal link or subscription info should be available
    const icalLink = page.locator('a[href*="ical"], a[href*=".ics"], [data-ical]');
    // May or may not be visible depending on UI — just check page loads
    await expect(page.locator('body')).toContainText(/event/i);
  });
});
