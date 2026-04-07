> **⛔ SUPERSEDED — 2026-04-07**
> This backlog (`BUSINESS-IA-CANONICAL-BACKLOG-01.md`) was the task list for the 10-module IA program.
>
> **New authoritative backlog:** `system/docs/OLLIRA-IA-7MODULE-MASTER-BACKLOG-V2-01.md`
>
> **Purge decisions:** See §1.3 of the new document for each old task's verdict (ELIMINATED / ABSORBED / CLOSED-KEPT / FOUNDATION-ONLY).
>
> **Summary of what was already delivered (CLOSED-KEPT):** Tasks 2.2, 3.1, 3.2, 4.1, 4.2, 5.1 — all delivered work remains valid and compatible with the new 7-module IA.
>
> **Summary of what was killed (ELIMINATED):** Tasks 1.0, 1.1, 1.2, 1.3, 7.1, 7.2 — these tasks added Catalog and Reports as standalone primary nav homes; the new IA removes those as primary homes.
>
> **Summary of what was re-homed (ABSORBED):** Tasks 5.2, 6.2, 7.3, 8.1, 8.2, 9.1, 9.2, 10.1, 10.2 — still valid work; now tracked in the new backlog under their correct Ollira module.

---

# Business IA — Execution Backlog (Program 01) — HISTORICAL RECORD

**Source law:** [`BUSINESS-IA-CANONICAL-LAW-01.md`](BUSINESS-IA-CANONICAL-LAW-01.md)  
**Live cleanup / single execution lane:** [`BUSINESS-IA-LIVE-EXECUTION-LOCK-01.md`](BUSINESS-IA-LIVE-EXECUTION-LOCK-01.md)  
**Rule:** One bounded task per execution slice; re-audit listed files **before** editing; commit + push after green verifiers.

**Regression bundle (run after any nav/shell/catalog/admin surfacing change):**

- `php system/scripts/read-only/verify_business_nav_entry_clarity_safe_lane_02.php`
- `php system/scripts/read-only/verify_catalog_growth_subsection_business_clarity_03.php`
- `php system/scripts/read-only/verify_admin_ia_business_first_truth_01.php`

Exit code must be `0` for each.

---

## Phase 0 (complete when these artifacts exist)

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 00A | Canonical law document | Freeze IA + ownership + roadmap | `base.php`, `SettingsController.php`, `register_*.php`, catalog hub view | Code changes | This file + law doc merged |
| 00B | Execution backlog | Ordered tasks 1–10 with verifiers | Law doc | Product code | This document in repo |

---

## Phase 1 — Primary nav law + home ownership

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 1.0 | Pre-flight nav audit | Record current `$navItems`, `$navSideIcons`, `$settingsActivePrefixes`, `$navIsSales`, `$navIsAppointments` | `system/shared/layout/base.php` | None | Short note in commit or doc appendix optional |
| 1.1 | Ten-home primary rail | Add **Catalog** (`/services-resources`) and **Reports** (href TBD: real index route or first report — **must be honest**) to primary nav; keep **exact** existing hrefs for other items unless a subtask proves change | `base.php`, `system/public/assets/js/app-shell-nav.js` (sidebar parity) | Route renames; mega-menu | All three read-only verifiers `0`; manual: 10 items, **8 icons → 10 icons** parity fixed in same change |
| 1.2 | Active-state families | Split or extend prefix families so **Catalog** is not forced under Admin active state **if** UX law requires; **preserve** `is-active` for legacy URLs (no dead highlights) | `base.php` | Moving `/memberships` URLs | Verifiers + spot-check `/services-resources`, `/memberships`, `/gift-cards` active states |
| 1.3 | Reports entry strategy | Either `GET /reports` index listing only real endpoints **or** shell page under reports module linking to existing GETs — **no** fake metrics | `register_reports.php`, `modules/reports/` | JSON contract changes for existing reports | Permission `reports.view` respected; link targets return 200 for authorized user |

**Explicit non-goals (phase):** DB migrations; `SettingsController` allowlist edits; renaming `/settings` URL.

---

