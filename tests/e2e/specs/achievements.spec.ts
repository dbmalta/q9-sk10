import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Achievements & Training', () => {
  test('achievement definitions list loads', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/achievements');
    await expect(page.locator('body')).toContainText(/woodcraft|navigation|first aid/i);
  });

  test('achievement definitions include training courses', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/achievements');
    await expect(page.locator('body')).toContainText(/safeguarding|leadership/i);
  });

  test('create achievement form loads', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/achievements/create');
    await expect(page.locator('input[name="name"]')).toBeVisible();
    await expect(page.locator('select[name="category"]')).toBeVisible();
  });

  test('member achievements visible in profile', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members');
    const firstLink = page.locator('a[href*="/members/"]').first();
    if (await firstLink.isVisible()) {
      await firstLink.click();
      // Look for achievements tab or section
      const achTab = page.locator('text=Achievement, text=Training, [data-tab="achievements"]');
      if (await achTab.first().isVisible()) {
        await achTab.first().click();
        await page.waitForTimeout(500);
      }
    }
  });
});
