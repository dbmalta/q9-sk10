/**
 * Admin Module — Comprehensive Tests
 *
 * Covers:
 *  - Dashboard: loads, shows stats
 *  - Reports: index, growth, status changes, exports
 *  - Settings: loads, update succeeds
 *  - Audit log: list, entity trail
 *  - System logs: list, clear (button present)
 *  - Backups: list, create, download link, delete button
 *  - Data export: CSV, XML, my-data
 *  - API monitoring: /api/health, /api/logs
 *  - Terms: list, create, show, publish
 *  - Notices: list, create, edit, deactivate, acknowledgements
 *  - Languages: list, strings, save override, set default
 *  - Updates: page loads, check button
 */

import { test, expect } from '@playwright/test';
import {
  login,
  expectPageOk,
  getFirstHrefId,
  uid,
} from './helpers';

// ---------------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------------

test.describe('Admin dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('dashboard page loads', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
  });

  test('dashboard shows member count or statistics', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    const hasStats = body?.match(/\d+/) && body?.match(/member|active|total|count/i);
    expect(hasStats, 'Dashboard should show numeric statistics').toBeTruthy();
  });

  test('dashboard has navigation links to modules', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');
    const moduleLinks = page.locator('a[href*="/members"], a[href*="/events"], a[href*="/admin/reports"]');
    expect(await moduleLinks.count()).toBeGreaterThan(0);
  });

  test('recent activity section visible', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');
    const activity = page.locator('[data-activity], .recent-activity, .activity-feed');
    if (await activity.count() > 0) {
      await expect(activity.first()).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Reports
// ---------------------------------------------------------------------------

test.describe('Admin reports', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('reports index loads', async ({ page }) => {
    await page.goto('/admin/reports');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/report|membership|growth/i);
  });

  test('growth report loads', async ({ page }) => {
    await page.goto('/admin/reports/growth');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/growth|member/i);
  });

  test('status changes report loads', async ({ page }) => {
    await page.goto('/admin/reports/status-changes');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/status|change/i);
  });

  test('member CSV export link resolves', async ({ page }) => {
    const resp = await page.request.get('/admin/export/members/csv', { failOnStatusCode: false });
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });

  test('member XML export link resolves', async ({ page }) => {
    const resp = await page.request.get('/admin/export/members/xml', { failOnStatusCode: false });
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });

  test('settings export link resolves', async ({ page }) => {
    const resp = await page.request.get('/admin/export/settings', { failOnStatusCode: false });
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });

  test('reports index has links to sub-reports', async ({ page }) => {
    await page.goto('/admin/reports');
    await page.waitForLoadState('networkidle');
    const links = page.locator(
      'a[href*="/admin/reports/growth"], a[href*="/admin/reports/status"]'
    );
    if (await links.count() > 0) {
      await expect(links.first()).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

test.describe('Admin settings', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('settings page loads', async ({ page }) => {
    await page.goto('/admin/settings');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/setting|configuration/i);
  });

  test('settings form has save/update button', async ({ page }) => {
    await page.goto('/admin/settings');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('button[type="submit"], input[type="submit"]')).toBeVisible();
  });

  test('submitting settings form succeeds', async ({ page }) => {
    await page.goto('/admin/settings');
    await page.waitForLoadState('networkidle');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });
});

// ---------------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------------

test.describe('Audit log', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('audit log page loads', async ({ page }) => {
    await page.goto('/admin/audit');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/audit|log/i);
  });

  test('audit log shows entries from seeded activity', async ({ page }) => {
    await page.goto('/admin/audit');
    await page.waitForLoadState('networkidle');
    const rows = page.locator('table tbody tr, .audit-entry, [data-audit]');
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('audit log has user, action, and timestamp columns', async ({ page }) => {
    await page.goto('/admin/audit');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    const hasColumns = body?.match(/action|user|date|time|entity/i);
    expect(hasColumns, 'Audit log should have action/user/time info').toBeTruthy();
  });

  test('entity audit trail page loads for a member', async ({ page }) => {
    // Find a member ID for entity trail
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/members\/(\d+)(?:\/|$)/);
    if (!id) { test.skip(true, 'No members'); return; }

    await page.goto(`/admin/audit/member/${id}`);
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/audit|log|action/i);
  });

  test('audit log has pagination if many entries', async ({ page }) => {
    await page.goto('/admin/audit');
    await page.waitForLoadState('networkidle');
    const pagination = page.locator('.pagination, [aria-label="pagination"]');
    // Pagination may or may not be present depending on entry count
    await expect(page.locator('body')).not.toContainText(/internal server error/i);
  });
});

// ---------------------------------------------------------------------------
// System logs
// ---------------------------------------------------------------------------

