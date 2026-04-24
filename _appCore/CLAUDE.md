# {{PROJECT_NAME}} — Developer Guide

> This file is loaded into Claude's context on every session in this project. It tells Claude how the codebase is organised, what conventions to follow, and what *not* to do.
>
> **When starting a new project from appCore, find-and-replace these tokens:**
> - `{{PROJECT_NAME}}` — human-readable project name (e.g. "Acme Membership Portal")
> - `{{PROJECT_SLUG}}` — URL/directory slug (e.g. "acme-portal")
> - `{{VENDOR}}` — vendor namespace (e.g. "Acme")
> - `{{VENDOR_SLUG}}` — composer vendor slug (e.g. "acme")
>
> Then delete this callout block.

---

## Overview

{{PROJECT_NAME}} is built on **appCore**, a lean, modular, self-hostable PHP 8.2 framework. Plain PHP (no framework), self-hostable on Linux shared hosting, no CLI required for end users.

**Stack**: PHP 8.2 · PDO/MySQL · FastRoute · Twig 3 · HTMX 2 · Alpine.js 3 · Bootstrap 5.3. No frontend build step. No ORM.

**Primary working directory**: this repository.

---

## Directory Layout

```
/index.php                    Front controller (never auto-updated)
/.htaccess                    Apache rewrites + security headers
/app/
  bootstrap.php               Autoloader + config loader + Application::init
  /src/
    Core/                     Framework primitives (Database, Router, Session, etc.)
    Setup/                    First-run wizard
  /modules/                   Feature modules (each with module.php)
  /migrations/                Numbered *.sql files (forward-only)
  /templates/                 Shared Twig: layouts/, components/, errors/
/assets/
  css/, js/                   Project-specific styles and scripts
  vendor/                     Vendored libs (Bootstrap, HTMX, Alpine) — no build step
/config/
  config.example.php          Template (committed)
  config.php                  Actual config (gitignored)
  encryption.key              32-byte random key (0600, gitignored)
/cron/run.php                 Cron dispatcher
/data/                        User uploads, generated artefacts (gitignored)
/lang/en.json                 Base language strings
/tools/migrate.php            CLI migration runner
/updater/run.php              Token-gated auto-update entry
/var/
  cache/, logs/, sessions/    Writable runtime state (gitignored)
  maintenance.flag            When present, app is in maintenance mode
/tests/                       PHPUnit + Playwright + seeder
/docs/                        architecture.md, conventions.md, decisions/, patterns/
```

## Module Structure

Each module lives in `/app/modules/{ModuleName}/` with:

```
module.php              Self-registration: routes, nav, permissions, cron
Controllers/*.php       Request handlers extending AppCore\Core\Controller
Services/*.php          Business logic (all SQL goes here)
templates/**/*.twig     Twig templates (namespaced as @{moduleid})
```

Modules self-register via `module.php` — loaded by `ModuleRegistry`. A module registers:
- **Routes**: FastRoute definitions
- **Navigation**: sidebar items with group, label, icon, sort order
- **Permissions**: module-level capabilities (declared, not enforced)
- **Cron handlers**: job classes invoked by `/cron/run.php`

### Current Modules

| Module | Path | Purpose |
|--------|------|---------|
| Auth | `app/modules/Auth` | Login, logout, password reset, MFA |
| Permissions | `app/modules/Permissions` | Role CRUD, assignment management |
| Admin | `app/modules/Admin` | Dashboard, Settings, Audit, Backup, Logs, Export, Updates, Languages, Notices, Terms, Monitoring |

Add your project modules alongside these. See `/docs/playbook.md` for the "add a new module" workflow.

---

## Database

- PDO wrapper: `AppCore\Core\Database` — all queries use prepared statements
- Migrations: numbered SQL files in `/app/migrations/`, run sequentially, forward-only
- Key core tables: `users`, `roles`, `role_assignments`, `role_assignment_scopes`, `settings`, `audit_log`, `languages`, `language_overrides`, `notices`, `terms_versions`
- Custom table conventions: snake_case, plural nouns, `id INT PRIMARY KEY AUTO_INCREMENT`, `created_at`/`updated_at` TIMESTAMPs

---

## Template Conventions

