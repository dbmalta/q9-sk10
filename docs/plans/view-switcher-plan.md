# View Switcher — Implementation Plan

Companion to [view-switcher-sketch.html](view-switcher-sketch.html). Captures the build order, file-level changes, and test coverage for the Member/Admin mode + node scope feature agreed across 40 design questions.

## Guiding principles

1. **Security is enforced by capability checks, not by the switcher.** The scope/mode pair is a filter and a UX affordance. Every controller still calls explicit capability checks against the active scope.
2. **URL is truth.** `?mode=` and `?scope=` on a request override session; session only seeds the initial value after login.
3. **Build the plumbing once; retrofit modules incrementally.** Members is the template. Other modules follow the same pattern.
4. **No feature flag.** Pre-v1, no existing users. Ship it on `main`.

---

## Phase 1 — Foundation (ViewContext + session + DB)

Goal: establish the service, session plumbing, DB columns, and the two endpoints. No visual change yet.

### DB migrations

New migration file `app/migrations/00NN_view_context.sql`:

```sql
ALTER TABLE users
    ADD COLUMN view_mode_last ENUM('admin','member') NULL,
    ADD COLUMN scope_node_id_last INT UNSIGNED NULL,
    ADD CONSTRAINT fk_users_scope_node
        FOREIGN KEY (scope_node_id_last) REFERENCES org_nodes(id) ON DELETE SET NULL;

ALTER TABLE audit_log
    ADD COLUMN node_id INT UNSIGNED NULL,
    ADD COLUMN view_mode VARCHAR(10) NULL,
    ADD INDEX idx_audit_node (node_id);

CREATE TABLE member_node_memberships (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    node_id INT UNSIGNED NOT NULL,
    role VARCHAR(80) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_member_node (member_id, node_id),
    CONSTRAINT fk_mnm_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_mnm_node FOREIGN KEY (node_id) REFERENCES org_nodes(id) ON DELETE CASCADE
);
```

### New files

- `app/src/Core/ViewContext.php` — value object holding `mode`, `scope_node_id`, `available_scopes[]`, `can_switch_to_admin`, `can_switch_to_member`.
- `app/src/Core/ViewContextService.php` — resolves the ViewContext per request from (URL params > session > user defaults), validates any submitted `node_id` against a fresh `role_assignment_scopes` query, persists last mode/scope to `users`.
- `app/modules/Core/Controllers/ViewContextController.php` — handles `POST /context/mode` and `POST /context/scope`; CSRF-validated; redirects back via `redirect_to`.

### Route registration

Add to the core module (or wherever global routes live):

```php
$r->addRoute('POST', '/context/mode',  [ViewContextController::class, 'setMode']);
$r->addRoute('POST', '/context/scope', [ViewContextController::class, 'setScope']);
```

### Middleware / view globals

Extend the Twig setup (wherever `app_lang`, `user`, `csrf_token` are injected) to add a `view` global populated from `ViewContextService::resolve()`. The `view` object exposes: `mode`, `active_scope` (or null), `available_scopes`, `can_switch_to_admin`, `can_switch_to_member`, `scope_applies_to_current_page` (driven per route).

### i18n keys

Append to `lang/en.json` under a new `view.*` namespace:

```
view.mode.member, view.mode.admin, view.mode.aria
view.scope.all, view.scope.label, view.scope.none
view.mismatch.title, view.mismatch.body
view.empty.scope_filtered, view.empty.switch_to_all
view.mode_changed_announcement, view.scope_changed_announcement
view.scope_fallback.deleted, view.scope_fallback.revoked
```

### Tests (Phase 1)

- `tests/Core/ViewContextServiceTest.php` — all resolution precedence cases (URL, session, user defaults), validation of submitted scope against role assignments, fallback when stored scope invalid, persistence of last mode/scope to users row.
- `tests/Core/ViewContextControllerTest.php` — valid/invalid scope POSTs, CSRF, redirect_to handling, audit entry on invalid submission.

**Deliverable:** service + endpoints work end-to-end; unit tests green; no UI change.

---

## Phase 2 — Topbar switcher component

Goal: render the switcher in the admin layout; wire mode/scope switching from the UI.

### New files

- `app/templates/components/_view_switcher.html.twig` — the desktop topbar component (mode pills + scope dropdown), driven by `view` global. Hides mode pills when user has no admin scopes OR no member record; hides scope dropdown when single-scope or non-scopable page.
- `app/templates/components/_view_switcher_mobile.html.twig` — offcanvas sidebar variant.

### Modified files

- `app/templates/layouts/admin.html.twig` — include `_view_switcher.html.twig` between brand and search (line ~24); include `_view_switcher_mobile.html.twig` at top of offcanvas body (line ~169); add mode class to topbar (`class="topbar ... mode-{{ view.mode }}"`); change brand `href` from `/admin/dashboard` to `/`.
- `app/templates/layouts/member.html.twig` — same accent-stripe treatment; mode pills only (no scope picker).
- `assets/css/app.css` — accent stripe:

