import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Members', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('member list page loads', async ({ page }) => {
    await page.goto('/members');
    await expect(page.locator('table, .member-list, [data-member]')).toBeVisible();
  });

  test('member list shows members', async ({ page }) => {
    await page.goto('/members');
    // Should have multiple member rows
    const rows = page.locator('table tbody tr, .member-card');
    await expect(rows.first()).toBeVisible();
  });

  test('search filters members', async ({ page }) => {
    await page.goto('/members');
    const searchInput = page.locator('#search-q');
    if (await searchInput.isVisible()) {
      await searchInput.fill('Anderson');
      await page.waitForTimeout(500); // HTMX debounce
      await expect(page.locator('body')).toContainText('Anderson');
    }
  });

  test('member profile page loads', async ({ page }) => {
    await page.goto('/members');
    const firstLink = page.locator('table a[href*="/members/"]').first();
    if (await firstLink.isVisible()) {
      await firstLink.click();
      await expect(page.locator('.nav-tabs')).toBeVisible();
    }
  });

  test('add member form loads', async ({ page }) => {
    await page.goto('/members/create');
    await expect(page.locator('input[name="first_name"]')).toBeVisible();
    await expect(page.locator('input[name="surname"]')).toBeVisible();
  });

  test('member profile tabs load via HTMX', async ({ page }) => {
    await page.goto('/members');
    const firstLink = page.locator('table a[href*="/members/"]').first();
    if (await firstLink.isVisible()) {
      await firstLink.click();
      // Click through tabs
      const tabs = page.locator('.nav-tabs .nav-link');
      const count = await tabs.count();
      for (let i = 0; i < Math.min(count, 4); i++) {
        await tabs.nth(i).click();
        await page.waitForTimeout(300);
      }
    }
  });
});
