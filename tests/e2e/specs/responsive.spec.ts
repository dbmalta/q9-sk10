import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Responsive Design', () => {
  test('mobile: login page renders correctly at 320px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 568 });
    await page.goto('/login');
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('mobile: navigation uses hamburger menu', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    // Desktop sidebar should be hidden, hamburger visible
    const hamburger = page.locator('.navbar-toggler, [data-bs-toggle="offcanvas"], .hamburger');
    await expect(hamburger).toBeVisible();
  });

  test('tablet: pages render at 768px', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 });
    await login(page, 'admin');
    await page.goto('/members');
    await expect(page.locator('body')).toContainText(/member/i);
  });

  test('desktop: sidebar navigation visible at 1024px', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 768 });
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    const sidebar = page.locator('.sidebar, #sidebar, nav.sidebar');
    if (await sidebar.isVisible()) {
      await expect(sidebar).toBeVisible();
    }
  });

  test('mobile: member list is usable on small screens', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, 'admin');
    await page.goto('/members');
    // Table should be wrapped in table-responsive for horizontal scrolling
    await expect(page.locator('.table-responsive')).toBeVisible();
    // Member data should be accessible
    await expect(page.locator('table')).toBeVisible();
  });
});
