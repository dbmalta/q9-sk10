# ScoutKeeper — Developer Guide

## Overview

ScoutKeeper is a PHP/MySQL membership management system for Scout organisations. Plain PHP (no framework), self-hostable on Linux shared hosting, no CLI required.

## Directory Layout

```
/index.php           ← Bootstrap; redirects to setup wizard if no config
/.htaccess           ← Apache rewrites + security headers
/app/
  /bootstrap.php     ← Autoloader, config loading, DI wiring
  /src/Core/         ← Framework: Database, Router, Session, I18n, Encryption, etc.
  /src/Setup/        ← Setup wizard (standalone PHP templates)
  /modules/          ← Feature modules (see below)
  /migrations/       ← Numbered SQL migration files (0001_*.sql)
  /templates/        ← Shared Twig templates (layouts, components, errors)
/assets/
  /css/app.css       ← Custom styles
  /js/app.js         ← Custom JS (Alpine.js components, HTMX config)
  /vendor/           ← Vendored frontend libs (Bootstrap 5.3, Alpine.js 3, HTMX 2, Bootstrap Icons)
/config/             ← config.php (not committed), config.example.php, encryption.key
/cron/run.php        ← Cron dispatcher entry point
/data/               ← Uploads and backups (gitignored contents)
/lang/en.json        ← English language strings
/updater/            ← Auto-update subsystem (standalone)
/tests/              ← PHPUnit tests + Playwright E2E + Seeders
/vendor/             ← Composer dependencies (gitignored)
```

## Module Structure

Each module lives in `/app/modules/{ModuleName}/` with:

```
module.php              ← Routes, nav registration, permissions declaration
Controllers/*.php       ← Request handlers extending App\Core\Controller
Services/*.php          ← Business logic (DB queries, validation)
templates/**/*.twig     ← Twig templates for this module
```

Modules self-register via `module.php` which is loaded by `ModuleRegistry`. A module registers:
- **Routes**: FastRoute definitions
- **Navigation**: sidebar items with group, label, icon, sort order
- **Permissions**: module-level read/write capabilities

### Current Modules

| Module | Path | Purpose |
|--------|------|---------|
| Auth | `app/modules/Auth` | Login, logout, password reset, MFA |
| Permissions | `app/modules/Permissions` | Role CRUD, assignment management |
| OrgStructure | `app/modules/OrgStructure` | Hierarchy nodes, teams, level types |
| Members | `app/modules/Members` | Member CRUD, custom fields, timeline, attachments, registration, bulk import, waiting list |
| Communications | `app/modules/Communications` | Articles, email compose/queue, cron |
| Events | `app/modules/Events` | Calendar, event CRUD, iCal feed |
| Achievements | `app/modules/Achievements` | Achievement/training definitions and awards |
| Directory | `app/modules/Directory` | Organogram, contact directory |
| Admin | `app/modules/Admin` | Dashboard, settings, audit, backup, export, logs, T&Cs, notices, reports, language management, monitoring |

## Database

- PDO wrapper: `App\Core\Database` — all queries use prepared statements
- Migrations: numbered SQL files in `/app/migrations/`, run sequentially
- Key tables: `members`, `users`, `org_nodes`, `org_closure`, `org_teams`, `roles`, `role_assignments`, `role_assignment_scopes`, `events`, `articles`, `achievement_definitions`, `member_achievements`, `audit_log`, `settings`
- Closure table (`org_closure`) for efficient org tree queries — maintained by `OrgService`
- Custom field values stored as JSON in `members.member_custom_data`
- Timeline entries in `member_timeline` table

## Template Conventions

- Twig 3 with auto-escaping enabled
- Layouts: `base.html.twig` → `admin.html.twig` (sidebar+topbar) / `member.html.twig` (topbar only) / `auth.html.twig` (minimal)
- Components: `_alert`, `_breadcrumbs`, `_confirm_modal`, `_empty_state`, `_loading_spinner`, `_pagination`
- HTMX for partial page updates (lazy-loaded tabs, search results)
- Alpine.js for interactive sprinkles (dropdowns, toggles, drag-and-drop)
- Bootstrap 5.3 with `data-bs-theme` for dark/light mode

## Testing

### PHPUnit
```bash
/c/xampp/php/php.exe vendor/bin/phpunit
```
Tests in `/tests/Core/`, `/tests/Modules/`, `/tests/SetupWizard/`, `/tests/Updater/`.

### Playwright E2E
```bash
cd tests/e2e && npm install && npx playwright test
```
Specs in `/tests/e2e/specs/`. Requires seeded database (run `php tests/seed.php` first).

### Seeder
```bash
/c/xampp/php/php.exe tests/seed.php           # standard (~155 members)
/c/xampp/php/php.exe tests/seed.php --large   # performance (~5000 members)
```

## Common Commands

```bash
# Run PHPUnit tests
/c/xampp/php/php.exe vendor/bin/phpunit

# Run a specific test
/c/xampp/php/php.exe vendor/bin/phpunit tests/Core/DatabaseTest.php

# Seed the database
/c/xampp/php/php.exe tests/seed.php

# Start PHP dev server
/c/xampp/php/php.exe -S localhost:8080

# Composer install (dev)
/c/xampp/php/php.exe /c/xampp/php/composer.phar install
```

## Key Conventions

- **PSR-12** coding standard
- **No inline SQL** in controllers — all queries go through Service classes
- **Permissions are explicit** — no implicit grants from hierarchy position
- **i18n keys** for all user-facing strings in `lang/en.json`
- **CSRF tokens** on all state-changing forms (`validateCsrf()` in Controller base class)
- **Medical data** encrypted at rest (AES-256-GCM via `Encryption` class)
- **Audit logging** on all record changes via `AuditService`

## Environment Notes

- PHP path on this machine: `/c/xampp/php/php.exe`
- Test DB: `scoutkeeper_test` (user: `sk_test`, pass: `sk_test_pass`)
- OneDrive interference: some filenames get locked; use temp directory as workaround if needed
