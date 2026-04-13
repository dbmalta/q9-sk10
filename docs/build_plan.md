# SK10 Implementation Plan

## Context

ScoutKeeper10 (SK10) is a free, open-source PHP/MySQL membership management system for Scout organisations. All planning is complete (April 2026). No code exists yet — this plan covers the full v1 build from skeleton to release packaging. Each step is designed to be executed by an AI agent with full context.

**Key constraints:** PHP 8.2+, MySQL 8.0+, Linux shared hosting, no CLI at runtime, no build step. Composer dev-only; CI builds release zips with vendor/ baked in.

---

## Conventions

- **Base path**: `/public_html/` (web root; maps to repo root in dev)
- **Namespace root**: `App\` → `/app/src/`
- **Test root**: `/tests/` mirrors `/app/src/`
- **Complexity**: S = 1-2 files; M = 3-8 files; L = 9+ files or complex logic
- **Every step**: PSR-12, docblocks on all functions, i18n keys for all user-facing strings
- **Testing**: test-alongside — each step includes PHPUnit tests; Playwright E2E after each module UI
- **Seeder**: grows incrementally — each module adds data to the "Scouts of Northland" synthetic org

---

## PHASE 1: Foundation

### Step 1.1 — Project Skeleton and Bootstrap [M]
**Dependencies: None**

Create the full directory structure, bootstrap, and config:

1. **`/composer.json`**: `quadnine/scoutkeeper`, requires: `php >=8.2`, `phpmailer/phpmailer ^6.9`, `pragmarx/google2fa ^8.0`, `nikic/fast-route ^1.3`, `twig/twig ^3.0`. Dev: `phpunit/phpunit ^10.5`. PSR-4 autoload: `App\` → `app/src/`, `Tests\` → `tests/`.

2. **`/public_html/index.php`** (~20 lines): Define `ROOT_PATH`, check maintenance flag at `/var/maintenance.flag`, require `/app/bootstrap.php`, call `App\Core\Application::run()`.

3. **`/public_html/.htaccess`**: RewriteEngine, deny `/app/`, `/config/`, `/var/`, `/data/` access, rewrite all non-file requests to `index.php`. Exceptions: `/updater/run.php`, `/health.php`, `/cron/run.php`. Security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy).

4. **Directory structure**:
   ```
   /public_html/
   ├── index.php, .htaccess, health.php (placeholder)
   ├── app/
   │   ├── bootstrap.php
   │   ├── src/Core/Application.php
   │   ├── templates/layouts/, templates/components/, templates/errors/
   │   ├── modules/
   │   └── migrations/
   ├── updater/.gitkeep
   ├── config/config.example.php
   ├── data/uploads/.gitkeep, data/backups/.gitkeep
   ├── var/cache/twig/, var/locks/, var/logs/, var/updates/, var/sessions/
   ├── assets/css/app.css, assets/js/app.js, assets/images/, assets/vendor/
   ├── lang/en.json
   ├── cron/run.php (placeholder)
   └── tests/bootstrap.php, tests/Core/
   ```

5. **`/app/bootstrap.php`**: Error reporting from config, Composer autoloader, load config (redirect to setup if missing), set timezone, init Application singleton.

6. **`/app/src/Core/Application.php`**: Singleton. Properties: `$config`, `$db`, `$router`, `$twig`, `$session`, `$i18n`, `$moduleRegistry`. `run()`: init session → load modules → dispatch route → render. Accessors for all properties.

7. **`/config/config.example.php`**: Returns array with `db`, `app` (name, url, timezone, debug, language), `smtp`, `security` (encryption_key_file), `monitoring` (api_key, slow_query_threshold_ms), `cron` (email_batch_size, email_interval_seconds).

8. **`/phpunit.xml`**, **`/tests/bootstrap.php`**: Separate test DB config, define ROOT_PATH.

9. **`/.gitignore`**: `/vendor/`, `/config/config.php`, `/config/encryption.key`, `/var/cache/`, `/var/logs/`, `/var/locks/`, `/var/sessions/`, `/var/updates/`, `/data/uploads/*`, `/data/backups/*`, `!.gitkeep`, `.phpunit.result.cache`.

**Tests**: `tests/Core/ApplicationTest.php` — init, config loading, missing config triggers setup redirect.

---

### Step 1.2 — Vendor Frontend Libraries [S]
**Dependencies: 1.1**

Download and place into `/assets/vendor/`:
- `bootstrap/5.3/css/bootstrap.min.css` + `js/bootstrap.bundle.min.js`
- `alpine/3/alpine.min.js`
- `htmx/2/htmx.min.js`
- `bootstrap-icons/bootstrap-icons.min.css` + `fonts/` (woff/woff2)

Each directory gets a `VERSION.txt` with exact version number.

**Tests**: None (static assets).

---

### Step 1.3 — Database Layer (PDO Wrapper) [M]
**Dependencies: 1.1**

1. **`/app/src/Core/Database.php`**: Thin PDO wrapper.
   - Constructor: config array → PDO with `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`, `SET NAMES utf8mb4`.
   - Methods: `query($sql, $params)`, `fetchOne()`, `fetchAll()`, `fetchColumn()`, `insert($table, $data): int`, `update($table, $data, $where): int`, `delete($table, $where): int`, `beginTransaction()`, `commit()`, `rollback()`, `lastInsertId()`.
   - Slow query logging: `microtime(true)` wrapper, threshold from config, appends to `/var/logs/slow-queries.json`.
   - `insert/update/delete` build parameterized SQL from arrays. `where` is `column => value` pairs joined with AND. Complex conditions use `query()` directly.

2. **`/app/src/Core/Migration.php`**: Reads `/app/migrations/NNNN_description.sql`, maintains `_migrations` table, applies pending in order (transaction per file).

3. **`/app/migrations/0001_initial_schema.sql`**: Creates `_migrations` table.

**Tests**: `tests/Core/DatabaseTest.php` (CRUD, transactions, slow query logging), `tests/Core/MigrationTest.php` (apply order, skip applied, status reporting).

---

### Step 1.4 — Router (fast-route) [M]
**Dependencies: 1.1, 1.3**

1. **`/app/src/Core/Router.php`**: Wraps nikic/fast-route. `addRoute()`, `addModuleRoutes()`, `dispatch()`. Pattern: `/{module}/{action}[/{id:\d+}]`. Handler: `[Controller::class, 'method']`. 404/405 handling.

2. **`/app/src/Core/Controller.php`**: Abstract base. Properties via Application. Methods: `render($template, $data)` (injects user, nav, breadcrumbs, csrf, i18n), `redirect()`, `json()`, `requireAuth()`, `requirePermission($module, $level)`, `getCsrfToken()`, `validateCsrf()`, `getParam()`, `getRouteParam()`.

3. **`/app/src/Core/Request.php`**: `getMethod()`, `getUri()`, `getParam()`, `getBody()`, `isHtmx()` (checks `HX-Request` header), `isAjax()`.

4. **`/app/src/Core/Response.php`**: `setStatusCode()`, `setHeader()`, `setBody()`, `send()`, `html()`, `json()`, `redirect()`.

**Tests**: `tests/Core/RouterTest.php`, `tests/Core/ControllerTest.php`.

---

### Step 1.5 — Twig Template Engine + Layouts + Design System [M]
**Dependencies: 1.1, 1.2, 1.4**

1. **Twig init** in Application: template paths `['/app/templates/', '/app/modules/*/templates/']`, cache `/var/cache/twig/`, auto-reload in debug.

