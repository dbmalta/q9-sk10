# Permissions UI — Implementation Plan

Companion to [view-switcher-plan.md](view-switcher-plan.md). Captures the build order, file-level changes, and test coverage for the permissions management, effective-permissions, explain, reverse-lookup, and governance surfaces. Executes **after** the view-switcher plan is complete — the switcher's `ViewContext`, `role_assignment_scopes` cascade, and scope-aware nav are prerequisites.

## Decisions captured (reference for future contributors)

The plan below is the resolution of 16 product questions worked through with the product owner. Each decision is tagged `(Qn)` at the point it's applied.

1. **(Q1)** Home is the Member profile → Permissions tab. External admins get member records in a designated "External Supporters" node with a census/notifications exclusion flag. No separate Users admin surface.
2. **(Q2)** Primary jobs (ranked): grant + troubleshoot (co-#1), offboard (#2). Audit-one-person and capability audit are lower-volume and designed for but not lead-prioritised.
3. **(Q3)** Grant flow is context-sensitive: inline row for routine grants, drawer-with-preview for grants conferring any sensitive capability.
4. **(Q4)** Delegation is strict subset — admins can only grant roles whose capabilities are a subset of their own. Super-admin is a flag, not a role with capabilities enumerated.
5. **(Q5)** One assignment per node. Multi-node coverage means multiple assignment rows. `role_assignment_scopes` stays a one-row-per-assignment projection.
6. **(Q6)** Per-role term policy (open-ended / fixed-term / auto-expire). Hard-stop on end date. Notifications at 30/7/1 days and on-expiry. Dedicated monitoring page.
7. **(Q7)** Explain tool has three entry points (person profile, record context, standalone). Output toggles between prose and structured trace. Diagnose-only — no auto-remediation in v1.
8. **(Q8)** Permissions tab has three sub-tabs: *Assignments* (default) / *Effective* / *Explain*.
9. **(Q9)** Non-person surfaces: People panel on node pages, capability registry under Admin → Permissions.
10. **(Q10)** Self-service is read-only view of own assignments + self-explain. No obscurity on super-admin flag — holders see what they hold.
11. **(Q11)** Role edits propagate live to existing assignments. Mandatory impact diff fires on any capability addition. Super-admin uses a flag (`is_super_admin`), not an exhaustive capability list.
12. **(Q12)** Cross-admin authority follows scope-containment: you can modify assignments at or below your scope. Original grantor is notified when their grant is modified by someone else.
13. **(Q13)** Nodes are soft-deleted only (archived). Archiving ends all active assignments scoped there. Merger wizard walks per-assignment decisions; ships in v1.
14. **(Q14)** Bulk operations v1: bulk renewal, bulk end, bulk grant (one role, many people). Defer CSV import and many-roles-one-person.
15. **(Q15)** Audit logs matrix views, explain queries, reverse-lookup queries. Skip self-views. Read events retained 1 year. Permissions audit gated behind a new `permissions.audit.read` capability separate from the general `audit.read`.
16. **(Q16)** Capabilities carry a hardcoded `sensitive` flag in the registry. Sensitivity (fires the drawer) and delegation (Q4, who can grant what) are layered, independent checks.

Out-of-scope items are documented in [permissions-ui-future.md](permissions-ui-future.md).

---

## Guiding principles

1. **One resolver, many presentations.** The effective matrix, explain tool, reverse lookup, and impact diffs are all projections of the same underlying resolver. Build the resolver API once with forward (`explain`), reverse (`whoCan`), and diff (`hypothetical`) modes; everything else is rendering.
2. **Scope containment is the universal boundary.** An admin sees, modifies, and grants only within their active scope. Out-of-scope data surfaces as counts, never details. The switcher's scope model is authoritative.
3. **Capabilities are declarative, not configurable.** The capability registry is a PHP code artefact. Admins assemble roles from capabilities; they do not redefine or re-flag capabilities.
4. **Audit writes and reads, separately governed.** Changes are append-only and retained forever; reads are retained 1 year and accessed through a distinct capability.
5. **No feature flag.** Pre-v1 product, ships on `main`. The switcher's foundation is assumed in place.

---

## Dependencies on the view-switcher plan

This plan assumes the switcher plan has shipped and is stable. Specifically:

- `ViewContextService` and the `view` Twig global exist. This plan's scope-filtering reuses them directly rather than re-implementing.
- `role_assignment_scopes` cascade via `org_closure` is wired in the switcher's scope resolution. This plan's effective-permissions resolver extends that cascade rather than defining it.
- The scope picker is in place; the grant drawer's scope-node dropdown reuses its data source (user's writable nodes at active scope).
- Audit fields (`view_mode`, `node_id`) are already on `audit_log` from the switcher's Phase 1 migration.

