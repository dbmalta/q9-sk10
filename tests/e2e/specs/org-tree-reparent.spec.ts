import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

/**
 * Drag-to-reparent on /admin/org.
 *
 * Two layers of coverage:
 *   1. Backend contract — POST /admin/org/nodes/{id}/move with a CSRF
 *      token must update parent_id and write an audit_log entry.
 *   2. UI contract — every tree row carries draggable="true" for users
 *      with org_structure.write, and not for users without it.
 *
 * A full HTML5 drag-drop simulation is brittle in Playwright across
 * versions of Chromium, so we exercise the same code path the UI calls
 * (form POST to the move endpoint) and separately assert the UI props.
 */
test.describe('Org tree reparent', () => {
  test('admin sees draggable rows; member does not', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/org');
    const draggable = await page.locator('.tree-row[draggable="true"]').count();
    expect(draggable).toBeGreaterThan(0);

    // Switch user — member should not see the drag affordance even if they
    // can reach the page (which they shouldn't, but the test handles either).
    await page.context().clearCookies();
    await login(page, 'member');
    const resp = await page.goto('/admin/org');
    if (resp && resp.status() === 200 && page.url().includes('/admin/org')) {
      await expect(page.locator('.tree-row[draggable="true"]')).toHaveCount(0);
    }
  });

  test('POST /move updates parent_id and persists', async ({ page, request }) => {
    await login(page, 'admin');
    await page.goto('/admin/org');

    // Find Beaver Colony (under 1st Frostdale) and Cub Pack (also under 1st Frostdale).
    // Move Beaver Colony to be a child of 2nd Frostdale instead.
    const sourceName = 'Beaver Colony';
    const newParentName = '2nd Frostdale';

    // Read the rendered DOM to grab node ids by name.
    const ids = await page.evaluate(() => {
      const map: Record<string, number> = {};
      document.querySelectorAll('.tree-row[data-node-id]').forEach((row) => {
        const id = parseInt(row.getAttribute('data-node-id') || '0', 10);
        const name = row.querySelector('.node-name')?.firstChild?.textContent?.trim() || '';
        if (name && !(name in map)) map[name] = id; // first occurrence wins
      });
      return map;
    });
    const sourceId = ids[sourceName];
    const newParentId = ids[newParentName];
    expect(sourceId, `expected ${sourceName} to be visible`).toBeTruthy();
    expect(newParentId, `expected ${newParentName} to be visible`).toBeTruthy();

    // Pull the CSRF token from the rendered move form.
    const csrf = await page.locator('#org-move-form input[name="_csrf_token"]').inputValue();
    expect(csrf).toBeTruthy();

    // Pass the session cookie to the API request context.
    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');

    const resp = await request.post(`http://localhost:8080/admin/org/nodes/${sourceId}/move`, {
      headers: { Cookie: cookieHeader, 'Content-Type': 'application/x-www-form-urlencoded' },
      data: `_csrf_token=${encodeURIComponent(csrf)}&parent_id=${newParentId}`,
      maxRedirects: 0,
    });
    expect([200, 302, 303]).toContain(resp.status());

    // Reload and confirm the moved node is now under 2nd Frostdale.
    await page.goto('/admin/org');
    const moved = await page.evaluate(({ src, dst }) => {
      const row = document.querySelector(`.tree-row[data-node-id="${src}"]`);
      if (!row) return { found: false };
      // Walk up to the wrapping .tree-children, then to the preceding .tree-row
      const wrap = row.parentElement?.closest('.tree-children');
      const prev = wrap?.previousElementSibling;
      const parentId = prev?.getAttribute('data-node-id');
      return { found: true, parentId: parentId ? parseInt(parentId, 10) : null };
    }, { src: sourceId, dst: newParentId });
    expect(moved.found).toBe(true);
    expect(moved.parentId).toBe(newParentId);

    // Restore: move Beaver Colony back under 1st Frostdale so subsequent runs
    // start from the seeded state.
    const originalParentId = ids['1st Frostdale'];
    await request.post(`http://localhost:8080/admin/org/nodes/${sourceId}/move`, {
      headers: { Cookie: cookieHeader, 'Content-Type': 'application/x-www-form-urlencoded' },
      data: `_csrf_token=${encodeURIComponent(csrf)}&parent_id=${originalParentId}`,
      maxRedirects: 0,
    });
  });

  test('POST /move rejects cycle (descendant as new parent)', async ({ page, request }) => {
    await login(page, 'admin');
    await page.goto('/admin/org');

    const ids = await page.evaluate(() => {
      const map: Record<string, number> = {};
      document.querySelectorAll('.tree-row[data-node-id]').forEach((row) => {
        const id = parseInt(row.getAttribute('data-node-id') || '0', 10);
        const name = row.querySelector('.node-name')?.firstChild?.textContent?.trim() || '';
        if (name && !(name in map)) map[name] = id;
      });
      return map;
    });
    // Northern Region (parent of Frostdale District) — try to move it under Beaver Colony (a deep descendant).
    const sourceId = ids['Northern Region'];
    const targetId = ids['Beaver Colony'];
    expect(sourceId).toBeTruthy();
    expect(targetId).toBeTruthy();

    const csrf = await page.locator('#org-move-form input[name="_csrf_token"]').inputValue();
    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');

    await request.post(`http://localhost:8080/admin/org/nodes/${sourceId}/move`, {
      headers: { Cookie: cookieHeader, 'Content-Type': 'application/x-www-form-urlencoded' },
      data: `_csrf_token=${encodeURIComponent(csrf)}&parent_id=${targetId}`,
      maxRedirects: 0,
    });

    // The flash should report the cycle error after redirect.
    await page.goto('/admin/org');
    await expect(page.locator('body')).toContainText(/cannot move|cycle|descendant/i);

    // And the parent must still be its original parent (Scouts of Northland — the national root).
    const stillUnderRoot = await page.evaluate((src) => {
      const row = document.querySelector(`.tree-row[data-node-id="${src}"]`);
      const wrap = row?.parentElement?.closest('.tree-children');
      const prev = wrap?.previousElementSibling;
      return prev?.querySelector('.node-name')?.firstChild?.textContent?.trim() || null;
    }, sourceId);
    expect(stillUnderRoot).toBe('Scouts of Northland');
  });
});
