# Open-Core Split — ScoutKeeper Free + Pro

**Status:** planning
**Authored:** 2026-04-23
**Scope:** technical architecture, repo & release mechanics, licensing infrastructure, legal/contribution, commercial/go-to-market
**Not in scope:** which specific pro modules to build first (separate roadmap)

---

## 1. Decisions (locked in brainstorming)

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | **Pattern 2**: public open-source core + private pro modules that drop into the same module loader | Matches existing `ModuleRegistry` architecture; public repo stays a first-class product; no stripped-build pipeline |
| D2 | **Core licence: AGPL-3.0** | Blocks SaaS competitors rebundling the code without publishing changes |
| D3 | **Pro licence: commercial, proprietary** | Private source, per-install licence key |
| D4 | **CLA required** for all external contributions | Retains the right to ship contributed code in proprietary pro builds and to relicence core in the future |
| D5 | **Target customer: national scout associations** (districts, federations, single groups also welcome) | Governance/GSAT/management features anchor the commercial positioning |
| D6 | **Pricing v1: single pro bundle, flat annual fee**; hosting commercial model TBD | Simplicity — licence key encodes only `{domain, expiry}`, no per-module entitlement logic |
| D7 | **Distribution: private Composer repository**; customer adds auth token from purchase portal to `composer.json` | Professional, familiar, supports `composer update` workflow |
| D8 | **Licence enforcement: domain-locked online key**, purchased on a mini-site, validated at install + update time | Strong enough to prevent casual sharing; no ongoing phone-home required at runtime after install |
| D9 | **Pro architecture (A): install-all, activate-by-key** — customer downloads all pro modules; licence key gates which modules boot | Simpler for v1; can migrate to key-gated download later |
| D10 | **Compatibility: Composer semver range** — pro declares `"scoutkeeper/core": "^X.Y"` | Forces treating core extension points as a real public API |
| D11 | **1.0 = public launch milestone** | Commits the extension-point API at exactly the moment pro starts depending on it |
| D12 | **Security backport policy:** current + previous minor, 12 months after the minor bumps | Predictable for customers without infinite backport liability |
| D13 | **Core stays single-tenant**; federation/multi-group becomes a pro module later | Avoids multi-tenant retrofit of every query |
| D14 | **Self-host primary**; hosted-alongside option deferred | Hosting ops tooling will live in a separate private repo, not loaded into the AGPL process |

---

## 2. Feature split

### 2.1 Core (AGPL-3.0, public)

Everything currently shipping:
- Auth (local accounts, password reset, MFA)
- Permissions & roles
- OrgStructure (hierarchy, teams, closure-table tree)
- Members (CRUD, custom fields, timeline, attachments, self-registration, waiting list, bulk import)
- Events (calendar, iCal feed)
- Achievements & training
- Directory / organogram
- Communications (articles, SMTP email compose + queue)
- Admin (settings, audit log, manual backup/export, language management, T&Cs, notices, basic reports, logs, monitoring)

**Principle:** core is a complete, usable product for a single scout group.

### 2.2 Pro (commercial, private) — all greenfield, none exist yet

Initial candidate list (final order TBD in a separate roadmap doc):

**Governance & compliance bundle** (anchor)
- GSAT workflows (self-assessment, evidence, action tracking)
- Policy & T&C versioning with acknowledgement tracking
- Safeguarding-case management
- Risk register
- Advanced audit retention + tamper-evident log

**Integration bundle**
- SSO (SAML, OIDC, Google Workspace, Microsoft Entra)
- REST API + outbound webhooks
- Zapier/Make-style integration surface

**Operations bundle**
- Custom report builder + scheduled reports
- Cloud backup (S3, Backblaze, Dropbox) with automated restore testing
- SMS provider adapters (Twilio, MessageBird) + templates
- White-labelling (branding, custom domain, removed attribution)

**Scale bundle**
- **Federation / multi-group** — one install managing many scoped groups with federated admin
- Advanced bulk operations
- Performance tier (query caching, read replicas)

Pro ships as **one bundle** for v1 (D6). Internal split above is for engineering organisation only.

---

## 3. Technical architecture

### 3.1 Core principle

