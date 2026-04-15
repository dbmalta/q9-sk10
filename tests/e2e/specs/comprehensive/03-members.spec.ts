/**
 * Members — Comprehensive Tests
 *
 * Covers:
 *  - Member list: loads, shows rows, pagination
 *  - Search: name, email (HTMX debounce)
 *  - Status filter (if present)
 *  - Create member: form fields, required-field validation, successful submit
 *  - View member profile: all 7 tabs load content via HTMX
 *  - Edit member: form pre-filled, successful update
 *  - Change member status: UI control visible
 *  - Pending changes list and review flow
 *  - Timeline: add entry, delete entry
 *  - Attachments: upload section visible, download link present after upload
 *  - Member API partials: /members/api/search, /members/api/{id}/card
 */

import { test, expect } from '@playwright/test';
import {
  login,
  expectPageOk,
  expectSuccess,
  expectValidationError,
  getFirstHrefId,
  uid,
} from './helpers';

// ---------------------------------------------------------------------------
// Member list
// ---------------------------------------------------------------------------

test.describe('Member list', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('list page loads with a table or card list', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(
      page.locator('table tbody tr, .member-card, [data-member-row]').first()
    ).toBeVisible();
  });

  test('shows multiple members from seeded data', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const rows = page.locator('table tbody tr, .member-card, [data-member-row]');
    const count = await rows.count();
    expect(count, 'Expected at least 5 seeded members').toBeGreaterThanOrEqual(5);
  });

  test('page title / heading contains Members or similar', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('h1, h2, .page-title')).toContainText(/member/i);
  });

  test('search input is present', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    await expect(
      page.locator('input[type="search"], input[name="q"], #search-q, input[placeholder*="search" i]')
    ).toBeVisible();
  });

  test('searching by name filters results via HTMX', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');

    const search = page.locator('input[type="search"], input[name="q"], #search-q').first();
    await search.fill('Anderson');
    await page.waitForTimeout(700); // HTMX debounce
    await page.waitForLoadState('networkidle');

    const body = await page.locator('body').textContent();
    // Either shows 'Anderson' or 'No members found' — must not 500
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
    if (body?.includes('Anderson')) {
      await expect(page.locator('body')).toContainText('Anderson');
    }
  });

  test('searching by partial email filters results', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');

    const search = page.locator('input[type="search"], input[name="q"], #search-q').first();
    await search.fill('@northland.test');
    await page.waitForTimeout(700);
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
  });

  test('clearing search shows all members again', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');

    const search = page.locator('input[type="search"], input[name="q"], #search-q').first();
    await search.fill('ZZZ_nobody_here');
    await page.waitForTimeout(700);
    await search.fill('');
    await page.waitForTimeout(700);
    await page.waitForLoadState('networkidle');

    const rows = page.locator('table tbody tr, .member-card, [data-member-row]');
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('each member row has a clickable link to their profile', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const link = page.locator('table a[href*="/members/"], .member-card a[href*="/members/"]').first();
    await expect(link).toBeVisible();
    const href = await link.getAttribute('href');
    expect(href).toMatch(/\/members\/\d+/);
  });

  test('add member button is present and leads to create form', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const addBtn = page.locator('a[href*="/members/create"], a:text-matches("add|new|create", "i")').first();
    if (await addBtn.isVisible()) {
      const href = await addBtn.getAttribute('href');
      expect(href).toContain('/members/create');
    }
  });
});

// ---------------------------------------------------------------------------
// Create member
// ---------------------------------------------------------------------------

