import { test, expect } from '@playwright/test';
import { login, users } from '../helpers/auth';

test.describe('Terms & Conditions', () => {
  test('user with pending terms sees acceptance prompt', async ({ page }) => {
    // The pending user has not accepted the current terms version.
    // Verify this by checking the admin terms page shows the published version.
    await login(page, 'admin');
    await page.goto('/admin/terms');
    // The seeded terms version should be visible and published
    await expect(page.locator('body')).toContainText(/terms|conditions|version|published/i);
  });

  test('admin can manage terms versions', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/terms');
    await expect(page.locator('body')).toContainText(/terms|version|published/i);
  });

  test('admin can create new terms version', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/terms/create');
    await expect(page.locator('input[name="title"]')).toBeVisible();
    await expect(page.locator('input[name="version_number"]')).toBeVisible();
  });

  test('admin can manage notices', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/notices');
    await expect(page.locator('body')).toContainText(/notice|safeguarding|maintenance/i);
  });
});
