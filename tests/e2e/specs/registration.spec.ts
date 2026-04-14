import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Registration', () => {
  test('self-registration page loads', async ({ page }) => {
    await page.goto('/register');
    await expect(page.locator('input[name="first_name"]')).toBeVisible();
  });

  test('self-registration form validates required fields', async ({ page }) => {
    await page.goto('/register');
    await page.click('button[type="submit"]');
    // Browser native validation prevents submission — first_name is still empty and required
    const firstName = page.locator('input[name="first_name"]');
    await expect(firstName).toBeVisible();
    const isInvalid = await firstName.evaluate((el: HTMLInputElement) => !el.checkValidity());
    expect(isInvalid).toBe(true);
  });

  test('admin can view pending registrations', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/registrations');
    await expect(page.locator('body')).toContainText(/pending|registration/i);
  });

  test('admin can view invitations', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/invitations');
    await expect(page.locator('body')).toContainText(/invitation/i);
  });

  test('admin can view waiting list', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/waiting-list');
    await expect(page.locator('body')).toContainText(/waiting/i);
  });

  test('bulk import page loads', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/bulk-import');
    await expect(page.locator('body')).toContainText(/import|csv|upload/i);
  });

  test('waiting list public page loads', async ({ page }) => {
    await page.goto('/waiting-list');
    await expect(page.locator('input[name="parent_name"]')).toBeVisible();
  });
});
