import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Registration', () => {
  test('self-registration page loads', async ({ page }) => {
    await page.goto('/register');
    await expect(page.locator('input[name="first_name"], input[name="email"]')).toBeVisible();
  });

  test('self-registration form validates required fields', async ({ page }) => {
    await page.goto('/register');
    await page.click('button[type="submit"]');
    // Should show validation errors
    await expect(page.locator('.is-invalid, .invalid-feedback, .alert-danger')).toBeVisible();
  });

  test('admin can view pending registrations', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members/pending');
    await expect(page.locator('body')).toContainText(/pending|registration/i);
  });

  test('admin can view invitations', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members/invitations');
    await expect(page.locator('body')).toContainText(/invitation/i);
  });

  test('admin can view waiting list', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members/waiting-list');
    await expect(page.locator('body')).toContainText(/waiting/i);
  });

  test('bulk import page loads', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members/import');
    await expect(page.locator('body')).toContainText(/import|csv|upload/i);
  });

  test('waiting list public page loads', async ({ page }) => {
    await page.goto('/waiting-list');
    await expect(page.locator('input[name="parent_name"], input[name="child_name"]')).toBeVisible();
  });
});