**Pro modules are regular ScoutKeeper modules.** They use the same `module.php` shape, the same `ModuleRegistry`, the same `Controller` base class, the same Twig loader, the same `Database`, the same `AuditService`. There is no parallel API surface.

What pro needs that core doesn't already provide: **a small set of extension points** that let pro modify core behaviour without forking core code.

### 3.2 Current module system — what exists

`app/src/Core/ModuleRegistry.php` already supports:
- Directory scan for `module.php` files ([ModuleRegistry.php:43](app/src/Core/ModuleRegistry.php:43))
- Route registration via `routes` callable ([ModuleRegistry.php:68](app/src/Core/ModuleRegistry.php:68))
- Nav registration with groups, ordering, modes, permission gating
- Permission definitions ([ModuleRegistry.php:151](app/src/Core/ModuleRegistry.php:151))
- Cron handlers ([ModuleRegistry.php:167](app/src/Core/ModuleRegistry.php:167))

This is already ~70% of what pro needs. The remaining extension points must be added to core **before** any pro module is built, because they are the public API contract.

### 3.3 Extension points to add to core before 1.0

| # | Extension point | Why pro needs it | Design sketch |
|---|-----------------|------------------|---------------|
| E1 | **Multi-path module loading** | Pro modules installed via Composer live in `vendor/scoutkeeper/pro-*/modules/`, not `/app/modules/` | `ModuleRegistry::loadModules()` accepts an array of paths; bootstrap iterates `[app/modules, vendor/scoutkeeper/*/modules]` |
| E2 | **Event/hook bus** | Pro modules react to core lifecycle events (member.created, event.attendance_recorded, audit.logged) without core knowing pro exists | Symfony EventDispatcher or a slim in-house equivalent; core emits named events; listeners register via `module.php` |
| E3 | **Template override / fallback loader** | Pro white-label module replaces `_footer.twig`; federation module replaces admin dashboard widget area | Twig namespaced loader with priority; pro module registers a template path that shadows core templates by name |
| E4 | **Settings page injection** | Pro modules add tabs/panels to Admin → Settings without editing core settings controller | Settings page reads a registry of settings sections; modules register sections via `module.php` |
| E5 | **Migration namespacing** | Pro modules ship their own SQL migrations without colliding with core's numbered file scheme | Migration runner discovers migrations per module, tracks them in `migrations` table with a `source` column (core/pro-module-id) |
| E6 | **Service container bindings** | Pro modules add/replace services (e.g. alternative EmailSender, additional BackupDriver) | DI container (already in bootstrap) exposes a `register()` hook per module |
| E7 | **Permission-resolver extension** | Pro governance modules add new permission kinds (e.g. GSAT reviewer scope) | Permission resolver reads resolver strategies from modules, not a hardcoded list |
| E8 | **Dashboard widgets** | Pro modules surface content on the admin dashboard | Dashboard controller renders widgets from a registry; modules contribute widget definitions |
| E9 | **Licence gate helper** | Pro modules themselves must check licence status before booting | `App\Core\Licence::isActive('module-id'): bool` — returns true in core (always) and in pro (per key state) |
| E10 | **API surface primitive** | If/when API/webhooks module ships, it needs a way to discover exposed endpoints | Controllers declare `#[ApiExposed]` attribute or module.php lists api routes; resolved by pro API module |

**These ten extension points are the public API that 1.0 locks in.** Semver discipline applies to them from 1.0 onwards — breaking changes require a major bump.

### 3.4 Licence verification (E9 in detail)

**Flow:**
1. Customer purchases on licence mini-site → receives key + domain binding
2. Customer adds key to `config/licence.php` (or via admin UI)
3. On first use and then every 24h, `LicenceService::refresh()` calls `https://licence.scoutkeeper.org/v1/validate` with `{key, domain, install_id}`
4. Server returns a **signed licence payload** (Ed25519) with `{key, domain, expires_at, entitlements[], issued_at, nonce}`
5. Payload cached in `licence_cache` table; **pro modules boot only if a valid non-expired cached payload exists**
6. Grace period: if the licence server is unreachable but the cached payload is not yet expired, pro keeps running. If expired + unreachable, pro enters a **14-day hard grace** (warnings shown, still functional), then disables.
7. Composer access: the private Composer repo itself authenticates with the same key (HTTP basic auth via purchase portal token), so revoked customers cannot download updates.