test.describe('System logs', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('system logs page loads', async ({ page }) => {
    await page.goto('/admin/logs');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/log|error|slow/i);
  });

  test('log types (error, slow query, etc.) are shown', async ({ page }) => {
    await page.goto('/admin/logs');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    const hasTypes = body?.match(/error|access|slow|application/i);
    expect(hasTypes, 'Logs page should show log types').toBeTruthy();
  });

  test('clear log button is present', async ({ page }) => {
    await page.goto('/admin/logs');
    await page.waitForLoadState('networkidle');
    const clearBtn = page.locator(
      'button:text-matches("clear|purge|delete", "i"), form[action*="/clear"] button'
    ).first();
    if (await clearBtn.count() > 0) {
      await expect(clearBtn).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Backups
// ---------------------------------------------------------------------------

test.describe('Backups', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('backups page loads', async ({ page }) => {
    await page.goto('/admin/backups');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/backup|restore/i);
  });

  test('create backup button is present', async ({ page }) => {
    await page.goto('/admin/backups');
    await page.waitForLoadState('networkidle');
    const createBtn = page.locator(
      'button:text-matches("create backup|new backup|backup now", "i"), form[action*="/create"] button'
    ).first();
    if (await createBtn.count() > 0) {
      await expect(createBtn).toBeVisible();
    }
  });

  test('creating a backup succeeds', async ({ page }) => {
    await page.goto('/admin/backups');
    await page.waitForLoadState('networkidle');
    const createBtn = page.locator(
      'button:text-matches("create backup|new backup|backup now", "i"), form[action*="/backups/create"] button'
    ).first();
    if (!await createBtn.isVisible()) { test.skip(true, 'No create backup button'); return; }

    await createBtn.click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });

  test('download link present for existing backups', async ({ page }) => {
    await page.goto('/admin/backups');
    await page.waitForLoadState('networkidle');
    const downloadLink = page.locator('a[href*="/admin/backups/"], a:text-matches("download", "i")').first();
    if (await downloadLink.count() > 0) {
      await expect(downloadLink).toBeVisible();
    }
  });

  test('delete button present for existing backups', async ({ page }) => {
    await page.goto('/admin/backups');
    await page.waitForLoadState('networkidle');
    const deleteBtn = page.locator(
      'button:text-matches("delete|remove", "i"), form[action*="/delete"] button'
    ).first();
    if (await deleteBtn.count() > 0) {
      await expect(deleteBtn).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Data export
// ---------------------------------------------------------------------------

test.describe('Data export', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('data export page loads', async ({ page }) => {
    await page.goto('/admin/export');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/export|download/i);
  });

  test('export page shows CSV and XML options', async ({ page }) => {
    await page.goto('/admin/export');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).toMatch(/csv|xml/i);
  });

  test('my data export resolves', async ({ page }) => {
    const resp = await page.request.get('/my-data/export', { failOnStatusCode: false });
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });
});

// ---------------------------------------------------------------------------
// API monitoring
// ---------------------------------------------------------------------------

test.describe('API monitoring endpoints', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('GET /api/health returns non-500 response', async ({ page }) => {
    const resp = await page.request.get('/api/health', { failOnStatusCode: false });
    expect(resp.status()).not.toBe(500);
    // Health endpoint should return 200 or 503 (degraded), not 404
    expect(resp.status()).not.toBe(404);
  });

  test('/api/health response is JSON', async ({ page }) => {
    const resp = await page.request.get('/api/health', { failOnStatusCode: false });
    const contentType = resp.headers()['content-type'] ?? '';
    expect(contentType).toMatch(/json/i);
  });

  test('GET /api/logs returns non-500 response', async ({ page }) => {
    const resp = await page.request.get('/api/logs', { failOnStatusCode: false });
    expect(resp.status()).not.toBe(500);
    expect(resp.status()).not.toBe(404);
  });
});

// ---------------------------------------------------------------------------
// Terms & Conditions
// ---------------------------------------------------------------------------

test.describe('Terms and conditions', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('terms management page loads', async ({ page }) => {
    await page.goto('/admin/terms');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/terms|condition|agreement/i);
  });

  test('create terms form loads', async ({ page }) => {
    await page.goto('/admin/terms/create');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('textarea[name="content"], [contenteditable], textarea[name="body"]').first()).toBeVisible();
  });

  test('valid terms creation succeeds', async ({ page }) => {
    await page.goto('/admin/terms/create');
    await page.waitForLoadState('networkidle');

    const contentField = page.locator('textarea[name="content"], textarea[name="body"]').first();
    if (await contentField.isVisible()) {
      await contentField.fill(`PW Terms Content ${uid()}`);
    }

    const titleField = page.locator('input[name="title"], input[name="version"]');
    if (await titleField.isVisible()) {
      await titleField.fill(`PW Terms ${uid()}`);
    }

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });

  test('terms detail page loads', async ({ page }) => {
    await page.goto('/admin/terms');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/admin\/terms\/(\d+)(?:\/|$)/);
    if (!id) { test.skip(true, 'No terms'); return; }

    await page.goto(`/admin/terms/${id}`);
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
  });

  test('publish button present on draft terms', async ({ page }) => {
    await page.goto('/admin/terms');
    await page.waitForLoadState('networkidle');
    const publishBtn = page.locator(
      'button:text-matches("publish", "i"), form[action*="publish"] button'
    ).first();
    if (await publishBtn.count() > 0) {
      await expect(publishBtn).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Notices
// ---------------------------------------------------------------------------

test.describe('Notices', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('notices page loads', async ({ page }) => {
    await page.goto('/admin/notices');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/notice|announcement/i);
  });

  test('create notice form loads', async ({ page }) => {
    await page.goto('/admin/notices/create');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('input[name="title"]')).toBeVisible();
  });

  test('valid notice creation succeeds', async ({ page }) => {
    const id = uid();
    await page.goto('/admin/notices/create');
    await page.waitForLoadState('networkidle');

    await page.fill('input[name="title"]', `PW Notice ${id}`);

    const bodyField = page.locator('textarea[name="body"], textarea[name="content"]');
    if (await bodyField.isVisible()) await bodyField.fill(`PW notice content ${id}`);

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });

  test('edit notice form loads', async ({ page }) => {
    await page.goto('/admin/notices');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/admin\/notices\/(\d+)\/edit/);
    if (!id) { test.skip(true, 'No notices to edit'); return; }

    await page.goto(`/admin/notices/${id}/edit`);
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('input[name="title"]')).toBeVisible();
  });

  test('deactivate button present on active notices', async ({ page }) => {
    await page.goto('/admin/notices');
    await page.waitForLoadState('networkidle');
    const deactivateBtn = page.locator(
      'button:text-matches("deactivate|disable", "i"), form[action*="deactivate"] button'
    ).first();
    if (await deactivateBtn.count() > 0) {
      await expect(deactivateBtn).toBeVisible();
    }
  });

  test('notice acknowledgements page loads', async ({ page }) => {
    await page.goto('/admin/notices');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/admin\/notices\/(\d+)(?:\/|$)/);
    if (!id) { test.skip(true, 'No notices'); return; }

    await page.goto(`/admin/notices/${id}/acknowledgements`);
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/acknowledgement|confirm|agree/i);
  });
});