If the switcher plan's Phase 1–4 are incomplete, this plan cannot start.

---

## Phase 1 — Foundation: capability registry, resolver, services

Goal: land the code-level primitives. No UI yet.

### DB migrations

New migration file `app/migrations/00NN_permissions_ui.sql`:

```sql
ALTER TABLE roles
    ADD COLUMN term_policy ENUM('open_ended','fixed_term','auto_expire') NOT NULL DEFAULT 'open_ended',
    ADD COLUMN term_years TINYINT UNSIGNED NULL,
    ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE role_assignments
    ADD COLUMN expiry_notice_sent_at DATETIME NULL,
    ADD COLUMN end_reason ENUM('manual','expired','node_archived','node_merged') NULL,
    ADD INDEX idx_ra_end_date (end_date);

ALTER TABLE org_nodes
    ADD COLUMN excluded_from_census TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN excluded_from_notifications TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN archived_at DATETIME NULL,
    ADD INDEX idx_nodes_archived (archived_at);

CREATE TABLE permission_audit_reads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT UNSIGNED NOT NULL,
    event_type ENUM('matrix_view','explain_query','reverse_lookup','capability_registry') NOT NULL,
    subject_user_id INT UNSIGNED NULL,
    subject_node_id INT UNSIGNED NULL,
    subject_capability VARCHAR(120) NULL,
    view_mode VARCHAR(10) NULL,
    viewer_scope_node_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_par_actor_created (actor_user_id, created_at),
    INDEX idx_par_subject (subject_user_id, created_at),
    INDEX idx_par_created (created_at)
);
```

Separate table for read-events keeps the main `audit_log` lean (change-only, indefinite retention) and lets the purge job operate on reads alone.

### New files

- `app/src/Core/CapabilityRegistry.php` — singleton exposing the hardcoded list of capabilities. Each entry has `id` (e.g. `members.write`), `description`, `category` (e.g. Members / Medical / Admin / System), `sensitive: bool`, `i18n_key`. Read-only from PHP's perspective; adding a capability = adding a line here.
- `app/src/Core/PermissionResolver.php` — extended. New methods:
  - `explain(int $userId, string $capability, ?int $nodeId): Explanation` — forward resolution; returns granted/denied + the list of contributing assignments with full provenance chain.
  - `whoCan(string $capability, ?int $nodeId, ?int $callerScopeNodeId = null): WhoCanResult` — reverse resolution; returns users with the capability at the given node, filtered by caller's scope containment.
  - `effectiveMatrix(int $userId, ?int $callerScopeNodeId = null): Matrix` — composite view; capability × node, with per-cell provenance lazy-loadable.
  - `hypothetical(int $userId, array $deltas, ?int $capability = null, ?int $nodeId = null): DiffResult` — runs resolution twice (current state + hypothetical deltas) and returns the difference. Backs the impact diff.
- `app/src/Core/AccessInspector.php` — service layer on top of the resolver. Its job is the *operations* on the resolver's outputs: rendering the explain result as prose or structured trace, building impact diffs for grant/end/role-edit/node-archive/merge/bulk. Controllers call the inspector; the inspector calls the resolver.
- `app/src/Core/PermissionAuditService.php` — wraps `permission_audit_reads` writes. Exposes `recordMatrixView`, `recordExplainQuery`, `recordReverseLookup`, `recordCapabilityRegistryView`. Self-views (actor == subject) are a no-op by design.

### Capability registry seeding

Declare `permissions.audit.read` as a new entry in `CapabilityRegistry` with `sensitive: true`. Declare the existing capabilities used across modules as registry entries as part of this work — if they're already listed in module `permissions` arrays, the registry consolidates them. Each capability gets a description string and a sensitivity flag set by reviewing the capability's impact:

- **Sensitive by default**: anything in `medical.*`, `user.*`, `roles.*`, `backup.*`, `audit.*`, `permissions.audit.*`, plus `members.delete`, `communications.send_all`, any export.
- **Not sensitive**: read-scoped capabilities on non-sensitive domains, routine write capabilities like `events.write`, `articles.write`.

