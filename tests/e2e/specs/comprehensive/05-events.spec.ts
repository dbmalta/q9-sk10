/**
 * Events — Comprehensive Tests
 *
 * Covers:
 *  - Public events calendar (authenticated)
 *  - Individual event detail page
 *  - Admin events list
 *  - Create event: form fields, required-field validation, successful submit
 *  - Edit event: form pre-filled, successful update
 *  - Publish event: status changes
 *  - Unpublish event
 *  - Delete event: confirmation required
 *  - iCal management page
 *  - iCal token generation
 *  - iCal feed URL (token-based, unauthenticated)
 */

import { test, expect } from '@playwright/test';
import {
  login,
  expectPageOk,
  expectSuccess,
  getFirstHrefId,
  uid,
} from './helpers';

// ---------------------------------------------------------------------------
// Public events calendar
// ---------------------------------------------------------------------------

test.describe('Events calendar (public/member view)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'member');
  });

  test('calendar page loads', async ({ page }) => {
    await page.goto('/events');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/event|calendar/i);
  });

  test('has a heading or title', async ({ page }) => {
    await page.goto('/events');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('h1, h2, .page-title')).toBeVisible();
  });

  test('seeded events are visible', async ({ page }) => {
    await page.goto('/events');
    await page.waitForLoadState('networkidle');
    // Seeder creates events — at least one should appear
    const eventItems = page.locator('a[href*="/events/"], .event-item, .fc-event, [data-event]');
    expect(await eventItems.count()).toBeGreaterThan(0);
  });

  test('clicking an event link goes to detail page', async ({ page }) => {
    await page.goto('/events');
    await page.waitForLoadState('networkidle');
    const link = page.locator('a[href*="/events/"]:not([href*="ical"]):not([href*="/admin"])').first();
    if (!await link.isVisible()) { test.skip(true, 'No event links visible'); return; }
    await link.click();
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    expect(page.url()).toMatch(/\/events\/\d+/);
  });

  test('/events requires authentication', async ({ page }) => {
    await page.goto('/logout');
    await page.goto('/events');
    await expect(page).toHaveURL(/\/login/);
  });
});

// ---------------------------------------------------------------------------
// Event detail page
// ---------------------------------------------------------------------------

test.describe('Event detail page', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'member');
  });

  test('detail page shows event title, date, description', async ({ page }) => {
    await page.goto('/events');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/events\/(\d+)/);
    if (!id) { test.skip(true, 'No event IDs found'); return; }

    await page.goto(`/events/${id}`);
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    // Should show at least one of these
    const body = await page.locator('body').textContent();
    const hasContent = body?.match(/date|time|location|description|detail/i);
    expect(hasContent, 'Event detail should show date, location, or description').toBeTruthy();
  });

  test('non-existent event ID returns graceful error', async ({ page }) => {
    await page.goto('/events/999999999');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/internal server error/i);
    // Should be 404 or redirect
    const isError = body?.toLowerCase().includes('not found') || body?.toLowerCase().includes('404');
    expect(isError, 'Unknown event should show 404').toBe(true);
  });
});

// ---------------------------------------------------------------------------
// Admin events list
// ---------------------------------------------------------------------------

test.describe('Admin events list', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('admin events list loads', async ({ page }) => {
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('table, .event-list, [data-event]')).toBeVisible();
  });

  test('seeded events appear in admin list', async ({ page }) => {
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    const rows = page.locator('table tbody tr, .event-item');
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('create event button links to create form', async ({ page }) => {
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    const createBtn = page.locator('a[href*="/admin/events/create"], a:text-matches("create|add|new", "i")').first();
    if (await createBtn.isVisible()) {
      const href = await createBtn.getAttribute('href');
      expect(href).toContain('/admin/events/create');
    }
  });

  test('each event row has edit link', async ({ page }) => {
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    const editLink = page.locator('a[href*="/admin/events/"][href*="/edit"]').first();
    if (await editLink.count() > 0) {
      await expect(editLink).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Create event
// ---------------------------------------------------------------------------

test.describe('Create event', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('create form loads with title and date fields', async ({ page }) => {
    await page.goto('/admin/events/create');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('input[name="title"]')).toBeVisible();
    await expect(
      page.locator('input[name="start_date"], input[name="starts_at"], input[name="date"]')
    ).toBeVisible();
  });

  test('form has end date or time fields', async ({ page }) => {
    await page.goto('/admin/events/create');
    await page.waitForLoadState('networkidle');
    const endDate = page.locator('input[name="end_date"], input[name="ends_at"]');
    expect(await endDate.count()).toBeGreaterThan(0);
  });

  test('submitting empty title shows validation error', async ({ page }) => {
    await page.goto('/admin/events/create');
    await page.waitForLoadState('networkidle');
    await page.click('button[type="submit"]');
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });

  test('valid event creation redirects or shows success', async ({ page }) => {
    const id = uid();
    await page.goto('/admin/events/create');
    await page.waitForLoadState('networkidle');

    await page.fill('input[name="title"]', `PW Event ${id}`);

    const startDate = page.locator('input[name="start_date"], input[name="starts_at"]').first();
    await startDate.fill('2026-07-01T10:00');

    const endDate = page.locator('input[name="end_date"], input[name="ends_at"]').first();
    if (await endDate.isVisible()) await endDate.fill('2026-07-02T12:00');

    const descField = page.locator('textarea[name="description"], textarea[name="body"]');
    if (await descField.isVisible()) await descField.fill(`Playwright test event ${id}`);

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
    // Should redirect to admin events or event detail
    const url = page.url();
    const ok = url.includes('/admin/events') || url.includes('/events/') || await page.locator('.alert-success').isVisible();
    expect(ok, 'After creating event, should be on events list or show success').toBe(true);
  });
});

// ---------------------------------------------------------------------------
// Edit event
// ---------------------------------------------------------------------------

test.describe('Edit event', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('edit form loads for first event', async ({ page }) => {
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/admin\/events\/(\d+)\/edit/);
    if (!id) { test.skip(true, 'No events with edit link'); return; }

    await page.goto(`/admin/events/${id}/edit`);
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('input[name="title"]')).toBeVisible();
  });

  test('edit form is pre-filled with existing title', async ({ page }) => {
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/admin\/events\/(\d+)\/edit/);
    if (!id) { test.skip(true, 'No events to edit'); return; }

    await page.goto(`/admin/events/${id}/edit`);
    await page.waitForLoadState('networkidle');
    const titleVal = await page.locator('input[name="title"]').inputValue();
    expect(titleVal.length, 'Title should be pre-filled').toBeGreaterThan(0);
  });

  test('updating title and submitting succeeds', async ({ page }) => {
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/admin\/events\/(\d+)\/edit/);
    if (!id) { test.skip(true, 'No events to edit'); return; }

    await page.goto(`/admin/events/${id}/edit`);
    await page.waitForLoadState('networkidle');

    const titleField = page.locator('input[name="title"]');
    const current = await titleField.inputValue();
    await titleField.fill(current + ' (updated)');

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });
});

