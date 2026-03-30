# Organization boundary — enforcement checklist and decision matrix (FOUNDATION-07)

**Purpose:** Operational checklist for later implementation waves. **Design parent:** `ORGANIZATION-BOUNDARY-CANONICAL-DESIGN-FOUNDATION-07.md`. **Current truth:** `ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md`.

---

## 1) Terminology decision matrix (resolved in FOUNDATION-07)

| Term | Use in this repo? | Notes |
|------|-------------------|--------|
| **organization** | **Yes — canonical** | Schema-facing name (`organization_id`, `organizations`). |
| business | Narrative only | Roadmap §6 “business layer” = organization boundary. |
| tenant | Avoid as primary | Implies deployment/DB split; we target shared DB + row scope. |
| branch | Keep | Operational child of organization. |

---

## 2) Entity classification checklist (fill per table during schema wave)

For each table that holds PII, money, or auth:

- [ ] **Owner:** organization required? (Y/N)
- [ ] **Branch column:** nullable allowed under org policy? (explicit rule)
- [ ] **List queries:** org predicate mandatory? (Y/N)
- [ ] **find($id):** replaced or wrapped with org/branch scope? (Y/N)
- [ ] **CLI/cron:** resolves org from row branch? (Y/N)

**Seed list from F-06:** clients, users (membership pivot), invoices, payments, documents (+ links), intake submissions, consents, memberships, packages, `invoice_number_sequences`, notifications with PII.

---

## 3) Wave gate checklist (binary)