2. **`/app/src/Core/TwigExtensions.php`**: Functions: `t(key, params)`, `csrf_field()`, `route(name, params)`, `asset(path)` (cache-busting), `has_permission(module, level)`, `current_route()`. Filters: `time_ago(datetime)`, `format_date(datetime, format)`.

3. **`/app/templates/layouts/base.html.twig`**: HTML5, `<html lang="{{ app_lang }}" data-bs-theme="{{ theme }}">`, head (meta, Bootstrap CSS, Icons CSS, app.css), body block, footer scripts (Bootstrap JS, Alpine, HTMX, app.js). HTMX global CSRF header. Alpine store for theme + session timeout modal.

4. **`/app/templates/layouts/admin.html.twig`**: Extends base. Topbar: `[☰] [Logo→Dashboard] [🔍 Search]` `[🌐 EN▾] [🔔] [🌓] [👤 User▾]`. Left sidebar: grouped sections from `nav_items` (MAIN, ENGAGEMENT, OPERATIONS, ADMINISTRATION), empty groups auto-hidden. Bootstrap Offcanvas for mobile. Breadcrumbs below topbar. Skip-to-content link. Session timeout modal (Alpine + HTMX 401 intercept → inline re-login form).

5. **`/app/templates/layouts/member.html.twig`**: Extends base. Topbar only (no sidebar). Breadcrumbs. Content block.

6. **`/app/templates/layouts/auth.html.twig`**: Extends base. Centered card. Logo + org name above. No nav. Language switcher.

7. **`/app/templates/components/`**: `_pagination.html.twig`, `_alert.html.twig`, `_confirm_modal.html.twig`, `_breadcrumbs.html.twig`, `_empty_state.html.twig`, `_loading_spinner.html.twig`.

8. **`/assets/css/app.css`**: Design system CSS custom properties:
   - Light/dark palette on `:root` and `[data-bs-theme="dark"]` — neutral greys, one accent (blue/teal), semantic colours (success/warning/danger/info). WCAG AA minimum, AAA where practical.
   - `--border-radius: 0.5rem` (cards/inputs), `0.375rem` (buttons/badges)
   - `--shadow-sm: 0 1px 2px rgba(0,0,0,0.05)`, `--shadow-md: 0 4px 6px rgba(0,0,0,0.07)`
   - `--spacing-base: 1rem`, `--content-max-width: 1200px`
   - System font stack. Sidebar: 260px fixed, full height. Topbar: subtle bottom shadow.
   - Focus rings: `outline: 2px solid var(--bs-primary); outline-offset: 2px` on `:focus-visible`.
   - Min 44x44px touch targets. Comfortable density (generous padding on cards, forms, table rows).
   - Stripe Dashboard-inspired: generous whitespace, subtle shadows, refined buttons.

9. **`/assets/js/app.js`**: Theme toggle (localStorage/prefers-color-scheme → `data-bs-theme`), HTMX 401 → session modal, Alpine global store, CSRF token header for all HTMX requests.

10. **Dev styleguide route** `/dev/styleguide` (debug mode only): renders all components for visual verification.

**Tests**: `tests/Core/TwigExtensionsTest.php`.

---

### Step 1.6 — Module Registry System [M]
**Dependencies: 1.4, 1.5**

1. **`/app/src/Core/ModuleRegistry.php`**: Scans `/app/modules/*/module.php`. Each returns:
   ```php
   return [
       'id' => 'members',
       'name' => 'members.module_name',
       'version' => '1.0.0',
       'nav' => ['group' => 'main', 'label' => 'nav.members', 'icon' => 'bi-people', 'route' => '/members', 'order' => 20],
       'routes' => function(Router $router) { ... },
       'permissions' => ['members.read' => '...', 'members.write' => '...'],
       'cron' => [],
   ];
   ```
   Methods: `getNavItems()` (grouped, sorted, permission-filtered), `getPermissionDefinitions()`, `getCronHandlers()`, `getModule($id)`.

2. **Nav group definitions** (hardcoded order): main(1), engagement(2), operations(3), administration(4).

3. **Module directory convention**:
   ```
   /app/modules/{name}/
   ├── module.php, Controllers/, Models/, Services/, templates/{name}/
   ```

**Tests**: `tests/Core/ModuleRegistryTest.php` — scanning, sorting, empty group exclusion, permission aggregation, invalid module handling.

---

### Step 1.7 — i18n System [M]
**Dependencies: 1.3, 1.5**

1. **`/app/src/Core/I18n.php`**: Load `/lang/{language}.json` + DB overrides from `i18n_overrides` table. DB overrides take precedence. Cache merged result in `/var/cache/`. Methods:
   - `t($key, $params)` — translate with `{placeholder}` replacement. Missing key returns key itself + logs it.
   - `getAll()` — returns all translations for current language (merged: file + DB overrides)
   - `getMasterStrings()` — returns all keys from `en.json` (the master source) with their English values. This is the definitive list of every translatable string in the system.
   - `exportMasterFile()` — generates a downloadable JSON file containing every translatable string keyed by its i18n key with the English value. This file is what a translator or AI works from to produce a new `xx.json` language pack.
   - `getAvailableLanguages()` — scans `/lang/` for `*.json` files + checks `languages` DB table for activated languages. Returns list with language code, name, completion percentage.
   - `getCompletionPercentage($language)` — compares keys in `xx.json` against master `en.json`, returns percentage of keys translated.
   - `getMissing($language)` — returns keys present in master but missing from the given language file.
   - `clearCache($language)`.

2. **`/lang/en.json`**: Initial strings — nav groups, nav items, auth strings, common buttons (save/cancel/delete/edit/search/confirm/back/loading), pagination, error pages. Grows with every step. This file is the **master language file** — it defines the complete set of translatable keys.

3. **Migration `0002_i18n.sql`**: Two tables:
   - `i18n_overrides` (language, translation_key, translation_value, unique on lang+key) — per-installation string overrides
   - `languages` (code VARCHAR(10) PK, name VARCHAR(100), native_name VARCHAR(100), is_active TINYINT default 0, is_default TINYINT default 0, added_at DATETIME) — tracks which languages are available and active on this installation

4. **Language delivery via auto-update**: Community-contributed language files merged into the GitHub repo are included in release zips. When an update is applied, new `/lang/xx.json` files arrive automatically. However, they are **not active by default** — admin must activate them from Settings → Languages (see Step 5.10).