The sensitivity audit is part of Phase 1 deliverables.

### i18n keys

Append to `lang/en.json` under a new `permissions.*` namespace:

```
permissions.tab.label, permissions.tab.assignments, permissions.tab.effective, permissions.tab.explain
permissions.grant.inline_cta, permissions.grant.drawer_title, permissions.grant.sensitive_warning
permissions.grant.impact_preview, permissions.grant.confirm
permissions.assignment.status.active, permissions.assignment.status.expired, permissions.assignment.status.future
permissions.assignment.end_confirm, permissions.assignment.ended_by_other_notification
permissions.matrix.cell.granted, permissions.matrix.cell.denied, permissions.matrix.cell.inherited
permissions.matrix.pivot.by_node, permissions.matrix.pivot.by_capability
permissions.explain.output.prose, permissions.explain.output.trace
permissions.explain.not_granted, permissions.explain.granted_via
permissions.monitoring.title, permissions.monitoring.expiring_soon, permissions.monitoring.expired, permissions.monitoring.renewed
permissions.registry.title, permissions.registry.conferring_roles, permissions.registry.current_holders
permissions.node_panel.title, permissions.node_panel.assign_cta
permissions.bulk.renew, permissions.bulk.end, permissions.bulk.grant
permissions.merger.title, permissions.merger.decide_per_assignment
permissions.out_of_scope.banner
permissions.term_policy.open_ended, permissions.term_policy.fixed_term, permissions.term_policy.auto_expire
```

### Tests (Phase 1)

- `tests/Core/CapabilityRegistryTest.php` — sensitive flags match the documented audit; every capability used in controllers is registered.
- `tests/Core/PermissionResolverTest.php` — extended with `explain`, `whoCan`, `hypothetical` scenarios covering: direct grants, inherited via ancestor scope, multiple overlapping roles, expired assignments excluded, super-admin flag grants everything, out-of-scope filtering for `whoCan`.
- `tests/Core/AccessInspectorTest.php` — prose rendering correctness, structured trace shape, diff computation for each trigger type.
- `tests/Core/PermissionAuditServiceTest.php` — write correctness, self-view no-op, retention-ready schema.

**Deliverable:** backend primitives and tests green. No user-visible change.

---

## Phase 2 — Member profile Permissions tab scaffold

Goal: the three sub-tabs render, routes exist, default `Assignments` sub-tab shows current assignments read-only.

### Modified files

- `app/modules/Members/Controllers/MemberTabsController.php` — replace the existing read-only Roles tab with a delegating call into the new Permissions module's tab renderer.
- `app/modules/Members/templates/view.html.twig` — relabel the tab "Permissions" (i18n key) and wire to the new tab.

### New files

- `app/modules/Permissions/Controllers/PermissionsTabController.php` — routes:
  - `GET  /members/{memberId}/permissions` → redirect to `/assignments` sub-tab
  - `GET  /members/{memberId}/permissions/assignments`
  - `GET  /members/{memberId}/permissions/effective`
  - `GET  /members/{memberId}/permissions/explain`
- `app/modules/Permissions/templates/tab/layout.html.twig` — the three-sub-tab chrome, HTMX-enabled for sub-tab switching without full-page reload.
- `app/modules/Permissions/templates/tab/assignments.html.twig` — the default landing: list of current assignments (active + future), separate section for expired (collapsed by default).
- `app/modules/Permissions/templates/tab/effective.html.twig` — placeholder in this phase.
- `app/modules/Permissions/templates/tab/explain.html.twig` — placeholder in this phase.

### Integration with view-switcher

The assignments list is scope-filtered: assignments whose scope node is outside the admin's active scope render as a single aggregated *"+N additional assignments outside your current scope"* banner. Details are never exposed. This is the switcher's scope-containment rule applied to this surface.

Self-view mode (viewing own profile) overrides scope filtering — you always see all of your own assignments regardless of active admin scope. The self-view also hides destructive actions (no End buttons, no grant drawer).

### Tests (Phase 2)

- `tests/Modules/Permissions/PermissionsTabTest.php` — renders for admin-viewing-other, admin-viewing-self, non-admin-viewing-self, non-admin-viewing-other (should 403).
- E2E: `tab-rendering.spec.ts` — the three sub-tabs navigate correctly; out-of-scope banner appears when expected; self-view suppresses destructive actions.

**Deliverable:** the Permissions tab is reachable and reflects current assignments. No grant or explain functionality yet.

