/**
 * Shared helpers for the comprehensive Playwright test suite.
 *
 * These build on top of the base auth helpers and add
 * page-quality assertions, entity-navigation utilities,
 * and form helpers used across every spec file.
 */

import { Page, expect, APIRequestContext } from '@playwright/test';
export { login, logout, users } from '../../helpers/auth';
export type { UserRole } from '../../helpers/auth';

// ---------------------------------------------------------------------------
// Page quality assertions
// ---------------------------------------------------------------------------

/** Assert the current page is not a 404 or 500 error page. */
export async function expectPageOk(page: Page): Promise<void> {
  const body = page.locator('body');
  await expect(body).not.toContainText(/\b404\b.*not found|page not found/i);
  await expect(body).not.toContainText(/\b500\b.*internal server error|something went wrong/i);
  // Must not have been redirected to login (indicates auth failure mid-test)
  expect(page.url()).not.toMatch(/\/login(\?|$)/);
}

/** Assert a green success flash is visible on the page. */
export async function expectSuccess(page: Page): Promise<void> {
  await expect(
    page.locator('.alert-success, [data-flash="success"], .toast-success')
  ).toBeVisible({ timeout: 6_000 });
}

/** Assert at least one validation/danger alert is visible. */
export async function expectValidationError(page: Page): Promise<void> {
  const el = page.locator(
    '.alert-danger, .invalid-feedback, [data-flash="error"], .text-danger'
  );
  await expect(el.first()).toBeVisible({ timeout: 5_000 });
}

/** Assert a confirmation/danger modal is visible. */
export async function expectConfirmModal(page: Page): Promise<void> {
  await expect(
    page.locator('.modal.show, [role="dialog"]:visible')
  ).toBeVisible({ timeout: 4_000 });
}

// ---------------------------------------------------------------------------
// Navigation / link utilities
// ---------------------------------------------------------------------------

/**
 * Collect every unique internal <a href> on the current page and
 * return those that start with "/" (relative) or the base origin.
 */
export async function collectInternalLinks(page: Page): Promise<string[]> {
  const origin = new URL(page.url()).origin;
  const hrefs = await page.$$eval('a[href]', (anchors, o) => {
    return anchors
      .map(a => (a as HTMLAnchorElement).href)
      .filter(h => h.startsWith(o) || h.startsWith('/'));
  }, origin);
  // Deduplicate, strip fragments, ignore logout/ical-feed
  const seen = new Set<string>();
  return hrefs
    .map(h => {
      try { return new URL(h).pathname; } catch { return h; }
    })
    .filter(h =>
      !h.includes('/logout') &&
      !h.includes('/ical/') &&
      !h.includes('/attachments/') &&
      !h.includes('/export/') &&
      !h.includes('/backups/') &&
      !seen.has(h) &&
      seen.add(h)
    );
}

/**
 * Verify that a GET request to `path` does not return 404 or 500.
 * Uses the authenticated session cookie from `page`.
 */
export async function assertLinkReachable(
  page: Page,
  path: string
): Promise<void> {
  const resp = await page.request.get(path, { failOnStatusCode: false });
  // 302/303 redirects (e.g. back to login for unauthenticated) count as "not found" in this context
  // We only care that it isn't a hard 404 or 500
  const status = resp.status();
  expect(status, `${path} → HTTP ${status}`).not.toBe(404);
  expect(status, `${path} → HTTP ${status}`).not.toBe(500);
}

/**
 * Navigate to each path in the list and assert no hard error.
 * Skips paths that look like dynamic IDs we can't know ahead of time.
 */
export async function assertAllLinksReachable(
  page: Page,
  paths: string[]
): Promise<void> {
  for (const path of paths) {
    // Skip paths with unresolved placeholders
    if (/\{[^}]+\}/.test(path)) continue;
    await assertLinkReachable(page, path);
  }
}

// ---------------------------------------------------------------------------
// Entity helpers (navigate via list pages, not hardcoded IDs)
// ---------------------------------------------------------------------------

/**
 * On the current page, find the first `<a>` whose href matches
 * the pattern (e.g. /\/members\/(\d+)\/edit/) and return the ID.
 * Returns null if not found.
 */
export async function getFirstHrefId(
  page: Page,
  pattern: RegExp
): Promise<string | null> {
  const links = await page.$$eval('a[href]', (els, p) => {
    const re = new RegExp(p);
    for (const el of els) {
      const href = (el as HTMLAnchorElement).getAttribute('href') ?? '';
      const m = href.match(re);
      if (m) return m[1];
    }
    return null;
  }, pattern.source);
  return links;
}

/**
 * Navigate to a list page, then click the first link matching the
 * given href pattern, and wait for networkidle.
 * Returns the ID extracted from the href if found.
 */
export async function clickFirstEntity(
  page: Page,
  listUrl: string,
  hrefPattern: RegExp
): Promise<string | null> {
  await page.goto(listUrl);
  await page.waitForLoadState('networkidle');
  const id = await getFirstHrefId(page, hrefPattern);
  if (!id) return null;
  const link = page.locator(`a[href*="/${id}"]`).first();
  await link.click();
  await page.waitForLoadState('networkidle');
  return id;
}

// ---------------------------------------------------------------------------
// Form helpers
// ---------------------------------------------------------------------------

/** Fill an input/select/textarea only if visible, then return. */
export async function fillIfPresent(
  page: Page,
  selector: string,
  value: string
): Promise<void> {
  const el = page.locator(selector).first();
  if (await el.isVisible({ timeout: 1_000 }).catch(() => false)) {
    const tag = await el.evaluate(n => n.tagName.toLowerCase());
    if (tag === 'select') {
      await el.selectOption(value);
    } else {
      await el.fill(value);
    }
  }
}

/** Generate a short unique string suitable for test names/emails. */
export function uid(): string {
  return Date.now().toString(36) + Math.random().toString(36).slice(2, 5);
}

/** Generate a test member name that is clearly synthetic. */
export function testName(prefix = 'Test'): { first: string; last: string } {
  const id = uid();
  return { first: `${prefix}${id}`, last: 'Playwright' };
}

// ---------------------------------------------------------------------------
// Sidebar nav link catalogue
// (Must be kept in sync with module registrations.)
// ---------------------------------------------------------------------------

/**
 * All known sidebar nav hrefs for an admin user.
 * Used in navigation tests to assert every item resolves.
 */
export const ADMIN_NAV_LINKS = [
  '/admin/dashboard',
  '/members',
  '/admin/registrations',
  '/admin/custom-fields',
  '/events',
  '/admin/events',
  '/articles',
  '/admin/articles',
  '/admin/email',
  '/directory',
  '/directory/contacts',
  '/admin/org',
  '/admin/org/levels',
  '/admin/achievements',
  '/admin/roles',
  '/admin/reports',
  '/admin/terms',
  '/admin/notices',
  '/admin/settings',
  '/admin/audit',
  '/admin/logs',
  '/admin/export',
  '/admin/backups',
  '/admin/languages',
  '/admin/updates',
] as const;
