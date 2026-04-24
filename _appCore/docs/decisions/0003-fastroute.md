# ADR-0003 — FastRoute for HTTP routing

**Status:** Accepted

## Context
We need routing with path parameters, method matching, and named routes. Options range from building a regex matcher by hand to pulling in Symfony's router.

## Decision
Use `nikic/fast-route`. It's a single-purpose library (~1 file) with no dependencies, and it's the routing layer Slim and many others use under the hood.

## Consequences
- Route compilation is fast; dispatch is cached per-request.
- We write a thin `Router` wrapper that adds named-route URL generation (which FastRoute does not provide natively) and integrates with the `ModuleRegistry` lifecycle.
- When the inevitable "I need per-route middleware" request arrives, we can add it in the wrapper without changing FastRoute itself.

## Alternatives considered
- **Symfony Router** — too many dependencies; ceremonial.
- **Hand-rolled regex matcher** — rejected; not worth the bug surface for a few bytes saved.
- **Router with built-in middleware (Slim)** — we'd be using 5% of the library.
