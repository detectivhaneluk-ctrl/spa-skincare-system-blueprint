# Business IA — Live Execution Lock (Program 01)

**Artifact:** single live cleanup, stale-task elimination, and **one** ordered execution lane for `BUSINESS-IA-CANONICAL-REBUILD-PROGRAM-01`.  
**Audit anchored:** 2026-04-07 against repo files under `system/` (no archive authority).  
**Status:** DONE — **2026-04-07:** Phase **4.2** **closed** (`BUSINESS-IA-PHASE-4-2-PACKAGE-PLACEMENT-STERILE-CLOSURE-01`); **next** single lane **Phase 5.1** (see §5–§6, §12).

---

## 1. STATUS

**DONE** — Audit + elimination log remain valid. **2026-04-07:** Phase **4.2** **closed** (package plan vs client-held placement copy — §12); next **implementation** slice is **5.1** only (client profile aggregation — per backlog).

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
| `README.md` (root) | KEEP-AS-LIVE | **2026-04-08:** English fence above Armenian body; goals header marked historical; aligns with ten-home law (no settings-centered execution read). |
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
| `system/modules/settings/views/index.php` | KEEP-AS-LIVE | **2026-04-07:** Section headings/help reframed as policy/defaults; contracts unchanged. |
| `system/modules/settings/views/partials/shell.php` | KEEP-AS-LIVE | **2026-04-07:** Sidebar title **Admin**; label **Policies and defaults**; summary **All sections**; subtitle control-plane only. |
| `system/modules/settings/Support/SettingsShellSidebar.php` | KEEP-AS-LIVE | Permission map for sibling settings controllers; not a second nav rail in views. |
| `system/routes/web/register_settings.php` | KEEP-AS-LIVE | GET/POST `/settings` + adjacency routes unchanged. |
| `system/routes/web/register_branches.php` | KEEP-AS-LIVE | Branch registry under Admin family active state. |

### D. Canonical home touchpoints

| File / area | Verdict | Note |
|-------------|---------|------|
| `system/routes/web/register_clients.php` | KEEP-AS-LIVE | Client tabs + permission matrix comments match law. |
| `system/routes/web/register_payroll.php` | KEEP-AS-LIVE | Payroll ops routes; highlight under Team in `base.php`. |
| `system/routes/web/register_reports.php` | KEEP-AS-LIVE | `GET /reports` + JSON report GETs; real endpoints only. |
| `system/modules/services-resources/views/index.php` | KEEP-AS-LIVE | **2026-04-07:** Lead + gift-card card = Sales discovery; definitions vs Sales explicit. |
| `system/modules/sales/views/partials/sales-workspace-shell.php` | KEEP-AS-LIVE | **2026-04-07:** Default subtitle = one financial workspace; second line links Reports gift-card liability (measurement); `Invoices, checkout, payments` substring retained for `verify_business_nav_entry_clarity_safe_lane_02.php` **E1**. |
| `system/modules/clients/views/` (tree) | KEEP-AS-LIVE | No fake report routes found; Phase 5 aggregation still open. |
| `system/modules/packages/views/` | KEEP-AS-LIVE | **2026-04-07:** Plan definitions + client-held screens teach Catalog vs Clients vs Sales checkout; `verify_catalog_growth_subsection_business_clarity_03.php` **H10–H16**, **I9–I16**. |
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
| `system/scripts/read-only/verify_settings_control_plane_no_operational_launcher_hub_01.php` | KEEP-AS-LIVE | **2026-04-07:** S9 needle follows sidebar label **Policies and defaults**. |

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
| 1 | 2.2 | Section honesty pass — each `section=` area describes policy/control; no new sections without allowlist task | 2 | **CLOSED** (2026-04-07) |
| 2 | 3.1 | Catalog hub: lead + cards — definitions vs client-record vs Sales (gift cards de-emphasized / discovery per law) | 3 | **CLOSED** (2026-04-07) |
| 3 | 3.2 | Secondary “back to catalog” / breadcrumb consistency | 3 | **CLOSED** (2026-04-08) |
| 4 | 4.1 | Sales workspace copy tightening (if any gaps after 3.x) | 4 | **CLOSED** (2026-04-07) |
| 5 | 4.2 | Packages views: definition vs client-package language | 4 | **CLOSED** (2026-04-07) |
| 6 | 5.1 | Client profile: aggregate memberships / packages / gift cards / balance **where data exists** | 5 | **NEXT** |
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

