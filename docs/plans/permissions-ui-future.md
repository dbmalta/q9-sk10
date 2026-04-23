# Permissions UI — Deferred Work

Companion to [permissions-ui-plan.md](permissions-ui-plan.md). Items below were deliberately excluded from v1. Each entry captures **what it is**, **why it was cut**, **what would trigger reconsidering it**, and **prerequisites** if picked up later.

The purpose of this doc is to prevent future contributors from re-litigating tradeoffs that were already reasoned through, and to give a grounded starting point if and when one of these items moves into scope.

---

## A. CSV import for bulk assignments

**What it is.** Upload a spreadsheet with columns *user, role, scope node, start date, end date*; system grants everything in one operation.

**Why cut from v1.** CSV imports are a classic source of indefinite edge cases (encoding, date formats, ambiguous identifiers, partial-failure semantics). The UX cost of "line 47 failed, what do you want to do?" dialogs dwarfs the feature's value at low volumes. Phase 11's bulk-grant (one role, many people) handles the largest genuine use case — cohort onboarding — without the import machinery.

**Trigger to reconsider.** When admins are repeatedly asking for it *and* the largest bulk-grant operation exceeds ~50 people per action (the point at which point-and-click with a multi-person picker genuinely gets tedious). Alternatively: if an onboarding partner integration emerges (national body sending warrant data, etc.) where the data already exists in CSV form.

**Prerequisites if picked up.**
- A well-defined identifier column (member email? external ID field? national-body registration number?). Resolve this first — imports that rely on fuzzy name-matching are worse than no imports.
- A dry-run / preview mode that applies the impact diff aggregate before commit, showing exactly what will change.
- Partial-failure semantics decided up front: all-or-nothing transaction, or best-effort with a failure report?

---

## B. Bulk grant: many roles to one person

**What it is.** Pick one person, select multiple role+scope combinations in one operation, commit.

**Why cut from v1.** Low-volume. New super-admins being set up might take five roles at once, and that happens twice a decade. Every other "many roles, one person" scenario is actually "one role, one person" repeated naturally through the per-person Assignments sub-tab, which is already fast enough.

**Trigger to reconsider.** Only if a recurring workflow emerges where one person legitimately needs 3+ roles granted atomically (e.g. a new super-admin onboarding flow that should be a single step rather than five clicks). Unlikely.

**Prerequisites if picked up.** Trivial — extends the existing grant drawer to accept multiple role+scope combinations with a shared impact diff.

---

## C. Tiered sensitivity with multi-person approval

**What it is.** Capabilities would carry a *level* (*standard / elevated / critical*) instead of a binary `sensitive` flag. Critical grants would require a second admin to approve before they take effect.

**Why cut from v1.** ScoutKeeper doesn't yet have capabilities that genuinely need two-person authorisation — the v1 capability set is about membership management, not financial disbursements or external data egress. The binary flag + impact diff is sufficient friction for the current risk surface. Building multi-person approval now means building a pending-approval queue, notification logic, timeout handling, and a whole adjacent UX surface that protects nothing that isn't already protected.

**Trigger to reconsider.** When new capabilities land that genuinely warrant it — the most likely candidate is *"export all member data including medical"* (a GDPR-sensitive operation that might reasonably require two signatures). Or when a real compliance regime (national Scout body, data protection regulator) mandates it.

**Prerequisites if picked up.**
- A pending-approval queue model (new table `assignment_approvals` with status, approver, decision-by, decision-at).
- Notification infrastructure for the second approver (already present via the email queue).
- A timeout policy (grant auto-denied after N days without second approval?).
- Upgrade from the hardcoded `sensitive: bool` to `sensitivity_level: enum`. Existing sensitive-flagged capabilities default to *elevated* (current behaviour unchanged); new *critical* capabilities opt in explicitly.

---

## D. Auto-remediation in the Explain tool