---

## Phase 3 — Grant workflow (inline + sensitive drawer + impact diff)

Goal: the #1 job-to-be-done ships. Routine grants use the inline row; sensitive grants use the drawer with impact preview.

### Modified files

- `app/modules/Permissions/Controllers/AssignmentsController.php` (existing) — the `store` action gains a sensitivity check via `CapabilityRegistry`: if the role being granted confers any sensitive capability, the controller expects a `confirmed_impact_hash` parameter matching the diff that was shown. This prevents CSRF-style "confirm a different change" attacks.
- Delegation check: role dropdown options filter to roles whose capability set is a subset of the granting admin's current capabilities (Q4). Super-admins (flag `is_super_admin = 1` on any role they hold) see every role.

### New files

- `app/modules/Permissions/templates/tab/_grant_inline.html.twig` — the inline row: role dropdown, scope node picker (filtered to admin's writable nodes), start date (default today), end date (defaulted per role's `term_policy`). Submits via HTMX.
- `app/modules/Permissions/templates/tab/_grant_drawer.html.twig` — the drawer: same fields as inline, plus:
  - Sensitivity warning banner listing which capabilities of the role are sensitive.
  - Impact preview block: *"Granting this will give Jane these capabilities at these nodes that she doesn't currently have: …"*. Rendered from `AccessInspector::diffForGrant()`.
  - Required confirm checkbox: *"I understand this grants Jane sensitive access."*
  - Hidden `confirmed_impact_hash`.

### Routing

Both flows POST to `/admin/roles/assignments/{userId}` (existing endpoint). The controller decides to accept or to reject-with-diff based on whether the role is sensitive and whether the hash is present.

### Tests (Phase 3)