**Blocked-by-prior-slice:** none — through **4.2** closed; execute **5.1** next.

---

## 6. SINGLE LIVE EXECUTION LANE

**Next task (only):** **Phase 5.1** — Client profile surfaces (aggregates **where data exists**) per [`BUSINESS-IA-CANONICAL-BACKLOG-01.md`](BUSINESS-IA-CANONICAL-BACKLOG-01.md) row **5.1**.

**Previous slice (closed):** Phase **4.2** — closure record **§12**. Prior: **4.1** — **§11**; **3.2** — **§10** (**§10.7**).

---

## 7. DRIFT GUARDS

Until a **new** scoped task explicitly permits them, Cursor / implementers **must refuse**:

- Renaming route paths, HTTP methods, or permission keys used by Business IA surfaces.
- Changing `SettingsController` POST bodies or `section=` allowlists without a dedicated contract task.
- Treating `archive/*` or root `docs/ARCHITECTURE-RESET-*` as authority for **Business IA** task selection.
- Re-adding **module launcher hubs** inside Admin settings sidebar (regresses `verify_admin_ia_business_first_truth_01.php`).
- Inventing report URLs or dashboard metrics not backed by `register_reports.php`.
- Running a **second** parallel Business IA lane (e.g. Phase 6 while **5.1** is the named next slice) — finish **5.1** first unless re-scoped.

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

Execute **only** **Phase 5.1** next per backlog. **§12** documents closed **4.2**; **§11** closed **4.1**; **§10** closed **3.2** (**§10.7**).

---

## 10. PHASE 3.2 — LANE PREP + CLOSURE (historical contract)

*Prep locked: 2026-04-08 (`README-DRIFT-CLEANUP-AND-PHASE-3-2-PREP-01`). **Implementation closed** same program: `BUSINESS-IA-PHASE-3-2-CATALOG-WAYFINDING-CLOSURE-01` — see **§10.7**.*

### 10.1 NEXT SINGLE TASK

| Field | Value |
|--------|--------|
| **Phase / ID** | Phase **3** — Backlog row **3.2** |
| **Task name** | Secondary “back to catalog” / breadcrumb consistency |
| **Business goal** | Every catalog-subsection surface that lifts the operator back toward the hub must name **Catalog** and `GET /services-resources` honestly, with no resurrected **Services & Resources** product label on live wayfinding. |

### 10.2 EXACT FILES TO RE-AUDIT BEFORE TOUCHING

Read each file fully before editing; order is suggestion only.

1. `system/modules/services-resources/views/services/index.php`  
2. `system/modules/services-resources/views/services/show.php`  
3. `system/modules/services-resources/views/services/_wizard_nav.php`  
4. `system/modules/services-resources/views/services/edit.php`  
5. `system/modules/services-resources/views/services/create.php`  
6. `system/modules/services-resources/views/services/step2.php`  
7. `system/modules/services-resources/views/services/step3.php`  
8. `system/modules/services-resources/views/services/step4.php`  
9. `system/modules/services-resources/views/services/_step1_form.php`  
10. `system/modules/services-resources/views/rooms/index.php`  
11. `system/modules/services-resources/views/rooms/show.php`  
12. `system/modules/services-resources/views/rooms/create.php`  
13. `system/modules/services-resources/views/rooms/edit.php`  
14. `system/modules/services-resources/views/equipment/index.php`  
15. `system/modules/services-resources/views/equipment/show.php`  
16. `system/modules/services-resources/views/equipment/create.php`  
17. `system/modules/services-resources/views/equipment/edit.php`  
18. `system/modules/services-resources/views/categories/index.php`  
19. `system/modules/services-resources/views/categories/show.php`  
20. `system/modules/services-resources/views/categories/create.php`  
21. `system/modules/services-resources/views/categories/edit.php`  
22. `system/modules/services-resources/views/index.php` (hub — only if a subsection change requires hub copy alignment)  
23. `system/scripts/read-only/verify_catalog_growth_subsection_business_clarity_03.php` (if new string anchors are added)

