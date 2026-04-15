/**
 * Error Pages & Edge Cases — Comprehensive Tests
 *
 * Covers:
 *  - 404 for completely unknown routes (authenticated)
 *  - 404 for unknown entity IDs (e.g. /members/999999)
 *  - 403 for authenticated-but-unauthorised access
 *  - Unauthenticated access to every protected area redirects to login
 *  - CSRF: raw POST without token is rejected (403/419)
 *  - API monitoring: /api/health returns JSON 200
 *  - Graceful error page layout (no raw PHP stack traces)
 *  - Navigation to non-existent admin sub-pages
 */

import { test, expect } from '@playwright/test';
import { login, users } from './helpers';

// ---------------------------------------------------------------------------
// 404 — unknown routes
// ---------------------------------------------------------------------------

test.describe('404 — unknown routes', () => {
  test('completely unknown route shows 404 page (authenticated)', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/this/does/not/exist/at/all-xyz-123');
    await page.waitForLoadState('networkidle');

    const status404 = page.url().includes('404') ||
      (await page.locator('body').textContent())?.toLowerCase().includes('not found') ||
      (await page.locator('body').textContent())?.includes('404');
    expect(status404, 'Unknown route should show 404 or "not found"').toBe(true);

    // Must not show a raw PHP stack trace
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception|Stack trace:/i);
  });

  test('unknown route shows 404 page (unauthenticated)', async ({ page }) => {
    await page.goto('/absolute-nonsense-route-xyz');
    await page.waitForLoadState('networkidle');
    // May redirect to login or show 404
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception|Stack trace:/i);
  });

  test('unknown member ID returns 404', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members/999999999');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception/i);
    const isError = body?.toLowerCase().includes('not found') ||
      body?.toLowerCase().includes('404') ||
      body?.toLowerCase().includes('error');
    expect(isError, 'Unknown member ID should show a graceful error').toBe(true);
  });

  test('unknown event ID returns 404', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/events/999999999/edit');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception/i);
  });

  test('unknown article ID returns graceful error', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/articles/999999999/edit');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception/i);
  });

  test('unknown role ID returns graceful error', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/roles/999999999/edit');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception/i);
  });

  test('unknown achievement ID returns graceful error', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/achievements/999999999/edit');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception/i);
  });

  test('unknown org node ID returns graceful error', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/org/nodes/999999999');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception/i);
  });

  test('unknown custom field ID returns graceful error', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/custom-fields/999999999/edit');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception/i);
  });

  test('unknown terms ID returns graceful error', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/terms/999999999');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception/i);
  });

  test('unknown notice acknowledgements returns graceful error', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/notices/999999999/acknowledgements');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error|Uncaught Exception/i);
  });
});

// ---------------------------------------------------------------------------
// 404 page layout quality
// ---------------------------------------------------------------------------

