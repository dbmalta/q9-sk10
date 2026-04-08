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
| Language | PHP 8.0+ |
| Database | MySQL 5.7+ |
| Hosting | Standard shared hosting (e.g. SiteGround, cPanel) |
| Updates | Auto-update mechanism from public GitHub repo |
| Testing | PHPUnit — unit tests required as part of the build process |
| Languages | JSON files per language in the repo + per-installation DB overrides |

**Constraints:**
- No CLI required for installation or updates
- No Docker, no cloud infrastructure dependencies
- Composer dependencies must be bundled if used (not assumed to be available on shared hosting)
- All architecture decisions must respect shared hosting limitations

---

## 6. Installation & Updates

**Installation:**
- User downloads the latest release from GitHub
- Uploads and unzips to their web server
- Runs the setup wizard via browser
- Setup wizard minimum: organisation name, admin account, basic org structure, email account configuration (SMTP for outbound + IMAP for inbound bounce/reply handling)
- No command-line access required at any stage

**Auto-updates:**
- Built-in update mechanism checks the public GitHub repo for new releases
- Updates are applied from within the admin panel
- Database schema migrations are handled via versioned SQL migration files
- A full update must not break active sessions or corrupt data mid-process

---

## 7. Backup & Restore

- Manual database download available from the admin panel at any time
- Scheduled automatic backups to Google Drive or compatible cloud storage
- A complete system restore must be achievable from: fresh installation + backup file only
- DB overrides (language customisations, etc.) are included in backups

---

## 8. Organisational Structure

The organisational hierarchy is **fully user-defined** — no levels are hardcoded. This allows ScoutKeeper to serve any Scout organisation regardless of how they structure themselves.

- Unlimited nested levels (e.g. National → Region → District → Group → Section)
- Parallel levels at any point in the hierarchy
- A member can hold **multiple simultaneous roles** across different nodes
- Each role carries a **start date and optional end date**
- Full role history is retained and never deleted
- The same installation scales from a single group to a national organisation

---

## 9. Member Data

**Core fixed fields (always present):**
- First name, surname, date of birth, gender (male / female / other), membership number (system-assigned)

**Custom fields (admin-configurable):**
- Field types: short text, long text, number, dropdown, date, image attachment, file attachment
- Each field: compulsory or optional, with basic validation rules
- **Date-bound fields with history**: some fields (e.g. rank, appointments, awards) are timelines — each value carries a date and the full history is retained
- Member profile UI is split into tabs/pages; field groups are lazy-loaded for performance
- The system must handle schema evolution gracefully as fields are added over time

---

## 10. Registration & Membership

Two registration flows:

| Flow | Behaviour |
|------|-----------|
| Admin-added | Member is active immediately |
| Self-registration | Member enters name, email, DOB, unit → pending status → admin approves → active |

Additional entry points:
- Register by invitation (admin sends invite link)
- Bulk import from CSV
- Waiting list: prospective members/parents can register interest and are managed by admin

**Key rules:**
- Member profile changes made by the member require admin approval before taking effect
- Medical details can be updated by the member or their parent/guardian directly

---

## 11. User Accounts & Authentication

- Login account and membership record are **separate concepts** — a member record can exist with no login
- **No-login mode**: an org can configure the system so members do not have login access at all
- **Authentication methods**: email + password, social login, multi-factor authentication (MFA)
- **Forgot password** / self-service password reset
- **Parent accounts**: a parent/guardian has their own login, linked to one or more member records

---

## 12. Permissions

Permissions operate on two axes:

| Axis | Description |
|------|-------------|
| **Access level** | Defined by role — Read/Write or Read-only |
| **Scope** | Defined by position in hierarchy — a user can only see and act on nodes at their own level and below |

- A member with multiple roles receives the **union** of all their permissions
- Permission levels are predefined (R/W or R-only) — no custom permission types, for ease of use

---

## 13. Finance / Ledger

The finance and subscription model is unified into a **per-level ledger system**:

- Each organisational level has one or more billing accounts (cash or bank)
- A member can owe fees to multiple levels simultaneously (e.g. national fee + group fee)
- Each member has a **running balance** per billing account — both debit and credit
- **Per-event accounts** are supported (e.g. summer camp finances tracked separately)
- Transaction types: membership fees, event fees, purchases, credits, refunds, donations

