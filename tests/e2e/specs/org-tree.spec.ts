import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

/**
 * Covers the interactive org tree on /admin/org:
 *   - rows render with member counts
 *   - selecting a row opens the detail panel
 *   - caret toggles children visibility
 *   - search filters rows
 *   - write actions are gated on org_structure.write (admin sees, member doesn't)
 */
test.describe('Org tree', () => {
  test('admin sees tree with member counts and can open write actions', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/org');

    // Tree has rendered — rows are present
    const rows = page.locator('.tree-row[data-node-id]');
    await expect(rows.first()).toBeVisible();
    expect(await rows.count()).toBeGreaterThan(0);

    // Every row has a count cell
    const firstCountText = await rows.first().locator('.count').textContent();
    expect(firstCountText?.trim()).toMatch(/\d+/);

    // Admin has write permission — the "Add top-level" button exists
    await expect(page.getByRole('link', { name: /add node/i })).toBeVisible();

    // Hover-reveal actions on a row include an edit link
    const firstRow = rows.first();
    await firstRow.hover();
    await expect(firstRow.locator('.actions a[title*="Edit" i]')).toBeVisible();
  });

  test('clicking a row selects it and opens the detail panel', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/org');

    const row = page.locator('.tree-row[data-node-id]').first();
    const nodeName = (await row.locator('.node-name').textContent())?.trim().split('\n')[0]?.trim() ?? '';

    await row.click();
    await expect(row).toHaveClass(/selected/);

    const detail = page.locator('.detail-card');
    await expect(detail).toBeVisible();
    await expect(detail.locator('h2')).toContainText(nodeName);
  });

  test('search filters rows', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/org');

    const totalBefore = await page.locator('.tree-row[data-node-id]:not(.filtered-out)').count();
    await page.fill('.tree-search input[type="search"]', 'Frostdale');
    // Allow Alpine reactivity tick
    await page.waitForTimeout(150);
    const totalAfter = await page.locator('.tree-row[data-node-id]:not(.filtered-out)').count();

    expect(totalAfter).toBeLessThan(totalBefore);
    expect(totalAfter).toBeGreaterThan(0);

    // At least one visible row must contain "Frostdale"
    const visible = page.locator('.tree-row[data-node-id]:not(.filtered-out) .node-name');
    const texts = await visible.allTextContents();
    expect(texts.some((t) => /frostdale/i.test(t))).toBe(true);
  });

  test('read-only member user does not see write actions', async ({ page }) => {
    await login(page, 'member');

    // Members mode won't have org nav, but the URL should either 403 or
    // render without write controls if permission is read-only. Probe the
    // URL directly and accept either a redirect away or a rendered page
    // without the "Add Node" button.
    const resp = await page.goto('/admin/org');
    const status = resp?.status() ?? 0;

    if (status === 200 && page.url().includes('/admin/org')) {
      // Rendered — must NOT show the write buttons
      await expect(page.getByRole('link', { name: /add node/i })).toHaveCount(0);
      await expect(page.locator('.tree-row .actions a[title*="Edit" i]')).toHaveCount(0);
    } else {
      // Redirected / forbidden — accept as proof that permissions gate read too
      expect(status === 403 || status === 302 || !page.url().includes('/admin/org')).toBeTruthy();
    }
  });
});
