# ScoutKeeper10 — Features Scratchpad

> This is a living brainstorm document. Items are not commitments — use this to explore, challenge, and prioritise.
> Last updated: 2026-04-08

---

## Modules Overview

| Module | v1 Status |
|--------|-----------|
| General / Platform | v1 |
| Administration | v1 |
| Registration + Member Management | v1 |
| Permissions | v1 |
| User Accounts | v1 |
| Communications (portal + email) | v1 |
| Events + Calendar | v1 (simplified — see module) |
| Achievements + Training | v1 (simplified — see module) |
| Directory / Organogram | v1 |
| Finance / Ledger | Dropped |
| Newsletter | Dropped |
| Equipment Inventory | Dropped |
| Resource Library | Dropped |
| Subscriptions (online payments) | Post-v1 |
| eShop | Post-v1 |

---

## General / Platform

- Web-based, PHP 8.2+ / MySQL 8.0+ (or MariaDB 10.6+), deployable on Linux shared hosting (e.g. SiteGround, cPanel-based hosts)
- Unzip-to-server installation with setup wizard; pre-flight check verifies write permissions before proceeding
- Two-phase auto-update: main app upgrades the `/updater/` subsystem; updater then upgrades `/app/` via atomic folder swap (`rename()`); previous versions retained one cycle for rollback
- Updates chunked into discrete steps with a state file — resumable if interrupted; progress shown in browser
- Signed release zips (signed in CI via GitHub Actions); updater verifies signature against a static public key in the bootstrap before staging anything — failure aborts the update
- Fully responsive — mobile, tablet, desktop
- Dark mode built in from day one
- Branding: custom logo + organisation name; single neutral palette (light/dark mode)
- Multi-language: English at launch; JSON language files in GitHub repo; DB overrides per installation; AI-generated starter packs; community-shareable translations
- GDPR compliance features (consent, data retention, right-to-erasure) — toggleable on/off in setup/config
- Every record change logged for full audit trail
- Full data export to CSV/Excel for any data the user has access to

---

## Administration Module

- Export all data / settings to CSV or XML
- Manual backup download: database + uploaded files packaged into a single zip, downloadable from the admin panel
- Full restore from fresh install + backup file
- Post-v1: scheduled automatic backups to S3-compatible storage
- Configurable data fields for the database (see Member Data Fields below)
- Organisational structure: unlimited nested and parallel levels, fully user-defined
- Site-wide search
- Usage reports and statistics
- Membership conditions / T&Cs: time-bound, must be acknowledged by all members; new versions trigger re-acknowledgement; refusal results in account blocked and flagged for admin attention
- Important notices: displayed on login, must be acknowledged before proceeding
- WOSM census reporting (post-v1): total membership broken down by age section and gender

---

## Member Data Fields

**Core fixed fields (always present, stored as real columns):**
- First name, surname, date of birth, gender (male/female/other), membership number (system-assigned)

**Admin-configurable custom fields:**
- Field types: short text, long text, number, dropdown, date
- Each field: compulsory or optional, with basic validation rules
- Values stored as JSON per member — no schema migration needed when fields are added
- Display order and tab/group assignment configurable per field

**Timeline fields:**
- Some fields record history rather than overwrite (e.g. rank, qualifications, role eligibility)
- Each entry carries a value, effective date, recorder, and optional notes
- Full history visible in the member profile

**File and image attachments:**
- Stored in a dedicated attachments table (not in the JSON blob)
- Independent lifecycle: upload, download, delete

**Member profile UI:**
- Split into tabs/groups, lazy-loaded for performance

---

## Registration + Member Management Module

**Registration flows:**
- Admin-added → active immediately
- Self-registration (name, email, DOB, unit) → pending status → admin approves → active
- Register by invitation
- Bulk import from CSV: admin downloads a node-scoped template (core fields + configured custom fields), fills it in, uploads; a preview screen flags row-level errors before any data is written; clean rows import, error rows are skipped for re-upload; role assignments handled separately post-import; org structure must exist first
- Waiting list: prospective members/parents can register interest; managed by admin