**Tests**: `tests/Core/I18nTest.php` — JSON loading, placeholder replacement, missing key fallback, DB override precedence, cache, master export contains all keys, completion percentage calculation, available languages detection.

---

### Step 1.8 — Session Management + CSRF [M]
**Dependencies: 1.3**

1. **`/app/src/Core/Session.php`**: `start()` with secure config (save_path `/var/sessions/`, httponly, secure if HTTPS, samesite Lax, gc_maxlifetime 7200, strict_mode). Methods: `get/set/remove/destroy()`, `flash/getFlash()`, `isAuthenticated()`, `getUser/setUser()`, `checkTimeout()`, `regenerate()`.

2. **`/app/src/Core/Csrf.php`**: Per-session token (HTMX compatible). `generateToken()`, `validateToken()` with `hash_equals()`. Auto-reject POST/PUT/DELETE without valid token in `Application::run()`.

**Tests**: `tests/Core/SessionTest.php`, `tests/Core/CsrfTest.php`.

---

### Step 1.9 — Error Handling and Logging [M]
**Dependencies: 1.1, 1.3**

1. **`/app/src/Core/ErrorHandler.php`**: Registered as PHP error/exception handler. Logs to `/var/logs/errors.json` (structured JSON: timestamp, level, message, file, line, trace, request_uri, user_id). Rotation: >5MB → rename to `.1.json`, keep max 5. Debug mode: detailed trace page. Production: generic error page.

2. **`/app/src/Core/Logger.php`**: `log($level, $message, $context)`. Levels: error, warning, info, debug. Writes to errors.json (error/warning) or app.json (info/debug, debug mode only). Static convenience methods.

3. **Error templates**: `errors/404.html.twig`, `errors/403.html.twig`, `errors/500.html.twig`, `errors/maintenance.html.twig`, `errors/debug.html.twig`.

**Tests**: `tests/Core/ErrorHandlerTest.php`, `tests/Core/LoggerTest.php`.

---

### Step 1.10 — Encryption Utility [S]
**Dependencies: 1.1**

**`/app/src/Core/Encryption.php`**: `__construct($keyFilePath)` reads key from file. `encrypt($plaintext)`: AES-256-GCM, random IV, returns base64(IV + tag + ciphertext). `decrypt($ciphertext)`: reverse. `generateKey()`: 32 random bytes (used by setup wizard). Missing/unreadable key → clear exception.

**Tests**: `tests/Core/EncryptionTest.php` — roundtrip, varied lengths, invalid key, tampered ciphertext.

---

## PHASE 2: Core Infrastructure Modules

### Step 2.1 — Authentication Module [L]
**Dependencies: 1.3, 1.4, 1.5, 1.7, 1.8, 1.10**

1. **Migration `0003_users.sql`**: Tables: `users` (id, email unique, password_hash, mfa_secret encrypted, mfa_enabled, is_active, is_super_admin, last_login_at, password_changed_at, failed_login_count, locked_until, timestamps), `password_resets` (user_id FK, token, expires_at, used_at), `user_sessions` (id, user_id FK, ip, user_agent, last_activity_at).

2. **`/app/modules/auth/module.php`**: System module (no nav). Routes: `GET/POST /login`, `GET /logout`, `GET/POST /forgot-password`, `GET/POST /reset-password/{token}`, `GET/POST /mfa-verify`.

3. **`AuthController.php`**: `showLogin()`, `login()` (validate email/password via `password_verify()`, check MFA, lock after 5 failures for 15 min), `showMfaVerify()`, `verifyMfa()` (google2fa), `logout()`, `showForgotPassword()`, `forgotPassword()` (token + email, always show success), `showResetPassword($token)`, `resetPassword($token)` (min 10 chars, bcrypt).

4. **`AuthService.php`**: `authenticate()`, `isLocked()`, `recordFailedLogin()`, `resetFailedLogins()`, `createPasswordResetToken()`, `validateResetToken()`, `updatePassword()`, `setupMfa()` (generate secret + QR data URI), `verifyMfaCode()`, `disableMfa()`.

5. **Templates**: `auth/login.html.twig`, `auth/forgot_password.html.twig`, `auth/reset_password.html.twig`, `auth/mfa_verify.html.twig` — all extend auth layout, Bootstrap styling, i18n keys.

6. **Password policy**: min 10 chars, no complexity rules (NIST). Client-side `minlength` + server-side.

**Tests**: `tests/Modules/Auth/AuthServiceTest.php` (auth success/fail, locking, lock expiry, reset tokens, expired/used tokens, password min length, MFA). `tests/Modules/Auth/AuthControllerTest.php` (page renders, login redirect, failed login error, logout clears session, CSRF required).

**Seeder**: First admin user.

---

### Step 2.2 — Permissions System [L]
**Dependencies: 2.1, 1.6**

1. **Migration `0004_permissions.sql`**: Tables: `roles` (id, name, description, permissions JSON, can_publish_events, can_access_medical, can_access_financial, is_system, timestamps), `role_assignments` (id, member_id, role_id, context_type enum node/team, context_id, start_date, end_date, assigned_by, timestamps), `role_assignment_scopes` (assignment_id FK, node_id).

2. **`/app/src/Core/PermissionResolver.php`**: `loadForUser($memberId)` loads active assignments (end_date IS NULL or >= today) with roles + scopes. `can($permission)`, `canPublishEvents()`, `canAccessMedical()`, `getScopeNodeIds()`, `canAccessNode($nodeId)`, `getActiveAssignments()`. Cached in session; invalidated on role change.

3. **`RolesController.php`**: CRUD for roles — checkboxes for each module permission (from ModuleRegistry) + special flags. System roles can't be deleted.

4. **`AssignmentsController.php`**: `forMember($memberId)` (current + historical), `create($memberId)` (select role, context node/team via tree picker, scope nodes with "this + descendants" button), `store()`, `end($assignmentId)` (set end_date, not delete).

5. **Module registration**: group `administration`, icon `bi-shield-lock`, route `/admin/roles`.

6. **Seed system roles**: "Super Admin" (all permissions, system), "Group Leader" (members r/w, events r, comms r), "Section Leader" (members r, events r).

**Tests**: `tests/Core/PermissionResolverTest.php` (single/multiple assignments, union, expired ignored, scope filtering, special flags, no assignments = no permissions). `tests/Modules/Permissions/RolesControllerTest.php`, `tests/Modules/Permissions/AssignmentsControllerTest.php`.

---

### Step 2.3 — Org Structure (Hierarchy + Closure Table + Teams) [L]
**Dependencies: 1.3, 1.4, 1.5, 2.2**

1. **Migration `0005_org_structure.sql`**: Tables: `org_level_types` (id, name, depth, is_leaf, sort_order), `org_nodes` (id, parent_id self-ref, level_type_id FK, name, short_name, description, age_group_min/max for sections, sort_order, is_active, timestamps), `org_closure` (ancestor_id, descendant_id, depth — composite PK), `org_teams` (id, node_id FK, name, description, is_permanent, is_active, timestamps).

