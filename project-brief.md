# ScoutKeeper — Project Brief

**Version:** 1.0 (Planning)
**Date:** April 2026
**Owner:** QuadNine Ltd (https://quadnine.mt)
**Repository:** https://github.com/dbmalta/q9-sk10

---

## 1. Project Overview

ScoutKeeper is a free, open-source, web-based membership management system designed for Scout organisations of any size. It replaces paper-based and spreadsheet-based membership processes with a structured, accessible, and configurable platform aligned with WOSM (World Organization of the Scout Movement) best practices.

Previous versions of ScoutKeeper have been used by Scout organisations over several years. Version 10 (SK10) is a full rebuild — designed from scratch for modern hosting environments, community contribution, and the needs of the global Scout movement.

---

## 2. Problem Statement

Many Scout organisations — particularly at group, district, and national level — manage their membership using paper records or spreadsheets. This creates:

- No reliable audit trail of member history
- Difficulty managing multi-level organisational structures
- No standardised way to track achievements, training, or events
- No communication infrastructure tied to membership data
- No financial visibility across organisational levels

ScoutKeeper addresses all of these without requiring technical expertise or ongoing software costs.

---

## 3. Target Audience

- Scout organisations of any size: single group, district, regional body, or national organisation
- Organisations that currently use paper or Excel for membership management
- Organisations with minimal technical infrastructure and no dedicated IT staff
- WOSM-member organisations globally

The system is intentionally accessible: installation requires no command-line knowledge, and day-to-day administration is designed for non-technical users.

---

## 4. Guiding Principles

- **Free and open** — no fees, no per-member or per-section charges; fully open-source
- **Simple to install and run** — unzip, upload, run the wizard; designed for non-technical administrators
- **Community-driven** — well-commented code, open contribution model, shared language packs
- **Built to last** — unit tested from the start; auto-updates from the public GitHub repository
- **Globally usable** — multi-language, GDPR-toggleable, no geography-specific assumptions

---

## 5. Tech Stack

| Component | Decision |
|-----------|----------|
| Language | PHP 8.2+ |
| Database | MySQL 8.0+ or MariaDB 10.6+ |
| Hosting | Linux shared hosting (e.g. SiteGround, cPanel-based hosts) |
| Updates | Auto-update mechanism from public GitHub repo |
| Testing | PHPUnit (unit/integration) + Playwright (end-to-end scenarios) |
| Languages | JSON files per language in the repo + per-installation DB overrides |

**Constraints:**
- No CLI required for installation or updates
- No Docker, no cloud infrastructure dependencies
- All architecture decisions must respect shared hosting limitations

**Dependency management:**
- Composer is used in development only; never required at install or update time
- CI (GitHub Actions) runs `composer install --no-dev` and packages the result into the release zip; `vendor/` is never committed to the source repo
- Runtime dependencies are kept to a deliberate minimum — only libraries that cover security-sensitive or well-established functionality that should not be reimplemented:
  - `phpmailer/phpmailer` — SMTP email
  - `pragmarx/google2fa` — TOTP MFA with QR code setup (Google Authenticator, Authy, etc.)
  - `nikic/fast-route` — URL routing
  - `phpunit/phpunit` — dev/test only, not shipped
- Dependency list will be reviewed and trimmed alongside the v1 feature scope

---

## 6. Installation & Updates

**Target hosting environment:** Linux shared hosting (e.g. SiteGround, cPanel-based hosts). Windows hosting is not a target and need not be accommodated.

**Directory layout:**
```
/public_html/
├── index.php        ← minimal bootstrap (~20 lines); never auto-updated
├── .htaccess        ← never auto-updated
├── /app/            ← main application; replaced wholesale by the updater
├── /updater/        ← the updater subsystem; replaced wholesale by the main app
├── /config/         ← config.php, encryption keys; never touched by updates
├── /data/           ← uploads, backups; never touched by updates
└── /var/            ← cache, locks, logs, update staging; never touched by updates
```

**Installation:**
- User downloads the latest release zip from GitHub releases
- Uploads and unzips to their web server
- Runs the setup wizard via browser
- Setup wizard includes a pre-flight check: verifies write permissions on `/app/`, `/updater/`, and `/var/`; refuses to proceed if permissions are wrong
- Setup wizard minimum: organisation name, admin account, basic org structure, SMTP configuration
- No command-line access required at any stage

**Auto-updates (two-phase):**

Phase 1 — updater upgrade (performed by the main app):
- Main app downloads the new release zip to `/var/updates/`, verifies signature against the public key shipped with the installer
- If the release includes a new updater version, the main app atomically swaps `/updater/` using Linux `rename()`: new folder staged, old folder moved aside, new folder moved into place
- Main app code is untouched during this phase

Phase 2 — main app upgrade (performed by the updater):
- Main app sets a maintenance flag and redirects the browser to `/updater/run.php` with a single-use token
- The updater runs as a separate PHP entry point — no main app code is loaded or executing
- The updater extracts the new `/app/` into a staging folder, then atomically swaps it via `rename()`
- The updater runs versioned DB migrations
- The updater clears the maintenance flag and redirects the browser back to the main app

**Update reliability:**
- Updates are chunked into discrete steps; each step writes progress to a state file in `/var/updates/`
- If a browser tab is closed mid-update, the updater can resume from the last completed step on next visit
- The previous `/app/` and `/updater/` folders are retained for one release cycle to allow rollback
- Opcache is explicitly invalidated after the folder swap (`opcache_reset()`; with a fallback TTL strategy)
- Concurrent update attempts are blocked via `flock()` on a lock file

**Update security:**
- Every release zip is signed using a private key held by the maintainer; the updater verifies the signature (via `openssl`) before staging anything — a verification failure aborts the update and notifies the admin
- The public signing key ships as a static file in the bootstrap (Tier 0) and is never auto-updated
- The updater always pulls from a tagged GitHub release, never from a branch HEAD
- Signing is automated via GitHub Actions as part of the release process

**Database schema migrations:**
- Versioned SQL migration files, applied in sequence by the updater
- A full update must not break active sessions or corrupt data mid-process
- A failed migration leaves the previous `/app/` intact and reclaimable via rollback

---

## 7. Backup & Restore

- Manual backup download available from the admin panel at any time — packages the database + `/data/` folder (uploads, attachments) into a single zip
- A complete system restore must be achievable from: fresh installation + backup file only
- DB overrides (language customisations, etc.) are included in the backup

Note: scheduled offsite backups are out of scope for v1. Most shared hosts (e.g. SiteGround) provide daily server-level backups which are sufficient at this stage.

Post-v1: scheduled automatic backups to S3-compatible storage (Backblaze B2, Wasabi, Cloudflare R2)

---

## 8. Organisational Structure

The organisational hierarchy is **fully user-defined** — no levels are hardcoded. This allows ScoutKeeper to serve any Scout organisation regardless of how they structure themselves.

**Two distinct types of organisational unit:**

1. **Hierarchy nodes** — the geographic/programmatic tree. Typical structure (all level names are user-defined):
   ```
   National Organisation
   └── [optional] Association (federation model)
       └── [optional] County / Region
           └── [optional] District
               └── Scout Group / Unit
                   └── Section (Beavers / Cubs / Scouts / Ventures / ...)
   ```
   - Intermediate levels (District, County/Region) are optional — a Group can connect directly to National
   - Sections are the leaf nodes and carry age-group definitions (name + age range, user-defined)
   - A member can hold roles at multiple nodes simultaneously

2. **Teams** — functional groups attached to any hierarchy node, at any level. Can be permanent (e.g. a finance team) or temporary (e.g. a camp organising committee). Teams do not have child nodes.

**Data model:**
- Hierarchy nodes are stored with a `parent_id` and a closure table (`org_closure`) for efficient descendant queries and org tree rendering
- Teams are a separate table linked to their parent hierarchy node
- The closure table is used for UI rendering and reporting only — it does not drive permissions

**Role history:**
- Every role assignment carries a start date and optional end date
- Full assignment history is retained and never deleted
- The same installation scales from a single group to a national organisation

---

## 9. Member Data

**Core fixed fields (always present, stored as real columns):**
- First name, surname, date of birth, gender (male / female / other), membership number (system-assigned)

**Custom fields (admin-configurable):**
- Field types: short text, long text, number, dropdown, date
- Each field: compulsory or optional, with basic validation rules
- Admin defines fields, their display order, and which tab/group they appear in
- Values stored as a JSON column on the member record (`member_custom_data`) — one row per member

**Timeline fields:**
- A subset of fields track history rather than overwrite — each change is a dated entry (e.g. rank, qualifications, role eligibility)
- Stored in a dedicated `member_timeline` table: `member_id`, `field_key`, `value`, `effective_date`, `recorded_by`, `notes`
- Full history retained and visible in the member profile

**File and image attachments:**
- Stored separately in a `member_attachments` table (not in the JSON blob)
- Each attachment: `member_id`, `field_key`, `file_path`, `original_name`, `mime_type`, `uploaded_by`, `uploaded_at`
- Separate lifecycle: upload, download, delete, storage management

**Member profile UI:**
- Split into tabs/groups; field groups are lazy-loaded for performance
- The system handles schema evolution gracefully — adding new custom fields does not require a migration

---

## 10. Registration & Membership

Two registration flows:

| Flow | Behaviour |
|------|-----------|
| Admin-added | Member is active immediately |
| Self-registration | Member enters name, email, DOB, unit → pending status → admin approves → active |

Additional entry points:
- Register by invitation (admin sends invite link)
- Bulk import from CSV (see below)
- Waiting list: prospective members/parents can register interest and are managed by admin

**Bulk import:**
- The org structure must be set up before importing members — the target node is a prerequisite
- Admin navigates to a specific node (group, section, etc.) and downloads a pre-formatted CSV template from there; the target node is pre-baked into the template, no column mapping required
- The template includes all core fixed fields plus any custom fields configured for the installation
- Admin fills in the template (or pastes data from an existing spreadsheet) and uploads it
- Before any data is written, a preview screen shows every row with inline validation errors flagged (missing required fields, invalid dates, duplicate membership numbers, etc.)
- Rows with errors are skipped; clean rows are imported. The admin can fix and re-upload the skipped rows.
- Import places member records only — role assignments are a separate step after import
- For a full org migration, the admin repeats the import per node

**Key rules:**
- Member profile changes made by the member require admin approval before taking effect
- Medical details can be updated by the member directly

---

## 11. User Accounts & Authentication

- Login account and membership record are **separate concepts** — a member record can exist with no login
- **No-login mode**: an org can configure the system so members do not have login access at all
- **Authentication methods**: email + password with optional TOTP MFA (Google Authenticator, Authy, etc.)
- **Forgot password** / self-service password reset
- The v1 auth schema uses a nullable `user_id` on member records, keeping the door open for parent/guardian accounts to be added post-v1 as an additive change (a `user_type` flag + a `parent_member_links` table)

Post-v1: parent/guardian accounts, magic-link login, social login

---

## 12. Permissions

Permissions are **fully explicit** — holding a position or being a member of a team does not automatically grant any access rights. All permissions must be explicitly configured.

**Roles:**
- Admins define roles (e.g. "Group Leader", "District Commissioner", "Camp Coordinator")
- Each role carries explicit permissions per module: read access, write access, a `can_publish_events` flag, and two sensitive-data flags (`can_access_medical`, `can_access_financial`)
- Roles are reusable across the organisation

**Assignments:**
- A member is assigned a role in a specific context — either a hierarchy node or a team
- Each assignment carries an explicit scope: one or more hierarchy nodes the assignment applies to
- The admin selects scope nodes when making an assignment; the UI offers "this node + all descendants" as a default but it is always overridable
- Each assignment has a start date and optional end date; full history is retained

**Permission resolution at runtime:**
- All active assignments for the logged-in member are loaded
- Permissions and scope nodes are unioned across all assignments
- Every data query is filtered against the resulting scope node list

**Key rules:**
- A member with multiple assignments receives the union of all their permissions and all their scope nodes
- The org hierarchy (closure table) is not used for permission checking — it is used only for UI tree rendering and reporting aggregations
- Medical data access requires the explicit `can_access_medical` flag on the role, regardless of other permissions
- The `can_access_financial` flag is reserved for post-v1 when the Finance/Ledger module is introduced

---

## 13. Finance / Ledger

> Dropped from v1. See §15 (Post-v1 modules) for planned scope.

---

## 14. Modules — v1 Scope

### Administration
- Export all data / settings to CSV or XML
- Configurable data fields (see Section 9)
- Org structure management (see Section 8)
- Site-wide search
- Usage reports and statistics
- Membership conditions / T&Cs: time-bound; must be acknowledged by all members; new versions trigger re-acknowledgement; refusal = account blocked and flagged
- Important notices: displayed on login, must be acknowledged before proceeding
- Full audit trail: every record change is logged with user and timestamp

### User Account (Member-facing)
- View and edit profile (key changes require admin approval)
- Upload photo / avatar
- Change password, MFA settings
- View events and training/achievements history
- Communication preferences
- Membership conditions and important notice acknowledgement
- Download my data (CSV/Excel)

### Communications
- **Member portal**: news and notifications on login, filtered by relevance to that member
- **Article publishing**: articles can be public or private; appear on the member portal
- **Email module**: send plain text or basic HTML emails to individuals or groups, targeted by level/role/criteria; uses the configured SMTP account
- **Email queue**: outbound emails queued and sent in batches; configurable batch size and interval to respect shared hosting sending limits
- **Cron dispatcher**: a single scheduled entry point (`cron/run.php`) that calls registered task handlers in sequence. v1 task: drain the outbound email queue. Post-v1 tasks (bounce handling, scheduled reports, etc.) are added as additional handlers without changing the dispatcher.
- **Cron setup**: setup wizard detects whether cron is available on the host and displays the exact cron command to paste into cPanel. If no cron job is detected, pseudo-cron fallback activates — queue processing is triggered on page loads via `fastcgi_finish_request()`. Admin panel shows a visible warning when running in pseudo-cron mode.

- **Bounce handling (v1)**: manual only — admins can flag a member's email address as invalid on their profile; no automated IMAP processing in v1

Post-v1: automated bounce handling (IMAP), newsletter, RSS feed, SMS, polls, social media posting, mail merge

### Events
> Simplified for v1: members with `can_publish_events` publish events; all members view them. No registration or advanced features.

- Members with `can_publish_events` create and publish events (title, description, location, dates, public/private)
- **In-system calendar**: all published events visible to logged-in members
- **iCal feed**: members subscribe in Google Calendar, Outlook, etc.

Post-v1: event registration, waiting lists, capacity management, parental consent, document packs, attendance records, event photos, online payment, auto-generated tickets

### Achievements + Training
> Simplified for v1: adult/leader members only. Training courses merged into this module. No youth programme tracking.

- Admin maintains a list of achievements and training courses (title, description)
- Admin manually assigns achievements and completed courses to adult/leader members, with date
- Member's achievements and training history visible in their portal profile

Post-v1: youth programme / badge tracking, self-application, peer validation, badge eligibility rules, e-certificates, expiry, activity metrics (service hours, camping nights, etc.)

### Directory / Organogram
- Visible to logged-in members only (not public)
- Visual organogram of the organisational structure
- Contact list for key roles (e.g. group leaders, district commissioners, national board)
- Data pulled automatically from org structure and role assignments
- Filtered by member's level/permissions

### Equipment Inventory
> Dropped from v1.

### Resource Library
> Dropped from v1.

---

## 15. Modules — Post-v1

| Module | Notes |
|--------|-------|
| Finance / Ledger | Per-level accounts, member balances, transaction recording |
| Events (full) | Registration, waiting lists, attendance, parental consent, document packs |
| Achievements (full) | Youth programme, self-application, peer validation, eligibility rules, e-certificates |
| Newsletter | Recipient lists, templates, composition |
| Resource Library | Documents, forms, templates per access level |
| Equipment Inventory | Per-level tracking, value, stock in/out |
| Online payments | Payment gateway integration for events, fees, donations |
| eShop | Online store with stock management; members-only items |
| SMS | Via third-party gateway (e.g. Twilio) |
| Polls | Member-facing polls and surveys |
| Social media posting | Instagram, X/Twitter, WhatsApp, LinkedIn |
| Mail merge export | Export member data for external mail merge tools |
| WOSM census reporting | Member counts by age group and gender for WOSM submission |
| Mobile app | Offline-capable app for attendance and field use |
| Programme planner | Session planning linked to badge requirements |

---

## 16. UI & Design

- **Fully responsive**: mobile, tablet, and desktop from day one
- **Dark mode**: built in from day one — not a retrofit
- **Branding**: custom logo + choice of one of 3 colour schemes (each with light and dark variants)
- Member profile split into tabs/pages — lazy-loaded for performance

---

## 17. Multi-Language

- Both admin UI and member-facing interface are fully translatable
- Launch language: English
- Language files: JSON files stored in the GitHub repo (`/lang/en.json`, `/lang/fr.json`, etc.)
- Community translations submitted back to the repo and shared via auto-update
- AI-generated starter packs from the English base for new languages
- Per-installation string overrides stored in DB (e.g. renaming "Group" to "Troop")
- DB overrides take precedence over JSON files at runtime
- DB overrides included in backup/restore
- **Master language file**: the system can natively export a complete master language file containing every translatable string in the system. This file is the definitive source for creating a new language translation — a translator (or AI) works from this file to produce a new `xx.json` language pack.

---

## 18. Data Privacy & GDPR

- Full GDPR compliance features built in:
  - Consent flags per member
  - Data retention policies
  - Right-to-erasure workflow
- All privacy features are **toggleable on/off** in setup and config — for use outside Europe where GDPR does not apply
- Members can download all data they have access to at any time
- Every record change is logged (see Administration module)

**Sensitive data handling:**

- **Medical details:** encrypted at the application layer using a key stored in `/config/` (outside the webroot, never in the database, never in release zips). Encryption uses `openssl_encrypt` — a DB dump without the key file is not sufficient to read medical data.
- **Medical access audit log:** every read of a member's medical details is logged (user, timestamp, action) — separate from the general audit trail. Supports GDPR accountability and detects insider misuse.
- **Passwords:** hashed using `password_hash()` with `PASSWORD_BCRYPT` — never stored plain or reversibly.
- **MFA TOTP secrets:** encrypted at rest using the same key as medical data.
- **SMTP credentials:** stored in `config.php` outside the webroot; protected by filesystem permissions.

---

## 19. Build Philosophy

- **Well-commented code**: written so any developer can read, understand, and contribute without needing the original author
- **Unit testing**: PHPUnit tests required as part of the build process — not added after the fact
- **End-to-end scenario testing**: Playwright tests covering realistic user journeys, run against a seeded fictitious organisation
- **Data-driven architecture**: org structure, field definitions, permissions — all driven by data, not hardcoded schema
- **Community-first**: public repo, open contribution model, shared language packs, `CONTRIBUTING.md` guide

**Synthetic design partner:**

Rather than waiting for a real Scout organisation to adopt SK10 as an alpha, the project uses a fully fictitious NSO ("Scouts of Northland" or similar) as a synthetic design partner. This provides:

- A realistic, reproducible test environment available from day one
- A seeder/fixture system that creates a complete org: national board, regions, districts, groups, sections, members with realistic AI-generated data, roles, events, achievements
- Edge cases by design: members with multiple roles, cross-level assignments, expired roles, pending registrations, etc.
- A Playwright scenario library — realistic user journeys ("group leader views the calendar", "admin approves a new member registration") run automatically against the seeded org
- The seeder itself serves as living documentation of what a real-world org looks like in the data model

**Testing stack:**
- PHPUnit — unit and integration tests
- Playwright — end-to-end browser scenario tests against the seeded fictitious org
- AI-assisted test data generation — realistic member names, culturally varied, edge cases

---

## 20. Out of Scope (v1)

- Finance / Ledger module
- Newsletter (plain text/HTML email only in v1)
- Event registration, waiting lists, attendance (events are publish/view only in v1)
- Youth programme / badge tracking (achievements cover adult/leader members only in v1)
- Equipment Inventory
- Resource Library
- Online payments
- eShop / retail functionality
- Mobile app
- SMS
- Social media integration
- WOSM census reporting
- Programme / session planning
- Venue / facility booking

---

## 21. Release Strategy

| Stage | Criteria |
|-------|----------|
| **0.x (dev)** | Repo public; no stability guarantees; not for production use |
| **Alpha** | All v1 modules complete; synthetic org scenarios passing; one real Scout org invited to trial |
| **Beta** | 3–5 orgs across different countries and sizes; localisation tested; stable for 60+ days |
| **1.0** | Beta stable for 3+ months with no critical issues; public announcement |

The synthetic design partner (see §19) replaces the need for a real alpha org during the build phase — real orgs are introduced at the alpha stage once the system is functionally complete and scenario-tested.

---

## 22. Governance & Sustainability

- **Maintainer:** QuadNine Ltd and its founder, Kevin Azzopardi
- **Licence:** GNU Affero General Public Licence v3.0 (AGPL-3.0) — free to use and self-host; anyone offering it as a hosted service must publish their modifications under the same licence
- **Model:** QuadNine-led with public roadmap; community PRs welcome; a paid hosted version offered by QuadNine funds ongoing development
- **Sustainability statement:** published in the README — in the event QuadNine is unable to continue, the repository remains public and the community is free to fork

---

## 23. Monitoring & Observability

> Applies to QuadNine-hosted instances only. Self-hosted installations have no monitoring integration.

SK10 exposes two authenticated endpoints consumed by Spike (QuadNine's performance monitoring system, hosted on a separate server). SK10 has no knowledge of Spike — it simply exposes the endpoints; Spike is configured per hosted instance with the URL and API key.

**`/health.php`** — polled by Spike for uptime and performance metrics:
- Database connectivity status
- PHP peak memory usage
- Last cron run timestamp and status
- Slow query flag (queries exceeding the configured threshold)
- SK10 version
- Error count since last check

**`/api/logs`** — polled by Spike for error and diagnostic data:
- Recent structured application errors (last N entries, or since a given timestamp)
- Recent slow query log entries
- Authenticated via a static API key in `config.php`; sent by Spike as a request header

**Internal logging:**
- Application errors written to `/var/logs/errors.json` (structured JSON, rotating)
- Slow queries written to `/var/logs/slow-queries.json` (threshold configurable in `config.php`)
- Log viewer available in the SK10 admin panel for on-site visibility

**Authentication:**
- API key stored in `config.php` (outside webroot, never in the database or release zips)
- Requests without a valid key receive a `401` response; no data exposed

---

## 25. Open Items

- Newsletter module — detailed design deferred to post-v1
- WOSM census reporting requirements — confirmed: total membership broken down by age section and gender
