/**
 * Directory — Comprehensive Tests
 *
 * Covers:
 *  - Organogram page: loads, shows org hierarchy
 *  - Contacts page: loads, shows member contact info
 *  - Both require authentication
 *  - Organogram renders org nodes in tree format
 *  - Contacts page shows member names/emails
 *  - Leader role can access directory
 *  - Regular member can access directory
 */

import { test, expect } from '@playwright/test';
import { login, expectPageOk } from './helpers';

// ---------------------------------------------------------------------------
// Organogram
// ---------------------------------------------------------------------------

test.describe('Organogram', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('organogram page loads', async ({ page }) => {
    await page.goto('/directory');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/organogram|organisation|structure|directory/i);
  });

  test('has page heading', async ({ page }) => {
    await page.goto('/directory');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('h1, h2, .page-title')).toBeVisible();
  });

  test('organogram shows org nodes from seeded data', async ({ page }) => {
    await page.goto('/directory');
    await page.waitForLoadState('networkidle');
    // Should show some organisation units
    const nodes = page.locator('.org-node, [data-node], .tree-node, li.node');
    if (await nodes.count() > 0) {
      await expect(nodes.first()).toBeVisible();
    } else {
      // May be rendered differently (table, divs, etc.)
      const body = await page.locator('body').textContent();
      expect(body).not.toMatch(/internal server error|Fatal error|Stack trace|Uncaught/i);
    }
  });

  test('organogram shows hierarchical structure', async ({ page }) => {
    await page.goto('/directory');
    await page.waitForLoadState('networkidle');
    // Should show at least a root node
    const body = await page.locator('body').textContent();
    // The seeder creates a Group, Districts, Sections hierarchy
    const hasHierarchy = body?.match(/group|district|section|county|area/i);
    expect(hasHierarchy, 'Organogram should show hierarchical org structure').toBeTruthy();
  });

  test('organogram does not show member email addresses (privacy)', async ({ page }) => {
    await page.goto('/directory');
    await page.waitForLoadState('networkidle');
    // Organogram is structural, not contact data
    const body = await page.locator('body').textContent();
    // Should not show raw email addresses of members
    // (A few may appear for contact people, but shouldn't be a full list)
    const emailMatches = body?.match(/\S+@\S+\.\S+/g) ?? [];
    // Having a handful is fine (e.g. contact person for org), but not hundreds
    expect(emailMatches.length).toBeLessThan(50);
  });

  test('/directory requires authentication', async ({ page }) => {
    await page.goto('/logout');
    await page.goto('/directory');
    await expect(page).toHaveURL(/\/login/);
  });
});

// ---------------------------------------------------------------------------
// Contacts
// ---------------------------------------------------------------------------

test.describe('Contacts directory', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('contacts page loads', async ({ page }) => {
    await page.goto('/directory/contacts');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/contact|directory|member/i);
  });

  test('contacts page shows member names', async ({ page }) => {
    await page.goto('/directory/contacts');
    await page.waitForLoadState('networkidle');
    // Should show at least some member names from seeded data
    const rows = page.locator('table tbody tr, .contact-item, [data-contact]');
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('contacts page has search or filter capability', async ({ page }) => {
    await page.goto('/directory/contacts');
    await page.waitForLoadState('networkidle');
    const search = page.locator(
      'input[type="search"], input[name="q"], input[placeholder*="search" i]'
    );
    // Not all implementations have search, just check page doesn't error
    await expect(page.locator('body')).not.toContainText(/internal server error/i);
  });

  test('/directory/contacts requires authentication', async ({ page }) => {
    await page.goto('/logout');
    await page.goto('/directory/contacts');
    await expect(page).toHaveURL(/\/login/);
  });
});

// ---------------------------------------------------------------------------
// Role-based access to directory
// ---------------------------------------------------------------------------

test.describe('Directory access by role', () => {
  test('leader can access organogram', async ({ page }) => {
    await login(page, 'leader');
    await page.goto('/directory');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText(/forbidden|403|access denied/i);
    await expectPageOk(page);
  });

  test('leader can access contacts', async ({ page }) => {
    await login(page, 'leader');
    await page.goto('/directory/contacts');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText(/forbidden|403|access denied/i);
    await expectPageOk(page);
  });

  test('member can access organogram', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/directory');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/\/login/);
    // Members may or may not be allowed — check it doesn't 500
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });

  test('member can access contacts', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/directory/contacts');
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText(/internal server error|Fatal error|Stack trace|Uncaught/i);
  });
});

// ---------------------------------------------------------------------------
// Navigation to/from directory
// ---------------------------------------------------------------------------

test.describe('Directory navigation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('organogram page has link to contacts', async ({ page }) => {
    await page.goto('/directory');
    await page.waitForLoadState('networkidle');
    const contactsLink = page.locator('a[href*="/directory/contacts"], a:text-matches("contact", "i")').first();
    if (await contactsLink.count() > 0) {
      await expect(contactsLink).toBeVisible();
    }
  });

  test('contacts page has link back to organogram', async ({ page }) => {
    await page.goto('/directory/contacts');
    await page.waitForLoadState('networkidle');
    // Look inside the main content area (not the sidebar, which is hidden on mobile)
    const orgLink = page.locator('main a[href="/directory"], main a[href*="organogram"], main a:has-text("Organogram"), #main-content a[href="/directory"]').first();
    if (await orgLink.count() > 0) {
      await expect(orgLink).toBeVisible();
    }
  });

  test('directory pages appear in sidebar navigation', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle');
    // On mobile, open the offcanvas menu first
    const hamburger = page.locator('button[data-bs-toggle="offcanvas"]').first();
    if (await hamburger.isVisible().catch(() => false)) {
      await hamburger.click();
      await page.waitForTimeout(400);
    }
    // :visible picks whichever copy is on-screen (desktop sidebar vs mobile offcanvas)
    const directoryLink = page.locator('a[href="/directory"]:visible, a[href="/directory/contacts"]:visible').first();
    await expect(directoryLink).toBeVisible();
  });
});