2. **`OrgService.php`**: `createNode($data)` (insert + maintain closure table), `updateNode()`, `moveNode()` (update parent + rebuild closure subtree), `deleteNode()` (only if no children/members), `getTree()`, `getAncestors()`, `getDescendants()`, `getChildren()`. Team CRUD. Closure maintenance:
   ```sql
   INSERT INTO org_closure (ancestor_id, descendant_id, depth)
   SELECT ancestor_id, :new_id, depth + 1 FROM org_closure WHERE descendant_id = :parent_id
   UNION ALL SELECT :new_id, :new_id, 0
   ```

3. **`OrgController.php`**: Tree view (nested list, expand/collapse via Alpine), node CRUD, move (HTMX drag-drop), team management per node.

4. **`LevelTypesController.php`**: CRUD for level type definitions, reorder.

5. **Module registration**: group `administration`, icon `bi-diagram-3`, route `/admin/org`.

**Tests**: `tests/Modules/Org/OrgServiceTest.php` (closure table maintenance, tree retrieval, ancestors, descendants, move, delete restrictions, team CRUD, closure integrity after multiple ops).

**Seeder**: "Scouts of Northland" — National → 2 Regions → 3 Districts → 4 Groups → ~12 Sections (with age ranges). 3 Teams (National Board, Finance Team, Camp Committee).

---

## PHASE 3: Member Management

### Step 3.1 — Core Member CRUD [L]
**Dependencies: 2.1, 2.2, 2.3**

1. **Migration `0006_members.sql`**: Tables: `members` (id, user_id FK nullable, membership_number unique, first_name, surname, dob, gender enum, email, phone, address fields, city, postcode, country, medical_notes encrypted, photo_path, member_custom_data JSON, status enum active/pending/suspended/inactive/left, status_reason, joined_date, left_date, gdpr_consent, timestamps, FULLTEXT on name+email), `member_nodes` (member_id, node_id, is_primary — composite PK), `member_pending_changes` (member_id, field_name, old/new_value, requested_by, status enum pending/approved/rejected, reviewed_by), `medical_access_log` (member_id, accessed_by, action, timestamp, ip).

2. **`MemberService.php`**: `create($data)` (validate, generate membership number, encrypt medical, insert), `update($id, $data)` (member updates → pending changes; admin → direct), `getById($id)` (decrypt medical if can_access_medical, log access), `search($query, $filters, $page, $perPage)` (FULLTEXT + scope filtering), `listByNode($nodeId, $includeDescendants, $filters)`, `changeStatus()`, `generateMembershipNumber()`, `getPendingChanges()`, `reviewChange()`.

3. **`MembersController.php`**: `index()` (paginated, filterable, permission-scoped), `view($id)`, `create()/store()`, `edit($id)/update()`, `changeStatus()`, `search()` (HTMX live), `pendingChanges()`, `reviewChange()`.

4. **`MemberApiController.php`**: HTMX partials — `searchResults()`, `memberCard($id)`, `statusBadge($id)`.

5. **Module registration**: group `main`, icon `bi-people`, route `/members`, order 20, permissions: `members.read`, `members.write`.

**Tests**: `tests/Modules/Members/MemberServiceTest.php` (CRUD, membership number, encryption, medical access logging, search, scope filtering, pending changes, status transitions).

**Seeder**: 50+ members across nodes, varied statuses, genders, age ranges.

---

### Step 3.2 — Custom Fields Engine [M]
**Dependencies: 3.1**

1. **Migration `0007_custom_fields.sql`**: `custom_field_definitions` (field_key unique, field_type enum short_text/long_text/number/dropdown/date, label, description, is_required, validation_rules JSON, display_group default 'additional', sort_order, is_active).

2. **`CustomFieldService.php`**: `getDefinitions()`, CRUD, `validateFieldValue($definition, $value)`, `validateAllCustomData($data)`, `renderField($definition, $value)`, `getFieldsForGroup($group)`.

3. **`CustomFieldsController.php`**: List (drag-drop reorder), create/edit/deactivate, reorder HTMX endpoint.

4. **Integration**: Member create/edit forms dynamically render custom fields. Values stored in `member_custom_data` JSON.

**Tests**: `tests/Modules/Members/CustomFieldServiceTest.php` (CRUD, per-type validation, required, dropdown options, number min/max, rendering).

**Seeder**: "Uniform Size" (dropdown), "Allergies" (long_text), "Emergency Phone" (short_text), "Swimming Ability" (dropdown: None/Basic/Competent/Strong).

---

### Step 3.3 — Timeline Fields [S]
**Dependencies: 3.1**

1. **Migration `0008_timeline.sql`**: `member_timeline` (member_id, field_key, value, effective_date, recorded_by, notes).

2. **`TimelineService.php`**: `addEntry()`, `getEntries($memberId, $fieldKey)` sorted by date desc, `getLatestEntry()`, `deleteEntry()`.

3. **Partial template** `_timeline.html.twig`: chronological list per field key.

**Tests**: `tests/Modules/Members/TimelineServiceTest.php`.

**Seeder**: Rank progressions, qualification dates for several members.

---

### Step 3.4 — Attachments [S]
**Dependencies: 3.1**

1. **Migration `0009_attachments.sql`**: `member_attachments` (member_id, field_key, file_path, original_name, mime_type, file_size, uploaded_by).

2. **`AttachmentService.php`**: `upload()` (whitelist: pdf/jpg/png/gif/doc/docx, max 10MB, store in `/data/uploads/members/{id}/{uuid}.{ext}`), `getForMember()`, `download()` (stream with correct headers), `delete()`.

3. **Controller + template partial** with Alpine dropzone.

**Tests**: `tests/Modules/Members/AttachmentServiceTest.php` (valid upload, invalid type rejected, size limit, download, deletion).

---

### Step 3.5 — Member Profile UI (Tabs) [M]
**Dependencies: 3.1, 3.2, 3.3, 3.4, 2.2**

1. **`members/view.html.twig`**: Bootstrap tabs, each lazy-loaded via HTMX (`hx-get="/members/{id}/tab/{name}"`):
   - **Personal Details**: name, DOB, gender, membership #, status, joined date, photo upload
   - **Contact**: email, phone, address
   - **Medical**: encrypted notes (only if `can_access_medical`), access logged, audit warning displayed
   - **Role History**: all assignments (current highlighted, past dimmed), role/context/scope/dates
   - **Achievements**: placeholder, populated in Step 4.3
   - **Documents/Attachments**: from Step 3.4
   - **Additional Info**: all custom fields from Step 3.2

2. **`MemberTabsController.php`**: One method per tab, each returns partial. Medical tab enforces `can_access_medical` + logs access.

**Tests**: `tests/Modules/Members/MemberTabsControllerTest.php` (each tab returns partial, medical permission enforced, medical access logged).

---

### Step 3.6 — Registration Flows [L]
**Dependencies: 3.1, 2.1, 2.3**

