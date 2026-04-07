# Business IA — Live Execution Lock (Program 01)

**Artifact:** single live cleanup, stale-task elimination, and **one** ordered execution lane for `BUSINESS-IA-CANONICAL-REBUILD-PROGRAM-01`.  
**Audit anchored:** 2026-04-07 against repo files under `system/` (no archive authority).  
**Status:** DONE — stale-task cleanup + surviving lane documented; law/backlog drift removed where contradicted by live code.

---

## 1. STATUS

**DONE** — This document records the file-by-file audit, eliminated stale instructions, the surviving backlog mapped to phases, the **single** next executable slice, drift guards, and verifier bundle. Implementation work **after** this artifact must start from **§6** only.

---

## 2. LIVE AUTHORITIES

**Source of truth (Business IA + runnable app)**

| File / area | Role |
|-------------|------|
| `system/docs/BUSINESS-IA-CANONICAL-LAW-01.md` | Frozen IA law (ten homes, ownership, definition ≠ record ≠ transaction ≠ policy). |
| `system/docs/BUSINESS-IA-CANONICAL-BACKLOG-01.md` | Phased task IDs 00A–10.2 + verifier bundle; now links here for “what is already live.” |
| `system/docs/MAINTAINER-RUNTIME-TRUTH.md` | Maintainer index: live vs historical surfaces, bootstrap chain, read-only scripts. |
| `README.md` (repo root) | Intro + pointers to `system/` and `archive/` (not module/route authority). |
| `system/README.md` | Runnable tree layout under `system/`. |
| `system/routes/web/*.php` | HTTP entry truth (if not registered, not live). |
| `system/shared/layout/base.php` | Tenant primary nav items, active-state families. |
| `system/public/assets/js/app-shell-nav.js` | Layout toggle only; nav items rendered from PHP. |
| `system/modules/settings/controllers/SettingsController.php` | `section=` + POST allowlist contracts. |
| `system/modules/settings/views/partials/shell.php` | Admin settings sidebar (control-plane copy). |
| `system/modules/settings/Support/SettingsShellSidebar.php` | Permission flags for settings-adjacent controllers. |

**Explicitly historical / non-live for Business IA execution**

| Surface | Role |
|---------|------|
| `archive/blueprint-reference/*` | Vision-era blueprint; **not** execution authority. |
| `archive/cursor-context/*` | Snapshots only. |
| `docs/ARCHITECTURE-RESET-2026-CANONICAL-ROADMAP.md` | **Separate program** (Foundation / tenant kernel queue). **Do not** use as Business IA task selector; **OUT-OF-SCOPE-FOR-CURRENT-PROGRAM** when choosing the next Business IA slice. |
| `docs/PLT-TNT-01-root-01-id-only-closure-wave.md` | Sealed wave evidence; not Business IA lane. |
| `system/docs/BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` | Historical reconciliation; global “no new pages” freeze there **does not** revoke Business IA surfacing tasks that law/backlog already allow (copy/nav honesty). |
| `handoff/*` | ZIP/build/release hygiene; not product IA execution. |
| `system/docs/SETTINGS-ENGLISH-CANONICALIZATION-AND-IA-CLEANUP-01.md` | 2026-03-24 English menu inventory; **sidebar structure has since been trimmed** (see verifiers); treat as **historical labeling** unless reconciled to `shell.php`. |

---

## 3. FILE-BY-FILE AUDIT LOG

*Verdicts: KEEP-AS-LIVE | UPDATE-LABELING | CLEANUP-REFERENCES | HISTORICAL-ONLY | NO-ACTION*

### A. Canonical truth / planning

| File | Verdict | Note |
|------|---------|------|
| `system/docs/BUSINESS-IA-CANONICAL-LAW-01.md` | UPDATE-LABELING | **Patched 2026-04-07:** §3.9 and §8 Phase 1 row aligned to live `base.php` + `GET /reports` (removed stale “no Reports in nav” gap). |
| `system/docs/BUSINESS-IA-CANONICAL-BACKLOG-01.md` | UPDATE-LABELING | **Patched:** link to this lock doc; Phase 1 open decisions marked resolved against live repo. |
| `system/docs/MAINTAINER-RUNTIME-TRUTH.md` | KEEP-AS-LIVE | Matches “code wins”; read-only script guidance consistent. |
| `README.md` (root) | KEEP-AS-LIVE | Correctly defers to `system/` and marks `archive/` historical. |
| `system/README.md` | CLEANUP-REFERENCES | **Patched:** maintainer path now `system/docs/MAINTAINER-RUNTIME-TRUTH.md` (was broken `docs/…`). |