## Phase 2 — Admin boundary finalization

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 2.1 | Settings shell copy + sidebar | Re-label “Admin” chrome so operational links read as **shortcuts** into Catalog/Sales/Clients/Team, not “owned by Admin” | `modules/settings/views/index.php`, `partials/shell.php`, `SettingsShellSidebar.php` | Removing sidebar links | `verify_admin_ia_business_first_truth_01.php` + manual copy review |
| 2.2 | Section honesty pass | Each `section=` tab describes **policy/control**; no new sections without allowlist task | `SettingsController.php`, settings views | New POST keys | Settings write verifiers if touched (`system/scripts/verify_settings_*` as applicable) |

**Explicit non-goals:** Dropping `public_channels` combined POST contract.

**Live closure note (2026-04-07, `BUSINESS-IA-SEQUENTIAL-LANE-CLOSURE-02-TO-03-01`):** Task **2.2** (section honesty + Admin shell copy) satisfied in repo with contracts unchanged.

---

## Phase 3 — Catalog finalization

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 3.1 | Hub copy + card order | Align `catalog-hub` lead/cards with definition vs client-record law; gift cards **Sales**, client packages/memberships **Clients** | `modules/services-resources/views/index.php` | Changing `/gift-cards` path | `verify_catalog_growth_subsection_business_clarity_03.php` |
| 3.2 | Secondary “back to catalog” consistency | Breadcrumbs or back links point to canonical Catalog without breaking deep links | **Exact file list:** `system/docs/BUSINESS-IA-LIVE-EXECUTION-LOCK-01.md` §10.2 | Wizard route changes; new routes | Mandatory verifier bundle in §10.5; extend `verify_catalog_growth_subsection_business_clarity_03.php` if new anchors |

**Live closure note (2026-04-08, `BUSINESS-IA-PHASE-3-2-CATALOG-WAYFINDING-CLOSURE-01`):** Task **3.2** closed — legacy **Services & Resources** hub labels removed from audited catalog views; **Spaces** naming aligned on services list, wizard step 4, service show, space detail; verifier extended (**C6–C14**, **E8–E9**); all mandatory + adjacent Business IA verifiers green.

**Sterile micro (`BUSINESS-IA-PHASE-3-2-STERILE-CLOSURE-MICRO-01`):** Service detail **Applies to room** / **Room #** user copy aligned to **space** / **Space #**; verifier **C7b–C7d**.

**Live closure note (2026-04-07, `BUSINESS-IA-SEQUENTIAL-LANE-CLOSURE-02-TO-03-01`):** Task **3.1** hub lead + gift-card card aligned to Sales ownership; `verify_catalog_growth_subsection_business_clarity_03.php` green.

---

## Phase 4 — Sales finalization

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 4.1 | Sales workspace shell copy | Invoices, checkout, payments, gift cards, packages — **wording** reflects money + liability | `modules/sales/views/partials/sales-workspace-shell.php`, gift-card views using shell | Invoice POST field renames | `verify_business_nav_entry_clarity_safe_lane_02.php` |
| 4.2 | Package/gift-card placement | Ensure definitions vs client-packages language matches law (routes unchanged) | `modules/packages/views/` | DB | Manual + verifiers |

**Live closure note (2026-04-07, `BUSINESS-IA-PHASE-4-1-SALES-MONEY-LIABILITY-COPY-CLOSURE-01`):** Task **4.1** closed — Sales shell default copy + Reports gift-card liability link; register/payment/cashier + gift-card view ledes; routes unchanged. Mandatory verifier bundle exited **0** each: `verify_business_nav_entry_clarity_safe_lane_02.php`, `verify_catalog_growth_subsection_business_clarity_03.php`, `verify_admin_ia_business_first_truth_01.php`.

**Live closure note (2026-04-07, `BUSINESS-IA-PHASE-4-2-PACKAGE-PLACEMENT-STERILE-CLOSURE-01`):** Task **4.2** closed — package definitions vs client-held placement copy; adjacent Clients list/show alignment; `verify_catalog_growth_subsection_business_clarity_03.php` **H10–H16** / **I9–I16**; mandatory verifier bundle **0** each; `verify_package_ownership_ia_phase3_01.php` and `verify_no_duplicate_first_class_owner_surfaces_phase5_01.php` **0**. **Sterile heading micro (`BUSINESS-IA-PHASE-4-2-STERILE-HEADING-CLOSURE-MICRO-01`):** list headings **Package plan definitions** / **Client-held packages** + verifier **H1** / **I17** / nav **H1**; bundle **0**.

