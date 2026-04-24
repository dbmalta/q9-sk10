# ADR-0010 — File-based PHP sessions

**Status:** Accepted

## Context
Sessions can be stored in files, a database, or Redis/Memcached. Shared hosting generally lacks Redis. Database-backed sessions add a table and a read-per-request. Files are the default and fastest in single-server deployments.

## Decision
Use PHP's default file-based session handler, with files stored in `/var/sessions/` (inside the project directory, not the system default) so permissions and cleanup are under the project's control.

Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS. Session timeout enforced by `Session::start()` via `security.session_timeout` in config.

## Consequences
- Zero dependencies, zero extra queries per request.
- Sessions do not survive across multiple application servers — appCore is single-server by default.
- `/var/sessions/` must be outside the document root (it is — `.htaccess` denies `/var/`).
- Cleanup relies on PHP's probabilistic garbage collection (`session.gc_probability`). Operators should ensure cron or system tmp-cleanup eventually reaps abandoned files.

## Migration path
When a project outgrows single-server, swap the session handler to a DB or Redis implementation by replacing `Session::start()` internals. No other code needs to change.

## Alternatives considered
- **DB-backed sessions** — extra query per request; added benefit only materialises under multi-server.
- **Redis** — not available on target hosting.
- **Cookie-only sessions** — size limits, signing complexity, can't invalidate server-side without a blacklist.