- Twig 3 with auto-escaping enabled (html)
- Layouts: `base.html.twig` → `admin.html.twig` (sidebar+topbar) / `auth.html.twig` (minimal)
- Components: `_alert`, `_breadcrumbs`, `_confirm_modal`, `_empty_state`, `_footer`, `_loading_spinner`, `_pagination`
- HTMX for partial updates (lazy-loaded tabs, forms that return fragments)
- Alpine.js for tiny interactive state (dropdowns, toggles)
- Bootstrap 5.3 with `data-bs-theme` for dark/light mode
- Cache-busted assets via `{{ asset('/path') }}` — appends `?v={filemtime}`

---

## Testing

### PHPUnit
```bash
vendor/bin/phpunit
```
Tests in `/tests/Core/`, `/tests/Modules/`. Fixtures in `/tests/fixtures/`.

### Playwright E2E (add when UI work starts)
```bash
cd tests/e2e && npm install && npx playwright test
```

### Seeder (add one per project if needed)
```bash
php tests/seed.php
```

---

## Common Commands

```bash
# Install dependencies
composer install

# Run PHPUnit tests
vendor/bin/phpunit

# Run a specific test file
vendor/bin/phpunit tests/Core/DatabaseTest.php

# Run migrations manually (setup wizard runs them automatically on first install)
php tools/migrate.php

# Start PHP dev server
php -S localhost:8080

# Trigger cron manually (CLI)
php cron/run.php
```

---

## Key Conventions (strict — do not deviate without ADR)

- **PSR-12** coding standard + `declare(strict_types=1);` on every `.php` file
- **PHP 8.2+** features expected: `readonly`, `enum`, constructor promotion, `match`
- **No inline SQL in controllers** — all queries go through Service classes
- **No ORM, no repositories, no models** — Controller → Service → Database (see [ADR-0013](docs/decisions/0013-controller-service-db.md))
- **Permissions are explicit** — never imply from role name or hierarchy position
- **i18n keys** for all user-facing strings: `{{ t('module.key') }}` / `$t('module.key')`
- **CSRF tokens** on all state-changing forms (`{{ csrf_field()|raw }}`); auto-validated for POST/PUT/DELETE
- **Sensitive data encrypted at rest** (AES-256-GCM via `Encryption` class); encrypted columns prefixed `encrypted_`
- **Audit logging** on all mutations — `AuditService::log()` from the service layer
- **Forward-only migrations** — to undo, write a new migration (see [ADR-0012](docs/decisions/0012-forward-only-migrations.md))
- **No frontend build step** — vendor libs live raw in `/assets/vendor/` (see [ADR-0015](docs/decisions/0015-no-frontend-build.md))

For the full rule set, read [docs/conventions.md](docs/conventions.md).

---

## Where to Look Before Acting

| If you're about to... | Read first |
|---|---|
| Add a new module | `docs/playbook.md` → "Add a module" |
| Add a new permission | `docs/conventions.md` §9 |
| Add a new migration | `docs/conventions.md` §4 + [ADR-0012](docs/decisions/0012-forward-only-migrations.md) |
| Add hierarchical data (trees) | `docs/patterns/hierarchy-closure-table.md` |
| Add file uploads | `docs/patterns/attachments.md` |
| Add activity feed / history | `docs/patterns/timeline.md` |
| Add flexible fields to an entity | `docs/patterns/custom-fields.md` |
| Add email sending | `docs/patterns/email-queue.md` |
| Add a calendar / events | `docs/patterns/calendar-ical.md` |
| Add a CSV import flow | `docs/patterns/bulk-import.md` |
| Add reports | `docs/patterns/reports.md` |
| Change an architectural default | Write a new ADR in `docs/decisions/` — supersede, don't edit the old one |

---

## Environment Notes

- Local dev: PHP 8.2+ required. Tested with XAMPP on Windows, native PHP on WSL/Linux/macOS.
- Config lives in `/config/config.php` (plain PHP array — no `.env`).
- Test DB: `{{PROJECT_SLUG}}_test` (credentials in `tests/fixtures/bootstrap.php` via env vars).
- On first run (no `config.php`), browser hits `/index.php` → redirects to setup wizard at `/setup`.

---

## What appCore Does Not Include (deliberately)

- No framework (not Laravel, not Symfony) — see [ADR-0001](docs/decisions/0001-no-framework.md)
- No ORM — see [ADR-0005](docs/decisions/0005-pdo-no-orm.md)
- No DI container — `Application` is a light service locator
- No queue/worker — cron is the scheduler
- No API layer — controllers can return JSON; build a REST module if needed
- No frontend build — see [ADR-0015](docs/decisions/0015-no-frontend-build.md)
- No multi-tenancy — one DB, one tenant

If you genuinely need one of these, discuss and write an ADR before implementing.
