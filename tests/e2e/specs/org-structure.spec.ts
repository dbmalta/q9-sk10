import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Org Structure', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('org tree page loads', async ({ page }) => {
    await page.goto('/admin/org');
    await expect(page.locator('body')).toContainText('Scouts of Northland');
  });

  test('org tree shows hierarchy', async ({ page }) => {
    await page.goto('/admin/org');
    await expect(page.locator('body')).toContainText('Northern Region');
    await expect(page.locator('body')).toContainText('Southern Region');
  });

  test('org node detail page loads', async ({ page }) => {
    await page.goto('/admin/org');
    const nodeLink = page.locator('a[href*="/admin/org/"]').first();
    if (await nodeLink.isVisible()) {
      await nodeLink.click();
      await expect(page.locator('body')).toContainText(/section|group|district|region|national/i);
    }
  });

  test('teams are listed', async ({ page }) => {
    await page.goto('/admin/org');
    await expect(page.locator('body')).toContainText(/team|board|committee/i);
  });

  test('add node form loads', async ({ page }) => {
    await page.goto('/admin/org/nodes/create');
    await expect(page.locator('input[name="name"]')).toBeVisible();
  });

  test('level types management page loads', async ({ page }) => {
    await page.goto('/admin/org/levels');
    await expect(page.locator('body')).toContainText(/level|type|national|section/i);
  });
});