**Dev/staging exemptions** (hardcoded in `LicenceService`):
- `localhost`, `127.0.0.1`, `::1`
- `*.test`, `*.local`, `*.localhost`
- One named staging domain declared in `licence.php` (e.g. `staging_domain => 'staging.example.org'`) — server validates this is at most one per key

**Domain transfer:** self-service on mini-site, cooldown 90 days, unlimited during first 14 days after purchase (to accommodate setup mistakes).

**Signing keys:** mini-site holds private key in HSM or at least in an isolated env; public key baked into core AND pro modules. Never rotate without a major version bump of core.

### 3.5 Pro module anatomy (illustrative)

```
vendor/scoutkeeper/pro-governance/
├── composer.json                    # requires scoutkeeper/core: ^1.0
├── LICENCE.md                       # commercial licence text
├── modules/
│   └── Governance/
│       ├── module.php               # id, nav, routes, permissions, licence_required: true
│       ├── Controllers/
│       ├── Services/
│       ├── templates/
│       └── migrations/              # scoped SQL migrations
└── src/                             # any cross-module classes
```

`module.php` boot check:
```php
if (!App\Core\Licence::isActive()) {
    return []; // returning empty definition = module not loaded
}
return ['id' => 'governance', 'name' => 'Governance', ...];
```

---

## 4. Repo & release mechanics

### 4.1 Repositories

| Repo | Visibility | Contents | Licence |
|------|-----------|----------|---------|
| `scoutkeeper/scoutkeeper` | **public** | Core application | AGPL-3.0 |
| `scoutkeeper/pro` | private | All pro modules (monorepo internally, may split later) | Commercial |
| `scoutkeeper/licence-site` | private | Mini purchase site + licence server | Internal |
| `scoutkeeper/hosting-ops` | private | Terraform, Ansible, provisioning, billing integration (only if/when hosting is offered) | Internal |
| `scoutkeeper/docs` | public | Public-facing docs site | CC-BY-SA |

Developer workflow:
- Work on core → PR into public repo
- Work on pro → PR into private repo; local dev composer-links pro into a core checkout via `composer config repositories.pro path ../pro`

### 4.2 Branching

- Core: `main` is always releasable; feature branches; tagged releases (`v1.0.0`, `v1.1.0`); `1.x` long-lived maintenance branch after 2.0 cuts
- Pro: mirrors core branching model; pro tags track the core tag they target (e.g. pro `v1.2.0` declares `"scoutkeeper/core": "^1.2"`)

### 4.3 CI/CD

**Core (public GitHub Actions):**
- PHPUnit on every PR (matrix: PHP 8.2, 8.3)
- Playwright E2E on a seeded DB
- PHPStan + PHP-CS-Fixer
- Release workflow: tag → auto-publishes to public Packagist as `scoutkeeper/core`

**Pro (private CI):**
- Same test suite, plus
- Integration tests that compose core + pro together
- Release workflow: tag → publishes to **private Composer repo** (Packagist.com, or self-hosted Satis/Packeton)
- Licence server integration test (staging licence + staging pro build)

### 4.4 Release process

1. Core releases first. Tag `vX.Y.Z` on core.
2. Pro integration tests re-run against the new core tag.
3. If pro has changes needing the new core, cut pro `vX.Y.Z` within 7 days.
4. If pro has no changes, skip — pro's existing `^X.Y` range picks up the core patch automatically.
5. Security patches: cut `vX.Y.(Z+1)` on current minor and previous minor simultaneously if within 12-month support window (D12).

### 4.5 Versioning rules

- Core and pro both follow **SemVer strictly from 1.0 onwards**.
- The ten extension points in §3.3 are the **public API** for SemVer purposes.
- Breaking change to any extension point = major bump + migration guide.
- Adding a new extension point = minor bump.
- Bug fix not affecting the extension points = patch bump.
- Core may refactor internal services (Database, AuditService internals) on minor bumps **only if they don't break the documented extension point contracts**.

---

## 5. Licensing infrastructure (the mini-site)

### 5.1 Functional scope (v1)