**What it is.** When the Explain tool returns *"Sam lacks `members.write` at Group A,"* it would additionally suggest: *"To fix, grant role X or role Y at Group A — you (the current admin) are allowed to grant role X."*

**Why cut from v1.** Significant additional work: requires reasoning about which roles confer a missing capability *and* filtering those against the caller's delegation rights *and* presenting the remediation as actionable UI. Pays off only if admins would rather click-through than think-through. In practice, experienced admins already know which role to grant; the Explain tool's job is to make the *diagnosis* precise, which is the harder part.

**Trigger to reconsider.** After 3–6 months of production use, if help-desk tickets show a pattern of *"I understand the problem, I don't know which role to grant to fix it."* Ticket data tells us; v1 ships without speculation.

**Prerequisites if picked up.**
- `CapabilityRegistry` already knows which roles confer each capability (via reverse index on role definitions).
- The delegation-filter function from the grant drawer is already built (Phase 3).
- UI work: a "Suggested remediation" panel on the Explain result view, with one-click launch into the grant drawer pre-filled.

---

## E. Custom permission profile builder

**What it is.** A meta-abstraction above roles: admins could define reusable "profiles" (e.g. *"section leader with safeguarding officer responsibilities"*) that bundle roles together, and assign profiles instead of individual roles.

**Why cut from v1.** Premature abstraction. Roles are already bundles of capabilities; profiles would be bundles of bundles, and every ScoutKeeper deployment would invent its own taxonomy. The cost of the extra indirection (every query, every audit entry, every grant flow has to resolve through an additional layer) isn't justified by the flexibility at v1 scale.

**Trigger to reconsider.** If an admin tenant emerges with 50+ roles and admins are copy-pasting role assignments in patterns. That's the signal that roles-as-bundles is leaking, and a higher-level abstraction earns its keep. Not before.

**Prerequisites if picked up.** Significant — introduces a new entity, migration path from existing role assignments, new UI. Treat as a minor epic.

---

## F. Audit log analytics / anomaly detection

**What it is.** Behavioural signals derived from the permission-audit reads table. *"Alert me when someone views Jane's permissions matrix more than 10× in a week"*, *"flag when an admin runs reverse-lookup queries for `medical.read` outside business hours"*, or machine-learning on access patterns.

**Why cut from v1.** Zero data to train or threshold on. v1 starts logging reads — meaningful signals need 6+ months of real operational baselines. Building detectors speculatively means building detectors tuned to seed data, which is a waste.

**Trigger to reconsider.** 6–12 months after Phase 13 ships. At that point, review the read log for genuine anomaly patterns (ideally triggered by a real incident or near-miss) and design specific detectors against observed behaviours rather than hypothetical ones.

**Prerequisites if picked up.**
- A meaningful read-log history (6+ months at production volume).
- A specific incident or near-miss as motivation — detectors without concrete threat models are decoration.
- A decision on where alerts land: email, a queue on the permissions monitoring page, integration with an external SIEM if the org has one.

---

## G. Policy-as-code / segregation-of-duties rules

**What it is.** Declarative rules enforced at grant time. Examples: *"No user may hold both `finance.write` and `member.delete` at any scope"*, *"Any user with `medical.read` must also hold `safeguarding.trained` within the last year"*, *"Only users with `org.write` at the district level may grant roles at group level"*.

**Why cut from v1.** ScoutKeeper's capability surface doesn't yet have enough cross-capability conflicts to justify a rules engine. The v1 scope-containment and strict-delegation rules (Q4, Q12) cover the common cases. A full policy engine is a classic case of "build it and they'll specify requirements" — rarely a good idea.

**Trigger to reconsider.** When a real policy requirement emerges that can't be expressed via roles alone — typically: compliance with an external framework (data protection law, national Scout body governance, insurer's requirements). Policy-as-code earns its keep when an auditor is asking "show me how you enforce X" and the answer needs to be declarative rather than conventional.