---

## Phase 5 — Client value aggregation

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 5.1 | Client profile surfaces | Single place (tab or section) for memberships/packages/gift cards/balance due **where data exists** | `ClientController`, `register_clients.php`, client views | Fake aggregates | Permission matrix unchanged; feature flagged off if data missing |
| 5.2 | Deep links | From client row → `/memberships/client-memberships` / `/packages/client-packages` / gift card ledger with client filter **if** supported today | respective modules | New gift card API | 200 + no permission regression |

**Live closure note (2026-04-07, `BUSINESS-IA-PHASE-5-1-CLIENT-PROFILE-OWNED-VALUE-AGGREGATION-01`):** Task **5.1** closed — profile section **Owned value & obligations**; `ClientProfileReadService` **memberships** bucket + invoice summary on `show.php`; `verify_clients_owned_value_profile_phase6_01.php` + `verify_client_profile_read_model_contract_01.php`; mandatory IA verifier bundle **0** each.

---

## Phase 6 — Team + payroll ownership

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 6.1 | Nav + active state for payroll | Payroll operations highlight **Team** not Admin | `base.php`, payroll views | Payroll permission key rename | Verifiers |
| 6.2 | Admin payroll policy | Any payroll **defaults/approvals** policy stays in Admin/settings if present | `SettingsController`, settings views | Moving payroll runs into settings | Document + code match law doc |

---

## Phase 7 — Reports foundation

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 7.1 | Report module audit | List every `ReportController` action, template, JSON vs HTML | `modules/reports/` | New report types | Written inventory in commit message or ops doc snippet |
| 7.2 | Reports home | Index or shell: only links to implemented GET routes | `register_reports.php` | Fake dashboards | All links 200 with `reports.view` |
| 7.3 | VAT guide position | Keep `vat-distribution-guide` as operator doc; link from Reports or Admin honestly | `SettingsController`, VAT guide view | Mislabeling as “analytics” | Read-only verifier optional |

---

## Phase 8 — Role-based navigation

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 8.1 | Permission → home map | Encode archetypes (receptionist/manager/owner/staff) as **configuration** mapping to visible homes | `PermissionService`, role seed data, `base.php` | New roles DB tables unless required | Manual matrix + automated read-only script |
| 8.2 | Hide dead links | Remove nav items when **no** permission for any child destination | `base.php` | fail-open access | Forbidden routes still 403 |

---

## Phase 9 — Breadcrumb / secondary nav / naming

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 9.1 | Breadcrumb component pass | Standardize plan vs record vs client vs org wording | High-traffic partials | Visual redesign | Spot tests |
| 9.2 | Tab labels | Client workspace tabs, wizard steps, settings subsections | client/settings/service views | Route changes | Consistency checklist |

---

## Phase 10 — Cross-system polish (after ownership true)

| ID | Task name | Purpose | Re-audit first | Non-goals | Acceptance proof |
|----|-----------|---------|----------------|-----------|------------------|
| 10.1 | Empty states + dead ends | Remove misleading CTAs | UX copy only | Feature creep | Manual smoke |
| 10.2 | Final verifier sweep | Full read-only lane scripts + one HTML + one JSON endpoint smoke | `system/scripts/read-only/` | — | All exit 0 |

---

## Task discipline checklist (every implementer)

1. Re-audit **exact** files for the lane (list in task row).  
2. Map route contracts, permissions, active-state, POST bodies **before** edit.  
3. One live lane at a time; no drive-by refactors.  
4. Run regression verifiers + task-specific proofs.  
5. `git commit` and `git push` to `origin` after success; record hash in program tracker.

---

## Open decisions (Phase 1 — **resolved in live repo**, 2026-04-07)

Reconciled against `base.php` + `register_reports.php` + `BUSINESS-IA-LIVE-EXECUTION-LOCK-01.md`. Do not re-open unless a later task intentionally changes behavior.

1. **Reports primary href:** **Resolved:** dedicated `GET /reports` index (`ReportController::index`) lists only real report GET paths. Primary nav uses `href="/reports"`.  
2. **Catalog active family:** **Resolved:** `$navIsCatalog` drives Catalog highlight; `settingsActivePrefixes` does not include `/memberships` plan URLs; client-held membership/package paths use `$navIsClientsMemberships` / `$navIsClientsPackages`.