- Stripe checkout for annual pro subscription
- Account dashboard: view keys, download licence file, view invoices
- Key management: bind to domain at purchase, self-service domain transfer (cooldown 90d)
- Composer auth token issuance (one per key, revokable)
- Admin console for you: view all customers, issue manual/free keys (non-profits, internal), revoke, extend

### 5.2 Technical stack recommendation

Keep it boring and fast:
- Same PHP stack as ScoutKeeper (reuse your skills)
- Stripe SDK
- Separate DB from ScoutKeeper installs
- Hosted on the same infrastructure you'd run hosted ScoutKeeper on (reuse ops)

### 5.3 Endpoints

```
POST /v1/validate          {key, domain, install_id}          → signed payload
POST /v1/transfer-domain   {key, new_domain}                   → success/cooldown error
GET  /v1/composer/...      (basic auth with key)               → Composer metadata + package zips
```

### 5.4 Licence mini-site is NOT bundled with ScoutKeeper

It's a separate product. Its outage must not break running customer installs — that's what the 24h cache + 14-day grace (§3.4) is for.

---

## 6. Legal & contribution

### 6.1 Pre-publication checklist

Before first public commit, ensure:
- [ ] `LICENCE` file with AGPL-3.0 text at repo root
- [ ] Every PHP file has a short AGPL header comment (tooling: `licensure` or a custom script)
- [ ] `CONTRIBUTING.md` explaining the CLA requirement, dev setup, PR flow
- [ ] `CODE_OF_CONDUCT.md` (Contributor Covenant is fine)
- [ ] `SECURITY.md` with responsible disclosure contact
- [ ] `README.md` rewritten for public audience
- [ ] `.github/ISSUE_TEMPLATE/` and `PULL_REQUEST_TEMPLATE.md`
- [ ] Scrub git history for any secrets, internal URLs, customer-specific branding
- [ ] Remove or gitignore anything in `_appCore/`, `bugfile.txt`, personal test artefacts currently untracked
- [ ] Confirm no third-party code is bundled without compatible licence

### 6.2 CLA mechanics

