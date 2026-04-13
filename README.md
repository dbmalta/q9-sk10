# ScoutKeeper

**A free, open-source membership management system for Scout organisations.**

ScoutKeeper is a web-based membership management platform built for Scout organisations of any size — from a single group to a national organisation. It is aligned with WOSM best practices, designed to replace paper and spreadsheet-based systems, and built to be installed and run by anyone with basic web hosting.

---

## Who is it for?

Any Scout organisation that needs a proper membership system but lacks the budget, technical infrastructure, or in-house expertise to run complex software. ScoutKeeper is free, self-hostable, and built to run on standard shared Linux hosting.

---

## Key Features

- **Membership management** — register, manage, and track members across any organisational structure
- **Flexible org structure** — define your own hierarchy (section, group, district, region, national — or anything else); includes functional teams at any level
- **Custom member fields** — configure the data fields your organisation needs, with full history on time-bound fields
- **Explicit permissions** — roles with module-level access and explicit scope; members can hold multiple roles across the hierarchy and in functional teams
- **Events calendar** — publish events with an in-system calendar and iCal subscription feed; event registration and attendance tracking are planned for post-v1
- **Achievements & training** — admin-maintained list of achievements and training courses, manually assigned to adult/leader members with full history; youth programme and badge tracking are planned for post-v1
- **Communications** — member portal with articles and notifications; email targeting by role, level, or criteria
- **Directory & organogram** — visual org chart and contact list for key roles, visible to members
- **Multi-language** — built-in i18n with community-shareable JSON language files
- **GDPR-ready** — consent, data retention, and right-to-erasure features (toggleable for non-EU use)
- **Audit trail** — every record change is logged
- **Dark mode** — fully responsive with light and dark mode built in

---

## Design Principles

- **Simple to install** — unzip, upload, run the setup wizard. No command line required.
- **Simple to run** — designed for non-technical administrators
- **Open and extensible** — well-commented code, built for community contribution
- **Built to last** — unit tested, with signed auto-updates from this repository
- **Accessible** — free forever, no per-member or per-section fees

---

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP 8.2+ |
| Database | MySQL 8.0+ or MariaDB 10.6+ |
| Hosting | Linux shared hosting (e.g. SiteGround, cPanel) |
| Updates | Signed auto-update from tagged GitHub releases |
| Frontend | Bootstrap 5.3, Alpine.js, HTMX, Twig 3 |
| Testing | PHPUnit + Playwright E2E |

---

## Installation

1. Download the latest release from the [Releases](../../releases) page
2. Upload and unzip to your web server
3. Create a MySQL database and user for ScoutKeeper
4. Navigate to your domain in a browser — the setup wizard will launch automatically
5. The wizard will verify server requirements, connect to your database, run migrations, and guide you through configuring your organisation name, admin account, org structure, and SMTP settings
6. Once complete, log in with the admin account you created

**Requirements:** PHP 8.2+, MySQL 8.0+ or MariaDB 10.6+, Linux shared hosting with Apache and `mod_rewrite` enabled

**No command-line access is needed at any stage.**

---

## Backup & Restore

- Manual backup download from the admin panel (database + uploaded files, packaged as a single zip)
- Full system restore from any backup file on a fresh installation

---

## Languages

ScoutKeeper ships with English. Additional language packs are stored as JSON files and can be:
- Downloaded via the auto-update mechanism
- Created using AI-generated starter packs from the English base
- Contributed back to this repository for the community

---

## Contributing

We welcome contributions — bug fixes, new language packs, feature additions, and improvements.

Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a pull request.

All contributions must include appropriate tests.

For architecture details, directory layout, and development conventions, see [CLAUDE.md](CLAUDE.md).

---

## About

ScoutKeeper is developed and maintained by [QuadNine Ltd](https://quadnine.mt) and its founder, Kevin Camilleri.

Previous versions of ScoutKeeper have been used by Scout organisations since the early days of the project. This is a clean rebuild, designed for modern hosting environments and the needs of the global Scout movement.

**Sustainability:** ScoutKeeper is an actively maintained project. The codebase is publicly hosted at [github.com/quadninemt/scoutkeeper](https://github.com/quadninemt/scoutkeeper) under the AGPL v3 licence. In the event that QuadNine Ltd is unable to continue development, the repository will remain public and the community is free to fork and continue the project independently.

A **paid hosted version** of ScoutKeeper is available for organisations that prefer a fully managed solution. Hosting fees fund ongoing development of the open-source project.

---

## Licence

ScoutKeeper is licenced under the [GNU Affero General Public Licence v3.0 (AGPL-3.0)](https://www.gnu.org/licenses/agpl-3.0.html).

You are free to use, self-host, and modify ScoutKeeper at no cost. If you offer ScoutKeeper (or a modified version) as a hosted service, you must make your source code available under the same licence.