```css
.topbar.mode-admin  { box-shadow: inset 0 -2px 0 var(--bs-primary); }
.topbar.mode-member { box-shadow: inset 0 -2px 0 var(--bs-success); }
```

- `assets/js/app.js` — Alpine component for form-dirty tracking; intercept scope/mode form submissions if any form on the page is dirty and show a confirm dialog.

### Mode-aware landing (`/` resolver)

New controller `app/modules/Core/Controllers/HomeController.php` mapped at `GET /`:
- If unauthenticated → `/login`.
- If authenticated and `view.mode === 'admin'` → `/admin/dashboard`.
- Otherwise → `/me/dashboard`.

### Accessibility

Add a persistent `aria-live="polite"` region to `base.html.twig` that the server populates with an i18n announcement when mode/scope changed on the previous request (via flash).

### Tests (Phase 2)

- E2E: `tests/e2e/specs/view-switcher/`:
  - `topbar-rendering.spec.ts` — renders correctly for each user archetype (dual-role, admin-only, member-only, single-scope, multi-scope).
  - `mode-switch.spec.ts` — clicking pill switches mode and persists on reload.
  - `scope-switch.spec.ts` — scope dropdown updates URL and session.
  - `mobile-switcher.spec.ts` — offcanvas variant.
  - `unsaved-form-confirm.spec.ts` — dirty form triggers confirm dialog.

**Deliverable:** the UI works. No data is filtered yet — every module still shows everything.

---

## Phase 3 — Nav filtering &amp; non-scopable page flag

Goal: sidebar hides admin-only nav in member mode; scope picker hides on non-scopable pages.

### Modified files

- `app/src/Core/ModuleRegistry.php` — `getNavItems($mode)` gains a mode filter. Admin-mode nav returns admin items (requires `mode === 'admin'` on each item); member-mode nav returns the member portal items.
- Each `app/modules/{X}/module.php` — nav items declare which mode(s) they appear in (`'modes' => ['admin']` default; member portal items declare `['member']` or `['admin', 'member']`).
- Route definitions — each scopable route declares `'scopable' => true` in its attributes so the controller/middleware can compute `view.scope_applies_to_current_page`. Default is non-scopable.

### Tests (Phase 3)

- Unit: `ModuleRegistryTest::testNavFilteredByMode`.
- E2E: `sidebar-visibility.spec.ts` — sidebar contents differ between modes for the same user.

**Deliverable:** nav and scope-pill visibility react correctly to mode/scope. Data still unfiltered.

---

## Phase 4 — Retrofit Members module (template)

Goal: first real module scoped end-to-end. Establishes the pattern.

### Modified files

