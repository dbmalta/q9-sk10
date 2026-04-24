# ADR-0005 — PDO with hand-written SQL (no ORM)

**Status:** Accepted

## Context
ORMs (Eloquent, Doctrine) save typing on CRUD but hide what the database is doing. For an app with moderate schema complexity, a mix of simple CRUD and genuinely complex queries (closure-table traversals, aggregate reports), the hidden SQL becomes a liability: N+1 queries, silently inefficient joins, migration drift between code and schema.

## Decision
Use PDO directly via a thin `Database` wrapper. SQL lives in service classes, hand-written and parameterised. No ORM. No query builder.

## Consequences
- Every query is visible. Explain-plan-able. Grep-able.
- Complex queries are easy — you just write them.
- Simple CRUD is slightly more typing. The `Database` helper's `insert/update/delete` methods soften this.
- N+1 detection is part of the built-in request profiler; the cost of bad patterns is visible, not hidden.
- Team members need to know SQL. For this project's size and domain, that's a reasonable expectation — not a burden.

## Alternatives considered
- **Eloquent** (Laravel's ORM) — drags Laravel conventions; hides query shape.
- **Doctrine** — too much ceremony (entity managers, metadata, migrations-via-entities).
- **A query builder only** (Aura.SqlQuery, Latitude) — small saving over plain PDO; not worth the dependency.
