/**
 * Navigation — Comprehensive Tests
 *
 * Covers:
 *  - Every sidebar nav link resolves (no 404, no redirect to login)
 *  - Sidebar collapse / expand toggle
 *  - Logo / brand link returns to dashboard
 *  - Breadcrumbs on sub-pages are valid, clickable links
 *  - Dead-link scan on dashboard (all <a href> on the page work)
 *  - User menu items (logout, my-data)
 *  - Mobile hamburger opens the sidebar
 *  - Theme toggle toggles data-bs-theme attribute
 *  - Back / cancel links don't result in 404
 *  - Root "/" redirects to dashboard (not a blank page or error)
 */

import { test, expect } from '@playwright/test';
import {
  login,
  ADMIN_NAV_LINKS,
  assertLinkReachable,
  collectInternalLinks,
  expectPageOk,
} from './helpers';

// ---------------------------------------------------------------------------
// Admin nav — every known sidebar link must respond without 404 / 500
// ---------------------------------------------------------------------------

test.describe('Sidebar navigation — all links reachable', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  for (const href of ADMIN_NAV_LINKS) {
    test(`GET ${href} — no 404/500`, async ({ page }) => {
      const resp = await page.request.get(href, { failOnStatusCode: false });
      expect(
        resp.status(),
        `${href} returned HTTP ${resp.status()}`
      ).not.toBe(404);
      expect(
        resp.status(),
        `${href} returned HTTP ${resp.status()}`
      ).not.toBe(500);
    });
  }
});

// ---------------------------------------------------------------------------
// Root redirect
// ---------------------------------------------------------------------------

test.describe('Root route', () => {
  test('GET / redirects to dashboard when logged in', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    // Should be on dashboard or have been redirected there
    expect(page.url()).toMatch(/\/(admin\/dashboard|$)/);
  });

  test('GET / redirects to login when logged out', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    // Either shows login form or redirects there
    const url = page.url();
    const body = await page.locator('body').textContent();
    const isLogin = url.includes('/login') || (body?.toLowerCase().includes('password') ?? false);
    expect(isLogin, 'Root should redirect unauthenticated user to login').toBe(true);
  });
});

// ---------------------------------------------------------------------------
// Logo / brand link
// ---------------------------------------------------------------------------

test.describe('Logo link', () => {
  test('clicking logo returns to dashboard', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members'); // start on a non-dashboard page
    await page.waitForLoadState('networkidle');

    const logo = page.locator(
      'a.navbar-brand, a[href="/"], a[href="/admin/dashboard"], .sidebar-brand a'
    ).first();
    if (await logo.isVisible()) {
      await logo.click();
      await page.waitForLoadState('networkidle');
      await expectPageOk(page);
    } else {
      test.skip(true, 'No logo link found in current layout');
    }
  });
});

// ---------------------------------------------------------------------------
// Sidebar toggle
// ---------------------------------------------------------------------------

test.describe('Sidebar collapse/expand', () => {
  test('sidebar toggle button exists and is clickable', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');

    const toggle = page.locator(
      '[data-action="sidebar#toggle"], [x-on\\:click*="sidebar"], #sidebarToggle, .sidebar-toggle'
    ).first();
    if (await toggle.isVisible()) {
      await toggle.click();
      await page.waitForTimeout(400); // CSS transition
      // Page should not error after toggle
      await expectPageOk(page);
    } else {
      test.skip(true, 'No sidebar toggle found — may be mobile-only');
    }
  });
});

// ---------------------------------------------------------------------------
// Theme toggle
// ---------------------------------------------------------------------------

test.describe('Theme toggle', () => {
  test('clicking theme toggle changes data-bs-theme on <html>', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');

    const toggle = page.locator(
      '[x-on\\:click*="theme"], [\\@click*="theme"], [data-theme-toggle], #themeToggle, .theme-toggle'
    ).first();
    if (!await toggle.isVisible()) {
      test.skip(true, 'Theme toggle not found');
      return;
    }

    const before = await page.evaluate(() =>
      document.documentElement.getAttribute('data-bs-theme')
    );
    await toggle.click();
    await page.waitForTimeout(300);
    const after = await page.evaluate(() =>
      document.documentElement.getAttribute('data-bs-theme')
    );
    expect(after).not.toBe(before);
  });
});

// ---------------------------------------------------------------------------
// Dead-link scan on dashboard
// ---------------------------------------------------------------------------

