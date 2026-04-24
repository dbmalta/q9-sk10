# ADR-0001 — No framework (plain PHP)

**Status:** Accepted

## Context
appCore targets self-hosted deployments on shared Linux hosting. The typical user is a small org that can extract a zip, edit a config file, and run a setup wizard — but will never touch `composer install` or a CLI. Laravel/Symfony assume CLI tooling, artisan/console, queue workers, and a host of environmental conventions that add setup friction and ongoing operational load.

## Decision
appCore is built on plain PHP 8.2. It pulls in small, focused libraries (FastRoute, Twig, PHPMailer, Google2FA) but no framework. The application lifecycle (bootstrap → router → controller → response) is custom code, readable end-to-end in ~200 lines.

## Consequences
- Install is "upload + run wizard." No `composer install` required if the zip ships `/vendor/`.
- The whole codebase fits in one developer's head. Any new engineer can trace a request in an afternoon.
- We have to build infrastructure that a framework would give us: routing integration, DI (light), migrations, error handling, session management. That work is done once and documented.
- We lose ecosystem leverage — no Laravel Nova, no Symfony bundles. Each feature is built from primitives.

## Alternatives considered
- **Laravel** — too heavy for shared-hosting targets; assumes CLI.
- **Symfony** — too ceremonial; steep learning curve for a small codebase.
- **Slim / Lumen** — lighter but still a framework; still drags conventions we don't need.
