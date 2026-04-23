# Test Coverage Gaps — Post Phase 1–3 + Self-Service

Snapshot after the View Switcher + Member Self-Service + /me dashboard work.
Recorded so the next contributor (or future me) doesn't have to re-derive it.

## Unit / integration covered

| Component | File | Notes |
|---|---|---|
| `ViewContext` value object | `tests/Core/ViewContextTest.php` | 8 tests — mode validation, scope flags, picker visibility rules |
| `ViewContextService` resolution | `tests/Core/ViewContextServiceTest.php` | 14 tests, mocked — URL > session > user default precedence, fallback, persistence |
| `ModuleRegistry` mode filter | `tests/Core/ModuleRegistryTest.php` | `testNavFilteredByMode` + `testNavWithNoModesDeclaredDefaultsToAdminOnly` |
| `Response::html` Cache-Control | `tests/Core/ResponseTest.php` | `testHtmlFactorySetsNoStoreCacheControl` — regression guard |
| `MemberService::submitSelfEdit` | `tests/Modules/Members/MemberSelfEditTest.php` (mocked, 6) + `SelfEditWorkflowTest.php` (real DB, 6) | Covers queueing, never-applies-directly, NEVER-list rejection, email normalisation, duplicate suppression, resubmit after approval |
| `MemberDashboardService::load` | `tests/Modules/Members/MemberDashboardServiceTest.php` | 4 real-DB tests — node hydration, future-only events, article dedup, notice acknowledgement lifecycle |

## Gaps — prioritised

### High priority
1. **`ViewContextController` endpoints** — integration test for `POST /context/mode` and `POST /context/scope`.
   - Valid mode switches land on `/`; invalid modes flash error and stay put.
   - Valid scope persists to session + `users.scope_node_id_last`.
   - Invalid scope produces an audit row and does not persist.
   - Same-origin `redirect_to` only (no `//evil.host`).

2. **`DashboardController::root()` mode-aware redirect** — integration test that asserts admin-mode users go to `/admin/dashboard`, member-mode users go to `/me`, unauthenticated go to `/login`.

3. **Switcher E2E** — `tests/e2e/specs/view-switcher/` (new directory):
   - Mode pills render only for dual-role users (`leader@northland.test`).
   - Clicking a pill POSTs, redirects to `/`, landing on the correct dashboard.
   - Scope dropdown lists `role_assignment_scopes`, hidden in member mode.
   - Scope picker hidden on a non-scopable page (once a controller overrides `scopeAppliesToCurrentPage()`).
   - Mobile viewport: offcanvas contains the mobile variant; desktop one is hidden.

4. **Self-edit E2E** — end-to-end: member logs in → flips to member mode → visits `/me/profile/edit` → submits a change → admin reviews queue → change applied, member's view reflects it. Guards the "approved changes stay listed" regression at the full stack.

### Medium priority
5. **Member-mode nav** — E2E: `/me` renders; sidebar contains exactly `Dashboard / View Profile / Edit Profile`; switching to admin replaces the sidebar with the admin nav.

6. **Gated admin UI on shared templates** — E2E: leader views their own member profile in member mode; status-change form + Edit button should be absent. In admin mode on the same URL, both should be present.

### Lower priority (hardening)
7. **Route-level scopable flag** — if we ever add the planned `'scopable' => true` attribute on route definitions (deferred in Phase 3), unit-test the router picks it up and `ViewContext::scopeAppliesToCurrentPage` is driven by it rather than per-controller overrides.

8. **Seed data matrix** — once Phase 4 Members retrofit is in, extend the seeder to create explicit "archetype users" (dual-role leader, admin-only coordinator, pure parent, single-scope leader) so every E2E can pick one by email.

9. **Language overlays** — DB `i18n_overrides` for `view.*` keys aren't exercised. Low risk (same code path as every other key), low priority.

## E2E specs at risk of breakage from recent changes

Flagged for whoever adds the Phase-4 E2E coverage — not yet verified, not yet fixed:

- `tests/e2e/specs/auth.spec.ts:52` — direct `/admin/dashboard` goto; should still work for the admin user.
- `tests/e2e/specs/comprehensive/01-navigation.spec.ts:83–98` — "clicking logo returns to dashboard"; logo now points at `/` and redirects based on mode. OK for admin user, needs a mode-aware assertion if re-run for member.
- `tests/e2e/specs/comprehensive/02-auth.spec.ts:288` — list of post-login routes; doesn't currently include `/me`, so no breakage, but should be extended.
- `tests/e2e/specs/comprehensive/03-members.spec.ts` — any test that asserts presence of admin-only buttons on `/members/{id}` will fail in member mode because of the `view.mode == 'admin'` guard. Currently they log in as admin so they're fine.

## Not in scope for this doc

The full retrofit test plan (per-module scope tests in Phase 5+) is covered by the main `view-switcher-plan.md`. This file only tracks gaps the recent shipped work leaves behind.
