import { Page, expect } from '@playwright/test';

/** Known test user credentials — must match PlaywrightFixtures.php */
export const users = {
  admin:  { email: 'admin@northland.test',   password: 'TestPass123!' },
  leader: { email: 'leader@northland.test',  password: 'TestPass123!' },
  member: { email: 'member@northland.test',  password: 'TestPass123!' },
  pending:{ email: 'pending@northland.test', password: 'TestPass123!' },
  mfa:    { email: 'mfa@northland.test',     password: 'TestPass123!' },
} as const;

export type UserRole = keyof typeof users;

/**
 * Log in as a known test user.
 */
export async function login(page: Page, role: UserRole = 'admin'): Promise<void> {
  const { email, password } = users[role];
  await page.goto('/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  // Wait for redirect away from login page
  await expect(page).not.toHaveURL(/\/login/);
}

/**
 * Log out the current user.
 */
export async function logout(page: Page): Promise<void> {
  await page.goto('/logout');
  await expect(page).toHaveURL(/\/login/);
}

/**
 * Assert the page shows an access denied / 403 error.
 */
export async function expectForbidden(page: Page): Promise<void> {
  await expect(page.locator('body')).toContainText(/forbidden|access denied|403/i);
}
