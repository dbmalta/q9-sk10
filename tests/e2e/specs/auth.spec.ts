import { test, expect } from '@playwright/test';
import { login, logout, users } from '../helpers/auth';

test.describe('Authentication', () => {
  test('successful login as admin', async ({ page }) => {
    await login(page, 'admin');
    await expect(page.locator('body')).toContainText(/dashboard|welcome/i);
  });

  test('successful login as member', async ({ page }) => {
    await login(page, 'member');
    await expect(page).not.toHaveURL(/\/login/);
  });

  test('failed login with wrong password', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', users.admin.email);
    await page.fill('input[name="password"]', 'WrongPassword999');
    await page.click('button[type="submit"]');
    await expect(page.locator('.alert-danger, .error, [role="alert"]')).toBeVisible();
  });

  test('failed login with non-existent email', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', 'nobody@nowhere.test');
    await page.fill('input[name="password"]', 'anything');
    await page.click('button[type="submit"]');
    await expect(page.locator('.alert-danger, .error, [role="alert"]')).toBeVisible();
  });

  test('logout redirects to login page', async ({ page }) => {
    await login(page, 'admin');
    await logout(page);
    await expect(page).toHaveURL(/\/login/);
  });

  test('password reset page loads', async ({ page }) => {
    await page.goto('/forgot-password');
    await expect(page.locator('input[name="email"]')).toBeVisible();
  });

  test('MFA user sees TOTP verification step', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', users.mfa.email);
    await page.fill('input[name="password"]', users.mfa.password);
    await page.click('button[type="submit"]');
    // Should land on MFA verification page
    await expect(page.locator('input[name="totp_code"], input[name="code"]')).toBeVisible();
  });

  test('unauthenticated access redirects to login', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/login/);
  });
});