### B. Primary nav / shell

| File | Verdict | Note |
|------|---------|------|
| `system/shared/layout/base.php` | KEEP-AS-LIVE | Ten `$navItems`; `$navIsCatalog`, `$navIsReports`, Team includes `/payroll`, Sales includes `/gift-cards`, Admin prefixes `/settings`+`/branches`+refund-review; `navSideIcons` count matches items. |
| `system/public/assets/js/app-shell-nav.js` | NO-ACTION | No hard-coded routes; parity comes from PHP-rendered links. |
| `system/shared/layout/platform_admin.php` | NO-ACTION | Platform plane; out of scope for tenant Business IA ten-home law. |

### C. Admin / settings boundary

| File | Verdict | Note |
|------|---------|------|
| `system/modules/settings/controllers/SettingsController.php` | KEEP-AS-LIVE | Re-audit before any `section` / POST allowlist work; law freezes contracts. |
| `system/modules/settings/views/index.php` | KEEP-AS-LIVE | `$title` / `$settingsPageTitle` = Admin; workspace sections; membership help text references Catalog/Clients (aligned). |
| `system/modules/settings/views/partials/shell.php` | KEEP-AS-LIVE | Control-plane sidebar only; module launcher `data-group` nodes absent (per verifiers). |
| `system/modules/settings/Support/SettingsShellSidebar.php` | KEEP-AS-LIVE | Permission map for sibling settings controllers; not a second nav rail in views. |
| `system/routes/web/register_settings.php` | KEEP-AS-LIVE | GET/POST `/settings` + adjacency routes unchanged. |
| `system/routes/web/register_branches.php` | KEEP-AS-LIVE | Branch registry under Admin family active state. |

### D. Canonical home touchpoints

| File / area | Verdict | Note |
|-------------|---------|------|
| `system/routes/web/register_clients.php` | KEEP-AS-LIVE | Client tabs + permission matrix comments match law. |
| `system/routes/web/register_payroll.php` | KEEP-AS-LIVE | Payroll ops routes; highlight under Team in `base.php`. |
| `system/routes/web/register_reports.php` | KEEP-AS-LIVE | `GET /reports` + JSON report GETs; real endpoints only. |
| `system/modules/services-resources/views/index.php` | UPDATE-LABELING | Hub titled Catalog; package/membership cards honest; **lead line still lists gift cards alongside definitions** — Phase 3.1 remainder. |
| `system/modules/sales/views/partials/sales-workspace-shell.php` | KEEP-AS-LIVE | Subtitle matches money-movement framing; verifier-green. |
| `system/modules/clients/views/` (tree) | KEEP-AS-LIVE | No fake report routes found; Phase 5 aggregation still open. |
| `system/modules/packages/views/` | KEEP-AS-LIVE | Definitions vs client-packages split exists; Phase 4.2 polish optional. |
| `system/modules/memberships/` (views used by routes) | KEEP-AS-LIVE | Plan vs client-membership surfaces separated in nav state. |
| `system/modules/gift-cards/` | KEEP-AS-LIVE | Sales workspace shell; operational copy consistent with Sales home. |
| `system/modules/reports/` | KEEP-AS-LIVE | HTML index lists only registered GET paths. |
| `system/modules/payroll/` | KEEP-AS-LIVE | Routes under `/payroll/*`; active state on Team. |

### E. Task / proof / handoff surfaces

| File | Verdict | Note |
|------|---------|------|
| `handoff/*.ps1`, `handoff/*.sh`, `handoff/*.txt` | HISTORICAL-ONLY | Release/ZIP; not Business IA execution. |
| `handoff/POST-FOUNDATION-ZIP-TRUTH-AUDIT-AND-SAAS-STRENGTH-GAP-MAP-01.md` | HISTORICAL-ONLY | Post-foundation audit; separate from IA menu program. |
| `docs/ARCHITECTURE-RESET-2026-CANONICAL-ROADMAP.md` | HISTORICAL-ONLY | **For Business IA:** do not merge queues — Foundation live slot is a different program. |
| `docs/FOUNDATION-*.md`, `docs/PLT-TNT-01-root-01-id-only-closure-wave.md` | HISTORICAL-ONLY | Same as above for IA task pick. |
| `system/docs/SETTINGS-ENGLISH-CANONICALIZATION-AND-IA-CLEANUP-01.md` | HISTORICAL-ONLY | Pre-dates current `shell.php` (no sidebar module launchers). |
| `system/docs/BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` | HISTORICAL-ONLY | Marked historical in its own header; backbone ordering not Business IA lane. |
| `system/scripts/read-only/verify_business_nav_entry_clarity_safe_lane_02.php` | KEEP-AS-LIVE | Regression authority for nav/catalog/reports honesty. |
| `system/scripts/read-only/verify_catalog_growth_subsection_business_clarity_03.php` | KEEP-AS-LIVE | Catalog + subsection clarity. |
| `system/scripts/read-only/verify_admin_ia_business_first_truth_01.php` | KEEP-AS-LIVE | Admin boundary + contracts. |