1. **Migration `0010_registration.sql`**: `registration_invitations` (token unique, target_node_id FK, created_by, email, expires_at, used_at), `waiting_list` (position, parent_name, parent_email, child_name, child_dob, preferred_node_id FK, notes, status enum waiting/contacted/converted/withdrawn).

2. **`RegistrationService.php`**: `selfRegister($data)` → member status `pending` + user account + confirmation email. `approveRegistration()` → `active` + welcome email. `rejectRegistration()`. `createInvitation($nodeId, $email, $createdBy)` → token + optional email. `processInvitation($token, $data)` → pre-selects unit, self-reg flow with approval. `getPendingRegistrations($scopeNodeIds)`.

3. **`BulkImportService.php`**: `generateTemplate($nodeId)` → CSV with core + custom field headers. `parseUpload($nodeId, $filePath)` → `{valid: [...], errors: [...]}` with per-row validation. `import($nodeId, $validRows, $importedBy)` → creates members. Validation: required fields, date formats, duplicate membership numbers, email format.

4. **`WaitingListService.php`**: `addEntry()`, `getList()`, `reorder($orderedIds)`, `updateStatus()`, `convertToRegistration($id)` → creates member + email notification to next in queue. `getNextPosition()`.

5. **Controllers**: Self-registration (public, auth layout), invitation (token-based), admin pending/approve/reject, bulk import (download template → upload → preview with errors → confirm), waiting list (public form + admin management with drag-to-reorder).

**Tests**: Registration (self-reg creates pending, approval, rejection, invitation tokens, expired invitation). BulkImport (template headers, valid CSV, validation errors, import count). WaitingList (add, position, reorder, convert).

**Seeder**: 5 pending registrations, 3 waiting list entries, 2 used invitations.

---

## PHASE 4: Feature Modules

### Step 4.1 — Communications (Portal, Articles, Email, Cron) [L]
**Dependencies: 3.1, 2.2, 1.7**

1. **Migration `0011_communications.sql`**: `articles` (title, slug unique, body, excerpt, author_id FK, is_public, is_published, published_at, target_node_ids JSON), `email_queue` (recipient_email/name, subject, body_text, body_html, status enum queued/sending/sent/failed, attempts, error_message, sent_at), `email_log` (recipient, subject, status, error, sent_at), `member_email_preferences` (member_id PK, receive_announcements, receive_events, email_bounced, bounced_at).

2. **`ArticleService.php`**: CRUD, `getPublishedForMember($memberId, $memberNodeIds, $page, $perPage)`, `generateSlug()`.

3. **`EmailService.php`**: `queueEmail()`, `queueBulk($recipients, $subject, $text, $html)`, `getRecipientsByFilter($filters)` (by node/role/status, respects preferences + bounce flags), `processQueue($batchSize)` (send via PHPMailer, update status).

4. **`CronHandler.php`**: Implements cron handler interface, calls `EmailService::processQueue()`.

5. **Portal controller**: dashboard view (recent articles, notifications), single article view.

6. **Article admin**: CRUD with textarea (basic HTML, no JS editor v1), target node selection, preview.

7. **Email controller**: compose (recipient filter, subject, body), send (queue), queue status view, sent log view.

8. **`/cron/run.php`**: Validate cron secret token or CLI execution, bootstrap app, get cron handlers from ModuleRegistry, execute sequentially, log to `/var/logs/cron.json`.

9. **Pseudo-cron fallback** in `Application::run()`: After response via `fastcgi_finish_request()`, check last cron run in `/var/cache/cron_last_run.txt`, execute if stale.

10. **Module registration**: group `engagement`, icon `bi-megaphone`, route `/communications`, order 10.

**Tests**: Article CRUD + visibility filtering. Email queue + recipient filtering + batch processing (mock PHPMailer). Cron handler execution.

**Seeder**: 5 articles (3 published, 2 draft), 10 queued emails, email preferences for all members.

---

### Step 4.2 — Events Module (Calendar, iCal) [M]
**Dependencies: 3.1, 2.2**

1. **Migration `0012_events.sql`**: `events` (title, description, location, start/end_datetime, all_day, is_public, is_published, created_by FK, node_id FK, published_at), `event_ical_tokens` (user_id FK, token unique).

2. **`EventService.php`**: CRUD (requires `can_publish_events`), `getUpcoming($scopeNodeIds, $limit)`, `getByMonth($year, $month, $scopeNodeIds)`, `getByDateRange()`.

3. **`ICalService.php`**: `generateFeed($userId)` → text/calendar output, `getOrCreateToken($userId)`.

4. **Controllers**: Calendar view (month grid, Alpine navigation, HTMX month nav), list view, single event view, create/edit form (date pickers, node selector, public/private toggle), iCal feed endpoint (token-authenticated).

5. **Module registration**: group `main`, icon `bi-calendar-event`, route `/events`, order 30.

**Tests**: CRUD, date range queries, scope filtering, iCal format validation, token generation.

**Seeder**: 15 events across 3 months, various nodes, mix of public/private.

---

### Step 4.3 — Achievements and Training [M]
**Dependencies: 3.1, 2.2**

1. **Migration `0013_achievements.sql`**: `achievement_definitions` (title, description, category enum achievement/training, is_active, sort_order), `member_achievements` (member_id FK, achievement_id FK, awarded_date, awarded_by, notes).

2. **`AchievementService.php`**: CRUD for definitions, `assignToMember()`, `getForMember($memberId)` grouped by category, `removeFromMember()`.

3. **Controllers**: Definition CRUD (tabbed: achievements/training), assign/remove from member profile (HTMX).

4. **Update `members/tabs/_achievements.html.twig`** (was placeholder in 3.5): shows achievements + training grouped, with dates.

5. **Module registration**: group `engagement`, icon `bi-trophy`, route `/admin/achievements`, order 20.

**Tests**: CRUD, assignment, grouped retrieval.

**Seeder**: 8 definitions (5 achievements, 3 training), 40+ assignments to adult members.

---

### Step 4.4 — Directory / Organogram [M]
**Dependencies: 2.3, 2.2, 3.1**

1. **`DirectoryService.php`**: `getOrganogram($scopeNodeIds)` → org tree with key role holders per node, `getContactList($scopeNodeIds)` → flat list of role holders (name, role, email, phone, node), `getKeyRoles()` → configurable list (admin setting).

2. **Controllers**: `organogram()` (visual CSS tree, nested Bootstrap cards, collapsible via Alpine, permission-filtered), `contacts()` (searchable/filterable table).

3. **Module registration**: group `main`, icon `bi-diagram-3`, route `/directory`, order 40, permissions: `directory.read`.

**Tests**: Organogram returns correct tree, contacts filtered by scope, key roles config.

---

## PHASE 5: Administration

### Step 5.1 — Dashboard with Stats [M]
**Dependencies: 3.1, 4.2, 2.3**

