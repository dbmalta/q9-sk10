# ScoutKeeper

**A free, open-source membership management system for Scout organisations.**

ScoutKeeper is a web-based membership management platform built for Scout organisations of any size — from a single group to a national organisation. It is aligned with WOSM best practices, designed to replace paper and spreadsheet-based systems, and built to be installed and run by anyone with basic web hosting.

---

## Who is it for?

Any Scout organisation that needs a proper membership system but lacks the budget, technical infrastructure, or in-house expertise to run complex software. SK10 is free, self-hostable, and built to run on standard shared hosting.

---

## Key Features

- **Membership management** — register, manage, and track members across any organisational structure
- **Flexible org structure** — define your own hierarchy (group, district, region, national — or anything else)
- **Custom member fields** — configure the data fields your organisation needs, with full history on time-bound fields
- **Role-based permissions** — members can hold multiple roles with scoped access across the hierarchy
- **Finance / ledger** — per-level billing accounts with individual member balances; track fees, events, and transactions
- **Events** — create and manage events with registration, attendance, capacity limits, and calendar feeds
- **Training** — manage training events with applications, approvals, and automatic record updates on completion
- **Achievements** — fully configurable badges and awards with auto-unlock rules and admin confirmation
- **Communications** — member portal, email, newsletter, and RSS feeds
- **Resource library** — centralised document and form repository with access controls
- **Directory & organogram** — visual org chart and contact list for key roles, visible to members
- **Equipment inventory** — track group equipment, values, and stock movements
- **Multi-language** — built-in i18n with community-shareable JSON language files
- **GDPR-ready** — consent, data retention, and right-to-erasure features (toggleable for non-EU use)
- **Audit trail** — every record change is logged
- **Dark mode** — fully responsive with light and dark mode built in

---

## Design Principles

- **Simple to install** — unzip, upload, run the setup wizard. No command line required.
- **Simple to run** — designed for non-technical administrators
- **Open and extensible** — well-commented code, built for community contribution
- **Built to last** — unit tested, with auto-updates from this repository
- **Accessible** — free forever, no per-member or per-section fees

---

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP |
| Database | MySQL |
| Hosting | Any shared hosting (e.g. SiteGround, cPanel) |
| Updates | Auto-update from this GitHub repository |
| Testing | PHPUnit |

---

## Installation

> Full installation guide coming soon.

1. Download the latest release
2. Upload and unzip to your web server
3. Navigate to your domain and run the setup wizard
4. Configure your organisation structure, branding, and admin account

**Requirements:** PHP 8.0+, MySQL 5.7+, web server with mod_rewrite or equivalent

---

## Backup & Restore

SK10 includes built-in backup tools:
- Manual database download from the admin panel
- Scheduled automatic backups to Google Drive or compatible cloud storage
- Full system restore from any backup file on a fresh installation

---

## Languages

SK10 ships with English. Additional language packs are stored as JSON files and can be:
- Downloaded via the auto-update mechanism
- Created using AI-generated starter packs from the English base
- Contributed back to this repository for the community

---

## Contributing

We welcome contributions — bug fixes, new language packs, feature additions, and improvements.

Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a pull request.

All contributions must include appropriate unit tests.

---

## About

ScoutKeeper is developed and maintained by [QuadNine Ltd](https://quadnine.mt).

Previous versions of ScoutKeeper have been used by Scout organisations since the early days of the project. SK10 is a clean rebuild, designed for modern hosting environments and the needs of the global Scout movement.

---

## Licence

Coming soon.
