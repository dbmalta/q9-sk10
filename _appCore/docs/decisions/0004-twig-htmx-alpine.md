# ADR-0004 — Twig + HTMX + Alpine (no SPA)

**Status:** Accepted

## Context
A membership/admin app needs forms, tables, modals, and some interactive sprinkles (drag-and-drop, tabs, live search). An SPA (React/Vue) is a full second codebase: a build pipeline, a state layer, a separate deployment model, and API contracts to maintain. For the size of app appCore targets, that's overkill.

## Decision
- **Twig** for server-side HTML.
- **HTMX** for partial updates (forms that return fragments, lazy-loaded tabs, search results).
- **Alpine.js** for tiny client-side state (dropdowns, toggles, confirm modals).

Controllers render Twig templates; HTMX requests may render fragment templates instead of full pages. Alpine handles local UI state that doesn't need to round-trip.

## Consequences
- No build step. No bundler. No Node runtime required to ship.
- Every page is still a URL; browser back/forward, bookmarking, and no-JS fallback all work.
- Skills required: HTML, CSS, Twig. No React/Vue learning curve.
- Complex interactions (rich editors, real-time collaboration) become awkward. For those, drop a self-contained JS component in — don't rewrite the shell.
- HTMX's convention of "one endpoint returns HTML or HTML fragment depending on request" is a trade: simpler than JSON APIs, but your endpoints aren't reusable for non-browser clients. Add a parallel JSON route if/when you need one.

## Alternatives considered
- **Livewire-style** (server-rendered reactive components) — would need a different backend; overkill.
- **React SPA + JSON API** — doubles surface area, triples deployment complexity.
- **Plain server-rendered forms, no HTMX** — rejected; forces full page reloads for every interaction, feels dated.
