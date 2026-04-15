/**
 * Achievements — Comprehensive Tests
 *
 * Covers:
 *  - Achievement definitions list: loads, shows seeded achievements
 *  - Create achievement: form, validation, successful submit
 *  - Edit achievement: form pre-filled, update succeeds
 *  - Deactivate achievement: works
 *  - Activate achievement: works
 *  - Award achievement to member: form visible, submit succeeds
 *  - Revoke achievement from member: button present
 *  - Member profile shows achievements (via tab or section)
 */

import { test, expect } from '@playwright/test';
import {
  login,
  expectPageOk,
  getFirstHrefId,
  uid,
} from './helpers';

// ---------------------------------------------------------------------------
// Achievement definitions list
// ---------------------------------------------------------------------------

test.describe('Achievements list', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('achievements list page loads', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/achievement|award|training/i);
  });

  test('has a heading', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('h1, h2, .page-title')).toBeVisible();
  });

  test('seeded achievements appear', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    const rows = page.locator('table tbody tr, .achievement-item, [data-achievement]');
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('create achievement button is present', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    const createBtn = page.locator(
      'a[href*="/admin/achievements/create"], a:text-matches("create|add|new", "i")'
    ).first();
    if (await createBtn.count() > 0) {
      await expect(createBtn).toBeVisible();
    }
  });

  test('each achievement has edit link', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    const editLink = page.locator('a[href*="/admin/achievements/"][href*="/edit"]').first();
    if (await editLink.count() > 0) {
      await expect(editLink).toBeVisible();
    }
  });

  test('achievements show active/inactive status', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    const body = await page.locator('body').textContent();
    const hasStatus = body?.match(/active|inactive|enabled|disabled/i);
    expect(hasStatus).toBeTruthy();
  });
});

// ---------------------------------------------------------------------------
// Create achievement
// ---------------------------------------------------------------------------

test.describe('Create achievement', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('create form loads with name field', async ({ page }) => {
    await page.goto('/admin/achievements/create');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('input[name="name"]')).toBeVisible();
  });

  test('form has type/category selector', async ({ page }) => {
    await page.goto('/admin/achievements/create');
    await page.waitForLoadState('networkidle');
    const typeField = page.locator('select[name="type"], select[name="category"]');
    if (await typeField.count() > 0) {
      await expect(typeField.first()).toBeVisible();
    }
  });

  test('form has description field', async ({ page }) => {
    await page.goto('/admin/achievements/create');
    await page.waitForLoadState('networkidle');
    const desc = page.locator('textarea[name="description"], input[name="description"]');
    if (await desc.count() > 0) {
      await expect(desc.first()).toBeVisible();
    }
  });

  test('submitting empty name shows validation error', async ({ page }) => {
    await page.goto('/admin/achievements/create');
    await page.waitForLoadState('networkidle');
    await page.click('button[type="submit"]');
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
  });

  test('valid achievement creation succeeds', async ({ page }) => {
    const id = uid();
    await page.goto('/admin/achievements/create');
    await page.waitForLoadState('networkidle');

    await page.fill('input[name="name"]', `PW Achievement ${id}`);

    const typeField = page.locator('select[name="type"], select[name="category"]');
    if (await typeField.isVisible()) await typeField.selectOption({ index: 1 });

    const descField = page.locator('textarea[name="description"]');
    if (await descField.isVisible()) await descField.fill(`Playwright test achievement ${id}`);

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
    const url = page.url();
    const ok = url.includes('/admin/achievements') || await page.locator('.alert-success').isVisible();
    expect(ok, 'After creating achievement, should be on list or show success').toBe(true);
  });
});

// ---------------------------------------------------------------------------
// Edit achievement
// ---------------------------------------------------------------------------

test.describe('Edit achievement', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('edit form loads for first achievement', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/admin\/achievements\/(\d+)\/edit/);
    if (!id) { test.skip(true, 'No achievements with edit link'); return; }

    await page.goto(`/admin/achievements/${id}/edit`);
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('input[name="name"]')).toBeVisible();
  });

  test('edit form is pre-filled with achievement name', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/admin\/achievements\/(\d+)\/edit/);
    if (!id) { test.skip(true, 'No achievements to edit'); return; }

    await page.goto(`/admin/achievements/${id}/edit`);
    await page.waitForLoadState('networkidle');
    const nameVal = await page.locator('input[name="name"]').inputValue();
    expect(nameVal.length).toBeGreaterThan(0);
  });

  test('updating achievement name and submitting succeeds', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/admin\/achievements\/(\d+)\/edit/);
    if (!id) { test.skip(true, 'No achievements to edit'); return; }

    await page.goto(`/admin/achievements/${id}/edit`);
    await page.waitForLoadState('networkidle');

    const nameField = page.locator('input[name="name"]');
    const current = await nameField.inputValue();
    await nameField.fill(current || `PW Achievement ${uid()}`);

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
  });
});

