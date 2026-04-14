import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Communications', () => {
  test('member portal shows articles', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/articles');
    await expect(page.locator('body')).toContainText(/welcome|scoutkeeper|article/i);
  });

  test('published article is visible', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/articles');
    await expect(page.locator('body')).toContainText('Welcome to ScoutKeeper');
  });

  test('article detail page loads', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/articles');
    const link = page.locator('a[href*="/articles/"]').first();
    if (await link.isVisible()) {
      await link.click();
      await expect(page.locator('article, .article-body, .article-content')).toBeVisible();
    }
  });

  test('admin can manage articles', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/articles');
    await expect(page.locator('body')).toContainText(/article|draft|published/i);
  });

  test('admin can compose email', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/email');
    await expect(page.locator('input[name="subject"]')).toBeVisible();
    await expect(page.locator('textarea[name="body"]')).toBeVisible();
  });

  test('admin can view email log', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/email/log');
    await expect(page.locator('body')).toContainText(/email|queue|sent|pending/i);
  });
});