### 10.3 EXACT CONTRADICTIONS TO REMOVE

Each item is a **live** string or pattern observed in §10.2 files as of prep date; fix means **copy/wayfinding only** (hub target remains `GET /services-resources` unless law explicitly allows otherwise).

- **Breadcrumb / parent label `Services & Resources`** on service **show** and **wizard** chrome — contradicts primary nav label **Catalog** and hub title **Catalog** (`services/show.php`, `_wizard_nav.php`).
- **Back link text `← Services & Resources`** on **categories** index — contradicts Catalog naming (`categories/index.php`).
- **Internal “Back to …”-only chains** (e.g. detail → list without a **Catalog** hop) where the operator expectation per law is **Catalog → subsection → entity** — evaluate `rooms/show.php`, `rooms/edit.php`, `rooms/create.php`, `equipment/show.php`, `equipment/edit.php`, `equipment/create.php` for an optional **Catalog** uplink consistent with index pages (index pages already use `← Catalog` per verifier).
- **Any new** reintroduction of `← Services & Resources` or plain `Services & Resources` as the **product** name on user-visible wayfinding in these paths — forbidden; use **Catalog** for hub-level naming.

### 10.4 EXACT NON-GOALS

- No changes to `section=` / `SettingsController` / POST allowlists / permission keys / route path strings in `system/routes/web/register_*.php` (except fixing typos only if unrelated — **prefer no route edits**).
- No renaming of wizard path segments (`/step-2`, `/edit`, service id patterns) or POST action URLs.
- No scope expansion into **packages**, **memberships**, **gift-cards**, **sales**, **reports**, or **Admin** modules for this slice.
- No new pages or new GET routes; only copy and optional **additional** `a href="/services-resources"` (or existing paths) where it clarifies hierarchy.
- No reliance on `archive/*`, `handoff/*`, or `docs/ARCHITECTURE-RESET-2026-CANONICAL-ROADMAP.md` for decisions.

### 10.5 EXACT VERIFIER BUNDLE

**Mandatory after any `services-resources` view change in this slice (exit code `0` each):**

```bash
php system/scripts/read-only/verify_business_nav_entry_clarity_safe_lane_02.php
php system/scripts/read-only/verify_catalog_growth_subsection_business_clarity_03.php
php system/scripts/read-only/verify_admin_ia_business_first_truth_01.php
```

**Adjacent (recommended same PR if `shell.php` untouched — optional):**

```bash
php system/scripts/read-only/verify_settings_control_plane_no_operational_launcher_hub_01.php
```

If **new** copy anchors are introduced (e.g. extended breadcrumb tests), **update** `verify_catalog_growth_subsection_business_clarity_03.php` in the **same** change set as the UI change ([MAINTAINER-RUNTIME-TRUTH.md](MAINTAINER-RUNTIME-TRUTH.md): living scripts track the codebase).

### 10.6 DONE BAR

Phase **3.2** is **complete** only when all of the following are true:

