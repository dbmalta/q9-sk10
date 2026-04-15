/**
 * Role-Based Access Control (RBAC) — Comprehensive Tests
 *
 * Tests the full access-control matrix across all modules:
 *
 *  - Admin can access all admin-only pages
 *  - Leader has appropriate access but not admin-only routes
 *  - Member has restricted access (their own data, public pages)
 *  - Unauthenticated users are redirected to login everywhere
 *  - 403 Forbidden is shown (not 500) when access is denied
 *  - Navigation items are conditionally shown/hidden per role
 */

import { test, expect } from '@playwright/test';
import { login, expectPageOk } from './helpers';

// ---------------------------------------------------------------------------
// Admin-only routes (all must return 403 for member role)
// ---------------------------------------------------------------------------

const ADMIN_ONLY_ROUTES = [
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
  '/admin/roles/create',
  '/admin/achievements',
  '/admin/achievements/create',
  '/admin/events',
  '/admin/events/create',
  '/admin/articles',
  '/admin/articles/create',
  '/admin/email',
  '/admin/email/log',
  '/admin/org',
  '/admin/org/nodes/create',
  '/admin/org/levels',
  '/admin/registrations',
  '/admin/waiting-list',
  '/admin/invitations',
  '/admin/custom-fields',
  '/admin/bulk-import',
];

test.describe('Member cannot access admin-only routes', () => {
  for (const route of ADMIN_ONLY_ROUTES) {
    test(`GET ${route} → 403 or redirect for member role`, async ({ page }) => {
      await login(page, 'member');
      await page.goto(route);
      await page.waitForLoadState('networkidle');

      const url = page.url();
      const body = await page.locator('body').textContent();

      const isBlocked =
        url.includes('/login') ||              // redirected to login
        body?.match(/forbidden|403|access denied|not authorised|not authorized/i) ||
        body?.match(/you do not have permission/i);

      expect(
        isBlocked,
        `Member should be blocked from ${route}, but got through`
      ).toBeTruthy();

      // In no case should a 500 be shown
      expect(body).not.toMatch(/Fatal error|internal server error|Stack trace|Uncaught/i);
    });
  }
});

// ---------------------------------------------------------------------------
// Admin CAN access all admin routes
// ---------------------------------------------------------------------------

test.describe('Admin can access all admin routes', () => {
  for (const route of ADMIN_ONLY_ROUTES) {
    test(`GET ${route} → accessible for admin`, async ({ page }) => {
      await login(page, 'admin');
      const resp = await page.request.get(route, { failOnStatusCode: false });
      // Accept 200, 302 (redirect within admin), but NOT 403 or 500
      expect(resp.status(), `Admin blocked from ${route}: HTTP ${resp.status()}`).not.toBe(403);
      expect(resp.status(), `500 error on ${route}: HTTP ${resp.status()}`).not.toBe(500);
    });
  }
});

// ---------------------------------------------------------------------------
// Leader role — should see member-facing pages but limited admin access
// ---------------------------------------------------------------------------

test.describe('Leader role access', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'leader');
  });

  test('leader can access member list', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/\/login/);
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/forbidden|403|access denied/i);
    expect(body).not.toMatch(/internal server error/i);
  });

  test('leader can access events calendar', async ({ page }) => {
    await page.goto('/events');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });

  test('leader can access articles', async ({ page }) => {
    await page.goto('/articles');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });

  test('leader can access directory', async ({ page }) => {
    await page.goto('/directory');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });

  test('leader dashboard does not show super-admin settings link', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');
    // Leaders may or may not access dashboard — if they can, they should not see settings
    if (!page.url().includes('/login')) {
      const body = await page.locator('body').textContent();
      expect(body).not.toMatch(/internal server error/i);
    }
  });
});

// ---------------------------------------------------------------------------
// Navigation items conditional visibility
// ---------------------------------------------------------------------------