**v1 scope:** Manual/offline transaction recording only
**Post-v1:** Online payment gateway integration

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
- Privacy settings
- View events and training history
- View balance and transaction history
- Communication preferences
- Download my data (CSV/Excel)

### Communications
- **Member portal**: news and notifications on login, filtered by relevance to that member
- **Email module**: send to individuals or groups, targeted by level/role/criteria; uses the configured SMTP account; inbound IMAP connection handles bounce processing and reply tracking
- **Email queue**: all outbound emails are queued and sent in batches via a scheduled cron job — not sent immediately on trigger. Configurable limits: max emails per batch and min interval between batches (to comply with shared hosting sending limits and avoid blacklisting)
- **Bounce handling**: cron job periodically polls the IMAP inbox, processes bounced emails, and flags undeliverable addresses on member records
- **Cron job**: a single scheduled task handles both the outbound email queue and inbound bounce processing. Setup wizard provides the cron command for the admin to configure on their server
- **Newsletter**:
  - Recipient lists: create and save filtered lists by role, rank, age, or other criteria
  - Templates: create and manage reusable newsletter templates
  - Images: upload or link images for use in templates
  - Manual creation: compose a newsletter by selecting existing published articles and/or adding new content
  - Post-v1: automated newsletter generation based on articles published in the last X days
- **RSS feed**: publicly available articles and events for external websites
- **Resource library**: documents, forms, and files — readable/downloadable per access level
- **Article publishing**: articles can be public or private, appear on portal and/or newsletter

Post-v1: SMS, polls, social media posting, mail merge

### Events
- Create events: title, description, location, dates, capacity, public/private
- Limited places with waiting list for oversubscribed events
- Member self-registration and admin registration
- Register multiple persons per booking
- Parental consent collection for minors
- Document packs for attendees
- Attendees as an emailable group
- Attendance records
- Event photos and resources; attendees can upload material
- Per-event finance accounts (linked to Finance/Ledger module)
- **In-system calendar**: filtered by member's level/permissions
- **iCal/RSS feed**: subscribable in Google Calendar, Outlook, etc.

Post-v1: online payment, auto-generated tickets

### Training
- Separate from Events — dedicated module
- Members apply for training events (limited places, waiting list)
- Admin approves or rejects applications
- On completion: participant's record automatically updated (achievements, qualifications, role eligibility)
- Training history visible in member profile

### Achievements
- Fully configurable per organisation — define your own achievements, badges, criteria
- No standard framework imposed (WOSM does not have one)
- Achievements visible in member's account
- Self-application or admin-awarded
- Badge eligibility rules: **auto-unlock when prerequisites are met**, but admin must confirm before publishing to the member's record
- Per-achievement: downloadable forms, requirements, resources
- Per-achievement: upload documents, fill in reports
- Tracking and management across multiple levels
- E-certificates
- Peer / leader validation
- Expiry on certain achievements
- Tracking of activity metrics: service hours, camping nights, hiking miles (or equivalent — configurable per organisation)

### Directory / Organogram
- Visible to logged-in members only (not public)
- Visual organogram of the organisational structure
- Contact list for key roles (e.g. group leaders, district commissioners, national board)
- Data pulled automatically from org structure and role assignments
- Filtered by member's level/permissions

### Equipment Inventory
- Track equipment items per group/level
- Record item value and condition over time
- Stock in / out logging
- Inventory reports including total value

### Resource Library
- Upload and organise documents, forms, templates
- Public or private visibility per resource
- Access controlled by level/role
- Linked from events, achievements, and the member portal

---

## 15. Modules — Post-v1

| Module | Notes |
|--------|-------|
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

---

## 19. Build Philosophy

- **Well-commented code**: written so any developer can read, understand, and contribute without needing the original author
- **Unit testing**: PHPUnit tests required as part of the build process — not added after the fact
- **Data-driven architecture**: org structure, field definitions, permissions — all driven by data, not hardcoded schema
- **Community-first**: public repo, open contribution model, shared language packs, `CONTRIBUTING.md` guide

---

## 20. Out of Scope (v1)

- Online payments
- eShop / retail functionality
- Mobile app
- SMS
- Social media integration
- WOSM census reporting
- Programme / session planning
- Venue / facility booking

---

## 21. Open Items

- Licence selection (MIT recommended for community open-source)
- PHP/MySQL minimum version confirmation
- Newsletter module — detailed design TBD
- WOSM census reporting requirements — to be confirmed when needed
