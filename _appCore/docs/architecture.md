# appCore — Architecture

> A lean, modular, self-hostable PHP 8.2 application framework extracted from ScoutKeeper. Zero heavy dependencies, no build step, module-driven, secure by default.

---

## 1. Philosophy

- **Explicit over implicit.** Permissions, routes, nav items, cron handlers — all declared in a module's `module.php`, never magic-discovered from class names.
- **Separation of concerns.** Controller → Service → Database. No inline SQL in controllers; no HTML in services.
- **Lean stack.** PHP 8.2, PDO, FastRoute, Twig, PHPMailer, Google2FA. No framework. No ORM. No build tooling required to run.
- **Self-hostable.** Runs on shared Linux hosting. Setup wizard in-browser. No CLI required for install.
- **Secure by default.** CSRF on state changes. Autoescape on templates. AES-256-GCM for PII. Session hardening. Security headers via `.htaccess`.
- **Observability built in.** Slow-query log, request profile log, error log, audit log — all JSON, rotated.

---

## 2. Request lifecycle

```
HTTP request
   │
   ▼
.htaccess → front-controller rewrite → /index.php
   │
   ├─► Maintenance flag set? → show maintenance page (allow /setup, /updater/*)
   ├─► config/config.php missing? → SetupWizard (self-contained, no Twig/modules)
   │
   ▼
app/bootstrap.php
   │   - Autoloader (Composer PSR-4)
   │   - Load config/config.php
   │   - Set timezone, error reporting
   │   - Application::init($config)
   │
   ▼
Application::run()
   │   1. Session::start()
   │   2. Database::connect()
   │   3. I18n::resolve() (session > DB default > config > 'en')
   │   4. TwigRenderer::init()
   │   5. ModuleRegistry::loadModules() — glob /app/modules/*/module.php
   │   6. Register routes, nav, permissions, cron handlers
   │   7. Csrf::validate() if request is state-changing
   │   8. Router::dispatch(Request)
   │
   ▼
Controller method
   │   - requireAuth() / requirePermission()
   │   - Read params via Request
   │   - Call Service(s)
   │   - Return Response (html | json | redirect | file)
   │
   ▼
Response emitted
   │
   ▼
Post-response (fastcgi_finish_request)
   │   - Pseudo-cron if interval elapsed and no real cron
   │   - Append slow-request + slow-query profile to /var/logs/
```

---

## 3. Directory layout

```
/index.php                    Entry point; maintenance + setup gate, then bootstrap
/.htaccess                    Apache rewrites, deny rules, security headers
/app/
  bootstrap.php               Autoload + config load + Application::init
  /src/
    Core/                     Framework primitives (see §4)
    Setup/                    Setup wizard (self-contained, no Twig)
  /modules/                   Feature modules, each with module.php
  /migrations/                Numbered *.sql files
  /templates/                 Shared Twig: layouts/, components/, errors/
/assets/                      Static: css/, js/, vendor/ (raw, no build step)
/config/
  config.example.php          Template written by setup wizard
  config.php                  Actual config (gitignored)
  encryption.key              32-byte random key (0600 perms)
/cron/run.php                 Cron dispatcher (HTTP with secret, or CLI)
/data/                        User uploads and generated artefacts
/lang/en.json                 Base language file
/tools/migrate.php            Migration runner entry
/updater/run.php              Auto-update entry (token-gated)
/var/
  cache/                      Twig cache, transient files
  logs/                       errors.json, app.json, slow-queries.json, requests.json
  sessions/                   File-based PHP sessions
  maintenance.flag            Flag file — when present, app is in maintenance mode
/tests/                       PHPUnit + Playwright
/vendor/                      Composer (gitignored)
```

---

## 4. Core primitives (`app/src/Core/`)

