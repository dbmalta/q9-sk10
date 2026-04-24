# Architecture Decision Records

Each ADR captures one load-bearing decision: why it was made, what it cost, what alternatives were considered. ADRs are immutable once accepted — to change a decision, write a new ADR that supersedes the old one (do not edit the original).

## Index

| # | Title | Status |
|---|---|---|
| [0001](0001-no-framework.md) | No framework (plain PHP) | Accepted |
| [0002](0002-plain-php-config.md) | Plain PHP config file over `.env` | Accepted |
| [0003](0003-fastroute.md) | FastRoute for HTTP routing | Accepted |
| [0004](0004-twig-htmx-alpine.md) | Twig + HTMX + Alpine (no SPA) | Accepted |
| [0005](0005-pdo-no-orm.md) | PDO with hand-written SQL (no ORM) | Accepted |
| [0006](0006-module-self-registration.md) | Module self-registration via `module.php` | Accepted |
| [0007](0007-closure-tables.md) | Closure tables for hierarchy (pattern) | Accepted |
| [0008](0008-json-permissions.md) | JSON permissions blob on roles | Accepted |
| [0009](0009-aes-gcm-encryption.md) | AES-256-GCM for at-rest encryption | Accepted |
| [0010](0010-file-sessions.md) | File-based PHP sessions | Accepted |
| [0011](0011-bcrypt-passwords.md) | Bcrypt for password hashing | Accepted |
| [0012](0012-forward-only-migrations.md) | Forward-only SQL migrations | Accepted |
| [0013](0013-controller-service-db.md) | Controller → Service → Database (no repositories, no models) | Accepted |
| [0014](0014-browser-setup-wizard.md) | In-browser setup wizard (no CLI required) | Accepted |
| [0015](0015-no-frontend-build.md) | No frontend build step (vendored assets) | Accepted |

## Template

When adding a new ADR, copy `_template.md` and fill it in.