---

## 4. STALE / CONFLICTING TASKS ELIMINATED

| Source | Task / instruction (summary) | Why stale / conflicting | Disposition |
|--------|------------------------------|-------------------------|-------------|
| `BUSINESS-IA-CANONICAL-LAW-01.md` §3.9 (pre-patch) | “**Current gap:** no entry in `$navItems`” for Reports | Live `base.php` includes Reports; `GET /reports` exists. | **CONTRADICTS-LAW** (resolved by doc patch same PR as this lock). |
| `BUSINESS-IA-CANONICAL-LAW-01.md` §8 Phase 1 (pre-patch) | “add **Catalog** and **Reports**” as future-only | Already implemented; implied future work misleads implementers. | **SUPERSEDED** (resolved by Phase 1 row wording update). |
| `BUSINESS-IA-CANONICAL-BACKLOG-01.md` | Task **1.1** acceptance: add Catalog/Reports, 8→10 icons | Ten items + ten icons + `/reports` href present in `base.php`. | **ALREADY-DONE** |
| `BUSINESS-IA-CANONICAL-BACKLOG-01.md` | Task **1.2** (Catalog not under Admin active state; payroll not under Admin) | `$navIsCatalog`, `$navIsReports`, Team includes `/payroll`; `settingsActivePrefixes` excludes `/memberships` plan paths. | **ALREADY-DONE** |
| `BUSINESS-IA-CANONICAL-BACKLOG-01.md` | Task **1.3** Reports entry strategy (honest index) | `register_reports.php` + `ReportController::index` + views hub list all live GETs; nav points to `/reports`. | **ALREADY-DONE** |
| `BUSINESS-IA-CANONICAL-BACKLOG-01.md` | Task **2.1** (remove Admin as operational launcher hub) | `shell.php` has no module launcher `data-group`s; subtitle states control-plane; verifiers enforce. | **ALREADY-DONE** |
| `BUSINESS-IA-CANONICAL-BACKLOG-01.md` | Task **6.1** (payroll highlights Team) | `$navIsTeam` includes `/payroll`. | **ALREADY-DONE** |
| `BUSINESS-IA-CANONICAL-BACKLOG-01.md` | Task **7.2** Reports home | Same delivery as **1.3** / `GET /reports` hub. | **DUPLICATE** (of 1.3; treat as one delivered slice). |
| `BUSINESS-IA-CANONICAL-BACKLOG-01.md` | Open decisions § Reports href + Catalog active family | Resolved in live code; keeping them “open” causes parallel planning. | **SUPERSEDED** (resolved in backlog text 2026-04-07). |
| `BUSINESS-IA-CANONICAL-BACKLOG-01.md` | Task **1.0** Pre-flight nav audit as blocking | Superseded by this lock + verifiers + live `base.php` read. | **SUPERSEDED** (optional note-only if ever needed). |
| `docs/ARCHITECTURE-RESET-2026-CANONICAL-ROADMAP.md` | “Only FOUNDATION-A7 PHASE-2 is LIVE” (global) | Conflicts with **parallel** Business IA program if read as “only allowed work in repo.” | **OUT-OF-SCOPE-FOR-CURRENT-PROGRAM** — Business IA uses law/backlog **this** lock; Foundation uses its charter. |
| `system/docs/BACKLOG-CANONICALIZATION-…01.md` | Product freeze / “do not add new pages” | Business IA phases are **surfacing** alignment on existing routes; must not block law-backed copy/nav tasks. | **OUT-OF-SCOPE-FOR-CURRENT-PROGRAM** when executing Business IA. |
| `system/docs/SETTINGS-ENGLISH-CANONICALIZATION-…01.md` | Sidebar lists operational launchers (Spaces, Staff, Packages, …) | Current `shell.php` removed those nodes; doc reads as live sidebar spec. | **HISTORICAL-NOT-LIVE** |

---

## 5. SURVIVING TASKS — LOCKED ORDER

Strict **phase order**; within a phase, row order. Status applies to **remaining** work only.