1. **`DashboardService.php`**: `getMemberStats($scopeNodeIds)` (total by status, by node, by gender, by age group), `getRecentRegistrations()`, `getUpcomingEvents()`, `getSystemHealth()` (last cron, error count, DB size, PHP/SK10 version), `getPendingActions()` (pending registrations, pending changes, waiting list counts).

2. **Dashboard template**: Responsive grid of stat cards, each HTMX-loadable partial. Total members, by node (top 5), recent registrations (10), upcoming events (5), pending actions, system health (admin only).

3. **Module registration**: group `main`, icon `bi-speedometer2`, route `/dashboard`, order 10. Default landing after login.

**Tests**: Stat calculations with known seed data.

---

### Step 5.2 — Membership Reports [M]
**Dependencies: 5.1**

1. **`ReportService.php`**: `memberGrowthOverTime($scopeNodeIds, $from, $to, $interval)`, `membersByAgeGroup()`, `membersByGender()`, `roleAssignmentReport()`, `statusChangeReport()`, `exportToCsv($data, $headers, $filename)` (stream download).

2. **Controllers**: Report selector, growth (table data, chart post-v1), demographics (age/gender breakdowns), roles (assignment summary), status changes. All exportable to CSV.

3. **Module registration**: group `administration`, icon `bi-graph-up`, route `/admin/reports`, order 50.

**Tests**: Each report query with known seed data.

---

### Step 5.3 — T&Cs / Membership Conditions [M]
**Dependencies: 2.1, 3.1**

1. **Migration `0014_terms.sql`**: `terms_versions` (title, content, version_number, grace_period_days default 14, published_at, published_by), `terms_acceptances` (terms_version_id FK, user_id FK, accepted_at, refused_at, unique on version+user).

2. **`TermsService.php`**: `getLatestPublished()`, `hasUserAccepted()`, `accept()`, `refuse()` (flag account), `isInGracePeriod()` (published_at + grace_period_days > now), `getAcceptanceReport()`, `publishNewVersion()`.

3. **Middleware in Application::run()**: After auth, check latest terms. Grace period → persistent banner (dismissible per-session, reappears next session). Grace expired + not accepted → blocking modal (accept/refuse). Refused → access-denied page.

4. **Admin controller**: CRUD for versions, publish, acceptance report.

**Tests**: Publishing, acceptance, refusal, grace period logic, acceptance report.

**Seeder**: 1 published T&Cs version, most accepted, 2 pending, 1 refused.

---

### Step 5.4 — Important Notices [S]
**Dependencies: 2.1**

1. **Migration `0015_notices.sql`**: `notices` (title, content, notice_type enum must_acknowledge/informational, is_active, created_by), `notice_acknowledgements` (notice_id FK, user_id FK, acknowledged_at, unique on notice+user).

2. **`NoticeService.php`**: `getUnacknowledgedForUser()`, `getMustAcknowledge()` (blocking), `acknowledge()`, admin CRUD.

3. **Middleware**: After login + terms check, check must-acknowledge notices → blocking modal. Informational → dismissible alerts on dashboard.

**Tests**: Acknowledgement flow, blocking vs informational.

**Seeder**: 1 must-acknowledge notice, 1 informational.

---

### Step 5.5 — Settings Panel [M]
**Dependencies: All prior modules**

1. **Migration `0016_settings.sql`**: `settings` (setting_key PK, setting_value, setting_type enum, description).

2. **`/app/src/Core/Settings.php`**: `get($key, $default)`, `set($key, $value)`, `getAll()`. Cached in memory after first load. Defaults in code, DB overrides.

3. **Settings tabs**: General (org name, URL, timezone, language, date format), Branding (logo upload, org name display), Registration (self-reg on/off, waiting list on/off, admin approval, no-login mode), Email/SMTP (host/port/user/password — test button), Security (session timeout, MFA enforcement off/optional/required), GDPR (enable/disable features, retention period, consent text), Cron (status, last run, mode, pseudo-cron warning), Directory (which roles appear).

4. **Module registration**: group `administration`, icon `bi-gear`, route `/admin/settings`, order 80.

**Tests**: `tests/Core/SettingsTest.php` — get/set, type casting, defaults, caching.

**Seeder**: Default settings values.

---

### Step 5.10 — Language Management [M]
**Dependencies: 1.7, 5.5**

1. **`/app/modules/languages/Services/LanguageService.php`**:
   - `getInstalled()` — returns all languages with: code, name, native name, is_active, is_default, completion percentage, source (shipped/uploaded)
   - `activate($code)` — marks language as active; appears in language switcher
   - `deactivate($code)` — removes from switcher (English cannot be deactivated)
   - `setDefault($code)` — sets the installation default language (used for new users and pre-login)
   - `uploadLanguageFile($file)` — validates uploaded JSON (must be valid JSON, keys checked against master en.json), saves to `/lang/xx.json`, creates/updates `languages` table entry. Returns validation report (total keys, translated, missing, invalid).
   - `exportMasterFile()` — calls `I18n::exportMasterFile()`, streams as download
   - `getOverrides($language)` — returns all DB overrides for a language
   - `setOverride($language, $key, $value)` — creates/updates a DB override
   - `deleteOverride($language, $key)` — removes a DB override (reverts to file value)
   - `detectNewLanguageFiles()` — called after auto-update; scans `/lang/` for files not yet in `languages` table, adds them as inactive. Admin sees a notification: "New languages available: French, German — activate in Settings."

2. **`/app/modules/languages/Controllers/LanguagesController.php`**:
   - `index()` — Language management page showing all installed languages in a table: flag/code, name, native name, completion %, status (active/inactive), source (shipped/uploaded), actions (activate/deactivate/set default/edit overrides)
   - `upload()` — Upload form + validation results preview
   - `exportMaster()` — Download master language file
   - `overrides($code)` — Searchable/filterable list of all strings for a language. Each row shows: key, English value (from master), current translation (from file), DB override (if any), edit button. Admin can inline-edit any string → saved as DB override. Can also clear an override to revert to file value.
   - `activate($code)` / `deactivate($code)` / `setDefault($code)` — HTMX toggle endpoints

3. **Language switcher integration**: The 🌐 dropdown in the topbar only shows **active** languages. Each entry shows the language code + native name (e.g. "EN English", "FR Français", "MT Malti"). No flags — language codes are unambiguous and avoid political/regional flag controversies (e.g. which flag for Spanish? Portuguese?).

4. **Templates**:
   - `languages/index.html.twig` — language list with status toggles, upload button, export master button
   - `languages/upload.html.twig` — upload form with drag-drop, validation results (keys found, missing, completion %)
   - `languages/overrides.html.twig` — searchable string editor with inline edit (HTMX), filter by: all/translated/untranslated/overridden

5. **Module registration**: Accessible from Settings panel (Settings → Languages tab), not a separate nav item. Route: `/admin/languages`.

6. **Auto-update hook**: After an update completes, `detectNewLanguageFiles()` runs. If new language files found, a notification appears in the admin dashboard: "New languages available after update — [manage languages]".