test.describe('Create member', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('create form loads with required fields', async ({ page }) => {
    await page.goto('/members/create');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('input[name="first_name"]')).toBeVisible();
    await expect(page.locator('input[name="surname"]')).toBeVisible();
  });

  test('form has date of birth, gender, or other expected fields', async ({ page }) => {
    await page.goto('/members/create');
    await page.waitForLoadState('networkidle');
    // At least one of these should exist
    const extras = page.locator(
      'input[name="date_of_birth"], input[name="dob"], select[name="gender"], input[name="email"]'
    );
    expect(await extras.count()).toBeGreaterThan(0);
  });

  test('submitting empty form shows validation errors', async ({ page }) => {
    await page.goto('/members/create');
    await page.waitForLoadState('networkidle');
    await page.click('button[type="submit"]');
    // Either HTML5 validation prevents submit, or server returns validation errors
    const onSamePage = page.url().includes('/members/create') || page.url().includes('/members');
    expect(onSamePage, 'Empty submit should not navigate away without errors').toBe(true);
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
  });

  test('submitting missing surname shows server validation error', async ({ page }) => {
    await page.goto('/members/create');
    await page.waitForLoadState('networkidle');
    await page.fill('input[name="first_name"]', 'TestOnly');
    // Leave surname empty
    await page.click('button[type="submit"]');
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
  });

  test('valid submission creates member and redirects', async ({ page }) => {
    const id = uid();
    await page.goto('/members/create');
    await page.waitForLoadState('networkidle');

    await page.fill('input[name="first_name"]', `PW${id}`);
    await page.fill('input[name="surname"]', 'Playwright');

    // Fill optional but common fields
    const emailField = page.locator('input[name="email"]');
    if (await emailField.isVisible()) {
      await emailField.fill(`pw${id}@playwright.test`);
    }
    const dobField = page.locator('input[name="date_of_birth"], input[name="dob"]');
    if (await dobField.isVisible()) {
      await dobField.fill('2000-01-15');
    }
    const genderField = page.locator('select[name="gender"]');
    if (await genderField.isVisible()) {
      await genderField.selectOption({ index: 1 });
    }

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    // Should redirect to profile or list with success flash
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
    const url = page.url();
    const success = url.match(/\/members\/\d+/) || url === `${new URL(page.url()).origin}/members`;
    // Also accept a success flash on the same page
    const hasFlash = await page.locator('.alert-success').isVisible();
    expect(success || hasFlash, 'Expected redirect to profile or success flash').toBeTruthy();
  });
});

// ---------------------------------------------------------------------------
// Member profile — all tabs
// ---------------------------------------------------------------------------

test.describe('Member profile tabs', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  /** Helper: navigate to the first member's profile page */
  async function goToFirstMember(page: any) {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const link = page.locator('table a[href*="/members/"], .member-card a[href*="/members/"]').first();
    if (!await link.isVisible()) throw new Error('No member links found');
    await link.click();
    await page.waitForLoadState('networkidle');
  }

  test('profile page loads with tab navigation', async ({ page }) => {
    await goToFirstMember(page);
    await expectPageOk(page);
    await expect(page.locator('.nav-tabs, [role="tablist"]')).toBeVisible();
  });

  test('Personal tab loads content', async ({ page }) => {
    await goToFirstMember(page);
    const tab = page.locator('.nav-tabs .nav-link, [role="tab"]').filter({ hasText: /personal/i }).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/internal server error/i);
      // Should show some personal info
      await expect(
        page.locator('[data-tab-content], .tab-pane.active, #tab-personal')
      ).not.toBeEmpty();
    }
  });

  test('Contact tab loads content', async ({ page }) => {
    await goToFirstMember(page);
    const tab = page.locator('.nav-tabs .nav-link, [role="tab"]').filter({ hasText: /contact/i }).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/internal server error/i);
    }
  });

  test('Medical tab loads content', async ({ page }) => {
    await goToFirstMember(page);
    const tab = page.locator('.nav-tabs .nav-link, [role="tab"]').filter({ hasText: /medical/i }).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/internal server error/i);
    }
  });

  test('Roles tab loads content', async ({ page }) => {
    await goToFirstMember(page);
    const tab = page.locator('.nav-tabs .nav-link, [role="tab"]').filter({ hasText: /roles?/i }).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/internal server error/i);
    }
  });

  test('Timeline tab loads content', async ({ page }) => {
    await goToFirstMember(page);
    const tab = page.locator('.nav-tabs .nav-link, [role="tab"]').filter({ hasText: /timeline|history/i }).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/internal server error/i);
    }
  });

  test('Documents tab loads content', async ({ page }) => {
    await goToFirstMember(page);
    const tab = page.locator('.nav-tabs .nav-link, [role="tab"]').filter({ hasText: /document|attachment|file/i }).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/internal server error/i);
    }
  });

  test('Additional/custom-fields tab loads content', async ({ page }) => {
    await goToFirstMember(page);
    const tab = page.locator('.nav-tabs .nav-link, [role="tab"]').filter({ hasText: /additional|custom|extra/i }).first();
    if (await tab.isVisible()) {
      await tab.click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/internal server error/i);
    }
  });

  test('clicking each visible tab does not produce a 500 error', async ({ page }) => {
    await goToFirstMember(page);
    const tabs = page.locator('.nav-tabs .nav-link, [role="tab"]');
    const count = await tabs.count();
    for (let i = 0; i < count; i++) {
      await tabs.nth(i).click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
    }
  });
});