Use **CLA Assistant** (https://cla-assistant.io) — GitHub bot, free, standard in the ecosystem.
- CLA text: assigns a non-exclusive, irrevocable, worldwide licence to ScoutKeeper/QuadNine to use the contribution under **any licence including proprietary**. Contributor retains copyright.
- Fall-back DCO (Developer Certificate of Origin) is weaker but contributor-friendlier. **Stick with a full CLA** since the whole point is mixing contributions into proprietary pro.

### 6.3 Existing authorship

D14 confirmed no existing external contributors. You own all current code. No relicensing consents needed before publication.

### 6.4 Pro commercial licence text

Draft in `docs/legal/` (not yet public). Must cover:
- Single-install, single-domain scope
- No redistribution
- Source available to licensee but not redistributable
- Warranty disclaimer, liability cap
- Termination conditions (non-payment, breach)
- Governing law (Malta, given QuadNine base)

**Recommend: get a lawyer to review before first sale.** Off-the-shelf SaaS terms + proprietary-software-licence templates are a starting point, not a finish line.

### 6.5 Trademark

"ScoutKeeper" as a brand name is distinct from the code. **Register the trademark** (at minimum in Malta + EU) before the name gains value in the community. AGPL gives away the code, not the name — you can and should enforce trademark separately to prevent confusingly named forks.

---

## 7. Commercial / go-to-market

### 7.1 Pricing structure (v1 proposal)

| Tier | Audience | Price (illustrative) | Includes |
|------|----------|---------------------|----------|
| Community | Single groups, hobbyists | Free | Core (AGPL), community support |
| Pro | Districts, national assocs, commercial users | €X/year per install, single bundle | All pro modules, domain-bound licence, email support, update access |
| Hosted Pro | (Deferred) | €Y/year base + per-member tier | Pro + you host + manage infra + backups + SLA |

Pricing number ranges are out of scope for this plan. Model is clear; dial in during launch prep.

### 7.2 Public website

Three sections minimum:
1. **Marketing / product** (feature highlights, screenshots, who it's for)
2. **Docs** (installation, configuration, modules, API) — public, feeds both core and pro users
3. **Pricing + buy** (links into licence mini-site)

Domain layout recommendation: `scoutkeeper.org` (marketing), `docs.scoutkeeper.org` (docs), `licence.scoutkeeper.org` (purchase + key server), `github.com/scoutkeeper/scoutkeeper` (code).

### 7.3 Support model

- **Community support:** GitHub Discussions + Issues for core. No SLA.
- **Pro support:** email with response-time SLA based on tier. Track in a light ticketing tool — don't build one.
- **Security disclosures:** `security@scoutkeeper.org`, PGP key published, responsible-disclosure policy in `SECURITY.md`.

### 7.4 Docs split

- **Public docs** cover core + document the pro module APIs/interfaces so pro customers can integrate
- **Pro-only docs** (behind customer login) cover pro module configuration, governance workflows, SSO setup specifics
- Both built from the same docs site with auth gating on pro pages

---

## 8. Phased implementation sequence

Rough ordering — sizes are t-shirts, not estimates.

### Phase 1 — Extension point foundation (before public launch) [L]
- Implement E1–E10 (§3.3) in core
- Write public API documentation for each
- Add contract tests that pin the API shape
- Exit criteria: a throwaway "hello-world" pro module can be built against the extension points without modifying core

### Phase 2 — Public launch prep (core 1.0) [M]
- Pre-publication checklist (§6.1)
- Trademark filing
- CLA Assistant configured
- Public repo published
- Packagist publication of `scoutkeeper/core`
- Tag `v1.0.0`

### Phase 3 — Licensing infrastructure [M]
- Mini-site + Stripe + licence server
- Private Composer repo (Packagist.com or Satis)
- End-to-end integration test: purchase → key → composer install → domain bind → pro module boots

### Phase 4 — First pro module [M–L]
- Build the **Governance bundle** first (anchors the commercial positioning)
- Exercises the extension points — if any are inadequate, fix before other pro modules pile on
- Internal alpha with a friendly customer

### Phase 5 — Launch + iterate [ongoing]
- First paying customer
- Remaining pro bundles built in priority order (separate roadmap)
- Gather real-world signal on extension-point gaps; plan any toward 2.0

---

## 9. Open questions / decisions deferred

| # | Question | Blocks |
|---|----------|--------|
| O1 | Hosting commercial model — do you offer hosted ScoutKeeper? When? | Phase 4/5 planning |
| O2 | Pricing numbers (actual €/year for pro) | Launch |
| O3 | Which pro bundle to build second | Roadmap doc, not this plan |
| O4 | Whether to split pro into per-bundle licences later | v2 of licence server |
| O5 | Whether to eventually offer a **Community Edition Plus** tier (e.g. small per-install fee, subset of pro) for small groups priced out of pro | Post-launch market signal |
| O6 | Governance of the public repo once there are external contributors — maintainer model, decision-making, release cadence | When first substantive community PR lands |

---

## 10. Risks & how we mitigate

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Core extension points prove inadequate when building pro, forcing core breaking changes | Medium | High | Build a throwaway pro module (Phase 1 exit criteria) before committing 1.0 |
| Licence server outage disables all pro customers | Low | High | 24h cache + 14-day grace period (§3.4) |
| A competitor forks core and sells a hosted version | Medium | Medium | AGPL triggers source-publishing obligation; trademark prevents naming confusion |
| Community contributor refuses CLA, blocking a good PR | Low | Low | Polite refusal; maintain the hard rule — occasional lost PRs are worth the strategic flexibility |
| Pro customer shares code with non-paying orgs | Medium | Low–Medium | Domain-locking + Composer auth; honour-system for the rest; commercial licence allows termination |
| Core commits accidentally leak pro-oriented abstractions | Medium | Medium | Code review discipline; contract tests on extension points; "does this exist because of pro?" question in PR template |
| Relicensing core later becomes necessary | Low | High | CLA already assigns rights to ship under any licence — you're covered |

---

## 11. Appendix — what this plan does NOT decide

- Which specific pro modules ship in which order (separate roadmap)
- Exact pricing
- Whether hosted ScoutKeeper is offered and on what model
- Which jurisdiction for terms of service
- Marketing messaging and positioning copy
- Community governance model beyond "you decide for now"

Each of these deserves its own document when it becomes the binding constraint on progress.
