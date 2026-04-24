# ADR-0015 — No frontend build step (vendored assets)

**Status:** Accepted

## Context
Modern frontends lean on bundlers (Webpack, Vite, esbuild) to transpile TypeScript, compile CSS, hash asset filenames, and ship minified JS. A build step means Node.js on the dev machine, a `package.json`, a CI step, and artefacts that must be regenerated after every change. For an app whose frontend is server-rendered Twig + HTMX + Alpine with minimal custom JS/CSS, that pipeline is pure overhead.

## Decision
No build step. Frontend vendor libraries (Bootstrap, HTMX, Alpine, Bootstrap Icons) live in `/assets/vendor/` as raw files, committed to the repo. Project-specific CSS/JS lives in `/assets/css/app.css` and `/assets/js/app.js` — plain CSS and plain JS, served as-is.

Cache-busting via the `asset()` Twig helper, which appends `?v={filemtime}` to URLs. No hash-in-filename.

## Consequences
- Zero Node.js requirement for dev or deploy.
- Zero CI frontend step.
- Upgrading a vendor library is "replace the file" — deliberate, visible, reviewable.
- Project-level customisation of vendor libs (e.g. Bootstrap SASS variables) is not possible without adding a build. Accept Bootstrap's defaults + override via CSS cascade, or add a build only when genuinely needed.
- Debug experience: the code the browser runs is the code in the editor. No source maps needed. No "why doesn't my change show up?" cache confusion.

## Alternatives considered
- **Vite** — wonderful tool; unnecessary here.
- **Webpack** — heavy, ceremonial.
- **CDN-linked vendor libs** — dependency on external availability; offline dev breaks; supply-chain exposure.
