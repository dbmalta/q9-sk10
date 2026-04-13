import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Dashboard', () => {
  test('admin dashboard loads', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await expect(page.locator('body')).toContainText(/dashboard|overview|statistics/i);
  });

  test('dashboard shows member count', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    // Should show some kind of count or stat
    await expect(page.locator('body')).toContainText(/member/i);
  });

  test('dashboard shows recent activity', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await expect(page.locator('body')).toContainText(/recent|activity|audit/i);
  });

  test('leader sees dashboard appropriate to role', async ({ page }) => {
    await login(page, 'leader');
    await page.goto('/admin/dashboard');
    await expect(page.locator('body')).not.toContainText(/403|forbidden/i);
  });
});