**Member management:**
- Edit personal details and group affiliation
- Key detail changes by members require admin approval
- Medical details: members can update their own medical information; stored encrypted at rest (app-layer encryption, key outside webroot); every access is logged separately from the general audit trail
- No-login mode: members can exist in the system with no login access (org-level setting)
- Post-v1: parental consent collection, parent/guardian accounts
- Management at different organisational levels

---

## Permissions Module

- Permissions are fully explicit — position in the hierarchy grants nothing automatically
- Admin defines roles; each role carries module-level read/write permissions, a `can_publish_events` flag, and two sensitive-data flags: `can_access_medical` and `can_access_financial`
- A member is assigned a role in a context (a hierarchy node or a team) with an explicit scope (one or more nodes, selected by the admin; UI defaults to "this node + all descendants")
- A member with multiple assignments receives the union of all permissions and all scope nodes
- Full assignment history retained (start date + optional end date per assignment)
- Reports on who has what permissions and over which scope

---

## User Account Module

- Login: email + password with optional TOTP MFA (Google Authenticator, Authy, etc.)
- Forgot password / self-service password reset
- My profile: view and update personal details (key changes require admin approval)
- Upload photo / avatar
- Communication preferences
- Membership conditions acknowledgement
- Important notice acknowledgement on login
- Download my data (CSV/Excel)
- Post-v1: parent/guardian accounts, magic-link login, social login

---

## Finance / Ledger Module

> Dropped from v1. Post-v1 scope: per-level billing accounts, individual member balances, transaction recording, e-invoices, financial reports, online payment gateway.

---

## Communications Module

**v1:**
- Member portal: news/notifications on login, filtered by relevance to that member
- Publication of articles (public or private, portal only)
- Email: send plain text or basic HTML emails to individual members or groups, targeted by level/role/criteria
- Outbound email queue: emails are queued and sent in configurable batches; never sent inline with a web request
- Cron dispatcher: single scheduled entry point calling modular task handlers; setup wizard provides the cPanel cron command; pseudo-cron fallback if no cron is available, with admin warning

**v1 — bounce handling (manual):**
- Admin can flag a member's email address as invalid on their profile record

**Dropped / Post-v1:**
- Newsletter (post-v1)
- Automated bounce handling via IMAP (post-v1 — added alongside newsletter)
- RSS feed (post-v1)
- SMS, polls, social media posting, mail merge (post-v1)

---

## Events Module

> Simplified for v1: members with `can_publish_events` publish events; all members view them. No registration or advanced features.

- Members with `can_publish_events` create and publish events (title, description, location, dates, public/private)
- In-system calendar: all published events visible to logged-in members
- iCal feed: members can subscribe in Google Calendar, Outlook, etc.

**Dropped / Post-v1:**
- Event registration, waiting lists, capacity management (post-v1)
- Parental consent, document packs, attendee emailing, attendance records (post-v1)
- Event photos and member uploads (post-v1)
- Per-event finance accounts (dropped — Finance module dropped)
- Auto-generated tickets (post-v1)

---

## Achievements + Training Module

> Simplified for v1: adult/leader members only. No youth programme tracking. Training courses merged into this module.

- Admin curates a list of achievements and training courses (title, description, date awarded)
- Achievements and courses are manually assigned to adult/leader members by an admin
- Member's achievements and completed training visible in their member portal profile
- No self-application, no peer validation, no prerequisite rules, no e-certificates in v1

**Dropped / Post-v1:**
- Youth programme / badge tracking (post-v1)
- Self-application and peer/leader validation (post-v1)
- Badge eligibility rules and auto-unlock (post-v1)
- E-certificates, expiry, activity metrics (post-v1)
- Per-achievement forms, document uploads, reports (post-v1)

---

## Equipment Inventory Module

> Dropped from v1.

---

## Resource Library Module

> Dropped from v1.

---

## Competitive Research Notes

> Researched: Online Scout Manager (OSM), UK Scouts My Membership, Tentaroo, TroopWebHost, TroopTrack, Scoutbook, Scout Manager, Orgo — April 2026

### Features seen in competitors worth considering for SK10

**Member Management**
- Waiting lists for groups/sections (OSM) ✓ added
- Medical records storage per member (OSM) ✓ added
- Health form tracking (TroopWebHost) ✓ added
- Parent/guardian portal with self-service detail updates (OSM, TroopTrack, TroopWebHost) — post-v1
- Household/family grouping — one login covers a family with multiple youth members (TroopTrack) — post-v1