**Tests**: `tests/Modules/Languages/LanguageServiceTest.php` — upload validation (valid JSON, key matching, completion %), activate/deactivate, default setting, override CRUD, detect new files. `tests/Modules/Languages/LanguagesControllerTest.php` — page renders, upload flow, master export download.

**Seeder**: English active as default. One additional inactive language file (`mt.json` with partial translations) for testing.

---

### Step 5.6 — Audit Log [M]
**Dependencies: 1.3, 2.1**

1. **Migration `0017_audit_log.sql`**: `audit_log` (BIGINT id, user_id, action, entity_type, entity_id, old_values JSON, new_values JSON, ip_address, user_agent, created_at). Indexes on entity, user, created_at, action.

2. **`/app/src/Core/AuditLog.php`**: `log($action, $entityType, $entityId, $oldValues, $newValues)`. Auto-captures user_id, IP, user agent. Redacts sensitive fields (medical, passwords) → `"[REDACTED]"`.

3. **Integration**: Add audit logging to ALL existing services — member CRUD, role CRUD, assignment create/end, org node CRUD, terms publish/accept/refuse, settings changes, login/logout/failed login.

4. **`AuditController.php`**: Paginated log with filters (entity type, user, date range, action). Per-entity trail view. Diff view (old vs new).

5. **Module registration**: group `administration`, icon `bi-clock-history`, route `/admin/audit`, order 60.

**Tests**: Log entry creation, sensitive field redaction, retrieval with filters.

---

### Step 5.7 — Log Viewer [S]
**Dependencies: 1.9**

1. **`LogViewerController.php`**: Display errors.json, slow-queries.json, cron.json. Paginated, filterable. Clear log button.

2. **Module registration**: group `administration`, icon `bi-file-earmark-text`, route `/admin/logs`, order 70.

**Tests**: Log parsing, pagination.

---

### Step 5.8 — Data Export [S]
**Dependencies: 3.1, 2.3**

1. **`ExportService.php`**: `exportMembersCsv($scopeNodeIds, $filters)` (stream), `exportMembersXml()`, `exportMyData($memberId)` (GDPR single member CSV), `exportSettings()` (JSON). All respect permission scoping.

2. **Integration**: Export buttons on member list, member profile ("Download My Data"), admin settings.

**Tests**: CSV/XML format, scope filtering, GDPR export completeness.

---

### Step 5.9 — Backup and Restore [M]
**Dependencies: 1.3**

1. **`BackupService.php`**: `createBackup()` → PHP-based DB dump (no mysqldump CLI — shared hosting), iterate tables with `SHOW TABLES` + chunked `SELECT *` → INSERT statements, zip SQL + `/data/` contents → store in `/data/backups/`. `listBackups()`, `downloadBackup()`, `deleteBackup()`, `restoreFromBackup()` (extract + run SQL + restore files).

2. **Controller + template**: Backup management page (create, download, delete). Restore form with file upload. Accessible from Settings panel.

**Tests**: Backup creation, SQL dump correctness, zip contents, restore.

---

## PHASE 6: Installation and Updates

### Step 6.1 — Setup Wizard [L]
**Dependencies: All Phase 1–5**

Multi-step wizard (session-based state):
1. **Pre-flight checks**: PHP ≥ 8.2, MySQL ≥ 8.0, required extensions (pdo_mysql, openssl, mbstring, json, gd), write permissions on `/app/`, `/updater/`, `/var/`, `/config/`, `/data/`. Pass/fail display.
2. **Database**: Host, name, user, password. Test connection. Run all migrations.
3. **Organisation**: Org name, initial hierarchy (root node), define level types.
4. **Admin account**: Email, password (min 10), name → creates super admin user + member record.
5. **SMTP**: SMTP settings + "Send test email" button. Skip option.
6. **Encryption key**: Auto-generate → `/config/encryption.key`.
7. **Cron setup**: Detect cPanel, display exact cron command. Explain pseudo-cron fallback.
8. **Finish**: Write `/config/config.php`, success screen, login link.

**Detection**: If config.php doesn't exist, all requests → `/setup`. After completion, wizard routes return 404.

**Tests**: Pre-flight checks, DB connection test, config generation, migration execution. Integration: full wizard walkthrough.

---

### Step 6.2 — Auto-Update Mechanism [L]
**Dependencies: 6.1**

1. **`UpdateService.php`**: `checkForUpdate()` (GitHub API → compare version), `downloadRelease()`, `verifySignature()` (openssl vs public key), `stageUpdate()`, state file management for resume.

2. **Phase 1 (main app updates updater)**: `updateUpdater()` → atomic swap of `/updater/` via `rename()`. Retain old in `/var/updates/updater_backup/`.

3. **`/updater/run.php`**: Standalone entry point (NO `/app/` loaded). Validates single-use token. Extracts new `/app/` from staging → atomic swap. Runs migrations from new `/app/migrations/`. Clears opcache. Clears maintenance flag. Redirects to main app.

4. **`/updater/UpdateRunner.php`**: Self-contained, own minimal DB connection (reads config directly). Step-by-step with state persistence. `flock()` concurrency prevention. Rollback: swap back old `/app/` if migration fails.

5. **Public key**: `/public_html/update_public_key.pem` — ships with install, never auto-updated.

6. **Admin UI**: Update available notification in topbar, update management page.

**Tests**: Version comparison, signature verification (test keys), state management.

---

### Step 6.3 — Monitoring Endpoints [S]
**Dependencies: 1.3, 1.9**

1. **`/public_html/health.php`**: Unauthenticated JSON: status, version, php_version, db_connected, peak_memory_mb, last_cron_run, slow_query_flag, error_count_since_last_check.

2. **`/api/logs` endpoint**: Authenticated via `X-API-Key` header (from config). Returns recent errors + slow queries. Supports `?since=ISO8601`.

**Tests**: Health endpoint format, API key auth, log retrieval, since parameter.

---

## PHASE 7: Polish and Release Prep

### Step 7.1 — Comprehensive Synthetic Org Seeder [L]
**Dependencies: All modules**

1. **`/tests/Seeders/NorthlandSeeder.php`**: Idempotent `run()` method creating complete "Scouts of Northland":
   - Level types + full org tree (expanded from 2.3) + 6 teams
   - 5 system + custom roles
   - 150+ members: realistic names (culturally varied), all sections, varied statuses (120 active, 15 pending, 5 suspended, 10 inactive), 30 with user accounts, 10 with multiple roles, 5 cross-level, 3 expired roles, custom field data, timeline entries for 20, medical data for 50
   - 20 events (past + future), 10 articles, 8 achievement defs + 40 assignments
   - 5 pending registrations, 3 waiting list, 2 invitation tokens
   - 1 published T&Cs, 2 notices, default settings, 100+ audit log entries, 20 queued emails

2. **`/tests/Seeders/PlaywrightFixtures.php`**: Known-state users: `admin@northland.test`, `leader@northland.test`, `member@northland.test` (all `TestPass123!`), user with pending T&Cs, user with MFA (known TOTP secret).

