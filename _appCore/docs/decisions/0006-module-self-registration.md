# ADR-0006 — Module self-registration via `module.php`

**Status:** Accepted

## Context
Features accrete over time. New menus, new routes, new permissions, new cron jobs. A central `routes.php` or `config/nav.php` becomes a merge-conflict magnet and forces cross-cutting edits for every change.

## Decision
Each module in `/app/modules/{Name}/` declares everything it contributes via a single file: `module.php`. It returns an array describing:

- `id`, `name`, `version`, `system` flag
- `routes` — a callable that registers routes with the `Router`
- `nav` — zero or more nav items with group, order, icon, mode, permission
- `permissions` — keys + human descriptions
- `cron` — handler class names

`ModuleRegistry` discovers modules by globbing `/app/modules/*/module.php`, loads each in directory-alphabetical order, and aggregates their contributions.

## Consequences
- Adding a feature is entirely local: create a directory, write a `module.php`, done. No edits to central config.
- Removing a feature is deleting a directory.
- Cross-module coupling is discouraged by default — modules cannot "see" each other without explicit service location.
- Load order is convention (alphabetical). Cross-module ordering dependencies are a smell.
- No hot-reload/disable toggle in core — disabling a module means moving its directory. This keeps the system simple; a full plugin registry is a later feature if ever needed.

## Alternatives considered
- **Central config files** (`routes.php`, `nav.php`) — merge-conflict prone, discourages modularity.
- **Service-provider-style registration** (Laravel-ish) — requires a container and more indirection than the problem warrants.
- **Annotations/attributes on controllers** — magical; hides wiring; slows cold-start unless cached.