1. No user-visible **Services & Resources** string remains on catalog subsection **wayfinding** in the files listed in §10.2 (breadcrumb, back links, wizard top chrome) — **Catalog** is the hub name for those surfaces.  
2. `← Catalog` remains on `services/index.php`, `equipment/index.php`, `rooms/index.php` (verifier **C1 / D1 / E3** still pass).  
3. Deep links and wizard **URLs** behave as before (no broken POST targets; smoke mentally or via existing tests).  
4. Mandatory verifier bundle (§10.5) exits **`0`** for all three scripts.  
5. `system/docs/BUSINESS-IA-CANONICAL-LAW-01.md` §3.5 Catalog hub sentence still matches UI (update law **only** if hub copy changes in the same task).

### 10.7 Phase 3.2 — closure record

**Closed:** 2026-04-08 — task **`BUSINESS-IA-PHASE-3-2-CATALOG-WAYFINDING-CLOSURE-01`**.

**Delivered:** Catalog hub label **Catalog** on service **show** + **wizard** breadcrumbs; categories index **← Catalog**; spaces detail **← Back to Spaces**; services list column **Spaces**; wizard step **4** label + step-4 copy + service show “spaces assigned” block aligned to **Spaces**; `verify_catalog_growth_subsection_business_clarity_03.php` anchors **C6–C14**, **E8–E9**. Routes and POST targets unchanged. Mandatory verifier bundle + `verify_settings_control_plane_no_operational_launcher_hub_01.php` exited **0**.

**Sterile micro (`BUSINESS-IA-PHASE-3-2-STERILE-CLOSURE-MICRO-01`):** Service detail **Applies to room** → **Applies to space** (same `applies_to_room` field); unnamed list fallbacks **Space #** (not **Room #**); verifier **C7b–C7d**.

---

## 11. PHASE 4.1 — CLOSURE RECORD

**Closed:** 2026-04-07 — task **`BUSINESS-IA-PHASE-4-1-SALES-MONEY-LIABILITY-COPY-CLOSURE-01`**.

**Delivered (copy/surfacing only; routes and POST contracts unchanged):** Sales workspace default subtitle reframed as **one financial workspace** (charge, collect, refund, reconcile; register = cash drawer vs checkout); default shell adds honest **Reports → Gift card liability** link for **measurement** (not operations). Register index and invoice payment form clarify **register vs checkout**. Cashier (`_cashier_workspace.php`) operator language for deferred lines, package series (Catalog plan definitions vs sell+assign), membership note, and branch sellables browse. Gift-card index/show/issue/redeem/adjust ledes aligned to **Sales stored value** + Reports for liability measurement. Mandatory verifier bundle exited **0** each: `verify_business_nav_entry_clarity_safe_lane_02.php`, `verify_catalog_growth_subsection_business_clarity_03.php`, `verify_admin_ia_business_first_truth_01.php`.

---

## 12. PHASE 4.2 — CLOSURE RECORD

**Closed:** 2026-04-07 — task **`BUSINESS-IA-PHASE-4-2-PACKAGE-PLACEMENT-STERILE-CLOSURE-01`**.

**Delivered (copy/surfacing only; routes and POST bodies unchanged):** Package **plan definition** views (`definitions/*`) consistently teach **Catalog** templates vs **Clients**-held records and **Sales** checkout as commercial flow only. **Client-held** views (`client-packages/*`) teach **client-owned records**, plan templates in Catalog, links to client profile and package plans list. Branch column wording **Organisation-wide** aligned on definitions + client-held lists. Clients list/show micro-copy and **Plan name** column headers aligned. `verify_catalog_growth_subsection_business_clarity_03.php` extended (**H10–H16**, **I9–I16**). Mandatory verifier bundle + `verify_package_ownership_ia_phase3_01.php` + `verify_no_duplicate_first_class_owner_surfaces_phase5_01.php` exited **0**.

---

## Document control

- **One cleanup doc:** this file is the program’s live execution lock; do not add parallel “status” markdown for Business IA.  
- **Updates:** bump anchor date and STATUS if the surviving lane changes after future merges.
