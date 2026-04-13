# SK10 Build Progress

Last updated: 2026-04-12

**Test suite: 330 tests, 741 assertions — all passing (4 skipped: OneDrive lock + test DB)**

---

## PHASE 1: Foundation — COMPLETE

| Step | Description | Size | Status | Tests |
|------|-------------|------|--------|-------|
| 1.1 | Project Skeleton and Bootstrap | M | Done | — |
| 1.2 | Vendor Frontend Libraries | S | Done | — |
| 1.3 | Database Layer (PDO Wrapper) | M | Done | Yes |
| 1.4 | Router (fast-route) | M | Done | Yes |
| 1.5 | Twig Template Engine + Layouts + Design System | M | Done | Yes |
| 1.6 | Module Registry System | M | Done | Yes |
| 1.7 | i18n System | M | Done | Yes |
| 1.8 | Session Management + CSRF | M | Done | Yes |
| 1.9 | Error Handling and Logging | M | Done | Yes |
| 1.10 | Encryption Utility | S | Done | Yes |

## PHASE 2: Core Infrastructure Modules — COMPLETE

| Step | Description | Size | Status | Tests |
|------|-------------|------|--------|-------|
| 2.1 | Authentication Module | L | Done | Yes |
| 2.2 | Permissions System | L | Done | Yes |
| 2.3 | Org Structure (Hierarchy + Closure Table + Teams) | L | Done | Yes |

## PHASE 3: Member Management — COMPLETE

| Step | Description | Size | Status | Tests |
|------|-------------|------|--------|-------|
| 3.1 | Core Member CRUD | L | Done | 24 tests, 55 assertions |
| 3.2 | Custom Fields Engine | M | Done | 29 tests, 79 assertions |
| 3.3 | Timeline Fields | S | Done | 16 tests, 34 assertions |
| 3.4 | Attachments | S | Done | 17 tests, 38 assertions |
| 3.5 | Member Profile UI (Tabs) | M | Done | (controller + templates, no unit tests) |
| 3.6 | Registration Flows | L | Done | 59 tests, 120 assertions |

## PHASE 4: Feature Modules — COMPLETE

| Step | Description | Size | Status | Tests |
|------|-------------|------|--------|-------|
| 4.1 | Communications (Portal, Articles, Email, Cron) | L | Done | — |
| 4.2 | Events Module (Calendar, iCal) | M | Done | — |
| 4.3 | Achievements and Training | M | Done | — |
| 4.4 | Directory / Organogram | M | Done | — |

## PHASE 5: Administration — COMPLETE

| Step | Description | Size | Status | Tests |
|------|-------------|------|--------|-------|
| 5.1 | Dashboard with Stats | M | Done | — |
| 5.2 | Membership Reports | M | Done | — |
| 5.3 | T&Cs / Membership Conditions | M | Done | — |
| 5.4 | Important Notices | S | Done | — |
| 5.5 | Settings Panel | M | Done | — |
| 5.6 | Audit Log | M | Done | — |
| 5.7 | Log Viewer | S | Done | — |
| 5.8 | Data Export | S | Done | — |
| 5.9 | Backup and Restore | M | Done | — |
| 5.10 | Language Management | M | Done | — |

## PHASE 6: Installation and Updates — COMPLETE

| Step | Description | Size | Status | Tests |
|------|-------------|------|--------|-------|
| 6.1 | Setup Wizard | L | Done | 28 tests, 65 assertions |
| 6.2 | Auto-Update Mechanism | L | Done | 21 tests, 28 assertions |
| 6.3 | Monitoring Endpoints | S | Done | 9 tests, 23 assertions |

## PHASE 7: Polish and Release Prep — NOT STARTED

| Step | Description | Size | Status |
|------|-------------|------|--------|
| 7.1 | Comprehensive Synthetic Org Seeder | L | Pending |
| 7.2 | Playwright E2E Test Suite | L | Pending |
| 7.3 | Documentation and Metadata | S | Pending |
| 7.4 | Security Audit | M | Pending |
| 7.5 | Performance Testing | M | Pending |
| 7.6 | Release Packaging (CI/GitHub Actions) | M | Pending |

---

## Key Files Created (Phase 3)

### Step 3.1 — Core Member CRUD
- `app/migrations/0006_members.sql` — members, member_nodes, member_pending_changes, medical_access_log
- `app/modules/Members/Services/MemberService.php` — CRUD, search, encryption, pending changes
- `app/modules/Members/Controllers/MembersController.php` — paginated list, view, create, edit, status
- `app/modules/Members/Controllers/MemberApiController.php` — HTMX partials (search, card, badge)
- `app/modules/Members/module.php` — routes, nav, permissions
- `app/modules/Members/templates/members/` — index, view, form, pending_changes
- `app/modules/Members/templates/partials/` — _status_badge, _search_results, _member_card

### Step 3.2 — Custom Fields Engine
- `app/migrations/0007_custom_fields.sql` — custom_field_definitions
- `app/modules/Members/Services/CustomFieldService.php` — CRUD, validation, rendering, reorder
- `app/modules/Members/Controllers/CustomFieldsController.php` — admin management
- `app/modules/Members/templates/custom_fields/` — index (drag-drop reorder), form
- `app/modules/Members/templates/partials/_custom_fields.html.twig` — member form integration
- `app/src/Core/ModuleRegistry.php` — updated to support multi-nav modules