| Class | Responsibility |
|---|---|
| **Application** | Singleton orchestrator. Holds DB, Router, Twig, Session, I18n, ModuleRegistry, PermissionResolver, Request. Drives `run()` lifecycle. |
| **Database** | PDO wrapper. Parameterised queries only. CRUD helpers (`insert/update/delete`), transactions, slow-query profiling. |
| **Router** | Wraps `nikic/fast-route`. `get/post/put/delete(pattern, [Class, 'method'], name)` + named-route URL generation. |
| **Session** | File-based sessions (`/var/sessions/`). HttpOnly + SameSite=Lax + Secure-if-HTTPS. Flash messages by type. Timeout enforcement. |
| **Csrf** | 64-char hex tokens (32 random bytes). `hash_equals()` comparison. Token via POST param (`_csrf_token`) or `X-CSRF-Token` header. |
| **Encryption** | AES-256-GCM. Key from `/config/encryption.key`. IV + tag + ciphertext, base64-encoded. Decrypt validates AEAD tag. |
| **I18n** | Loads `/lang/{code}.json`, merges `language_overrides` table rows. Placeholder `{name}` interpolation. Missing key returns the key. |
| **TwigRenderer** | Twig 3 with `@module` namespaces, autoescape=html, cache in `/var/cache/twig/`. Registers globals + `t()`, `csrf_field()`, `route()`, `asset()`, `has_permission()`, `flash()`. |
| **Controller** | Base class. `render()`, `json()`, `redirect()`, `requireAuth()`, `requirePermission()`, `validateCsrf()`, `flash()`. Injects standard template vars (user, csrf_token, nav_items, theme). |
| **Request** | Wraps superglobals. `getParam()`, `getBody()`, `isHtmx()` (`HX-Request` header), `isStateChanging()` (POST/PUT/DELETE). |
| **Response** | Value object. `html()`, `json()`, `redirect()`, `file()`. Emits `Cache-Control: no-store` by default. |
| **ModuleRegistry** | Discovers `/app/modules/*/module.php`. Aggregates routes, nav items, permissions, cron handlers. Caches in memory. |
| **PermissionResolver** | Loads active `role_assignments` for user. Unions permissions. Super-admin bypass. Scoped to node IDs via `role_assignment_scopes`. Caches in `$_SESSION['_permissions']`. |
| **Logger** | Static `error/warning/info/debug/smtp`. Writes JSON to `/var/logs/`. Rotates at 5MB (5 backups). |
| **Migration** | Reads `/app/migrations/*.sql` in filename order. Tracks applied files in `_migrations` table. PDO-level statement splitting (handles strings/comments). |
| **ErrorHandler** | Registers exception + error handlers. Routes fatals to logger + 500 page. |

---

## 5. Module system

A module is a directory `/app/modules/{Name}/` with a single required file: `module.php`. It returns an array:

```php
return [
    'id'      => 'example',          // unique slug
    'name'    => 'Example',          // display name
    'version' => '1.0.0',
    'system'  => false,              // true = cannot be disabled

    'nav' => [                       // optional; single item or array of items
        [
            'label'         => 'nav.example',     // i18n key
            'icon'          => 'bi-box',          // Bootstrap icon
            'route'         => '/admin/example',
            'group'         => 'admin',           // '_top' = ungrouped
            'order'         => 20,
            'requires_auth' => true,
            'modes'         => ['admin'],         // or ['member'], ['admin','member']
            'badge'         => fn() => null,      // optional callable returning int|null
        ],
    ],

    'routes' => function (\AppCore\Core\Router $router): void {
        $router->get('/admin/example', [ExampleController::class, 'index'], 'example.index');
        $router->post('/admin/example', [ExampleController::class, 'store'], 'example.store');
    },

    'permissions' => [
        'example.read'  => 'View examples',
        'example.write' => 'Create/edit examples',
    ],

    'cron' => [
        \AppCore\Modules\Example\Jobs\NightlyJob::class,
    ],
];
```

**Loading order** is directory-alphabetical. Modules should not depend on each other's load order — use service locators or events if cross-module coupling is genuinely needed.

**Template namespacing**: `/app/modules/Example/templates/` is exposed as `@example` in Twig. Render with `@example/index.html.twig`.

**Services** live in `/app/modules/Example/Services/`. They receive dependencies via constructor (typically `Database`, `Session`, `Encryption`) — wired by controllers or a small service locator.

---

## 6. Data layer

### Migrations

- Files in `/app/migrations/`, named `NNNN_description.sql` (4-digit prefix, zero-padded).
- ASCII sort determines execution order.
- Tracked in `_migrations` table; re-run is a no-op for already-applied files.
- Failure mid-file leaves DDL partially applied (MySQL auto-commits DDL). Convention: one schema concern per file, split risky multi-statement changes.
- Runner: `php tools/migrate.php` (CLI) or invoked by setup wizard.

### Core schema (shipped in appCore)