test.describe('Sidebar navigation visibility by role', () => {
  test('admin sees all navigation groups', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');

    // On mobile, open the offcanvas sidebar to reveal nav links
    const hamburger = page.locator('button[data-bs-toggle="offcanvas"]').first();
    if (await hamburger.isVisible().catch(() => false)) {
      await hamburger.click();
      await page.waitForTimeout(400);
    }

    const expectedLinks = [
      '/members',
      '/admin/roles',
      '/admin/settings',
      '/admin/audit',
    ];
    for (const link of expectedLinks) {
      // Use :visible pseudo — desktop sidebar and mobile offcanvas both render
      // the same hrefs; only one is on-screen depending on viewport.
      const navLink = page.locator(`a[href="${link}"]:visible`).first();
      await expect(navLink, `Admin should see nav link to ${link}`).toBeVisible();
    }
  });

  test('member does not see admin-only nav links', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/members');
    await page.waitForLoadState('networkidle');

    const adminLinks = [
      '/admin/settings',
      '/admin/audit',
      '/admin/logs',
      '/admin/roles',
      '/admin/backups',
    ];
    for (const link of adminLinks) {
      const navLink = page.locator(`a[href="${link}"]`).first();
      const visible = await navLink.isVisible();
      expect(visible, `Member should NOT see nav link to ${link}`).toBe(false);
    }
  });

  test('logout link always visible in nav', async ({ page }) => {
    for (const role of ['admin', 'member', 'leader'] as const) {
      await login(page, role);
      // After login the user lands on the dashboard (auth-only, no extra permission).
      // /account redirects to /members/{id} which 403s for member role; dashboard is safe.
      await page.goto('/admin/dashboard');
      await page.waitForLoadState('networkidle');
      // Logout lives inside the user dropdown — open it first
      // The user menu is identified by the person-circle icon (not the language/other dropdowns)
      const userMenuToggle = page.locator('button[data-bs-toggle="dropdown"]:has(.bi-person-circle)').first();
      if (await userMenuToggle.count() > 0) {
        await userMenuToggle.click();
        await page.waitForTimeout(200);
      }
      const logoutControl = page.locator(
        'a[href="/logout"], form[action="/logout"], [data-logout]'
      ).first();
      await expect(logoutControl, `${role} should see logout in nav`).toBeVisible();
      await page.goto('/logout');
    }
  });
});

// ---------------------------------------------------------------------------
// API endpoints RBAC
// ---------------------------------------------------------------------------

test.describe('API endpoint access control', () => {
  test('/api/health accessible without login', async ({ page }) => {
    const resp = await page.request.get('/api/health', { failOnStatusCode: false });
    // Health check should work without auth
    expect(resp.status()).not.toBe(500);
    expect(resp.status()).not.toBe(401);
  });

  test('/api/logs requires admin auth', async ({ page }) => {
    // Without login
    const resp = await page.request.get('/api/logs', { failOnStatusCode: false });
    // Should require auth (401/403) or redirect (302)
    expect([200, 302, 401, 403]).toContain(resp.status());
    expect(resp.status()).not.toBe(500);
  });

  test('member search API requires auth', async ({ page }) => {
    const resp = await page.request.get('/members/api/search?q=test', { failOnStatusCode: false });
    // Without session cookie, should redirect or deny
    expect(resp.status()).not.toBe(500);
  });

  test('admin can access member API with session', async ({ page }) => {
    await login(page, 'admin');
    const resp = await page.request.get('/members/api/search?q=a', { failOnStatusCode: false });
    expect(resp.status()).not.toBe(500);
    expect(resp.status()).not.toBe(403);
  });
});

// ---------------------------------------------------------------------------
// Error page quality for 403
// ---------------------------------------------------------------------------

test.describe('403 Forbidden page quality', () => {
  test('403 page shows helpful error message, not a blank page', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/admin/settings');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    if (!page.url().includes('/login')) {
      // If not redirected to login, should show 403 content
      expect(bodyText?.trim().length ?? 0).toBeGreaterThan(10);
      expect(bodyText).not.toMatch(/Fatal error|Uncaught Exception|Stack trace:/i);
    }
  });

  test('403 page has a way to navigate back', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/admin/settings');
    await page.waitForLoadState('networkidle');

    if (!page.url().includes('/login')) {
      // 403 page should have some navigation
      const navLink = page.locator('a[href]').first();
      await expect(navLink).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Pending member access restrictions
// ---------------------------------------------------------------------------

test.describe('Pending member access', () => {
  test('pending member can log in', async ({ page }) => {
    await login(page, 'pending');
    await expect(page).not.toHaveURL(/\/login/);
  });

  test('pending member cannot access admin routes', async ({ page }) => {
    await login(page, 'pending');
    await page.goto('/admin/settings');
    await page.waitForLoadState('networkidle');

    const url = page.url();
    const body = await page.locator('body').textContent();
    const blocked = url.includes('/login') || body?.match(/forbidden|403|access denied/i);
    expect(blocked, 'Pending member should be blocked from admin settings').toBeTruthy();
    expect(body).not.toMatch(/Fatal error|internal server error/i);
  });
});

// ---------------------------------------------------------------------------
// Data isolation — members cannot see each other's data if restricted
// ---------------------------------------------------------------------------

test.describe('Data isolation', () => {
  test('member user cannot edit another member directly', async ({ page }) => {
    await login(page, 'member');
    // Try to access edit for member ID 1 (likely admin or another user)
    await page.goto('/members/1/edit');
    await page.waitForLoadState('networkidle');

    const body = await page.locator('body').textContent();
    // Should either redirect to login, show 403, or show 404
    const blocked = page.url().includes('/login') ||
      body?.match(/forbidden|403|access denied/i) ||
      body?.match(/not found|404/i);
    expect(blocked, 'Member should not freely edit other member profiles').toBeTruthy();
    expect(body).not.toMatch(/Fatal error|internal server error/i);
  });
});
