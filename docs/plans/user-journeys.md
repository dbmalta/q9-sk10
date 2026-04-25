# ScoutKeeper v1 — User Journeys, Feature Coverage & Test Alignment

> v1 scope as of features.md (2026-04-08). Parent/guardian accounts, youth badge tracking, finance, newsletter, event registration all dropped from v1.

---

## User types in scope for v1

| ID | User type | Description |
|----|-----------|-------------|
| A | **System installer** | The person who installs the system for the first time (NSO IT admin or technical leader) |
| B | **Organisation admin** | NSO or district staff with full or near-full system access |
| C | **Scoped admin / leader-admin** | A group leader or district commissioner who has admin access scoped to their part of the tree |
| D | **Dual-role user** | Any person who is both a leader (admin role in some scope) and a member — uses the view switcher |
| E | **Member only** | A member with no admin role — uses My Account / member portal only |
| F | **Prospective member** | Someone not yet in the system — self-registers or lands on waiting list |

> **Not in v1:** parent/guardian as a distinct account type, read-only auditor account, youth members with badge tracking.

---

## A — System installer

### Journey

```
1. Download zip from GitHub releases
2. Upload to web host / unzip to public_html
3. Navigate to site → auto-redirect to /setup
4. Setup wizard:
   a. Pre-flight check (PHP version, extensions, write permissions)
   b. Database connection details
   c. Run migrations
   d. Organisation name, logo upload
   e. Create first admin account (email + password)
   f. Optionally configure SMTP
   g. Configure encryption key (for medical data)
   h. Complete → redirect to /admin/dashboard
5. First-time admin tasks (→ flows into User B journey)
```

### v1 feature coverage

| Step | In v1? | Notes |
|------|--------|-------|
| Setup wizard with pre-flight | Yes | `/app/src/Setup/` |
| DB migration runner | Yes | Numbered SQL files |
| Org name + logo | Yes | Settings module |
| First admin account creation | Yes | Part of setup |
| SMTP config | Yes | Admin settings |
| Encryption key setup | Yes | `config/encryption.key` |
| Signed update verification | Yes | Updater subsystem |
| Rollback to previous version | Yes | One-cycle retention |

### Test coverage

| Test | Covered? |
|------|----------|
| PHPUnit: SetupWizard tests | Partial (tests/SetupWizard/) |
| E2E: Setup wizard flow | **Not covered** |
| E2E: First admin login after setup | **Not covered** |
| PHPUnit: Migration runner | Not directly tested |

### Gaps

- [ ] No E2E spec for the setup wizard path — critical first-use journey with zero test coverage
- [ ] No test for the pre-flight failure path (missing extension, wrong PHP version)
- [ ] No test for the updater's signature verification or rollback path

---

## B — Organisation admin

### Journey

```
1. LOGIN
   → /login (email + password)
   → MFA prompt if TOTP enabled
   → /admin/dashboard

2. INITIAL SYSTEM CONFIGURATION (one-off)
   → Admin > Settings: org name, logo, SMTP, date formats, locale
   → Admin > T&Cs: write first membership conditions, publish
   → Admin > Notices: create any login notices
   → Admin > Org Structure: build the hierarchy (national → region → district → group → section)
   → Admin > Custom Fields: define extra member fields

3. ROLE & PERMISSION SETUP
   → Permissions > Roles: create roles (e.g. "Group Scout Leader", "Section Leader", "Member")
   → Set module-level read/write, can_publish_events, can_access_medical flags per role
   → Permissions > Assignments: assign roles to members with scope (node + descendants)

4. MEMBER MANAGEMENT (recurring)
   → Members > List: search, filter by node/status/role
   → Members > Add: admin-add new member (active immediately)
   → Members > Profile: view/edit member details, timeline entries
   → Members > Attachments: upload files (medical forms, consent)
   → Members > Bulk Import: download template, fill, upload, review errors, import
   → Members > Waiting List: review prospective applicants, approve or decline
   → Members > Registration Queue: approve self-registrations
   → Members > Self-Edit Queue: review pending member-initiated profile changes

5. COMMUNICATIONS
   → Communications > Articles: create article, set audience (public/private/by level), publish
   → Communications > Email: compose email, target by node/role/criteria, queue for send
   → Communications > Email Log: review sent emails, see delivery status

6. EVENTS
   → Events > Calendar: view all events
   → Events > New Event: create event (if has can_publish_events flag)

7. ACHIEVEMENTS
   → Achievements > Definitions: create achievement/training course definitions
   → Achievements > Award: assign achievement to a member

8. DIRECTORY
   → Directory > Organogram: view org tree (filtered to their scope)
   → Directory > Contacts: view contact list of leaders/commissioners

9. REPORTS & AUDIT
   → Admin > Reports: membership counts, role assignment report
   → Admin > Audit Log: review record changes
   → Admin > Export: download CSV/Excel of member data
   → Admin > Backup: download full backup zip

10. ACCOUNT MAINTENANCE
    → My Account: change password, enable MFA, update own details
    → "Download my data" for themselves

11. LOGOUT
    → /logout → /login
```