**Prerequisites if picked up.**
- A decision on the rule syntax: YAML config? DSL? GUI builder? (YAML is usually the right answer for something this rare.)
- Evaluation point — on grant, on role edit, on login, or continuously by cron?
- Remediation semantics — does a violated rule block the grant, warn, or just flag in the monitoring page?
- Integration with the impact diff — rules violations should show up in the preview.

---

## H. Time-of-day / conditional access

**What it is.** Capabilities gated by conditions beyond "user holds role at scope." Examples: *"medical data readable only 09:00–17:00"*, *"sensitive exports require IP from within the office network"*, *"capability X only active during a specific event date range"*.

**Why cut from v1.** Not a Scout-association need. Scout admins are volunteers who do their work on evenings and weekends; time-of-day restrictions would actively harm usability. IP restrictions don't match the "logged in from anywhere" pattern of volunteer access. No demand signal.

**Trigger to reconsider.** Essentially never for this product, unless the tenant profile changes dramatically (e.g. the system is adopted by professional organisations with office-hours security policies). Flag as "very unlikely" rather than "when conditions are right."

**Prerequisites if picked up.** A clear operational requirement and a named customer. Otherwise speculative.

---

## I. Third-party delegation

**What it is.** *"I'm going on holiday for two weeks — Tom can grant roles on my behalf during that time."* A user temporarily confers their delegation authority to another user with an expiry.

**Why cut from v1.** Niche. Scout admins typically either have a deputy already (who can be a super-admin) or can handle grant requests asynchronously. Building temporary delegation introduces a whole separate permission concept (delegated vs. direct authority), complicates the audit trail (grants made by X on behalf of Y), and is rarely used even when it exists.

**Trigger to reconsider.** Repeated explicit requests across multiple deployments, not single-tenant asks. Specifically: a pattern where a district-level admin routinely needs their group-level equivalents to grant roles during absences, and making all of them super-admins would be over-privileging.

**Prerequisites if picked up.**
- A new table `delegation_grants` (delegator, delegatee, scope, start, end).
- Audit model: every grant made under delegation records both the acting user and the delegating user.
- UX: a small banner in the grant drawer when the caller is acting under delegation ("You are granting on behalf of Kevin until 2026-05-01").
- Expiry enforcement via the same cron as assignment expiry.

---

## J. Full WCAG 2.1 AA audit

**What it is.** Formal accessibility audit of the entire Permissions UI surface against WCAG 2.1 AA success criteria.

**Why cut from v1 as a dedicated effort.** Same answer as the view-switcher plan: accessibility is an app-wide concern, not a per-feature effort. v1 follows the existing component patterns (Bootstrap 5.3, aria-live regions, keyboard navigation on interactive components) and is no worse than the rest of the app. A formal audit is better scheduled as a cross-cutting project with a specialist contractor rather than bolted onto one feature.

**Trigger to reconsider as a standalone effort.** When the app-wide accessibility project is kicked off. At that point, the Permissions UI gets audited alongside everything else.

**Prerequisites if picked up.** Specialist contractor or internal accessibility expertise; budget for remediation cycles (an audit that just produces a report without follow-through is waste).

---

## Appendix: items considered and explicitly dropped (not deferred)

Some ideas came up during planning and were rejected outright rather than deferred. Captured here so they don't re-surface:

- **Role version history / time-travel queries** (*"what did Jane hold on 2023-06-01?"*). The audit log already answers this; don't build a parallel system.
- **Frozen-capabilities model** (Q11 option b). Creates unrecoverable role drift; this is the wrong direction for any permissions system.
- **Admin-editable capability sensitivity flag** (Q16 option b). Security hole — flag stays in code.
- **Per-tenant custom capability catalogues.** Capabilities are a code concept; tenant flexibility is via roles, not via the capability list.
- **Hiding super-admin from self-view** (Q10 obscurity wrinkle). Weak security, creates invisible-power problems.
