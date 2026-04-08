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
| Finance / Ledger | v1 |
| Communications (portal, email, newsletter, RSS) | v1 |
| Events + Calendar | v1 |
| Achievements | v1 |
| Equipment Inventory | v1 |
| Resource Library | v1 |
| Directory / Organogram | v1 |
| Training | v1 |
| Subscriptions (online payments) | Post-v1 |
| eShop | Post-v1 |

---

## General / Platform

- Web-based, PHP + MySQL, deployable on shared hosting (e.g. SiteGround)
- Unzip-to-server installation with setup wizard
- Auto-update from public GitHub repo
- Fully responsive — mobile, tablet, desktop
- Dark mode built in from day one
- Branding: custom logo + choice of one of 3 colour schemes
- Multi-language: English at launch; JSON language files in GitHub repo; DB overrides per installation; AI-generated starter packs; community-shareable translations
- GDPR compliance features (consent, data retention, right-to-erasure) — toggleable on/off in setup/config
- Every record change logged for full audit trail
- Full data export to CSV/Excel for any data the user has access to

---

## Administration Module

- Export all data / settings to CSV or XML
- Database backup with manual download
- Scheduled database backup to Google Drive or similar
- Full restore from fresh install + backup file
- Configurable data fields for the database (see Member Data Fields below)
- Organisational structure: unlimited nested and parallel levels, fully user-defined
- Site-wide search
- Usage reports and statistics
- Membership conditions / T&Cs: time-bound, must be acknowledged by all members; new versions trigger re-acknowledgement; refusal results in account blocked and flagged for admin attention
- Important notices: displayed on login, must be acknowledged before proceeding
- WOSM census reporting (post-v1): member counts by age group and gender

---

## Member Data Fields

**Core fixed fields (always present):**
- First name, surname, date of birth, gender (male/female/other), membership number (system-assigned)

**Admin-configurable custom fields:**
- Field types: short text, long text, number, dropdown, date, image attachment, file attachment
- Each field can be compulsory or optional
- Basic validation rules per field
- Some fields are date-bound with full history (e.g. rank changes, appointments, awards) — stored as a timeline, not a single value
- Member profile UI split into tabs/pages — lazy-loaded for performance
- System handles schema evolution gracefully as fields are added over time

---

## Registration + Member Management Module

**Registration flows:**
- Admin-added → active immediately
- Self-registration (name, email, DOB, unit) → pending status → admin approves → active
- Register by invitation
- Bulk import from CSV
- Waiting list: prospective members/parents can register interest; managed by admin

**Member management:**
- Edit personal details and group affiliation
- Key detail changes by members require admin approval
- Medical details: members or parents can update their own medical information
- Parental consent: ability to collect parental consent for minors attending activities
- Parent accounts: a parent/guardian login can be linked to one or more member records
- No-login mode: members can exist in the system with no login access (org-level setting)
- Management at different organisational levels

---

## Permissions Module

- Dual-axis: role defines access level (Read/Write or Read-only); hierarchy position defines scope (own level and below only)
- A member with multiple roles gets the union of all their permissions
- List all permissions in the system
- Define who holds each permission
- Reports on who has what permissions

---

## User Account Module

- Login: email + password, social login, multi-factor authentication (MFA)
- Forgot password / password reset
- My profile: view and update personal details (key changes require admin approval)
- Upload photo / avatar
- Privacy settings
- View events attending / attended
- View pending balances and transaction history
- Communication preferences
- Membership conditions acknowledgement
- Important notice acknowledgement on login
- Download my data (CSV/Excel)

---

## Finance / Ledger Module

> Replaces the separate Subscriptions and Finance modules — unified ledger model.

- Each organisational level has its own billing account(s) — multiple cash/bank accounts per level
- Per-event accounts (e.g. summer camp finances tracked separately)
- Each member has a running balance per billing account (debit and credit)
- Transaction types: membership fees, event fees, uniform/purchase, credits, refunds, donations
- Offline/manual transaction recording by admin (online payments are post-v1)
- E-invoices and e-receipts
- Membership card / number issuance
- Email reminders on subscription renewal / outstanding balance
- Individual, group, and unified financial reports
- Equipment inventory value tracked over time (linked to Equipment module)

---

## Communications Module