- Unit: `AssignmentsControllerTest` — delegation rule (non-super-admin can't grant roles with capabilities they lack); sensitivity rule (drawer required, hash validated).
- Unit: `AccessInspectorTest::diffForGrantTest` — diff computation for various starting states.
- E2E: `grant-inline.spec.ts` — routine grant via inline row.
- E2E: `grant-sensitive-drawer.spec.ts` — sensitive grant triggers drawer, preview renders correctly, confirm proceeds.
- E2E: `grant-delegation.spec.ts` — non-super-admin cannot select roles they don't hold; super-admin can.

**Deliverable:** admins can grant roles. Routine grants are fast; sensitive grants surface their impact before commit.

---

## Phase 4 — Effective matrix + provenance

Goal: the "what can this person actually do?" view (`Effective` sub-tab) lands.

### Modified files

- `app/modules/Permissions/templates/tab/effective.html.twig` — the matrix rendering.
- `app/modules/Permissions/Controllers/PermissionsTabController.php` — `GET /members/{memberId}/permissions/effective` returns the matrix via `PermissionResolver::effectiveMatrix()`. Fires `PermissionAuditService::recordMatrixView()`.

### Matrix rendering

- Two pivots via a toggle: **By node** (default — rows are nodes the user has access at; expanding a row shows capabilities held there) and **By capability** (rows are capabilities; expanding shows the nodes where held).
- Per-cell states: granted direct / granted inherited / denied / inapplicable.
- Provenance popover on any granted cell: rendered by HTMX fetch to `GET /members/{memberId}/permissions/effective/provenance?capability=X&node=Y`. Calls `PermissionResolver::explain()` and formats the contributing assignments.
- Filters: active only / include expired / include future. Direct-only / include inherited.

### Scope filtering

The matrix restricts to nodes inside the admin's active scope. If the user has assignments at nodes outside that scope, a banner at the top says *"Effective permissions outside your current scope are hidden."* No cell, no hint of which capabilities exist there.

### Tests (Phase 4)

- Integration: matrix produced for varied user profiles (single-scope leader, multi-scope coordinator, super-admin, parent with only self-access).
- Integration: scope-filter boundary respected.
- E2E: `effective-matrix.spec.ts` — renders, pivots switch, provenance popover loads and lists contributing assignments.

**Deliverable:** admins can answer "what does Jane currently have, and where from?" definitively.

---

## Phase 5 — Explain tool (3 mounts)

Goal: the #1b job (troubleshooting) ships. Three entry points, one component.

### New files

- `app/modules/Permissions/Controllers/ExplainController.php` — routes:
  - `GET  /admin/permissions/explain` — standalone page (Admin → Permissions → Explain). Inputs: person search, capability dropdown, optional target (node or record).
  - `GET  /members/{memberId}/permissions/explain` — the sub-tab. Pre-fills subject = this member.
  - `GET  /records/{type}/{id}/access/explain` — the record-context mount, wired from the record view's "Access" button. Pre-fills target = this record's node.
  All three render the same partial and call `AccessInspector::explain()`.
- `app/modules/Permissions/templates/explain/standalone.html.twig`
- `app/modules/Permissions/templates/explain/_result.html.twig` — the shared result component with prose/trace toggle.

### Modified files

- `app/modules/Members/templates/view.html.twig` — add "Access" button near the member's top info, for admins with `members.read`.
- `app/modules/Events/templates/event/view.html.twig` — same.
- `app/modules/Communications/templates/article/view.html.twig` — same.

(Record-context mount is scoped to the record types that have per-record capability checks. Members, events, articles for v1; extend later.)

### Output

- **Prose mode** (default): narrative explanation. *"Sam has `members.read` at Group A via role Section Leader (assigned 2024-03-01, ends 2027-03-01). Sam does not have `members.write` at Group A — no assigned role confers it at Group A or any ancestor."*
- **Trace mode**: structured table. Columns: role held, conferring scope node, start date, end date, capability in question granted (✓/✗). One row per assignment the user holds.

### Audit

Every explain query logged via `PermissionAuditService::recordExplainQuery()` with subject user, capability, node/record.

### Tests (Phase 5)

- Integration: explain output correctness against seeded scenarios.
- E2E: each of the three mounts renders correctly, prose/trace toggle works.
- E2E: standalone mount search respects scope containment.

**Deliverable:** admins can answer "why can't X do Y?" from whichever context they're in.

---

## Phase 6 — Node page People panel + reverse lookup

Goal: the node-centric view for task #2 (capability audit) and the reverse-lookup entry point.

### Modified files

- `app/modules/OrgStructure/templates/node/view.html.twig` — add a "People & roles" section.
- `app/modules/OrgStructure/Controllers/NodesController.php` — inject the people-panel data.

### New files

- `app/modules/Permissions/templates/node_panel/_people.html.twig` — the panel:
  - Active assignments list scoped to this node, plus a toggle for "Include inherited from ancestors."
  - "Assign someone" button opens the grant drawer pre-filled with scope = this node.
  - Capability-filter dropdown: select a capability, the list narrows to assignments that confer it (direct or inherited). Fires `PermissionAuditService::recordReverseLookup()` when used.
- `app/modules/Permissions/Services/NodePermissionsService.php` — the data layer for the panel.

### Scope filtering

Admins see only assignments whose scope is within their active scope. Out-of-scope aggregates as a count banner.

### Tests (Phase 6)

- Integration: panel for varied node positions in the org tree.
- E2E: `node-people-panel.spec.ts` — panel renders, capability filter narrows correctly, assign button pre-fills drawer.

**Deliverable:** node-centric permissions view. "Who can do X here?" answerable from the node page.

---

## Phase 7 — Capability registry UI

Goal: the governance surface. Every capability documented, conferring roles listed, current holders enumerated.

### New files

- `app/modules/Permissions/Controllers/CapabilityRegistryController.php`:
  - `GET /admin/permissions/capabilities` — list view, grouped by category, with sensitivity badges.
  - `GET /admin/permissions/capabilities/{id}` — detail page for one capability.
- `app/modules/Permissions/templates/registry/index.html.twig`
- `app/modules/Permissions/templates/registry/detail.html.twig` — three sections per capability:
  1. **Description + metadata** (category, sensitivity flag).
  2. **Roles conferring it** — links to role edit pages.
  3. **Current holders** — list of users currently holding the capability, grouped by node. Via `PermissionResolver::whoCan()`. Scope-filtered per caller.

### Nav

Add "Capabilities" to Admin → Permissions submenu alongside Roles and Monitoring.

### Audit

Capability registry detail views log via `PermissionAuditService::recordCapabilityRegistryView()` and `recordReverseLookup()` for the holder list.

### Tests (Phase 7)

- Integration: registry entries match hardcoded list; category grouping correct; holder enumeration correct and scope-filtered.
- E2E: browsable registry, sensitivity visible, navigation to conferring roles works.

**Deliverable:** every capability has a discoverable page. Governance queries ("who can do X?") answerable globally.

---

## Phase 8 — Monitoring page + notifications + cron

Goal: expiring/expired assignments are governed, not orphaned. Hard-stop enforced.

### New files

- `app/modules/Permissions/Controllers/MonitoringController.php`:
  - `GET /admin/permissions/monitoring` — filtered view of assignments: expiring in 30 / 7 / 1 days / expired (last 90 days).
- `app/modules/Permissions/templates/monitoring/index.html.twig` — lists with bulk-action scaffolding (populated in Phase 11).
- `app/modules/Permissions/Services/ExpiryService.php`:
  - `findExpiringAssignments(int $days): array` — assignments crossing the window.
  - `enforceHardStops(): int` — called by cron; flips resolver state for any assignment past `end_date`. Actually, the resolver already filters by `end_date >= NOW()` — `enforceHardStops()`'s job is just to mark `end_reason = 'expired'` and fire audit entries for the transitions. No capability change is needed because the resolver never surfaces expired assignments.
  - `sendExpiryNotifications(int $daysAhead): int` — queues emails for the 30/7/1 day windows.

### Cron

- `cron/tasks/permissions_expiry.php` — runs daily. Calls `enforceHardStops()` and `sendExpiryNotifications(30)`, `sendExpiryNotifications(7)`, `sendExpiryNotifications(1)`.
- Registered in `cron/run.php`.

### Email templates

- `app/modules/Permissions/templates/email/expiry_warning.html.twig` — to assignee and original grantor.
- `app/modules/Permissions/templates/email/expired.html.twig` — on-expiry confirmation.

### Tests (Phase 8)

- Unit: `ExpiryServiceTest` — window boundaries correct, notification de-duplication via `expiry_notice_sent_at`.
- Integration: cron task dry-run produces expected email queue.
- E2E: `monitoring-page.spec.ts` — filters work, expiring and expired sections populated from seeded data.

**Deliverable:** assignments have lifecycle governance. Admins are notified before expiry; expired assignments lose effect at the right moment; a dashboard surfaces what needs attention.

---

## Phase 9 — Role edit propagation + cross-admin authority + grantor notifications

Goal: role definition changes are safe; inter-admin modifications preserve accountability.

### Modified files

- `app/modules/Permissions/Controllers/RolesController.php`:
  - `update` action: when `capabilities` change, compute `AccessInspector::diffForRoleEdit()`. If any user gains a capability, return a preview page requiring confirmation with a `confirmed_impact_hash`. Removals go straight through.
  - Super-admin role (`is_super_admin = 1`) — only super-admins can edit or create such a role. Enforced in the controller.
- `app/modules/Permissions/Controllers/AssignmentsController.php`:
  - `store`, `end`, `update`: scope-containment check — the admin's active scope must cover the assignment's scope node. Super-admin bypass.
  - `end`: if the assignment's `assigned_by` differs from the current admin, enqueue a `grantor_notified` email via `MailService`.

### New files

- `app/modules/Permissions/templates/email/grant_modified.html.twig` — to original grantor, explaining what was changed and by whom.
- `app/modules/Permissions/templates/role/_capability_diff_preview.html.twig` — the impact preview for role edits.

### Tests (Phase 9)

- Unit: role update blocks without confirmed hash when capabilities are added; proceeds when removed.
- Unit: assignment end/update denied when scope not covered; allowed for super-admin regardless.
- Integration: original grantor receives email when their grant is modified by another admin.

**Deliverable:** role edits don't silently escalate; admins can't reach beyond their scope; grantors always know when their grants change hands.

---

## Phase 10 — Node lifecycle: archive + merger wizard

Goal: org evolution is a first-class operation, not a data corruption risk.

### Modified files

- `app/modules/OrgStructure/Controllers/NodesController.php`:
  - `archive` action: sets `archived_at = NOW()` on the node. Calls `AssignmentsService::endAllAtNode($nodeId, reason: 'node_archived')` which end-dates every active assignment scoped there, records the reason, and fires audit entries.
  - `restore` action: clears `archived_at`. Does *not* re-activate assignments — those stay ended per Q13 (ii).
  - `merge` action: new endpoint that takes a source node and a target, opens the merger wizard.
- `app/modules/OrgStructure/Services/OrgService.php` — archive/restore/merge helpers; closure-table maintenance through both.

### New files

- `app/modules/OrgStructure/Controllers/MergerWizardController.php`:
  - `GET  /admin/org/merge` — pick source and target.
  - `GET  /admin/org/merge/plan` — per-assignment decision screen: for each active assignment at the source, offer *carry forward to target / end / rewrite (change role or dates)*.
  - `POST /admin/org/merge/commit` — applies all decisions in a transaction; fires audit entries with a common `merge_id` correlation token.
- `app/modules/OrgStructure/templates/merger/plan.html.twig` — table of per-assignment decisions with keyboard-friendly quick-select.

### Tests (Phase 10)

- Integration: archive ends active assignments and records reason; restore does not re-activate them.
- Integration: merger wizard's commit endpoint executes decisions atomically, produces correlated audit entries.
- E2E: `archive-node.spec.ts`, `merger-wizard.spec.ts`.

**Deliverable:** nodes can be archived safely and merged explicitly; no silent re-pointing, no orphan assignments.

---

## Phase 11 — Bulk operations

Goal: the seasonal-rhythm UX. Renewal, end, grant-one-role-to-many.

### Modified files

- `app/modules/Permissions/templates/monitoring/index.html.twig` — checkbox column; bulk action bar with *Renew* and *End*.
- `app/modules/Permissions/templates/tab/assignments.html.twig` — same, applied to the per-person assignments list (enables bulk-end of one person's assignments on offboarding).
- `app/modules/Permissions/Controllers/AssignmentsController.php`:
  - `POST /admin/permissions/bulk/renew` — renew selected assignments per their role's `term_policy`.
  - `POST /admin/permissions/bulk/end` — end selected on a common date.
  - `POST /admin/permissions/bulk/grant` — grant one role to many people at one node.

### New files

- `app/modules/Permissions/templates/bulk/_grant_picker.html.twig` — the multi-person picker: role + node (pre-filled from context when available), searchable member picker, confirm panel with aggregate impact diff.
- `app/modules/Permissions/Services/BulkAssignmentService.php` — orchestration: validates delegation rule *per person* (a user might already hold the role at this node), fires the aggregate impact diff, executes the batch in a transaction, records one audit entry with a manifest child table.

### Audit model

Bulk actions create one `audit_log` parent row per action with `actor`, `action`, timestamp, and a `bulk_manifest_id` pointing at a child table that lists the individual assignments affected. Forensics can query the parent to see "one admin decision" and drill into the manifest for details.

### Tests (Phase 11)

- Unit: bulk renew respects individual `term_policy`; bulk end rejects if scope containment fails for any one row.
- Unit: bulk grant delegation rule per-person; impact diff aggregate computed correctly.
- E2E: `bulk-renew.spec.ts`, `bulk-end.spec.ts`, `bulk-grant.spec.ts`.

**Deliverable:** seasonal operations (start-of-year, end-of-term, intake cohorts) take minutes instead of hours.

---

## Phase 12 — Self-service (read-only + self-explain)

Goal: Jane can answer her own "what can I do?" and "why can't I do X?" without an admin ticket.

### Modified files

- `app/modules/Permissions/Controllers/PermissionsTabController.php` — self-view mode: when `actor_user_id == target_user_id` and caller lacks `roles.read`, render read-only templates. Grant drawer hidden; inline add hidden; end buttons hidden.
- `app/modules/Permissions/Controllers/ExplainController.php` — self-explain: if caller lacks `roles.read`, the explain is allowed only when `subject = caller`. Record-context mount respects this (the caller can check their own access to a record, not someone else's).

### Tests (Phase 12)

- Integration: non-admin viewing own profile sees Permissions tab read-only; viewing another's profile gets 403.
- Integration: self-explain works; cross-user explain denied for non-admin.
- E2E: `self-service.spec.ts`.

**Deliverable:** help-desk deflection for the most common permissions ticket.

---

## Phase 13 — Audit extensions + retention

Goal: read-event auditing wired end-to-end; retention job runs.

### Modified files

- `app/modules/Admin/Controllers/AuditController.php` — new view for permission-read events, gated behind `permissions.audit.read`.
- `app/modules/Admin/templates/audit/permission_reads.html.twig` — filterable list (by actor, by subject, by event type, by date range).

### New files

- `cron/tasks/permission_audit_purge.php` — runs daily. `DELETE FROM permission_audit_reads WHERE created_at < NOW() - INTERVAL 1 YEAR`. Change events in `audit_log` are untouched.

### Tests (Phase 13)

- Unit: purge deletes rows older than 1 year; leaves newer rows.
- Integration: audit view respects `permissions.audit.read` capability; general `audit.read` doesn't suffice.

**Deliverable:** permission-read audit trail queryable by tightly-scoped admins; old read data automatically purged.

---

## Phase 14 — Polish

- Empty states for the matrix, people panel, monitoring page, capability holder lists — each with scope-aware messaging.
- i18n sweep: every string flagged through `lang/en.json`; sync any other language files.
- Accessibility: live-region announcement on grant/end confirmation, per switcher-plan pattern.
- Keyboard navigation: matrix pivots, drawer close, bulk-select.
- Mobile layout for Permissions tab (three sub-tabs collapse to a dropdown under 768px).
- Performance audit: matrix render on a seeded `--large` dataset (user with 20+ assignments across 5000-member org). Target: under 500ms server-time for the default active-only view; sub-tab switches via HTMX.
- Documentation: update `CLAUDE.md` with a "Permissions UI" section summarising the resolver API and the three tab pattern.

---

## Out of scope for v1

Captured in [permissions-ui-future.md](permissions-ui-future.md). Summary:

- CSV import for bulk assignments
- Bulk grant: many roles to one person
- Tiered sensitivity with multi-person approval
- Auto-remediation in the Explain tool
- Custom permission profile builder
- Audit log analytics / anomaly detection
- Policy-as-code segregation-of-duties rules
- Time-of-day / conditional access
- Third-party delegation ("grant on my behalf while I'm away")
- Full WCAG 2.1 AA audit (app-wide project)

---

## Open risks

1. **Performance of `effectiveMatrix` at scale.** A super-admin's matrix for themselves is capability-count × node-count; for a mature org this is thousands of cells. Mitigation: lazy-load provenance popovers (already planned); paginate by-node pivot when node count exceeds 50; measure under `--large` seed before release.

2. **Sensitivity flag drift.** When developers add new capabilities, they must remember to set the sensitivity flag. Mitigation: `CapabilityRegistry` defaults `sensitive = null` (not false); CI check fails the build if any capability has a null flag. Forces an explicit decision.

3. **Cross-admin notification fatigue.** If the grantor-notification email fires on every bulk-end, senior admins who granted a lot of assignments get flooded. Mitigation: bulk-end emits one email per grantor per bulk action, not per-assignment. Implemented in Phase 11's `BulkAssignmentService`.

4. **Merger wizard complexity.** The per-assignment decision UX could bog down on mergers with 50+ assignments. Mitigation: the wizard supports "apply this decision to all remaining" and groups assignments by role for batch decisioning. If still too slow in practice, defer to a post-v1 refinement.

5. **Audit read-table growth.** A busy org might log 10,000+ matrix views per month. Mitigation: the 1-year retention + the read table being separate from `audit_log` contains the impact. Add a table-size monitor to Admin → Monitoring.

6. **External-supporter feature dependency.** Q1's "external admins as members" assumption depends on the external-supporter node type shipping — that's a separate track. If it's not ready, non-member admins remain a gap. Mitigation: coordinate sequencing; if needed, a temporary fallback page for pure-user-no-member cases.

---

## Rough sequencing estimate

| Phase | Effort | Parallelisable? |
|-------|--------|-----------------|
| 1     | 2 PRs (DB + resolver, then inspector + audit service) | no (foundation) |
| 2     | 1 PR | no (depends on 1) |
| 3     | 1 PR | no (depends on 2) |
| 4     | 1 PR | yes (parallel with 5) |
| 5     | 1 PR | yes (parallel with 4) |
| 6     | 1 PR | yes (after 4) |
| 7     | 1 PR | yes (after 4) |
| 8     | 1 PR | yes (after 3) |
| 9     | 1 PR | yes (after 3) |
| 10    | 1 PR | yes (after 3) |
| 11    | 1 PR | yes (after 8) |
| 12    | 1 PR | yes (after 5) |
| 13    | 1 PR | yes (after 7) |
| 14    | rolling | ongoing |

Phases 1–3 are the critical path. Phases 4–13 can fan out across contributors once the foundation and grant flow ship.

---

## Post-implementation (not part of the feature PRs)

- Update `CLAUDE.md`: add a "Permissions UI" section documenting `CapabilityRegistry`, the resolver API, and the three-sub-tab pattern.
- Archive this plan doc with any deviations noted against each Q decision.
- Memory entry: summarise the 16 resolved decisions so future contributors don't re-litigate them.
- Revisit the future-work doc once per quarter; promote items as real demand appears.
