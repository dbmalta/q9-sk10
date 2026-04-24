import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

/**
 * Scope-filtering for /admin/org.
 *
 * Leader is scoped to "1st Frostdale" per NorthlandSeeder. The tree page
 * must only render 1st Frostdale plus its sections — not the rest of
 * Frostdale District, Northern Region, or Southern Region.
 */

test.describe('Org structure tree scoping', () => {
  test('leader sees only their scoped subtree', async ({ page }) => {
    await login(page, 'leader');
    await page.goto('/admin/org');

    const body = page.locator('body');
    await expect(body).not.toContainText(/Fatal error|internal server error|Whoops/i);

    // In-scope nodes — leader's group and the sections under it.
    await expect(body).toContainText('1st Frostdale');
    await expect(body).toContainText('Beaver Colony');
    await expect(body).toContainText('Scout Troop');

    // Out-of-scope ancestors and siblings must not appear.
    await expect(body).not.toContainText('Scouts of Northland');
    await expect(body).not.toContainText('Northern Region');
    await expect(body).not.toContainText('Southern Region');
    await expect(body).not.toContainText('Frostdale District');
    await expect(body).not.toContainText('2nd Frostdale');
    await expect(body).not.toContainText('1st Pinewood');
    await expect(body).not.toContainText('1st Coastview');
  });

  test('super admin still sees the whole tree', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/org');

    const body = page.locator('body');
    await expect(body).toContainText('Scouts of Northland');
    await expect(body).toContainText('Northern Region');
    await expect(body).toContainText('Southern Region');
    await expect(body).toContainText('Frostdale District');
    await expect(body).toContainText('1st Frostdale');
    await expect(body).toContainText('1st Coastview');
  });
});