### v1 feature coverage

| Area | In v1? |
|------|--------|
| Login / MFA / password reset | Yes |
| Admin dashboard | Yes |
| Org structure (unlimited nested levels) | Yes |
| Custom fields (5 types, JSON stored) | Yes |
| Role/permission CRUD | Yes |
| Role assignments with scope | Yes |
| Member CRUD + timeline | Yes |
| Medical data (encrypted) | Yes |
| Attachments | Yes |
| Bulk import with preview | Yes |
| Waiting list management | Yes |
| Self-registration approval | Yes |
| Self-edit approval queue | Yes |
| Articles | Yes |
| Email compose + queue + cron | Yes |
| Events (create/view) | Yes |
| Achievement definitions + award | Yes |
| Organogram + contacts directory | Yes |
| Reports | Yes |
| Audit log | Yes |
| CSV/Excel export | Yes |
| Backup download | Yes |
| T&Cs management | Yes |
| Login notices | Yes |
| Language overrides | Yes |

### Test coverage

| Area | PHPUnit | E2E |
|------|---------|-----|
| Login / MFA | Yes | 7/8 passing |
| Dashboard | Partial | 3/4 passing |
| Org structure | Yes (OrgServiceTest) | **4 failing (404)** |
| Member list/search | Partial | 1/4 passing |
| Member add | No dedicated unit test | **Failing** |
| Member profile/edit | SelfEditWorkflowTest | **Failing** |
| Bulk import | No | **No E2E spec** |
| Waiting list | No | **No E2E spec** |
| Registration queue | No | **6 failing (404)** |
| Self-edit queue | SelfEditWorkflowTest (6 real-DB) | No E2E |
| Communications/articles | Partial | 2/5 passing |
| Email compose/send | No | **No E2E spec** |
| Events | No | 1/4 passing |
| Achievements | No | **3 failing (404)** |
| Directory | No | **4 failing (404)** |
| Reports | Partial | Passing |
| Audit log | No dedicated | Passing |
| Export | No | Passing |
| Backup | No | **403 error** |
| T&Cs admin | No | 1/3 passing |
| Login notices | No | **Failing** |
| Permissions CRUD | No | **No E2E spec** |
| Role assignments | No | **No E2E spec** |

### Gaps

- [ ] Bulk import has no tests at all — high-risk feature with row-level error logic
- [ ] Waiting list has no tests
- [ ] Permissions/role assignment UI has no E2E spec
- [ ] Email compose → queue → cron → send pipeline has no E2E coverage
- [ ] Backup is broken (403 — likely permissions check mismatch)
- [ ] Achievements routes all return 404
- [ ] Directory routes all return 404

---

## C — Scoped admin / leader-admin

The journey is identical to User B except all data access is filtered to their assigned scope nodes.

```
- All member list queries automatically filtered to their assigned scope nodes
- Org structure view shows their subtree only
- Role assignment UI: they can only assign roles they themselves hold (or a subset)
- Reports are scoped to their nodes
- They cannot access system-level admin settings (SMTP, backup, etc.)
```

### v1 feature coverage

| Scoping behaviour | In v1? |
|-------------------|--------|
| Member list filtered by scope | Yes (role_assignment_scopes) |
| Org tree filtered to scope | Yes |
| Settings page blocked for scoped users | Yes |
| Backup blocked for scoped users | Yes |
| Permission to assign only sub-roles | Partial — not fully enforced |

### Test coverage

| Behaviour | Covered? |
|-----------|----------|
| Scoped member list | No E2E |
| Settings blocked for scoped user | E2E: partial (admin.spec.ts 403 on backup) |
| Scope filtering on org tree | org-tree.spec.ts (passing) |
| Scope picker / view switcher | view-switcher.spec.ts (partially covered, high-priority gaps remain) |

### Gaps

- [ ] No E2E test showing a scoped leader cannot see members outside their scope
- [ ] No test for "scoped admin tries to assign a role broader than their own"
- [ ] ViewContextController endpoint integration tests flagged as high priority in test-coverage-gaps.md

---

## D — Dual-role user (leader + member)

The most complex v1 user type — a person who is both a member and holds an admin role.

### Journey