| Order | ID | Task | Phase | Status |
|------:|----|------|-------|--------|
| 1 | 2.2 | Section honesty pass — each `section=` area describes policy/control; no new sections without allowlist task | 2 | **NEXT** |
| 2 | 3.1 | Catalog hub: lead + cards — definitions vs client-record vs Sales (gift cards de-emphasized / discovery per law) | 3 | **LATER** |
| 3 | 3.2 | Secondary “back to catalog” / breadcrumb consistency | 3 | **LATER** |
| 4 | 4.1 | Sales workspace copy tightening (if any gaps after 3.x) | 4 | **LATER** |
| 5 | 4.2 | Packages views: definition vs client-package language | 4 | **LATER** |
| 6 | 5.1 | Client profile: aggregate memberships / packages / gift cards / balance **where data exists** | 5 | **LATER** |
| 7 | 5.2 | Deep links from client row to client-held surfaces | 5 | **LATER** |
| 8 | 6.2 | Admin payroll **policy** copy alignment (runs stay Team) | 6 | **LATER** |
| 9 | 7.1 | Report module audit (inventory actions/templates JSON vs HTML) | 7 | **LATER** |
| 10 | 7.3 | VAT guide position: Reports vs Admin honesty | 7 | **LATER** |
| 11 | 8.1 | Permission → home map | 8 | **LATER** |
| 12 | 8.2 | Hide dead primary-nav homes | 8 | **LATER** |
| 13 | 9.1 | Breadcrumb pass | 9 | **LATER** |
| 14 | 9.2 | Tab labels pass | 9 | **LATER** |
| 15 | 10.1 | Empty states / misleading CTAs | 10 | **LATER** |
| 16 | 10.2 | Final verifier sweep | 10 | **LATER** |

**Blocked-by-prior-slice:** 3.x blocked until **2.2** closes for the phase-ordered lane (no skipping Phase 2 remainder).

---

## 6. SINGLE LIVE EXECUTION LANE

**Next task (only):** **Backlog 2.2 — Section honesty pass** (`BUSINESS-IA-CANONICAL-BACKLOG-01.md`).

**Why this is next:** Phases **0–1** deliverables for nav/Reports/Catalog active-state are **already live** and verifier-backed. Phase **2.1** shell boundary is **already live**. The **only** remaining Phase 2 backlog row is **2.2** before any Phase 3 Catalog copy work — preserves law phase ordering and avoids skipping Admin honesty.

**Re-audit before touching:**  
`system/modules/settings/controllers/SettingsController.php`, `system/modules/settings/views/index.php`, `system/modules/settings/views/partials/shell.php`, and any partial rendered per `section=` (establishment screens, payment-settings, public_channels block, etc.).

**Non-goals for 2.2:** No new `section=` keys; no `SettingsController` POST allowlist changes; no route or permission renames; no `public_channels` / `PUBLIC_CHANNELS_WRITE_KEYS` split; no database migrations.

---

## 7. DRIFT GUARDS

Until a **new** scoped task explicitly permits them, Cursor / implementers **must refuse**:

- Renaming route paths, HTTP methods, or permission keys used by Business IA surfaces.
- Changing `SettingsController` POST bodies or `section=` allowlists without a dedicated contract task.
- Treating `archive/*` or root `docs/ARCHITECTURE-RESET-*` as authority for **Business IA** task selection.
- Re-adding **module launcher hubs** inside Admin settings sidebar (regresses `verify_admin_ia_business_first_truth_01.php`).
- Inventing report URLs or dashboard metrics not backed by `register_reports.php`.
- Running a **second** parallel Business IA lane (e.g. Phase 5 client aggregation while Phase 2.2 is open) — finish **2.2** first.

---

## 8. VERIFIER BUNDLE

After any nav / shell / catalog hub / Admin settings copy change, these **must** exit `0`:

```bash
php system/scripts/read-only/verify_business_nav_entry_clarity_safe_lane_02.php
php system/scripts/read-only/verify_catalog_growth_subsection_business_clarity_03.php
php system/scripts/read-only/verify_admin_ia_business_first_truth_01.php
```

---

## 9. FINAL EXECUTION RECOMMENDATION

Execute **only** backlog task **2.2** next: read every live settings `section=` surface for policy/control honesty, adjust copy where a tab still implies operational CRUD ownership that belongs in Catalog, Sales, Clients, or Team, and re-run the three read-only verifiers until they exit zero — do not start Phase 3 Catalog hub refinements or client aggregation until Phase 2 is closed.

---

## Document control

- **One cleanup doc:** this file is the program’s live execution lock; do not add parallel “status” markdown for Business IA.  
- **Updates:** bump anchor date and STATUS if the surviving lane changes after future merges.