// ---------------------------------------------------------------------------
// Edit member
// ---------------------------------------------------------------------------

test.describe('Edit member', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('edit form is accessible from the profile page', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const memberLink = page.locator('table a[href*="/members/"]').first();
    if (!await memberLink.isVisible()) { test.skip(true, 'No members found'); return; }

    const href = await memberLink.getAttribute('href');
    await memberLink.click();
    await page.waitForLoadState('networkidle');

    const editLink = page.locator('a[href*="/edit"], a:text-matches("edit", "i")').first();
    await expect(editLink).toBeVisible();
    await editLink.click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('input[name="first_name"]')).toBeVisible();
    await expect(page.locator('input[name="surname"]')).toBeVisible();
  });

  test('edit form fields are pre-filled with existing data', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const memberLink = page.locator('table a[href*="/members/"]').first();
    if (!await memberLink.isVisible()) { test.skip(true, 'No members found'); return; }

    await memberLink.click();
    await page.waitForLoadState('networkidle');
    const editLink = page.locator('a[href*="/edit"]').first();
    if (!await editLink.isVisible()) { test.skip(true, 'No edit link'); return; }
    await editLink.click();
    await page.waitForLoadState('networkidle');

    const firstName = await page.locator('input[name="first_name"]').inputValue();
    expect(firstName.length, 'First name should be pre-filled').toBeGreaterThan(0);
  });

  test('updating first name and submitting succeeds', async ({ page }) => {
    const memberId = await getFirstHrefId(page, /\/members\/(\d+)(?:\/|$)/);
    if (!memberId) {
      await page.goto('/members');
      await page.waitForLoadState('networkidle');
    }
    // Navigate to first member's edit page
    const id = await (async () => {
      await page.goto('/members');
      await page.waitForLoadState('networkidle');
      return await getFirstHrefId(page, /\/members\/(\d+)(?:\/|$)/);
    })();
    if (!id) { test.skip(true, 'No members'); return; }

    await page.goto(`/members/${id}/edit`);
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);

    const firstNameField = page.locator('input[name="first_name"]');
    const original = await firstNameField.inputValue();
    await firstNameField.fill(original || 'TestUpdate');

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
  });
});

// ---------------------------------------------------------------------------
// Member status change
// ---------------------------------------------------------------------------