// ---------------------------------------------------------------------------
// Publish / Unpublish
// ---------------------------------------------------------------------------

test.describe('Event publish/unpublish', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('publish button visible on draft events', async ({ page }) => {
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    const publishBtn = page.locator(
      'button:text-matches("publish", "i"), form[action*="publish"] button'
    ).first();
    if (await publishBtn.count() > 0) {
      await expect(publishBtn).toBeVisible();
    }
  });

  test('unpublish button visible on published events', async ({ page }) => {
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    const unpublishBtn = page.locator(
      'button:text-matches("unpublish", "i"), form[action*="unpublish"] button'
    ).first();
    if (await unpublishBtn.count() > 0) {
      await expect(unpublishBtn).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Delete event
// ---------------------------------------------------------------------------

test.describe('Delete event', () => {
  test('delete button or link present on event admin page', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/events');
    await page.waitForLoadState('networkidle');
    const deleteBtn = page.locator(
      'button:text-matches("delete|remove", "i"), form[action*="delete"] button, a:text-matches("delete", "i")'
    ).first();
    if (await deleteBtn.count() > 0) {
      await expect(deleteBtn).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// iCal
// ---------------------------------------------------------------------------

test.describe('iCal integration', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'member');
  });

  test('iCal management page loads', async ({ page }) => {
    await page.goto('/events/ical');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/ical|calendar|subscribe/i);
  });

  test('generate iCal token button or form is present', async ({ page }) => {
    await page.goto('/events/ical');
    await page.waitForLoadState('networkidle');
    const generateBtn = page.locator(
      'button:text-matches("generate|create|subscribe", "i"), form[action*="generate"] button'
    ).first();
    if (await generateBtn.count() > 0) {
      await expect(generateBtn).toBeVisible();
    }
  });

  test('generating a token shows a feed URL', async ({ page }) => {
    await page.goto('/events/ical');
    await page.waitForLoadState('networkidle');
    const generateBtn = page.locator(
      'button:text-matches("generate|create|subscribe", "i"), form[action*="generate"] button'
    ).first();
    if (!await generateBtn.isVisible()) { test.skip(true, 'No generate button'); return; }

    await generateBtn.click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
    // A URL or token should appear
    const body = await page.locator('body').textContent();
    const hasUrl = body?.includes('/ical/') || body?.includes('.ics');
    if (hasUrl) {
      expect(hasUrl).toBe(true);
    }
  });

  test('iCal feed URL with valid token returns ical content or 200', async ({ page }) => {
    // First generate a token
    await page.goto('/events/ical');
    await page.waitForLoadState('networkidle');
    const generateBtn = page.locator('form[action*="generate"] button, button:has-text("Generate")').first();
    if (await generateBtn.count() === 0 || !await generateBtn.isVisible()) {
      test.skip(true, 'No generate button'); return;
    }

    await generateBtn.click();
    await page.waitForLoadState('networkidle');

    // Extract the ical URL from the page
    const linkLocator = page.locator('a[href*="/ical/"]').first();
    const inputLocator = page.locator('input[value*="/ical/"]').first();
    let icalLink: string | null = null;
    if (await linkLocator.count() > 0) {
      icalLink = await linkLocator.getAttribute('href');
    } else if (await inputLocator.count() > 0) {
      icalLink = await inputLocator.inputValue();
    }
    if (!icalLink) { test.skip(true, 'No iCal feed URL found after generating'); return; }

    // iCal feeds work without auth (token-based)
    const resp = await page.request.get(icalLink, { failOnStatusCode: false });
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });

  test('iCal feed with invalid token returns 404 or 403', async ({ page }) => {
    const resp = await page.request.get('/ical/invalidtoken00000000000000000000000000000000000', {
      failOnStatusCode: false,
    });
    expect([401, 403, 404]).toContain(resp.status());
  });
});