| Gate | Pass criteria |
|------|----------------|
| **G-ORG-SCHEMA** | `organizations` exists; `branches.organization_id` non-null; backfill documented. **Proof:** `php scripts/verify_organization_branch_ownership_readonly.php` (FOUNDATION-08). |
| **G-ORG-CONTEXT** | HTTP `OrganizationContext` + resolver + middleware after branch context; fail closed on ambiguous multi-org without branch. **Proof:** `php scripts/verify_organization_context_resolution_readonly.php` + `ORGANIZATION-CONTEXT-RESOLUTION-FOUNDATION-09-OPS.md` (FOUNDATION-09). |
| **G-CHOKE-ORG-MIN** | Minimal mutating choke points (`BranchDirectory` update/delete + org-aware create pin, `InvoiceService`, `PaymentService`, `ClientService`) assert branch ownership vs resolved org via **`OrganizationScopedBranchAssert`**. **Proof:** `php scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php` + `ORGANIZATION-SCOPED-CHOKE-POINTS-MINIMAL-ENFORCEMENT-FOUNDATION-11-OPS.md` (FOUNDATION-11). |
| **G-ORG-REPO-AUDIT** | Read-only inventory of repository ID-only patterns vs F-11 coverage; safest-first repo wave boundary documented — **no** repo implementation in this gate. **Proof:** `ORGANIZATION-SCOPED-REPOSITORY-PATTERN-TRUTH-AUDIT-FOUNDATION-12-OPS.md` + matrix `ORGANIZATION-SCOPED-REPOSITORY-COVERAGE-MATRIX-FOUNDATION-12.md` (FOUNDATION-12). |
| **G-ORG-REPO-R1-MKT** | Marketing repository family applies org predicate via **`OrganizationRepositoryScope`** when context resolved; service create path aligned. **Proof:** `php scripts/verify_marketing_repository_org_scope_foundation_13_readonly.php` + `ORGANIZATION-SCOPED-MARKETING-REPOSITORY-R1-FOUNDATION-13-OPS.md` (FOUNDATION-13). |
| **G-ORG-REPO-R1B-PAY** | Payroll repository family (`PayrollRunRepository`, `PayrollCompensationRuleRepository`, `PayrollCommissionLineRepository`) applies org predicates when context resolved; create paths aligned in service/controller. **Proof:** `php scripts/verify_payroll_repository_org_scope_foundation_14_readonly.php` + `ORGANIZATION-SCOPED-PAYROLL-REPOSITORY-R1B-FOUNDATION-14-OPS.md` (FOUNDATION-14). |
| **G-ORG-CLIENT-READ-AUDIT** | Read-only inventory of client **read** surfaces vs F-11 mutate-only coverage; ID-only / list / search / merge-preview / cross-domain (`ClientListProvider`, sales profile) exposure documented; **minimal** first client repo wave boundary = `ClientRepository::find` / `findForUpdate` only (implementation **not** in this gate). **Proof:** `ORGANIZATION-SCOPED-CLIENT-READ-SURFACES-TRUTH-AUDIT-FOUNDATION-15-OPS.md` + `CLIENT-READ-SURFACE-COVERAGE-MATRIX-FOUNDATION-15.md` (FOUNDATION-15). |
| **G-ORG-REPO-R1-CLIENT** | `ClientRepository::find` / `findForUpdate` apply **`OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause`** when organization context resolves (via scope helper). **Proof:** `php scripts/verify_client_repository_org_scope_foundation_16_readonly.php` + `ORGANIZATION-SCOPED-CLIENT-REPOSITORY-R1-FOUNDATION-16-OPS.md` (FOUNDATION-16). **List/count:** **G-ORG-REPO-R1-CLIENT-LIST-COUNT** (FOUNDATION-18). **Not in F-16/F-18:** duplicate search repos, public lock helpers, provider file edits. |
| **G-ORG-CLIENT-LIST-COUNT-AUDIT** | Read-only inventory of **`ClientRepository::list` / `count`** SQL and **direct + `ClientListProvider`** callers; safest-first vs deferred perimeter; NULL branch semantics; acceptance gates for next implementation wave. **Proof:** `ORGANIZATION-SCOPED-CLIENT-LIST-COUNT-TRUTH-AUDIT-FOUNDATION-17-OPS.md` + `CLIENT-LIST-COUNT-CALLER-MATRIX-FOUNDATION-17.md` (FOUNDATION-17). **No** repo implementation in this gate. |
| **G-ORG-REPO-R1-CLIENT-LIST-COUNT** | **`ClientRepository::list` / `count`** append **`OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause('c')`** when org resolves; filter parity. **Provider consumers:** audit **G-ORG-CLIENT-LIST-PROVIDER-AUDIT** (F-19) + QA closure **G-ORG-CLIENT-LIST-PROVIDER-QA-CLOSURE** (F-20). **Proof:** `php scripts/verify_client_repository_org_scope_foundation_18_readonly.php` + `ORGANIZATION-SCOPED-CLIENT-LIST-COUNT-R1-FOUNDATION-18-OPS.md` (FOUNDATION-18). |
| **G-ORG-CLIENT-LIST-PROVIDER-AUDIT** | Read-only inventory of **`ClientListProviderImpl`** (single **`list`** surface) and **all** in-repo **`ClientListProvider`** consumers (five staff controllers); route/middleware proof; F-18 inheritance vs **unresolved org** / **NULL `branch_id` client** semantics. **Proof:** `ORGANIZATION-SCOPED-CLIENT-LIST-PROVIDER-CONSUMER-TRUTH-AUDIT-FOUNDATION-19-OPS.md` + `CLIENT-LIST-PROVIDER-CONSUMER-MATRIX-FOUNDATION-19.md` (FOUNDATION-19). **No** code. |
| **G-ORG-CLIENT-LIST-PROVIDER-QA-CLOSURE** | **Waiver / containment / QA closure** for the five **`ClientListProvider`** consumers: per-consumer paths, nullable branch + in-org cross-branch semantics, **QA vs product waiver** classification, **manual smoke matrix** for ZIP/runtime review; **foundation stream closed** at repo+provider boundary — **branch pinning / controller tightening / unresolved-org hardening** moved to **product / org-context** streams. **Proof:** `ORGANIZATION-SCOPED-CLIENT-LIST-CONSUMER-WAIVER-CONTAINMENT-QA-FOUNDATION-20-OPS.md` + `CLIENT-LIST-PROVIDER-MANUAL-SMOKE-MATRIX-FOUNDATION-20.md` (FOUNDATION-20). **No** code. |
| **G-ORG-UNRESOLVED-BEHAVIOR-AUDIT** | Read-only map of **`resolvedOrganizationId() === null`** vs **resolved** on accepted surfaces **F-10–F-20**: F-09 resolver prerequisites, F-11 assert **no-op**, F-13/F-14/F-16/F-18 repo dual-paths, F-19/F-20 provider inheritance; **staff** conditions for unresolved org; **no** single provable minimal hardening wave without product — recommend **context hardening** / **per-surface** programs. **Proof:** `ORGANIZATION-RESOLUTION-GAP-AND-UNRESOLVED-BEHAVIOR-TRUTH-AUDIT-FOUNDATION-21-OPS.md` + `ORGANIZATION-UNRESOLVED-BEHAVIOR-SURFACE-MATRIX-FOUNDATION-21.md` (FOUNDATION-21). **No** code. |
| **G-ORG-STAFF-HTTP-POLICY-DESIGN** | **Design-only** staff HTTP org policy: F-09 resolution modes + `BranchContextMiddleware` prerequisites; policy option matrix; **recommended** mixed **post-auth multi-org gate** (implementation **not** in this gate); explicit waivers. **Proof:** `STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-TRUTH-AND-DESIGN-AUDIT-FOUNDATION-22-OPS.md` + `STAFF-HTTP-ORG-RESOLUTION-POLICY-OPTIONS-MATRIX-FOUNDATION-22.md` (FOUNDATION-22). **No** code. |
| **G-ORG-STAFF-HTTP-POLICY-CLOSURE** | **Governance closure:** chosen **mixed staff multi-org org-mandatory** baseline; rejected alternatives; **exception map** (E1–E6); **FOUNDATION-24** = minimal post-auth gate implementation wave (name + acceptance gates only here — **no** code in F-23). **Proof:** `STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-DECISION-CLOSURE-FOUNDATION-23-OPS.md` + `STAFF-HTTP-ORG-RESOLUTION-EXCEPTION-MAP-FOUNDATION-23.md` (FOUNDATION-23). **No** code. |
| **G-ORG-STAFF-HTTP-GATE-BOUNDARY** | **Implementation boundary truth cut (read-only):** exact choke (**post-`AuthMiddleware`**); files (**Auth** or **Dispatcher** + **new gate** + **bootstrap**); optional resolver **count** accessor **without** rule changes; downstream = all **Auth** routes; **non-target** = repos/resolver rules/org middleware/branch middleware/controllers. Matrix **`STAFF-HTTP-ORG-RESOLUTION-GATE-FILE-IMPACT-MATRIX-FOUNDATION-24.md`**. **Proof:** `STAFF-HTTP-ORG-RESOLUTION-GATE-IMPLEMENTATION-BOUNDARY-TRUTH-CUT-FOUNDATION-24-OPS.md` (FOUNDATION-24). **No** code. |
| **G-ORG-REPO-R1-STAFF-MULTI-ORG-GATE** | **FOUNDATION-25:** **`StaffMultiOrgOrganizationResolutionGate`** + **`AuthMiddleware`** hook + **`OrganizationContextResolver::countActiveOrganizations()`** (read-only); multi-org + unresolved org → **403**; single-org unchanged; tiny exempt **`POST /logout`** + **`/account/password`**. **Proof:** `STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-FOUNDATION-25-OPS.md`. **No** repos/controllers/UI/schema/resolver rules/org+branch middleware edits. |
| **G-ORG-F25-R1-POST-IMPL-TRUTH-AUDIT** | **FOUNDATION-26 (read-only):** post-implementation truth audit of F-25 — gate boundary, insertion order, 403 shapes, trigger/non-trigger, exemptions, F-23/F-24 drift notes; **no** code. **Proof:** `STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-POST-IMPLEMENTATION-TRUTH-AUDIT-FOUNDATION-26-OPS.md` + `STAFF-MULTI-ORG-GATE-R1-TRIGGER-ESCAPE-MATRIX-FOUNDATION-26.md`. |
| **G-ORG-F27-POST-GATE-UNRESOLVED-RECHECK** | **FOUNDATION-27 (read-only):** F-21 org-scoped surfaces vs **post-F-25** staff HTTP — what multi-org unresolved exposure is **contained** at gate vs **still reachable** (`count ≤ 1`, exemptions, CLI/public); closure vs smallest gap. **Proof:** `POST-GATE-STAFF-HTTP-UNRESOLVED-ORG-SURFACE-RECHECK-FOUNDATION-27-OPS.md` + `POST-GATE-STAFF-HTTP-UNRESOLVED-ORG-SURFACE-MATRIX-FOUNDATION-27.md`. **No** code. |
| **G-ORG-F28-STAFF-MULTI-ORG-GATE-QA-CLOSURE** | **FOUNDATION-28 (docs/QA/governance only):** layer **closure** for staff multi-org gate line — manual smoke matrix, explicit waivers, future programs named; **no** code. **Proof:** `STAFF-MULTI-ORG-GATE-QA-WAIVER-AND-LAYER-CLOSURE-FOUNDATION-28-OPS.md` + `STAFF-MULTI-ORG-GATE-MANUAL-SMOKE-MATRIX-FOUNDATION-28.md`. **Runtime:** operators execute smoke; not proven by docs alone. |
| **G-ORG-CONTEXT** | Request has resolved org for authenticated users; branch ∈ org. |
| **G-QUERY-SCOPE** | Risk-tier-1 tables use scoped finds/lists (audit script or CI). |
| **G-SETTINGS** | No mixed `all()` + branched getters on same admin surface. |
| **G-SEQUENCES** | Invoice (and peers) sequence keys include org (and branch if required). |
| **G-RBAC** | Assignments keyed by org; proof: user in org A cannot gain org B role. |
| **G-STORAGE** | New writes use org-prefixed paths; migration plan for legacy bytes. |
| **G-PACKAGES** | No wave starts until **G-ORG-CONTEXT** + **G-RBAC** (minimum) pass. |

---

## 4) Public flow verification (post-implementation)

- [ ] Public booking: branch inactive → reject before org checks expose internals.
- [ ] Branch belongs to org; suspended org rejects all public entrypoints for that org’s branches.
- [ ] Settings effective reads still **entity/branch** based, not operator session.

---

## 5) Explicit non-actions (prevent drift)

- [ ] Do not add subscription SKU matrices in org-boundary waves.
- [ ] Do not repurpose `users.branch_id` without a migration plan for HQ users.
- [ ] Do not partition disks without updating `documents.storage_path` consistency rules.