```
1. LOGIN → /login → /admin/dashboard (default for admin mode)

2. VIEW SWITCHER (admin mode ↔ member mode)
   → Mode pills visible in top bar (admin / member)
   → Scope dropdown visible in admin mode
   → Click "Member" → POST /context/mode → redirect to /me

3. ADMIN MODE TASKS
   → Same as User C above (scoped to their role assignment)

4. MEMBER MODE TASKS
   → /me: dashboard — upcoming events, recent articles, notices
   → /me/profile: view own member record (read-only core fields)
   → /me/profile/edit: edit own details → submits to self-edit queue (NOT applied directly)
   → /me/events: event calendar
   → /me/events/{id}: event detail + iCal subscribe link
   → /me/achievements: own achievements and training records
   → /me/communications: articles relevant to them

5. ACCOUNT SETTINGS (available in both modes)
   → /account/password: change password
   → /account/mfa: enable/disable TOTP MFA
   → /account/preferences: communication preferences
   → /account/data: download my data

6. T&Cs ACKNOWLEDGEMENT (forced gate)
   → If T&Cs version updated → forced to /terms/acknowledge before accessing any page
   → Accept → proceed; Decline → account flagged, access blocked, admin notified

7. NOTICE ACKNOWLEDGEMENT (forced gate on login)
   → If unacknowledged notice exists → shown on login → must click acknowledge to proceed

8. LOGOUT
```

### v1 feature coverage

| Feature | In v1? |
|---------|--------|
| View switcher (admin ↔ member mode) | Yes |
| Scope picker in admin mode | Yes |
| /me dashboard | Yes |
| /me/profile (view + edit queue) | Yes |
| /me/events | Yes |
| /me/achievements | Yes |
| /me/communications | Yes |
| Self-edit queue (not direct apply) | Yes |
| T&Cs forced acknowledgement gate | Yes |
| Notice forced acknowledgement on login | Yes |
| MFA setup/disable | Yes |
| Download my data | Yes |

### Test coverage

| Feature | PHPUnit | E2E |
|---------|---------|-----|
| View switcher | ViewContextTest, ViewContextServiceTest | view-switcher.spec.ts (partially covered, gaps remain) |
| /me dashboard | MemberDashboardServiceTest (4 real-DB) | **No E2E spec** |
| /me/profile view | No | **No E2E** |
| /me/profile/edit → queue | MemberSelfEditTest, SelfEditWorkflowTest | **No E2E** (high priority per gaps doc) |
| T&Cs gate | No | 1/3 passing, 2 failing |
| Notice gate | No | **Failing** |
| MFA setup | No | **No E2E** |
| Download my data | No | **No E2E** |
| Mode-aware nav | ModuleRegistryTest | **No E2E** (medium priority per gaps doc) |

### Gaps

- [ ] Self-edit E2E (member edits → admin approves → member sees change) — high priority per gaps doc
- [ ] /me dashboard has 4 unit tests but no E2E spec
- [ ] T&Cs acknowledgement gate: 2/3 E2E tests failing
- [ ] Notice acknowledgement E2E failing
- [ ] No test for T&Cs decline path (account gets flagged, access blocked)
- [ ] No test for MFA setup/disable flow
- [ ] Mode-aware nav not covered in E2E
- [ ] View switcher: 5 specific missing cases listed in test-coverage-gaps.md

---

## E — Member only

A person with no admin role. Can only access `/me/*`. No mode switcher pills shown.

### Journey

```
1. ACCOUNT CREATION (admin-created or self-registered — see User F)
   → Either: admin creates them directly (active immediately)
   → Or: they self-registered and were approved (receive email with login link)

2. FIRST LOGIN
   → /login → password set (if first-time link) → notice acknowledgement → T&Cs acknowledgement → /me

3. MEMBER PORTAL
   → /me: dashboard — upcoming events, relevant articles, unread notices
   → /me/profile: own record (read-only for core fields)
   → /me/profile/edit: request changes (queued, not auto-applied; admin approves)
   → /me/events: event calendar
   → /me/events/{id}: event detail page
   → /me/events/feed.ics: iCal subscription URL
   → /me/achievements: own achievements and training (assigned by admin only)
   → /me/communications: articles relevant to this member

4. ACCOUNT SETTINGS
   → /account/password: change own password
   → /account/mfa: set up TOTP if they choose
   → /account/preferences: opt in/out of email types
   → /account/data: download own data as CSV

5. T&Cs / NOTICE GATES (same as User D)

6. LOGOUT
```

### v1 feature coverage

Same as User D member-mode section. The portal is the same shell; mode switcher pills are absent.

### Test coverage

Same gaps as User D member-mode section, plus:

| Behaviour | Covered? |
|-----------|----------|
| Member blocked from `/admin/*` routes | **Failing** (admin.spec.ts — test exists but fails) |
| iCal feed endpoint | **No test at any layer** |

### Gaps