| Table | Purpose |
|---|---|
| `_migrations` | Applied migration filenames (idempotency) |
| `users` | Email, bcrypt password hash, encrypted MFA secret, locked flag, failed-attempt count |
| `password_resets` | One-time 64-char-hex tokens with expiry |
| `roles` | Name + JSON `permissions` blob + special-capability flags |
| `role_assignments` | user ↔ role with date range |
| `role_assignment_scopes` | Optional node-ID scoping |
| `settings` | Key/value store (strings or JSON) |
| `audit_log` | `entity_type`, `entity_id`, `action`, `old_values`, `new_values`, `user_id`, `created_at` |
| `languages` | Supported languages with `is_default` flag |
| `language_overrides` | Per-language string overrides (layered over JSON base) |
| `notices` | Admin-broadcast notices + `notice_acknowledgements` |
| `terms_versions` | Terms & Conditions versioning + `terms_acceptances` |

### Encryption at rest

Columns holding PII or credentials (e.g. `users.mfa_secret`) are encrypted via `Encryption::encrypt($plaintext)` before insert and decrypted on read. Convention: name encrypted columns with an `encrypted_` prefix to signal they require the key to be useful.

---

## 7. Frontend

- **Twig 3**, autoescape=html, cached to `/var/cache/twig/`.
- **Layouts** (`/app/templates/layouts/`): `base.html.twig` (doctype + nav + footer) → `admin.html.twig` (sidebar + topbar) / `auth.html.twig` (centred card).
- **Components** (`/app/templates/components/`), prefixed `_`: `_alert`, `_breadcrumbs`, `_confirm_modal`, `_empty_state`, `_loading_spinner`, `_pagination`, `_footer`.
- **Bootstrap 5.3** for layout + utilities. `data-bs-theme` drives light/dark mode.
- **HTMX 2** for partial updates. Controllers detect via `Request::isHtmx()` and may render a fragment template instead of a full page.
- **Alpine.js 3** for small interactive behaviours (dropdowns, toggles). Avoid writing big SPAs in Alpine.
- **No build step.** Vendor libraries live raw in `/assets/vendor/`. `asset()` Twig function appends `?v={filemtime}` for cache busting.

---

## 8. Auth & permissions

### Auth
- Password hashing: `password_hash($plain, PASSWORD_BCRYPT)`.
- Account lockout after N failed attempts (`users.failed_attempts`, `users.is_locked`).
- MFA: TOTP via `pragmarx/google2fa`. Secret encrypted in `users.mfa_secret`. Login redirects to `/login/mfa` when enabled.
- Password reset: one-time 64-char hex token, stored with expiry, emailed to user.

### Permissions
- Each `role` has a JSON `permissions` blob: `{"example.read": true, "example.write": true}`.
- User's effective permissions = union of all active role assignments.
- `PermissionResolver::can($key)` checks single permissions. Result cached in session until invalidated.
- Controllers: `$check = $this->requirePermission('example.read'); if ($check) return $check;`
- Templates: `{% if has_permission('example.read') %}...{% endif %}`.
- Assignments can be scoped to specific node IDs (e.g. "this role applies only under node 5 and descendants"). Scope enforcement happens at application level in service queries — it is **not** enforced at the database layer.

---

## 9. Cron

- HTTP entry: `GET /cron/run.php?secret=...` — secret from `config.cron.secret`, constant-time compared.
- CLI entry: `php cron/run.php` — always allowed.
- Dispatcher walks `ModuleRegistry::getCronHandlers()` and invokes each.
- Pseudo-cron fallback: after a normal response, if `fastcgi_finish_request` is available and the cron interval has elapsed, `Application` runs the dispatcher post-response so the user isn't blocked. Intended only for deployments that cannot configure a real cron.

---

## 10. Updater

- `/updater/run.php`: token-gated entry (token stored in `/var/`, single-use).
- Flow: set maintenance flag → extract update zip from `/var/updates/` → swap `/app/` → run migrations → clear flag → log result.
- Rollback stub present but manual; operators should take a DB backup before invoking.
- Migration runner is the same one used by setup wizard — no separate upgrade path.

---

## 11. Setup wizard

Self-contained in `/app/src/Setup/`. Does **not** load modules, Twig, or the full bootstrap. Runs when `/config/config.php` is missing. Eight steps:

