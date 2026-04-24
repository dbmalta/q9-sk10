# ADR-0013 — Controller → Service → Database (no repositories, no models)

**Status:** Accepted

## Context
Common PHP architectures layer several abstractions: Controller → Service → Repository → ORM model → Database. Each layer has a defensible purpose, but each also adds indirection. For an app without an ORM and with SQL queries that are often bespoke (reports, closure-table traversals), the Repository and Model layers add ceremony without carrying their weight.

## Decision
Three layers:

1. **Controller** — request parsing, permission checks, response building.
2. **Service** — business logic + direct PDO usage via the `Database` wrapper. Services are plain classes with typed methods. No base class, no inheritance.
3. **Database** — the PDO wrapper itself.

No Repository interfaces, no Active Record models, no DTOs beyond plain associative arrays (or typed value objects when genuinely useful — e.g. a `User` readonly class around the session user).

## Consequences
- One file to read to understand a feature's data access.
- No "which implementation of this repository am I hitting?" indirection.
- Testing is integration-style (real DB via test fixtures). Unit-testing services in isolation requires a DB, not a mock — we lean into that rather than fight it.
- When a project genuinely benefits from a Repository boundary (e.g. a service needs to swap persistence for caching), add it locally — don't retrofit the pattern across the codebase.

## Alternatives considered
- **Controller → Repository → Model → DB** (classic) — too many layers for the value delivered.
- **Action classes** (one class per endpoint) — makes cross-action reuse awkward; leads to extracting services anyway.
- **Transaction Script** (SQL in controllers) — rejected; controllers stay thin.
