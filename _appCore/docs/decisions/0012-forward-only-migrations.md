# ADR-0012 — Forward-only SQL migrations

**Status:** Accepted

## Context
Many migration frameworks ship "up" and "down" migrations so you can roll back. In practice, rollback scripts are rarely written correctly (data loss scenarios in the down path), rarely tested, and rarely executed in production — the real rollback strategy is "restore from backup" or "write a forward migration that undoes it."

## Decision
Migrations are **forward-only**. Each file is a `.sql` file named `NNNN_description.sql` with a 4-digit zero-padded prefix. Applied files are recorded in a `_migrations` table. There is no down-migration mechanism.

If a migration was wrong, write a new migration that corrects it.

## Consequences
- No false sense of reversibility.
- Less code to write per migration.
- Operators rely on DB backups for true rollback — which they need anyway, regardless of migration framework.
- Developers cannot "rewind to a previous state" in local dev; they drop their DB and re-run from zero. This is fine and fast.
- Failed migrations mid-file leave partial DDL applied (MySQL auto-commits DDL). Convention: one concern per file; if that's not possible, write idempotent `IF NOT EXISTS` clauses where the SQL dialect supports them.

## Alternatives considered
- **Up + down migrations** — too much ceremony for rarely-exercised code.
- **Schema-diff tools** (Doctrine, Phinx's diff) — too much magic; reviewing generated diffs is harder than writing the SQL directly.
