import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Administration', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('settings page loads', async ({ page }) => {
    await page.goto('/admin/settings');
    await expect(page.locator('body')).toContainText(/settings|configuration/i);
  });

  test('audit log page loads', async ({ page }) => {
    await page.goto('/admin/audit');
    await expect(page.locator('body')).toContainText(/audit|log/i);
  });

  test('audit log shows entries', async ({ page }) => {
    await page.goto('/admin/audit');
    const rows = page.locator('table tbody tr');
    await expect(rows.first()).toBeVisible();
  });

  test('log viewer loads', async ({ page }) => {
    await page.goto('/admin/logs');
    await expect(page.locator('body')).toContainText(/log|error|slow/i);
  });

  test('backup page loads', async ({ page }) => {
    await page.goto('/admin/backup');
    await expect(page.locator('body')).toContainText(/backup|restore|download/i);
  });

  test('data export page loads', async ({ page }) => {
    await page.goto('/admin/export');
    await expect(page.locator('body')).toContainText(/export|csv|data/i);
  });

  test('language management page loads', async ({ page }) => {
    await page.goto('/admin/languages');
    await expect(page.locator('body')).toContainText(/language|english/i);
  });

  test('reports page loads', async ({ page }) => {
    await page.goto('/admin/reports');
    await expect(page.locator('body')).toContainText(/report|membership/i);
  });

  test('member cannot access admin settings', async ({ page }) => {
    await page.goto('/logout');
    await login(page, 'member');
    await page.goto('/admin/settings');
    await expect(page.locator('body')).toContainText(/forbidden|denied|403/i);
  });
});
