# GSAT Compliance Helper — Feature Brainstorm

Based on WOSM **Global Support Assessment Tool (GSAT) v3.0** (June 2023). GSAT has **10 dimensions, 105 criteria, 20 "major non-conformities"**, scored 0–3 during periodic audits. Goal: lightweight (low programming effort) features that deliver high compliance value to a National Scout Organization (NSO).

Key drivers from the standard:
- Audits need **evidence packs** assembled ~4 weeks before the audit date.
- Compliance is **document- and procedure-heavy** (policies, registers, minutes, reports).
- Many criteria require things to be "defined, implemented, **reviewed periodically**, and acted on" — recurring proof, not one-offs.
- 20 major criteria: 0101, 0204, 0301, 0302, 0313, 0401–0403, 0501, 0601, 0608, 0612, 0613, 0702, 0706, 0708, 0710, 0801, 0807, 0809.

---

## A. Evidence & document management (biggest audit-prep win)
1. **Criteria-to-evidence mapper** — CRUD over all 105 seeded criteria with status (compliant/partial/non-compliant), owner, last-reviewed date, and document links/uploads. *Very low effort, huge impact.*
2. **"Audit pack" exporter** — one-click ZIP/PDF bundle of all linked evidence + a coversheet per dimension.
3. **Document expiry & review reminders** — flag policies/documents past their review interval; email + dashboard alerts.
4. **Major-non-conformity dashboard** — RAG view of the 20 essential criteria so leadership sees red/amber/green instantly.

## B. Governance & board operations (Dimension 2)
5. **Board meeting register** — enforces 2–6 meetings/year (0210), attendance (0211), quorum (0212), proxy-vote limits (0213).
6. **Conflict of interest register** (0209) — annual digital declaration form, auto-renews, export-ready.
7. **Board composition checker** — computes %under-30 (≥40% per 0207), gender split, non-Scouting background (0217).
8. **Term-of-office tracker** (0208) — rotation, re-election limits, expiring mandates.

## C. Adults in Scouting (Dimension 6) — high "major" density
9. **Adult lifecycle register** — recruitment → induction → training → appraisal → retirement (0601).
10. **Safe from Harm training tracker** (0612) — blocks "interacts with youth" flag until SfH training complete.
11. **Background check register** (0613) — expiry dates + alerts.
12. **Annual appraisal reminders** (0604).

## D. Integrity & safeguarding (Dimension 4)
13. **Safeguarding incident reporting form** (0403) — routed to SfH coordinator with immutable audit log.
14. **Whistleblower intake** (0407) — anonymous form, restricted inbox.
15. **Code-of-conduct e-acknowledgement tracker** (0405).

## E. Financial transparency (Dimension 7)
16. **Revenue diversification calculator** (0701) — warns if any single source exceeds threshold over 3-year average.
17. **Liquidity ratio check** (0710).
18. **Procurement threshold helper** (0711) — forces competitive bidding above configurable threshold.

## F. Strategic & continuous improvement (Dimensions 3 & 10)
19. **KPI tracker** (0311) — strategic KPIs logged quarterly with trend charts.
20. **Risk register** (0313) — annual review reminder, pre-filled categories.
21. **Stakeholder map review reminder** (0306) — every 3 years.

## G. Communication & reporting (Dimension 5)
22. **Annual report builder** (0501 — major) — template pulling mission, board list, membership census, audited financials from other modules. High payoff because inputs already live in the app.
23. **Crisis-comms playbook** (0504) — stored, versioned, annual review reminder.

## H. Cross-cutting cheap wins
24. **Self-assessment wizard** — walk all 105 criteria, score 0–3, generate gap report. Mirrors assessor workflow.
25. **Standard browser** — searchable read-only view of the GSAT standard itself.
26. **Compliance trend chart** — store self-assessment scores over time (matches §1.4 encouraging periodic reapplication).

---

## Recommended minimum viable bundle
Smallest build, biggest payoff:
- **(1) Criteria-to-evidence mapper**
- **(3) Review reminders**
- **(4) Major-NC dashboard**
- **(24) Self-assessment wizard**

Essentially a CRUD app over a seeded list of 105 criteria + a reminder cron + a scoring screen. Covers ~80% of audit-prep pain.

## Deferred decisions
Tech stack, hosting, auth, multi-tenant vs single-NSO — all pending feature selection.