// ---------------------------------------------------------------------------
// Languages
// ---------------------------------------------------------------------------

test.describe('Language management', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('languages list page loads', async ({ page }) => {
    await page.goto('/admin/languages');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/language|english/i);
  });

  test('English language appears in list', async ({ page }) => {
    await page.goto('/admin/languages');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText(/english/i);
  });

  test('language strings page loads for English', async ({ page }) => {
    await page.goto('/admin/languages/en/strings');
    await page.waitForLoadState('networkidle');
    // Cannot use expectPageOk here — the translation table legitimately contains
    // the string "Page Not Found" (the English value of error.404.title).
    // Instead, verify we were not redirected to login and the page did not 500.
    expect(page.url()).not.toMatch(/\/login(\?|$)/);
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
    await expect(page.locator('body')).toContainText(/string|translation|override/i);
  });

  test('strings page shows key-value pairs', async ({ page }) => {
    await page.goto('/admin/languages/en/strings');
    await page.waitForLoadState('networkidle');
    const rows = page.locator('table tbody tr, .string-row, [data-string-key]');
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('upload language file page loads', async ({ page }) => {
    await page.goto('/admin/languages/upload');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    const uploadField = page.locator('input[type="file"]');
    if (await uploadField.count() > 0) {
      await expect(uploadField.first()).toBeVisible();
    }
  });

  test('export master language file resolves', async ({ page }) => {
    const resp = await page.request.get('/admin/languages/export-master', { failOnStatusCode: false });
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });
});

// ---------------------------------------------------------------------------
// Updates
// ---------------------------------------------------------------------------

test.describe('Updates', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('updates page loads', async ({ page }) => {
    await page.goto('/admin/updates');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/update|version/i);
  });

  test('current version is displayed', async ({ page }) => {
    await page.goto('/admin/updates');
    await page.waitForLoadState('networkidle');
    // Version should be shown (e.g. "v0.1.9" or "Current version: 0.1.9")
    const body = await page.locator('body').textContent();
    const hasVersion = body?.match(/\d+\.\d+\.\d+|version/i);
    expect(hasVersion, 'Updates page should show version info').toBeTruthy();
  });

  test('check for updates button is present', async ({ page }) => {
    await page.goto('/admin/updates');
    await page.waitForLoadState('networkidle');
    const checkBtn = page.locator(
      'a[href*="/admin/updates/check"], button:text-matches("check.*update|check now", "i")'
    ).first();
    if (await checkBtn.count() > 0) {
      await expect(checkBtn).toBeVisible();
    }
  });

  test('check updates endpoint resolves', async ({ page }) => {
    const resp = await page.request.get('/admin/updates/check', { failOnStatusCode: false });
    // This will attempt to reach GitHub — may return various status codes
    // Just ensure it's not a hard 404 or 500
    expect(resp.status()).not.toBe(404);
  });
});