**Achievements / Advancement**
- Badge eligibility rules — auto-unlock when prerequisites are met (OSM Gold)
- "Due badge" notifications — alerts when a member is close to completing an award (OSM)
- Badge stock / shopping list generator — tells leaders what badges to order (OSM)
- Parent-uploadable evidence for achievements from home (OSM "Badges At Home")
- Service hours, camping nights, hiking miles tracking as part of advancement (TroopTrack, Scoutbook)

**Programme Planning** *(not in SK10 brainstorm yet)*
- Session/programme planner linked to badge requirements (OSM)
- Community-contributed activity database (15,000+ activities in OSM)
- AI-assisted session planning, risk assessments, kit list generation (OSM "Gilbert") — *future consideration*

**Events**
- Waitlisting for events (Tentaroo) ✓ added
- Parent rota / volunteering sign-up linked to events (OSM)
- Campership / financial aid workflow within event registration (Tentaroo)
- Shift sign-up for fundraiser events (TroopWebHost)

**Finance**
- Bank statement import with auto-categorisation (OSM)
- Gift Aid management (OSM — UK-specific but worth noting for EU equivalents)
- Prepaid expense cards for leaders (OSM — advanced, probably out of scope for v1)
- Individual member account balances (dues, fees, transaction history) — post-v1 (Finance module)
- Campership / financial aid applications (Tentaroo)

**Communications**
- Push notifications via mobile app (OSM, TroopWebHost, TroopTrack)
- Automated renewal / payment reminder emails (OSM, Scout Manager) — post-v1 (Finance module)

**Governance / Admin** *(Orgo — NSO-level, relevant if SK10 targets national orgs)*
- e-Voting for general assemblies and board elections
- Electronic document signing
- Official gazette / policy document publication
- Dual-level fee collection (national fee + local chapter fee simultaneously) — post-v1 (Finance module)

**Facilities / Venue Booking** *(out of scope for SK10 v1)*
- Campsite / activity centre booking portal
- Equipment / quartermaster inventory check-in and check-out ✓ added

**Mobile**
- Offline-capable mobile app for attendance and requirement sign-off during campouts — *future consideration*

### Gaps SK10 has that competitors don't fully address
- **WOSM-aligned** data model and reporting (most competitors are BSA/UK-centric)
- **Accessible to low-resource organisations** — free, self-hostable, no per-section fees
- **Truly multi-org / multi-country** without NSO-level pricing (Orgo charges €149–299/month)
- **Simple PHP/MySQL stack** — deployable on any shared host, no Docker, no cloud dependency

### Competitor pricing reference
| System | Free tier | Paid from |
|--------|-----------|-----------|
| OSM | Yes (Bronze) | £18/section/yr |
| Scoutbook | Fully free (BSA-funded) | — |
| TroopTrack | 30-day trial | $99/yr per unit |
| TroopWebHost | No | $109/yr per troop |
| Scout Manager | 2-month trial | $45/yr per unit |
| Orgo | No | €149/mo per NSO |
| Tentaroo | No | $500/module/yr + 1% |

---

## Directory / Organogram Module

- Visible to logged-in members only (not public)
- Displays organisational structure visually as an organogram
- Contact list for key people in key roles (e.g. group leaders, district/regional commissioners, national board)
- Roles and contacts pulled automatically from the org structure and role assignments
- Access filtered by member's permissions

---

## Monitoring & Observability

> QuadNine-hosted instances only. Not applicable to self-hosted installations.

- `/health.php` — unauthenticated performance endpoint: DB status, memory, cron last run, slow query flag, version, error count since last check
- `/api/logs` — authenticated endpoint (API key in `config.php`): recent structured errors and slow query entries; supports `since` timestamp parameter
- Application errors logged to `/var/logs/errors.json` (structured JSON, rotating)
- Slow queries logged to `/var/logs/slow-queries.json` (threshold configurable)
- Log viewer in admin panel for on-site visibility
- Spike (separate QuadNine project) polls both endpoints per hosted instance

---

## Open Questions / To Explore
- Service hours / camping nights / hiking miles tracking — relevant for WOSM orgs? (post-v1)
- Programme planning module — future consideration
- Offline mobile app — future consideration