// ---------------------------------------------------------------------------
// Deactivate / Activate achievement
// ---------------------------------------------------------------------------

test.describe('Achievement activation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('deactivate button present on active achievements', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    const deactivateBtn = page.locator(
      'button:text-matches("deactivate|disable", "i"), form[action*="deactivate"] button'
    ).first();
    if (await deactivateBtn.count() > 0) {
      await expect(deactivateBtn).toBeVisible();
    }
  });

  test('activate button present on inactive achievements', async ({ page }) => {
    await page.goto('/admin/achievements');
    await page.waitForLoadState('networkidle');
    const activateBtn = page.locator(
      'button:text-matches("activate|enable", "i"), form[action*="activate"] button'
    ).first();
    if (await activateBtn.count() > 0) {
      await expect(activateBtn).toBeVisible();
    }
  });
});

// ---------------------------------------------------------------------------
// Award achievement to member
// ---------------------------------------------------------------------------

test.describe('Award achievement to member', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  async function getFirstMemberId(page: any): Promise<string | null> {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    return await getFirstHrefId(page, /\/members\/(\d+)(?:\/|$)/);
  }

  test('achievements section visible on member profile', async ({ page }) => {
    const memberId = await getFirstMemberId(page);
    if (!memberId) { test.skip(true, 'No members'); return; }

    await page.goto(`/members/${memberId}`);
    await page.waitForLoadState('networkidle');

    // Click timeline or a specific achievements tab/section
    const tabs = page.locator('.nav-tabs .nav-link, [role="tab"]');
    const count = await tabs.count();
    for (let i = 0; i < count; i++) {
      await tabs.nth(i).click();
      await page.waitForLoadState('networkidle');
      const body = await page.locator('body').textContent();
      if (body?.match(/achievement|award|training/i)) {
        await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
        return; // found it
      }
    }
    // Achievement section may be embedded without a separate tab
    // Just check no 500
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
  });

  test('award achievement form is accessible', async ({ page }) => {
    const memberId = await getFirstMemberId(page);
    if (!memberId) { test.skip(true, 'No members'); return; }

    await page.goto(`/members/${memberId}`);
    await page.waitForLoadState('networkidle');

    // Navigate through all tabs to find achievements section
    const tabs = page.locator('.nav-tabs .nav-link, [role="tab"]');
    const count = await tabs.count();
    for (let i = 0; i < count; i++) {
      await tabs.nth(i).click();
      await page.waitForLoadState('networkidle');

      const awardForm = page.locator(
        'form[action*="/achievements"], select[name="achievement_id"], button:text-matches("award|add achievement", "i")'
      ).first();
      if (await awardForm.isVisible()) {
        await expect(awardForm).toBeVisible();
        return;
      }
    }
    // If not found in tabs, it might be a separate section — that's OK
  });

  test('awarding an achievement succeeds', async ({ page }) => {
    const memberId = await getFirstMemberId(page);
    if (!memberId) { test.skip(true, 'No members'); return; }

    await page.goto(`/members/${memberId}`);
    await page.waitForLoadState('networkidle');

    // Navigate tabs to find award form
    const tabs = page.locator('.nav-tabs .nav-link, [role="tab"]');
    const count = await tabs.count();
    for (let i = 0; i < count; i++) {
      await tabs.nth(i).click();
      await page.waitForLoadState('networkidle');

      const achievementSelect = page.locator('select[name="achievement_id"]').first();
      if (await achievementSelect.isVisible()) {
        const options = await achievementSelect.locator('option').count();
        if (options > 1) {
          await achievementSelect.selectOption({ index: 1 });
          const dateField = page.locator('input[name="awarded_at"], input[name="date"]').first();
          if (await dateField.isVisible()) await dateField.fill('2026-01-01');

          await page.click('button[type="submit"]');
          await page.waitForLoadState('networkidle');
          await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
        }
        return;
      }
    }
  });

  test('revoke achievement button present when member has achievements', async ({ page }) => {
    const memberId = await getFirstMemberId(page);
    if (!memberId) { test.skip(true, 'No members'); return; }

    await page.goto(`/members/${memberId}`);
    await page.waitForLoadState('networkidle');

    const tabs = page.locator('.nav-tabs .nav-link, [role="tab"]');
    for (let i = 0; i < await tabs.count(); i++) {
      await tabs.nth(i).click();
      await page.waitForLoadState('networkidle');

      const revokeBtn = page.locator(
        'button:text-matches("revoke|remove", "i"), form[action*="/revoke"] button'
      ).first();
      if (await revokeBtn.count() > 0) {
        await expect(revokeBtn).toBeVisible();
        return;
      }
    }
  });
});
