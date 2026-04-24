# ADR-0014 — In-browser setup wizard (no CLI required)

**Status:** Accepted

## Context
Most PHP frameworks install via CLI: `composer install`, `php artisan migrate`, editor `.env`. The target user for appCore is a small-org admin who can upload a zip via cPanel but has never opened a terminal. Every CLI step is a failure point.

## Decision
On first request, if `/config/config.php` is missing, `index.php` hands control to `SetupWizard`. The wizard is a self-contained PHP class with plain-PHP templates (no Twig dependency — Twig is not bootstrapped yet). It walks the operator through eight steps, ending with a written `config.php`, a generated `encryption.key`, applied migrations, and seeded data.

The wizard performs:
1. Prerequisite checks (PHP version, extensions, writable dirs).
2. DB connection + database creation if missing.
3. Install-type selection (blank / clean / demo).
4. Project-specific seed input (project name, first admin, etc.).
5. Admin account creation.
6. SMTP config.
7. Encryption key generation.
8. Write config, run migrations, apply seeds.

## Consequences
- Zero-CLI install. Upload zip, browse to `/`, follow prompts.
- The wizard is its own codebase — it cannot use modules, Twig, or Core's full bootstrap. Templates are plain PHP.
- Re-running the wizard is gated: if `config.php` already exists and the operator hits `/setup`, they get a 403. To re-install, delete the config file manually.
- Operators who prefer CLI can still work: drop a `config.php` in place, run `php tools/migrate.php`, skip the wizard. The wizard and CLI paths produce identical state.

## Alternatives considered
- **CLI-only install** — unacceptable for the target audience.
- **Docker-based install** — wrong deployment model for shared hosting.
- **Post-install wizard that requires Twig** — chicken-and-egg if Twig install itself fails a prerequisite.