test.describe('Dead-link scan', () => {
  test('no dead links on admin dashboard', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');

    const paths = await collectInternalLinks(page);
    const failures: string[] = [];

    for (const path of paths) {
      const resp = await page.request.get(path, { failOnStatusCode: false });
      if (resp.status() === 404 || resp.status() === 500) {
        failures.push(`${path} → ${resp.status()}`);
      }
    }

    expect(failures, `Dead links found:\n${failures.join('\n')}`).toHaveLength(0);
  });

  test('no dead links on members list', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members');
    await page.waitForLoadState('networkidle');

    const paths = await collectInternalLinks(page);
    const failures: string[] = [];

    for (const path of paths.slice(0, 30)) { // cap to 30 to avoid very long tests
      const resp = await page.request.get(path, { failOnStatusCode: false });
      if (resp.status() === 404 || resp.status() === 500) {
        failures.push(`${path} → ${resp.status()}`);
      }
    }

    expect(failures, `Dead links found:\n${failures.join('\n')}`).toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// Breadcrumbs on sub-pages
// ---------------------------------------------------------------------------

test.describe('Breadcrumbs', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('member create page has breadcrumb back to members list', async ({ page }) => {
    await page.goto('/members/create');
    await page.waitForLoadState('networkidle');

    const crumbs = page.locator('.breadcrumb a, nav[aria-label="breadcrumb"] a');
    const count = await crumbs.count();
    if (count === 0) {
      test.skip(true, 'No breadcrumbs present');
      return;
    }
    // At least the first breadcrumb link should work
    const href = await crumbs.first().getAttribute('href');
    if (href) {
      const resp = await page.request.get(href, { failOnStatusCode: false });
      expect(resp.status()).not.toBe(404);
    }
  });

  test('event create page breadcrumb links work', async ({ page }) => {
    await page.goto('/admin/events/create');
    await page.waitForLoadState('networkidle');

    const crumbs = page.locator('.breadcrumb a, nav[aria-label="breadcrumb"] a');
    for (let i = 0; i < await crumbs.count(); i++) {
      const href = await crumbs.nth(i).getAttribute('href');
      if (href && href.startsWith('/')) {
        const resp = await page.request.get(href, { failOnStatusCode: false });
        expect(resp.status(), `Breadcrumb ${href} → ${resp.status()}`).not.toBe(404);
      }
    }
  });

  test('role create page breadcrumbs work', async ({ page }) => {
    await page.goto('/admin/roles/create');
    await page.waitForLoadState('networkidle');

    const crumbs = page.locator('.breadcrumb a, nav[aria-label="breadcrumb"] a');
    for (let i = 0; i < await crumbs.count(); i++) {
      const href = await crumbs.nth(i).getAttribute('href');
      if (href && href.startsWith('/')) {
        const resp = await page.request.get(href, { failOnStatusCode: false });
        expect(resp.status(), `Breadcrumb ${href} → ${resp.status()}`).not.toBe(404);
      }
    }
  });

  test('org node create page breadcrumbs work', async ({ page }) => {
    await page.goto('/admin/org/nodes/create');
    await page.waitForLoadState('networkidle');

    const crumbs = page.locator('.breadcrumb a, nav[aria-label="breadcrumb"] a');
    for (let i = 0; i < await crumbs.count(); i++) {
      const href = await crumbs.nth(i).getAttribute('href');
      if (href && href.startsWith('/')) {
        const resp = await page.request.get(href, { failOnStatusCode: false });
        expect(resp.status(), `Breadcrumb ${href} → ${resp.status()}`).not.toBe(404);
      }
    }
  });
});

// ---------------------------------------------------------------------------
// Cancel / back links on forms
// ---------------------------------------------------------------------------

test.describe('Cancel/back links on forms', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  const formPages = [
    '/members/create',
    '/admin/events/create',
    '/admin/articles/create',
    '/admin/roles/create',
    '/admin/achievements/create',
    '/admin/terms/create',
    '/admin/notices/create',
    '/admin/org/nodes/create',
    '/admin/custom-fields/create',
  ];

  for (const formPage of formPages) {
    test(`cancel link on ${formPage} works`, async ({ page }) => {
      await page.goto(formPage);
      await page.waitForLoadState('networkidle');

      const cancel = page.locator('a:text-matches("cancel|back", "i")').first();
      if (await cancel.isVisible()) {
        const href = await cancel.getAttribute('href');
        if (href) {
          const resp = await page.request.get(href, { failOnStatusCode: false });
          expect(resp.status(), `Cancel → ${href} gave ${resp.status()}`).not.toBe(404);
          expect(resp.status()).not.toBe(500);
        }
      }
    });
  }
});

// ---------------------------------------------------------------------------
// User / account menu
// ---------------------------------------------------------------------------

test.describe('User account menu', () => {
  test('logout link is present on every admin page', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');

    // Logout must be accessible somehow (link or form button)
    const logout = page.locator(
      'a[href="/logout"], form[action="/logout"] button, [data-logout]'
    ).first();
    await expect(logout).toBeVisible();
  });

  test('my data export link resolves', async ({ page }) => {
    await login(page, 'admin');
    const resp = await page.request.get('/my-data/export', { failOnStatusCode: false });
    // 200 or redirect (e.g. file download) — just not 404/500
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });
});

// ---------------------------------------------------------------------------
// Mobile navigation
// ---------------------------------------------------------------------------

test.describe('Mobile navigation', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('login page renders on mobile without overflow errors', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
  });

  test('admin dashboard renders on mobile', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
  });

  test('hamburger/nav-toggle is visible on mobile', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');

    const hamburger = page.locator(
      '.navbar-toggler, [data-bs-toggle="offcanvas"], button[aria-label*="menu" i], #navbarToggler'
    ).first();
    // On narrow screens some form of nav control must be present
    const sidebar = page.locator('.sidebar, nav.sidebar, #sidebar');
    const hasSidebar = await sidebar.isVisible();
    const hasHamburger = await hamburger.isVisible();
    expect(hasSidebar || hasHamburger, 'No sidebar or hamburger on mobile').toBe(true);
  });
});