- `app/modules/Members/Services/MemberService.php`:
  - New method `listScoped(ViewContext $ctx, array $filters = [])` that joins `org_closure` filtered by `$ctx->scopeNodeIds()` (single node, its subtree, or all the user's assignment subtrees for "All nodes").
  - Visibility cascade: include records whose `node_id` is an ancestor OR descendant of the active scope (per Q10).
  - Per-row capability attach: for each row, compute editable/deletable flags using the user's capabilities at that row's `node_id`.
- `app/modules/Members/Controllers/MembersController.php`:
  - Show: if member is outside the user's visible scopes, check if the member is in their own family → silent redirect into member mode; otherwise 404-style "not in your scope" page.
  - Bulk import: add required "target node" dropdown; pre-select from active scope when narrowing, show all writable nodes otherwise; reject submission without explicit target.
- `app/modules/Members/Services/RegistrationService.php` — applicant records carry `target_node_id`; pending queue filtered by the admin's active scope.
- `app/templates/members/list.html.twig`:
  - Add "Node" column when scope is "All nodes" or a multi-node view.
  - Empty state uses the two-query pattern from Q28: if list empty, run `existsInBroaderScope()`; render scope-aware empty state with a one-click "switch to All nodes" action.

### Tests (Phase 4)

- `tests/Modules/Members/MemberServiceScopingTest.php` — integration tests hitting a seeded DB:
  - filter by specific scope returns only records in subtree;
  - "All nodes" returns union across the user's scopes;
  - visibility cascade (ancestor/descendant) works as specified;
  - per-row capability flags correct when user has mixed rights across the tree;
  - secondary memberships make a record visible under their secondary node.
- E2E: `members-scoping.spec.ts` — switch scope, list updates; deep-link into out-of-scope record shows correct outcome.

**Deliverable:** Members module fully scoped, tested. Pattern documented in-code via comments on `MemberService::listScoped()`.

---

## Phase 5 — Retrofit remaining scopable modules

Apply the Phase 4 pattern to each module. Budget: one module per small PR.

Order (easiest first):

1. **Articles** (Communications) — single `node_id`; straightforward.
2. **Events** — includes visibility cascade + iCal feed (which ignores scope).
3. **Achievements** — scope-filters definitions and awards by node.
4. **Directory** — organogram respects scope root; contact list filtered.
5. **Communications (email queue, compose)** — compose pre-selects recipients from active scope.
6. **Audit** — add scope filter; system-level entries visible only at "All nodes"; every `AuditService::log()` call records `view_mode` + `node_id`.
7. **Reports** — every report query honours active scope.

Each module gets its own integration test file mirroring `MemberServiceScopingTest`.

---

## Phase 6 — Search, emails, exports

### Search

- `app/modules/{global search}/SearchController.php` — scope-aware by default; results include a `node_label` badge; dropdown has a "search all my nodes" toggle that flips a session flag.

### Emails

- `app/src/Core/MailService.php` — `buildLink($route, $params, LinkContext $ctx)` where `LinkContext` embeds `mode` and `scope`. Every email template chooses the link context appropriate to why the email is being sent (e.g. "pending approval" email → `admin` mode scoped to the target node; "event reminder" → `member` mode).

### Exports

- Admin > Export — filter exported rows by active scope.
- Admin > Backup — explicitly unscoped; only users with `backup.run` capability.

### Tests (Phase 6)

- `SearchScopingTest` — result filtering + toggle behaviour.
- `MailServiceTest::testLinkContextEmbeddedCorrectly`.
- `ExportScopingTest` / `BackupIgnoresScopeTest`.

---

## Phase 7 — Cron, observability

- `cron/run.php` tasks that generate user-facing links use `MailService::buildLink()` with the correct `LinkContext`.
- `AuditService::log()` additionally records `view_mode` and `node_id` from the current `ViewContext` when present; falls back to null when invoked from cron.

---

## Phase 8 — Polish

- Last-scope fallback messages when stored scope is invalid (deleted node / revoked rights). One-time flash on login.
- Scope-cache bust: hook into every write to `role_assignments` / `role_assignment_scopes` so live sessions invalidate their cached `available_scopes` list (cheap — just unset a session key, `ViewContextService` rebuilds on next request).
- Accessibility live-region announcements after any mode/scope change.
- Mobile offcanvas polish: current mode/scope visible at top of offcanvas header.

---

## Phase 9 — Test suite hardening

- Seeder `tests/seed.php` stamps `view_mode_last` on every seeded user so test fixtures start in a known state.
- Existing 35 failing E2E specs: each updated to either (a) explicitly set `?mode=` and `?scope=` in navigation, or (b) rely on the single-scope-preselect rule.
- Matrix test for capability × mode × scope boundary cases: unit-level property-style suite in `tests/Modules/Members/MemberCapabilityMatrixTest.php`.

---

## Post-implementation (not part of the feature PR)

- Update `CLAUDE.md` — add a "View Modes &amp; Scoping" section; nuance the existing "permissions are explicit, no implicit grants from hierarchy position" line to clarify that *scope* cascades via `org_closure` while *capabilities* do not.
- Save memory entry summarising the 40 resolved decisions so future sessions (and contributors) don't re-litigate them.

---

## Out of scope for v1

- Feature flag / gradual rollout (Q36a).
- Keyboard shortcut / command palette (Q30a).
- Per-module independent scopes (Q7a).
- Per-report scope override (Q17a).
- Scoped backups (Q20a — backups always full).
- Rate limiting on invalid scope submissions (Q34b).
- Full WCAG 2.1 AA audit — scheduled as an app-wide project rather than a per-feature effort.

---

## Open risks

1. **Retrofit breadth.** Every scopable query in every module must be updated. Missing one means silent data leaks. Mitigation: the integration-test pattern from Phase 4 is copied to every module in Phase 5; a grep for `SELECT ... FROM members/events/articles/etc.` not going through a `*Scoped` service is part of code review.
2. **Seed data complexity.** Test scenarios need users with varied role × node combinations. Mitigation: extend `tests/seed.php` with a set of "archetype users" (dual-role leader, admin-only coordinator, pure parent, single-scope group leader) that every E2E can reuse.
3. **i18n drift.** New `view.*` keys must be added to every language file going forward. Mitigation: the admin language-management tool (per existing modules) should flag missing keys; CI already has a lang-sync check (or add one).

---

## Rough sequencing estimate

| Phase | Effort | Parallelisable? |
|-------|--------|-----------------|
| 1     | 1 PR   | no (foundation) |
| 2     | 1 PR   | no (depends on 1) |
| 3     | 1 PR   | no (depends on 2) |
| 4     | 1 PR   | no (template)   |
| 5     | 7 PRs  | yes (one per module) |
| 6     | 2–3 PRs | partially |
| 7     | 1 PR   | yes             |
| 8     | 1 PR   | yes             |
| 9     | rolling | ongoing         |

Phases 1–4 are the critical path. Phase 5 onwards is parallelisable across contributors.