1. **Prerequisites** — PHP version, required extensions (`pdo`, `pdo_mysql`, `json`, `mbstring`, `openssl`, `fileinfo`, `zip`), writable dirs.
2. **Database** — host/port/user/password. Creates DB if absent.
3. **Install type** — blank / clean / demo.
4. **Organisation** — project-defined first-run seed data.
5. **Admin account** — email + password + name.
6. **SMTP** — mail transport config.
7. **Encryption key** — generates 32 random bytes to `/config/encryption.key` with 0600 perms.
8. **Finish** — writes `config.php`, runs migrations, applies seeds.

Templates are plain PHP (`step{N}.php`), not Twig — they must render before Twig exists.

---

## 12. Observability

All logs are JSON, append-only, auto-rotated at 5 MB with 5 backups.

| Log | Source | Trigger |
|---|---|---|
| `errors.json` | `Logger::error` + uncaught exceptions | Always |
| `app.json` | `Logger::info/debug` | Debug mode only |
| `smtp.json` | PHPMailer wrapper | Every mail attempt |
| `slow-queries.json` | `Database` profiler | Query exceeds `db.slow_query_threshold_ms` |
| `requests.json` | `Application` post-response | Wall time or query count exceeds thresholds (includes N+1 detection via normalised-SQL aggregation) |
| `cron.json` | `/cron/run.php` | Every invocation, last 100 entries |
| `updates.json` | `/updater/run.php` | Every attempt |
| `audit_log` table (not a file) | `AuditService::log()` | Every state-changing mutation — call from service layer |

---

## 13. Configuration

`/config/config.php` returns a plain PHP array — no YAML, no .env. Written by setup wizard, edited by hand afterwards.

```php
return [
    'app' => [
        'name'     => 'My Project',
        'url'      => 'https://example.com',
        'timezone' => 'Europe/Malta',
        'language' => 'en',
        'debug'    => false,
    ],
    'db' => [
        'host' => 'localhost', 'port' => 3306,
        'name' => 'myproject', 'user' => 'app', 'password' => '...',
    ],
    'security' => [
        'session_timeout'     => 3600,
        'encryption_key_file' => __DIR__ . '/encryption.key',
    ],
    'smtp' => [
        'host' => '...', 'port' => 587,
        'user' => '...', 'password' => '...',
        'from_email' => '...', 'from_name' => '...',
    ],
    'cron' => [
        'secret'                  => '...',
        'email_interval_seconds'  => 60,
    ],
    'monitoring' => [
        'slow_request_threshold_ms' => 500,
        'slow_request_query_count'  => 20,
        'slow_query_threshold_ms'   => 100,
    ],
];
```

---

## 14. What appCore ships vs. what you build

**Ships as working code** (the always-needed core):

- Bootstrap, autoloader, DI wiring, Application lifecycle
- All Core primitives (§4)
- Auth module (login, logout, password reset, MFA)
- Permissions module (roles, assignments, scopes CRUD)
- Admin module — trimmed: Settings, Audit log viewer, Backup, Logs, Language management, Monitoring, Notices, Terms & Conditions, Update check
- Setup wizard + 8 steps
- Migration runner + core migrations (users, roles, settings, audit, languages, notices, terms)
- Base Twig layouts + shared components
- Cron dispatcher skeleton
- Updater subsystem
- Testing scaffolding (PHPUnit, Playwright, seeder skeleton)
- `CLAUDE.md` template with project placeholders
- Pattern documentation for common add-ons (see `/docs/patterns/`)

**Documented as patterns** (add when your project needs them):

- Closure-table hierarchy (org structures, category trees)
- Custom fields (JSON-column flexible schema)
- Timeline / activity-log per entity
- Attachments / file uploads
- Email compose + queue + cron dispatch
- Calendar + iCal feeds
- Bulk import (CSV)
- Reports
- Entity-scoped notices

---

## 15. What appCore deliberately does **not** include

- **No ORM.** PDO + hand-written SQL in service classes. Readable, debuggable, fast. If you need query-builder ergonomics, add one per-project.
- **No container.** `Application` is a light service locator. No reflection-based DI, no compile step.
- **No queue/worker.** Cron is the scheduler. If you need a real queue, add it explicitly — don't layer it into Core.
- **No API layer.** Controllers can return JSON; if you need a full REST/GraphQL surface, build it as a module.
- **No front-end build.** If you need TypeScript/bundling, add it outside `/assets/vendor/` — Core will not know or care.
- **No multi-tenancy.** One database, one tenant. If you need tenancy, that's a project-level decision.
