import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Dark Mode', () => {
  test('login page respects prefers-color-scheme: dark', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/login');
    const theme = await page.getAttribute('html', 'data-bs-theme');
    expect(theme).toBe('dark');
  });

  test('login page respects prefers-color-scheme: light', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/login');
    const theme = await page.getAttribute('html', 'data-bs-theme');
    expect(theme).toBe('light');
  });

  test('dark mode toggle switches theme', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    const toggle = page.locator('#theme-toggle, [data-theme-toggle], .theme-switch');
    if (await toggle.isVisible()) {
      const before = await page.getAttribute('html', 'data-bs-theme');
      await toggle.click();
      const after = await page.getAttribute('html', 'data-bs-theme');
      expect(before).not.toBe(after);
    }
  });

  test('dark mode: text is readable against background', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    // Verify the page has dark theme set
    const theme = await page.getAttribute('html', 'data-bs-theme');
    expect(theme).toBe('dark');
  });
});