### Step 3.3 — Timeline Fields
- `app/migrations/0008_timeline.sql` — member_timeline
- `app/modules/Members/Services/TimelineService.php` — add, get, getLatest, delete, grouped
- `app/modules/Members/Controllers/TimelineController.php` — store, delete
- `app/modules/Members/templates/partials/_timeline.html.twig` — chronological list

### Step 3.4 — Attachments
- `app/migrations/0009_attachments.sql` — member_attachments
- `app/modules/Members/Services/AttachmentService.php` — upload, download, delete, MIME validation
- `app/modules/Members/Controllers/AttachmentController.php` — upload, download, delete
- `app/modules/Members/templates/partials/_attachments.html.twig` — Alpine dropzone + file list
- `app/src/Core/Response.php` — added file() download method

### Step 3.5 — Member Profile UI (Tabs)
- `app/modules/Members/Controllers/MemberTabsController.php` — 7 tab endpoints (HTMX partials)
- `app/modules/Members/templates/members/view.html.twig` — rewritten with Bootstrap tabs + HTMX lazy-load
- `app/modules/Members/templates/partials/tabs/` — _personal, _contact, _medical, _roles, _timeline, _documents, _additional
- `app/src/Core/Controller.php` — added validateCsrf() helper

### Step 3.6 — Registration Flows
- `app/migrations/0010_registration.sql` — registration_invitations, waiting_list
- `app/modules/Members/Services/RegistrationService.php` — selfRegister, approve, reject, invitations
- `app/modules/Members/Services/BulkImportService.php` — CSV template, parse, validate, import
- `app/modules/Members/Services/WaitingListService.php` — add, list, reorder, status transitions, convert
- `app/modules/Members/Controllers/RegistrationController.php` — admin: pending, approve/reject, invitations, bulk import, waiting list
- `app/modules/Members/Controllers/PublicRegistrationController.php` — public: self-register, invitation, waiting list
- `app/modules/Members/templates/registration/` — pending, invitations, bulk_import, bulk_import_preview, waiting_list, public_register, public_register_success, invitation_invalid, public_waiting_list, public_waiting_list_success
- `tests/Modules/Members/MemberRegTest.php` — RegistrationService tests (22 tests)
- `tests/Modules/Members/BulkImpTest.php` — BulkImportService tests (16 tests)
- `tests/Modules/Members/WaitListSvcTest.php` — WaitingListService tests (21 tests)

### Cross-cutting
- `lang/en.json` — ~100 new i18n keys (members.*, custom_fields.*, timeline.*, attachments.*, profile.*)
- `tests/fixtures/bootstrap.php` — test bootstrap with smtp config (renamed from init.php due to OneDrive lock)
- `tests/Modules/Members/` — CustomFieldSvcTest, TimelineSvcTest, AttachmentSvcTest

### Step 6.1 — Setup Wizard
- `app/src/Setup/SetupWizard.php` — 7-step wizard: prerequisites, DB, org, admin, SMTP, encryption key, config write
- `app/src/Setup/templates/layout.php` — Bootstrap 5 wizard layout with step indicator
- `app/src/Setup/templates/step1.php`–`step7.php` — individual step forms
- `index.php` — integrated setup wizard entry point (redirects to /setup if no config)
- `tests/SetupWizard/WizardTest.php` — 28 tests covering all steps, validation, and migrations

### Step 6.2 — Auto-Update Mechanism
- `updater/UpdateManager.php` — standalone update manager: GitHub releases, download, verify, apply, rollback
- `updater/run.php` — standalone update runner with token auth and maintenance mode
- `tests/Updater/UpdateManagerTest.php` — 21 tests covering tokens, state, maintenance, signatures, versioning

### Step 6.3 — Monitoring Endpoints
- `app/modules/Admin/Controllers/MonitoringController.php` — `/api/health` (public) + `/api/logs` (API key auth)
- `health.php` — standalone health check endpoint for external monitoring (Spike)
- `tests/Modules/Admin/MonitoringTest.php` — 9 tests covering health JSON, API key auth, log retrieval

---

## Known Issues / Notes

- **OneDrive interference**: File creation in `tests/` directory can fail silently. Workaround: write to `C:\Users\kevin\AppData\Local\Temp\` then copy. Some filenames are permanently locked by OneDrive after deletion (init.php, health.php, SetupWizardTest.php). PHPUnit bootstrap renamed to `bootstrap.php`.
- **PHPUnit output**: Git Bash on Windows doesn't always capture PHPUnit stdout. Use direct php execution.
- **DB rebuild after tests**: Test tearDown drops tables. Rebuild script at `C:\Users\kevin\AppData\Local\Temp\sk10_rebuild.php`.
- **Admin credentials after rebuild**: admin@scoutkeeper.local / c8363b3ef4679af7
- **PHP path**: `/c/xampp/php/php.exe` (not in system PATH)
- **FK drops in test setUp**: AuthServiceTest, PermissionResolverTest, OrgServiceTest all need member_attachments, member_timeline, medical_access_log, member_pending_changes, member_nodes, members dropped before parent tables.
