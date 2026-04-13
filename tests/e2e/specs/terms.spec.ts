import { test, expect } from '@playwright/test';
import { login, users } from '../helpers/auth';

test.describe('Terms & Conditions', () => {
  test('user with pending terms sees acceptance prompt', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', users.pending.email);
    await page.fill('input[name="password"]', users.pending.password);
    await page.click('button[type="submit"]');
    // Should see T&Cs acceptance page or modal
    await expect(page.locator('body')).toContainText(/terms|conditions|accept|acknowledge/i);
  });

  test('admin can manage terms versions', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/terms');
    await expect(page.locator('body')).toContainText(/terms|version|published/i);
  });

  test('admin can create new terms version', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/terms/create');
    await expect(page.locator('input[name="title"], input[name="version_number"]')).toBeVisible();
  });

  test('admin can manage notices', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/notices');
    await expect(page.locator('body')).toContainText(/notice|safeguarding|maintenance/i);
  });
});
