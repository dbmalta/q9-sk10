# Policies Feature — Implementation Status

Last updated: 2026-04-21

## Status: Admin side COMPLETE, not yet committed. Member-side acknowledgement flow NOT done.

## What's done (uncommitted in working tree)

### Schema — `app/migrations/0019_policies.sql`
- New `policies` table: id, name, description, is_active, created_by, timestamps
- New `policy_scopes` table: (policy_id, node_id) junction — empty = all active members
- Added `policy_id` NOT NULL FK column to `terms_versions`, backfilled existing rows into a seed "General Policy"
- **Already applied to dev DB in WSL** via manual SQL run (see smoke test output: 122 required, 29 acked, 23.8% for seed policy)
- **Not yet tracked in `migrations` table** — was inserted manually during dev (ran the ALTERs directly after the `;` in a SQL comment broke my split-by-semicolon runner). When you resume on another machine: make sure this migration runs cleanly end-to-end on a fresh DB. Consider rewriting the multi-statement runner or splitting into single-statement lines.

### Services
- **`app/modules/Admin/Services/PoliciesService.php`** (new): CRUD, scope (get/replace), `getRequiredMemberIds` (uses `member_nodes` + `org_closure` for descendants), `getStats` (required/acknowledged/rate), `getAcknowledgementReport`, `setActive`
- **`app/modules/Admin/Services/TermsService.php`** (modified): `createVersion` now requires `policy_id`; `publishVersion` only unpublishes siblings within the same policy; added `getVersionsByPolicy`

### Controller — `app/modules/Admin/Controllers/TermsController.php` (rewritten)
- Policy CRUD: `createPolicyForm`/`storePolicy`/`showPolicy`/`editPolicyForm`/`updatePolicy`/`togglePolicyActive`/`exportCsv`
- Version CRUD (now nested under policy for creation): `createVersionForm`/`storeVersion`/`editVersionForm`/`updateVersion`/`publishVersion`/`showVersion`

### Routes — `app/modules/Admin/module.php`
```
GET  /admin/terms                                      → policies index
GET  /admin/terms/policies/create
POST /admin/terms/policies
GET  /admin/terms/policies/{id}                        → detail (stats + versions + ack report)
GET  /admin/terms/policies/{id}/edit
POST /admin/terms/policies/{id}
POST /admin/terms/policies/{id}/toggle-active
GET  /admin/terms/policies/{id}/export.csv
GET  /admin/terms/policies/{id}/versions/create
POST /admin/terms/policies/{id}/versions
GET  /admin/terms/versions/{id}                        → version view
GET  /admin/terms/versions/{id}/edit
POST /admin/terms/versions/{id}
POST /admin/terms/versions/{id}/publish
```

### Templates
- `app/modules/Admin/templates/admin/terms/index.html.twig` — rewrite: policies table with progress bars
- `app/modules/Admin/templates/admin/terms/policy_form.html.twig` — NEW: name, description, Alpine node multi-select (same widget as email composer)
- `app/modules/Admin/templates/admin/terms/policy_show.html.twig` — NEW: stats cards, scope summary, versions list, ack report table with CSV download
- `app/modules/Admin/templates/admin/terms/form.html.twig` — modified: POSTs to new nested version route, shows parent policy name
- `app/modules/Admin/templates/admin/terms/show.html.twig` — modified: edit link updated to `/admin/terms/versions/{id}/edit`, back button to policy detail

### i18n
Added to both `lang/en.json` and `lang/it.json`:
- `common.inactive`, `common.activate`, `common.deactivate`, `common.export_csv`
- `policies.*` — create, name, description, name_required, none, versions, required, acknowledged, rate, audience, audience_help, audience_all, audience_selected, audience_all_members, acknowledgement_report, accepted_at, no_required_members

## What's NOT done — next steps when you resume

### 1. Member-facing acknowledgement flow (the whole point)
The admin side lets you *define* policies and *see* who's acknowledged, but nothing currently *prompts* members to acknowledge. Needed:

- New member route like `/my/policies` showing outstanding policies (active policies in the member's scope, with a published version, not yet acknowledged by their user_id)
- An accept endpoint that calls `TermsService::acceptTerms($versionId, $userId, $ip)` (already exists, per-version not per-policy — fine)
- A login interstitial or dashboard banner if `requiresAcceptance` returns outstanding policies — note `TermsService::requiresAcceptance` / `getCurrentVersion` currently only look at the FIRST published version in the DB regardless of policy. They're not called anywhere today but will need rewriting to iterate over all active policies in the member's scope.
- Consider renaming `TermsService` → fold into `PoliciesService` or split per-policy methods out.

### 2. Commit
Nothing is committed yet. Suggested commit message when you confirm it works:

```
Introduce Policies with audience scoping and acknowledgement tracking

Replace the single T&Cs document with multiple named policies. Each policy
has its own versions, a unit-based audience scope, an active flag so
superseded policies can be retired, and per-member acknowledgement stats +
CSV export. Existing terms versions are backfilled into a seed
"General Policy" so nothing is orphaned.

- Migration 0019_policies.sql: policies, policy_scopes, terms_versions.policy_id FK
- PoliciesService with CRUD, scope, stats, ack report
- TermsController rewritten to manage policies + nested versions
- Policy index (stats + progress bars), policy detail (stats cards, versions, ack table + CSV)
- Multi-select unit picker mirrors the email composer
```

### 3. Verify on a fresh install
Because the migration was hand-applied, run it end-to-end on a clean DB to make sure the `;`-in-comment issue doesn't break the stock migration runner. The comment in `0019_policies.sql` already has the `;` replaced with `,` — just confirm.

### 4. Possible follow-ups (not requested)
- Delete policy (currently no delete action — only deactivate)
- Filter ack report by acknowledged / not-acknowledged
- "Send reminder email" button on ack report
- Track per-policy acknowledgement timeline (already in `terms_acceptances.accepted_at`, just needs a chart)

## WSL sync notes

Files live in the OneDrive Windows path; WSL dev server runs from `~/sk10`. Every edit has to be `cp`'d across, and `var/cache/twig` + `var/cache/i18n_*.json` must be cleared. See prior commits for the pattern. Dev server: `php -S 0.0.0.0:8080 -t .` from `~/sk10`.

## Git state at checkpoint

Branch: main, 6 commits ahead of origin/main. Last shipped commit: `81adcf0` (T&Cs/Notices → Policies nav rename). Everything in this document is uncommitted in the working tree.