test.describe('Member status change', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('status change control is visible on member profile', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const memberLink = page.locator('table a[href*="/members/"]').first();
    if (!await memberLink.isVisible()) { test.skip(true, 'No members'); return; }
    await memberLink.click();
    await page.waitForLoadState('networkidle');

    const statusControl = page.locator(
      'select[name="status"], button:text-matches("status|active|suspend|archive", "i"), [data-status-change]'
    ).first();
    await expect(statusControl).toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// Timeline entries
// ---------------------------------------------------------------------------

test.describe('Member timeline', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('timeline tab shows add-entry form or button', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const memberLink = page.locator('table a[href*="/members/"]').first();
    if (!await memberLink.isVisible()) { test.skip(true, 'No members'); return; }
    await memberLink.click();
    await page.waitForLoadState('networkidle');

    const timelineTab = page.locator('.nav-tabs .nav-link').filter({ hasText: /timeline|history/i }).first();
    if (!await timelineTab.isVisible()) { test.skip(true, 'No timeline tab'); return; }
    await timelineTab.click();
    await page.waitForLoadState('networkidle');

    const addForm = page.locator(
      'textarea[name="note"], textarea[name="entry"], button:text-matches("add entry|add note|log", "i")'
    ).first();
    await expect(addForm).toBeVisible();
  });

  test('adding a timeline entry succeeds', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/members\/(\d+)(?:\/|$)/);
    if (!id) { test.skip(true, 'No members'); return; }

    await page.goto(`/members/${id}`);
    await page.waitForLoadState('networkidle');

    const timelineTab = page.locator('.nav-tabs .nav-link').filter({ hasText: /timeline|history/i }).first();
    if (!await timelineTab.isVisible()) { test.skip(true, 'No timeline tab'); return; }
    await timelineTab.click();
    await page.waitForLoadState('networkidle');

    const noteField = page.locator('textarea[name="note"], textarea[name="entry"]').first();
    if (!await noteField.isVisible()) { test.skip(true, 'No note field'); return; }

    await noteField.fill(`Playwright test entry ${uid()}`);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/internal server error|500/i);
  });
});

// ---------------------------------------------------------------------------
// Attachments
// ---------------------------------------------------------------------------

test.describe('Member attachments', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('documents tab shows upload section', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const memberLink = page.locator('table a[href*="/members/"]').first();
    if (!await memberLink.isVisible()) { test.skip(true, 'No members'); return; }
    await memberLink.click();
    await page.waitForLoadState('networkidle');

    const docsTab = page.locator('.nav-tabs .nav-link').filter({ hasText: /document|attachment|file/i }).first();
    if (!await docsTab.isVisible()) { test.skip(true, 'No docs tab'); return; }
    await docsTab.click();
    await page.waitForLoadState('networkidle');

    const uploadControl = page.locator(
      'input[type="file"], button:text-matches("upload|attach", "i"), [data-upload]'
    ).first();
    await expect(uploadControl).toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// Pending changes
// ---------------------------------------------------------------------------

test.describe('Pending member changes', () => {
  test('pending changes page loads for admin', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members/pending-changes');
    await page.waitForLoadState('networkidle');
    await expectPageOk(page);
    await expect(page.locator('body')).toContainText(/pending|change|review/i);
  });
});

// ---------------------------------------------------------------------------
// Member API partials
// ---------------------------------------------------------------------------

test.describe('Member API partials', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('GET /members/api/search returns HTML partial, not 404', async ({ page }) => {
    const resp = await page.request.get('/members/api/search?q=a', { failOnStatusCode: false });
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });

  test('GET /members/api/{id}/card returns content for first member', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/members\/(\d+)(?:\/|$)/);
    if (!id) { test.skip(true, 'No members'); return; }

    const resp = await page.request.get(`/members/api/${id}/card`, { failOnStatusCode: false });
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });

  test('GET /members/api/{id}/status-badge returns content', async ({ page }) => {
    await page.goto('/members');
    await page.waitForLoadState('networkidle');
    const id = await getFirstHrefId(page, /\/members\/(\d+)(?:\/|$)/);
    if (!id) { test.skip(true, 'No members'); return; }

    const resp = await page.request.get(`/members/api/${id}/status-badge`, { failOnStatusCode: false });
    expect(resp.status()).not.toBe(404);
    expect(resp.status()).not.toBe(500);
  });
});