**v1:**
- Member portal: news/notifications on login, filtered by relevance to that member
- Publication of articles (public or private, portal and/or newsletter)
- Email: send to individual members or groups, targeted by level/role/criteria
- Newsletter:
  - Recipient lists: create and save filtered lists by role, rank, age, or other criteria
  - Templates: create and manage reusable newsletter templates
  - Images: upload or link images for use in templates
  - Manual creation: compose a newsletter by selecting existing articles and/or adding new content
  - Post-v1: automated newsletter generation based on articles published in the last X days
- RSS feed: publicly available articles and events consumable by external websites
- Resource library: forms, documents, files — readable and downloadable by members with appropriate access

**Post-v1:**
- SMS
- Polls
- Social media posting (Instagram, X/Twitter, WhatsApp, LinkedIn)
- Mail merge export

---

## Events Module

- Create events with full details (title, description, location, dates, capacity, public/private)
- Limited places with waiting list for oversubscribed events
- Member event registration (self or admin)
- Register multiple persons per booking
- Parental consent collection for minors attending events
- Training event applications (separate flow — apply, admin approves/rejects)
- Online payment for events (post-v1)
- Document packs for attendees
- Attendees as an emailable group
- Attendance records
- Event photos and resources
- Attendees can upload and share material
- Auto-generated tickets (post-v1)
- Per-event finance accounts (linked to Finance module)
- In-system calendar: visible to members filtered by their level/permissions
- iCal/RSS feed: subscribable in personal calendar apps (Google Calendar, Outlook, etc.)

---

## Achievements Module

- Fully configurable — each organisation defines their own achievements, badges, and requirements
- No standard framework imposed
- Achievements visible in member's account
- Self-application or admin-awarded
- Per-achievement: downloadable forms, requirements, resources
- Per-achievement: upload documents, fill in reports
- Tracking and management across multiple groups/levels
- E-certificates
- Peer / leader validation of achievements
- Expiry on certain achievements
- Badge eligibility rules: achievements auto-unlock when prerequisites are met, but require admin confirmation before publishing to the member's record
- Tracking of activity metrics: service hours, camping nights, hiking miles (configurable per organisation)

---

## Equipment Inventory Module

- Track equipment items per group/level
- Record item value and condition over time
- Stock in / out logging
- Inventory reports including total value
- (eShop and online sales — post-v1)

---

## Resource Library Module

- Upload and organise documents, forms, templates
- Public or private visibility per resource
- Access controlled by level/role
- Members can read or download resources they have access to
- Linked from relevant areas (events, achievements, portal)

---

## Competitive Research Notes

> Researched: Online Scout Manager (OSM), UK Scouts My Membership, Tentaroo, TroopWebHost, TroopTrack, Scoutbook, Scout Manager, Orgo — April 2026

### Features seen in competitors worth considering for SK10

**Member Management**
- Waiting lists for groups/sections (OSM) ✓ added
- Medical records storage per member (OSM) ✓ added
- Health form tracking (TroopWebHost) ✓ added
- Parent/guardian portal with self-service detail updates (OSM, TroopTrack, TroopWebHost) ✓ added
- Household/family grouping — one login covers a family with multiple youth members (TroopTrack) ✓ added

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
- Individual member account balances (dues, fees, transaction history) ✓ added
- Campership / financial aid applications (Tentaroo)

**Communications**
- Push notifications via mobile app (OSM, TroopWebHost, TroopTrack)
- Automated renewal / payment reminder emails (OSM, Scout Manager) ✓ added

**Governance / Admin** *(Orgo — NSO-level, relevant if SK10 targets national orgs)*
- e-Voting for general assemblies and board elections
- Electronic document signing
- Official gazette / policy document publication
- Dual-level fee collection (national fee + local chapter fee simultaneously) ✓ added

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
- Access filtered by member's level/permissions

---

## Training Module

- Separate from the Events module
- Members apply for training events (with limited places and waiting list)
- Admin approves/rejects applications
- Upon completion, participant's record is automatically updated (achievements, qualifications, role eligibility, etc.)
- Training history visible in member profile

---

## Open Questions / To Explore
- Service hours / camping nights / hiking miles tracking — relevant for WOSM orgs?
- Programme planning module — future consideration
- Offline mobile app — future consideration
- WOSM census reporting details — to be added when WOSM requirements are clarified
- Social posting platforms for post-v1 comms — update from defunct Google+ to Instagram, X/Twitter, WhatsApp, LinkedIn