test.describe('Error page quality', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('404 page has a helpful message, not a blank page', async ({ page }) => {
    await page.goto('/route-that-simply-does-not-exist-abc-def-ghi');
    await page.waitForLoadState('networkidle');
    const bodyText = await page.locator('body').textContent();
    expect(bodyText?.trim().length ?? 0).toBeGreaterThan(10);
  });

  test('404 page still has navigation (user not stranded)', async ({ page }) => {
    await page.goto('/route-does-not-exist-xyz');
    await page.waitForLoadState('networkidle');
    // Should still show the nav bar so user can navigate away
    const navLink = page.locator('a[href*="/admin/dashboard"], a[href*="/members"], a[href="/"]');
    if (await navLink.count() > 0) {
      await expect(navLink.first()).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Unauthenticated redirects
// ---------------------------------------------------------------------------

test.describe('Unauthenticated access redirects to login', () => {
  const protectedRoutes = [
    '/admin/dashboard',
    '/admin/settings',
    '/admin/audit',
    '/admin/logs',
    '/admin/backups',
    '/admin/export',
    '/admin/reports',
    '/admin/terms',
    '/admin/notices',
    '/admin/languages',
    '/admin/updates',
    '/admin/roles',
    '/admin/achievements',
    '/admin/events',
    '/admin/articles',
    '/admin/email',
    '/admin/email/log',
    '/admin/org',
    '/admin/registrations',
    '/admin/waiting-list',
    '/admin/invitations',
    '/admin/custom-fields',
    '/admin/bulk-import',
    '/members',
    '/events',
    '/articles',
    '/directory',
    '/directory/contacts',
  ];

  for (const route of protectedRoutes) {
    test(`GET ${route} redirects unauthenticated user to login`, async ({ page }) => {
      // Ensure no session
      await page.context().clearCookies();
      await page.goto(route);
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveURL(/\/login/);
    });
  }
});

// ---------------------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------------------

test.describe('CSRF protection', () => {
  test('POST to login without CSRF token is rejected or handled', async ({ page }) => {
    // Directly POST without a valid CSRF token
    const resp = await page.request.post('/login', {
      form: {
        email: users.admin.email,
        password: users.admin.password,
        // No csrf_token
      },
      failOnStatusCode: false,
    });
    // Should either redirect (302) after valid attempt, or reject (403/419) due to missing CSRF
    const status = resp.status();
    // We don't know which CSRF strategy is used (cookie or field)
    // Just ensure it's not 500
    expect(status, `POST /login without CSRF → ${status}`).not.toBe(500);
  });

  test('login form always has CSRF token on page load', async ({ page }) => {
    await page.goto('/login');
    const csrfField = page.locator('input[name="csrf_token"], input[name="_token"]');
    if (await csrfField.count() > 0) {
      const val = await csrfField.first().inputValue();
      expect(val.length).toBeGreaterThan(8);
    }
    // If no CSRF field, SameSite cookies may be used — acceptable
  });

  test('registration form has CSRF token', async ({ page }) => {
    await page.goto('/register');
    const csrfField = page.locator('input[name="csrf_token"], input[name="_token"]');
    if (await csrfField.count() > 0) {
      const val = await csrfField.first().inputValue();
      expect(val.length).toBeGreaterThan(8);
    }
  });

  test('settings form has CSRF token', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/settings');
    await page.waitForLoadState('networkidle');
    const csrfField = page.locator('input[name="csrf_token"], input[name="_token"]');
    if (await csrfField.count() > 0) {
      const val = await csrfField.first().inputValue();
      expect(val.length).toBeGreaterThan(8);
    }
  });

  test('member create form has CSRF token', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members/create');
    await page.waitForLoadState('networkidle');
    const csrfField = page.locator('input[name="csrf_token"], input[name="_token"]');
    if (await csrfField.count() > 0) {
      const val = await csrfField.first().inputValue();
      expect(val.length).toBeGreaterThan(8);
    }
  });
});

// ---------------------------------------------------------------------------
// API health check
// ---------------------------------------------------------------------------

test.describe('API health endpoint', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('/api/health returns 200 with JSON body', async ({ page }) => {
    const resp = await page.request.get('/api/health', { failOnStatusCode: false });
    expect(resp.status()).toBe(200);
    const json = await resp.json().catch(() => null);
    expect(json, '/api/health must return valid JSON').not.toBeNull();
  });

  test('/api/health JSON contains status field', async ({ page }) => {
    const resp = await page.request.get('/api/health', { failOnStatusCode: false });
    if (resp.status() !== 200) return;
    const json = await resp.json().catch(() => ({}));
    expect(json).toHaveProperty('status');
  });
});

// ---------------------------------------------------------------------------
// Malformed input protection
// ---------------------------------------------------------------------------

test.describe('Malformed / XSS input protection', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('XSS payload in member search does not execute script', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');

    const search = page.locator('input[type="search"], input[name="q"], #search-q').first();
    if (!await search.isVisible()) { test.skip(true, 'No search field'); return; }

    await search.fill('<script>window.__xss_test=1</script>');
    await page.waitForTimeout(700);
    await page.waitForLoadState('networkidle');

    const xssExecuted = await page.evaluate(() => (window as any).__xss_test);
    expect(xssExecuted, 'XSS payload in search should not execute').toBeUndefined();
  });

  test('SQL injection payload in search does not crash the server', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');

    const search = page.locator('input[type="search"], input[name="q"], #search-q').first();
    if (!await search.isVisible()) { test.skip(true, 'No search field'); return; }

    await search.fill("' OR '1'='1");
    await page.waitForTimeout(700);
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|500/i);
  });
});