3. **CLI runner**: `php tests/seed.php`.

---

### Step 7.2 — Playwright E2E Test Suite [L]
**Dependencies: 7.1**

1. **`/tests/e2e/playwright.config.ts`**: Local dev server, screenshot on failure.

2. **Scenarios**: `auth.spec.ts` (login/logout/failed/reset/MFA), `members.spec.ts` (list/search/profile/add/edit), `registration.spec.ts` (self-reg/invitation/approval), `org-structure.spec.ts` (tree/add/team/move), `events.spec.ts` (calendar/create/view), `communications.spec.ts` (portal/article/email), `achievements.spec.ts` (definitions/assign), `directory.spec.ts` (organogram/contacts), `dashboard.spec.ts`, `admin.spec.ts` (settings/audit/logs/backup), `terms.spec.ts` (acceptance/grace/blocking), `responsive.spec.ts` (320px, 768px), `dark-mode.spec.ts`, `session-timeout.spec.ts`.

3. **`/tests/e2e/helpers/`**: Page object models.

---

### Step 7.3 — Documentation and Metadata [S]
**Dependencies: All**

1. **Create `/CLAUDE.md`**: Architecture summary, directory layout, module creation guide, DB/template/test conventions, common commands.
2. **Fix `/CONTRIBUTING.md`**: PHP 8.2+ (not 8.0+), MySQL 8.0+ (not 5.7+), add Playwright, update dir structure.
3. **Update `/features.md`**: Mark v1 features implemented, fix "3 colour schemes" → "single neutral palette".
4. **Update `/README.md`**: Add Playwright, link CLAUDE.md.

---

### Step 7.4 — Security Audit [M]
**Dependencies: All**

Review and fix: SQL injection (all queries use prepared statements), XSS (Twig auto-escaping, justified `|raw`), CSRF (all state-changing endpoints), auth/permission checks on all routes, file upload validation + path traversal, encryption verification, security headers in .htaccess (CSP, HSTS, X-Content-Type-Options, X-Frame-Options), session config, `composer audit`. Create `/SECURITY.md`.

---

### Step 7.5 — Performance Testing [M]
**Dependencies: 7.1**

1. Large dataset seeder option: `php tests/seed.php --large` (5000+ members, 200+ events).
2. Index review for all service queries.
3. Slow query optimization from large dataset tests.
4. Twig cache verification.
5. Pagination verification with large datasets.

---

### Step 7.6 — Release Packaging (CI/GitHub Actions) [M]
**Dependencies: All**

1. **`/.github/workflows/ci.yml`**: Trigger push/PR. Jobs: lint (PSR-12), unit tests (composer install → test DB → migrations → PHPUnit), E2E (PHP server → seeder → Playwright). Matrix: PHP 8.2, 8.3.

2. **`/.github/workflows/release.yml`**: Trigger tag `v*`. Full CI → `composer install --no-dev --optimize-autoloader` → strip dev files → zip → sign with release key (GitHub secret) → create GitHub release with zip + signature. Changelog from commits.

3. **`/.github/ISSUE_TEMPLATE/`**: Bug report + feature request templates.

---

## Dependency Graph

```
Phase 1 (Foundation):
  1.1 (Skeleton) → 1.2 (Vendor) → 1.5 (Twig+Layouts)
  1.1 → 1.3 (Database) → 1.4 (Router) → 1.5
  1.1 → 1.10 (Encryption)
  1.3 → 1.7 (i18n), 1.8 (Session), 1.9 (Error handling)
  1.4 + 1.5 → 1.6 (Module Registry)

Phase 2 (Core infra):
  1.* → 2.1 (Auth) → 2.2 (Permissions) → 2.3 (Org Structure)

Phase 3 (Members):
  2.* → 3.1 (Member CRUD) → 3.2 (Custom fields), 3.3 (Timeline), 3.4 (Attachments)
  3.1-3.4 → 3.5 (Profile UI)
  3.1 → 3.6 (Registration flows)

Phase 4 (Features) — all depend on 3.1 + 2.2, can run in parallel:
  4.1 (Communications), 4.2 (Events), 4.3 (Achievements), 4.4 (Directory)

Phase 5 (Admin) — mostly independent, some sequential:
  5.1 (Dashboard) → 5.2 (Reports)
  5.3 (T&Cs), 5.4 (Notices), 5.5 (Settings), 5.10 (Languages), 5.6 (Audit), 5.7 (Logs), 5.8 (Export), 5.9 (Backup)

Phase 6:
  All → 6.1 (Setup wizard) → 6.2 (Auto-update)
  6.3 (Monitoring) independent

Phase 7:
  All → 7.1 (Seeder) → 7.2 (Playwright), 7.5 (Performance)
  7.3 (Docs), 7.4 (Security), 7.6 (CI/CD)
```

## Complexity Summary

| Phase | Steps | S | M | L |
|-------|-------|---|---|---|
| 1 Foundation | 10 | 2 | 8 | 0 |
| 2 Core infra | 3 | 0 | 0 | 3 |
| 3 Members | 6 | 2 | 2 | 2 |
| 4 Features | 4 | 0 | 3 | 1 |
| 5 Admin | 10 | 3 | 6 | 1 |
| 6 Install/Update | 3 | 1 | 0 | 2 |
| 7 Polish | 6 | 1 | 3 | 2 |
| **Total** | **42** | **9** | **22** | **11** |

## Agent Execution Rules

1. **Check existing code first** before creating files — another step may have already created what you need.
2. **Every service method** gets a docblock (params, return, purpose).
3. **All user-facing strings** use i18n keys: `$this->i18n->t('key')` in PHP, `{{ t('key') }}` in Twig. Never hardcode English.
4. **Database queries** always use PDO wrapper prepared statements. Never concatenate user input into SQL.
5. **Permission checks** on every controller action accessing scoped data: `requirePermission()` + filter by `getScopeNodeIds()`.
6. **Audit logging** for every create/update/delete in service classes.
7. **HTMX pattern**: detect `$request->isHtmx()` → return partial template only, not full layout.
8. **Migration numbering**: sequential, no gaps. Check highest existing number first.
9. **Seeder grows incrementally**: each module step adds to NorthlandSeeder. Step 7.1 consolidates.
10. **Test database**: separate DB in `tests/bootstrap.php`. Run migrations in setUp, truncate in tearDown.

## Verification

After each phase, verify:
- `composer test` (PHPUnit) passes
- Application boots without errors
- Seeder runs successfully: `php tests/seed.php`
- All routes render correctly in browser (admin + member shells)
- Dark/light mode toggle works
- Mobile responsive (check at 375px, 768px, 1024px)

After Phase 7:
- Full Playwright suite passes
- Large dataset seeder + performance benchmarks acceptable
- Security audit checklist complete
- CI pipeline green
- Release zip builds and installs via setup wizard on clean shared hosting
