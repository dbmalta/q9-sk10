import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Directory & Organogram', () => {
  test('organogram page loads', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/directory/organogram');
    await expect(page.locator('body')).toContainText(/scouts of northland|organogram|structure/i);
  });

  test('contacts page loads', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/directory/contacts');
    await expect(page.locator('body')).toContainText(/contact|leader|role/i);
  });

  test('organogram shows hierarchy', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/directory/organogram');
    await expect(page.locator('body')).toContainText(/region|district|group/i);
  });

  test('unauthenticated users cannot access directory', async ({ page }) => {
    await page.goto('/directory/organogram');
    await expect(page).toHaveURL(/\/login/);
  });
});