- [ ] E2E asserting member-only user is blocked from all `/admin/*` routes (test exists but fails)
- [ ] No test for the iCal feed endpoint
- [ ] All /me/* gaps identical to User D member-mode section above

---

## F — Prospective member

No account yet. Entry through public-facing registration pages.

### Journey

```
PATH 1 — Self-registration (if enabled by admin)
  1. Navigate to /register (public page, no login required)
  2. Fill form: name, email, DOB, preferred unit/group
  3. Submit → account created in "pending" status
  4. Admin sees pending registration in queue → approves
  5. Member receives email with login setup link
  6. Member sets password → first login → portal (User E journey continues)

PATH 2 — Waiting list
  1. Navigate to /register → shown waiting list form (if registration is closed/full)
  2. Fill interest form: name, email, contact details
  3. Submit → waiting list entry created
  4. Admin reviews: approves (creates member record + invite) or declines (notification email)
  5. If approved → same as Path 1 step 5 onwards

PATH 3 — Register by invitation
  1. Admin sends invite email to a specific address
  2. Prospective member receives email with unique invite link
  3. Clicks link → /register/invite/{token}
  4. Fills in details → account created active immediately
  5. First login → portal
```

### v1 feature coverage

| Feature | In v1? |
|---------|--------|
| Self-registration form | Yes |
| Pending status + admin approval queue | Yes |
| Waiting list form + admin management | Yes |
| Register by invitation | Yes |
| Welcome/invite email sent on approval | Yes (via email queue + cron) |
| Public registration page (no login required) | Yes |

### Test coverage

| Feature | PHPUnit | E2E |
|---------|---------|-----|
| Self-registration | No | **6 failing (all 404)** |
| Admin approval queue | No | **No spec** |
| Waiting list | No | **No spec** |
| Invite registration | No | **No spec** |
| Welcome email sent on approval | No | **No spec** |

### Gaps

- [ ] All registration E2E specs failing with 404 — `/registration/*` routes not resolving
- [ ] Full registration lifecycle (submit → queue → approve → email → login) has no test at any layer
- [ ] Waiting list has zero coverage anywhere
- [ ] Invite registration has zero coverage anywhere

---

## Cross-cutting gaps summary

### Critical (broken in E2E — journey step unreachable)

| Gap | Affected users | Next action |
|-----|---------------|-------------|
| `/registration/*` routes all 404 | F | Fix route registration in module.php |
| `/achievements` routes all 404 | B, C, D, E | Fix route registration in module.php |
| `/directory/*` routes all 404 | B, C, D, E | Fix route registration in module.php |
| `/org-structure` routes all 404 | B, C | Fix route registration in module.php |
| Backup returns 403 | B | Fix permission check |
| Logout doesn't redirect to /login | All | Fix redirect in Auth controller |
| Notice acknowledgement E2E failing | D, E | Debug terms/notices spec |

### High (journey step exists but untested at any layer)

| Gap | Affected users | Notes |
|-----|---------------|-------|
| Setup wizard has no E2E | A | Entire onboarding flow untested |
| Bulk import has zero tests | B, C | High-risk: row-level error logic |
| Waiting list has zero tests | B, C, F | |
| Invite registration has zero tests | B, C, F | |
| Permissions/role assignment UI has no E2E | B, C | Core to every scoped user |
| Email compose → queue → send has no E2E | B, C | Cron path untested |
| Self-edit E2E (full lifecycle) | D, E | High priority per test-coverage-gaps.md |
| Member blocked from /admin/* | E | Test exists but failing |
| T&Cs decline path (account blocked) | D, E | No test at any layer |
| iCal feed | D, E | No test at any layer |
| MFA setup/disable | All | No test at any layer |
| Download my data | D, E | No test at any layer |

### Medium (unit tests exist, E2E missing)

| Gap | Notes |
|-----|-------|
| /me dashboard | 4 unit tests, no E2E |
| View switcher mode pills and scope picker | Partially covered; 5 specific cases missing per test-coverage-gaps.md |
| Mode-aware sidebar nav | ModuleRegistryTest passes, no E2E |
| Admin-only buttons hidden in member mode | No E2E |

---

## Recommended priorities

1. **Fix the 404 routes** (achievements, directory, org-structure, registration) — without these, B/C/F journeys are broken in prod, not just in tests
2. **Fix logout redirect** — affects every user's exit
3. **Fix backup 403** — breaks User B's journey
4. **Write registration E2E** — covers User F's entire journey end-to-end
5. **Write permissions/role assignment E2E** — everything downstream depends on roles being assigned correctly
6. **Write self-edit lifecycle E2E** — already flagged high priority in test-coverage-gaps.md
7. **Write setup wizard E2E** — User A has no coverage at all
8. **Add bulk import unit tests** — most complex failure-state logic with no safety net
