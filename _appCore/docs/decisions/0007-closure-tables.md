# ADR-0007 — Closure tables for hierarchy (pattern)

**Status:** Accepted (pattern — not shipped in core)

## Context
Many projects need hierarchical data: org structures, category trees, nested comments, location trees. The classic approaches:

- **Adjacency list** (parent_id) — simple, but "get all descendants" is a recursive CTE or N queries.
- **Nested sets** (lft/rgt) — O(1) subtree queries but moves are O(n) rewrites.
- **Materialised paths** — good for display, messy for structural changes.
- **Closure table** — a separate `(ancestor_id, descendant_id, depth)` table. O(1) subtree queries. Structural changes rewrite O(descendants × ancestors) rows but still bounded.

For apps where tree reads vastly outnumber writes (the typical admin/directory case), closure tables win.

## Decision
When a project needs hierarchy, use a **closure table** pattern:

- One "nodes" table with `parent_id` (adjacency list kept as the source of truth for structural moves).
- One `{nodes}_closure` table with `ancestor_id`, `descendant_id`, `depth`.
- A service method that rebuilds closure rows whenever a node is inserted or moved.

This is **documented as a pattern** in `/docs/patterns/hierarchy-closure-table.md`; it is not shipped in core because not every project needs it.

## Consequences
- Subtree queries: `SELECT descendant_id FROM nodes_closure WHERE ancestor_id = ?` — one query, indexed.
- Depth-limited queries: same, plus `WHERE depth <= N`.
- Moves require a two-step service operation (delete old closure rows, insert new) — must be transactional.
- There is **no** database trigger auto-maintaining the closure table in this pattern. Maintenance is the service's responsibility. This is deliberate — triggers are harder to debug and less portable than application code.

## Alternatives considered
- **Adjacency list only** — insufficient for common queries without recursive CTEs (which some shared-hosting MySQL versions don't support).
- **Nested sets** — move cost unacceptable for interactive org-chart editing.
- **Materialised paths** — string-based queries awkward; breaks on rename if not careful.
