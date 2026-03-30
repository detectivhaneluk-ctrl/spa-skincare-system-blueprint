# USER-ORGANIZATION — MEMBERSHIP & RUNTIME TRUTH LANE CONSOLIDATED PROGRAM CLOSURE TRUTH AUDIT (FOUNDATION-64)

**Mode:** Read-only consolidation through **FOUNDATION-63**. **No** code, schema, routes, resolver, middleware, auth, repository scope, controllers, or UI changes.

**Evidence read:** `OrganizationContextResolver.php`, `OrganizationContext.php`, `OrganizationRepositoryScope.php`, `OrganizationScopedBranchAssert.php`, `StaffMultiOrgOrganizationResolutionGate.php`, `BranchContext.php` (pointer), `BranchContextMiddleware.php` (head), `OrganizationContextMiddleware.php`, `UserOrganizationMembershipReadRepository.php`, `UserOrganizationMembershipReadService.php`, `UserOrganizationMembershipStrictGateService.php`, `UserOrganizationMembershipBackfillService.php`, `register_organizations.php`, `modules/bootstrap.php` (resolver registration), `backfill_user_organization_memberships.php`, `audit_user_organization_membership_backfill_and_gate.php` (grep + contract), `audit_user_organization_membership_context_resolution.php` (header + grep), `Dispatcher.php` (global middleware order), grep `assertSingleActiveMembershipForOrgTruth` in `system/**/*.php`, grep `INSERT INTO user_organization_memberships` in `system/**/*.php`, roadmap §8 tail, F-63 ops (waivers).

---

## 1. Lane slices now closed (code-proven)

| Slice | Where proven |
|-------|----------------|
| **Backward-safe membership reads when table 087 absent** | `UserOrganizationMembershipReadRepository::membershipTableAvailable()` → reads return **0** / **[]** without querying missing table (`countActiveMembershipsForUser` lines 60–62). |
| **Deterministic backfill helper** | `UserOrganizationMembershipBackfillService::run` — idempotent rules, **INSERT** only when not `dryRun` (lines 97–103). |
| **Strict gate helper** | `UserOrganizationMembershipStrictGateService::getUserOrganizationMembershipState` + **`assertSingleActiveMembershipForOrgTruth`** (read/throw only; ```91:109:system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php```). |
| **Verifier-only first non-HTTP `assert*` consumer** | **`audit_user_organization_membership_backfill_and_gate.php`** (F-51 header + `assert*` calls). |
| **Verifier-only second non-HTTP `assert*` consumer** | **`audit_user_organization_membership_context_resolution.php`** (F-54 header + optional positive `assert*`). |
| **Resolver membership-single runtime consumer** | `OrganizationContextResolver` branch-null path, **`assertSingleActiveMembershipForOrgTruth`** (line 64) + F-57 `DomainException` wrap (lines 65–71). |
| **Resolver branch-derived membership alignment** | **`enforceBranchDerivedMembershipAlignmentIfApplicable`** (F-62; read service only, **no** `assert*` on branch path). |

**`assert*` call-site inventory (grep, `system/**/*.php`):** resolver (branch-null single path only), two audit scripts, strict gate **definition** — matches F-59/F-63 closure story.

---

## 2. Runtime precedence / order (stable, documented)

**Global order** — `Dispatcher`: Csrf → ErrorHandler → **`BranchContextMiddleware`** → **`OrganizationContextMiddleware`** → route middleware (```20:25:system/core/router/Dispatcher.php```).

**Organization fill** — `OrganizationContextMiddleware` delegates to **`OrganizationContextResolver::resolveForHttpRequest`** (```21:27:system/core/middleware/OrganizationContextMiddleware.php```).

**Resolver precedence** — class docblock + body (```31:32:system/core/Organization/OrganizationContextResolver.php```): **(1)** branch-derived (with F-62 alignment) → **(2)** membership-single (F-57) → **(3)** legacy single-active-org → **(4)** unresolved modes.

**Post-auth multi-org guard** — unchanged: **`StaffMultiOrgOrganizationResolutionGate`** after auth (out of lane edits; file unchanged in this audit’s scope read).

---

## 3. Intentionally untouched by this lane (no drift in scoped files)

| Surface | Role | Lane touch |
|---------|------|------------|
| **F-25** | `StaffMultiOrgOrganizationResolutionGate` | **None** — still `countActiveOrganizations` + resolved org id. |
| **`OrganizationRepositoryScope`** | SQL fragments from context | **None** — `resolvedOrganizationId()` unchanged. |
| **`BranchContextMiddleware`** | Branch id selection | **None** — guest clears branch (lines 28–35 sample). |
| **`OrganizationScopedBranchAssert`** | Branch row vs context org | **None**. |
| **Controllers / downstream module services** | Consumers of context | **Out of lane** — not modified for membership waves. |

**DI wiring only** (lane-adjacent, not behavior of F-25/scope/middleware): `register_organizations.php` registers read repo/service, strict gate, backfill; `modules/bootstrap.php` constructs **`OrganizationContextResolver`** with DB + Auth + read + strict gate (lines 41–48).

---

## 4. Write path / schema drift within lane

- **Intended mutation:** **`UserOrganizationMembershipBackfillService`** — **`INSERT INTO user_organization_memberships`** when table present and `dryRun === false` (```97:102:system/modules/organizations/services/UserOrganizationMembershipBackfillService.php```), invoked from **`backfill_user_organization_memberships.php`** CLI.
- **Read repository:** **`UserOrganizationMembershipReadRepository`** — doc states **no** INSERT/UPDATE/DELETE (lines 10–12).
- **Resolver / strict gate / verifiers:** **read-only** membership access for runtime and audits.
- **No** evidence in scoped tree of **unintended** new membership writes outside backfill + accepted migration story (F-48).

---

## 5. Verifier coverage: truthful and bounded

| Script | Proves | Does **not** prove |
|--------|--------|---------------------|
| **`audit_user_organization_membership_backfill_and_gate.php`** | Read contract pieces, gate API, negative `assert(0)`, optional positive `assert*` vs gate `single` | HTTP resolver branch alignment **M1/M2**, full request simulation |
| **`audit_user_organization_membership_context_resolution.php`** | `MODE_MEMBERSHIP_SINGLE_ACTIVE`, resolver ctor ≥4, read parity vs raw SQL, optional F-54 `assert*` | Branch-derived alignment behavior, F-62 matrix end-to-end |

**Conclusion:** Verifiers are **honest** about scope; **branch alignment** and **HTTP error UX** rely on **runtime + ops**, not these scripts.

---

## 6. Remaining risks / waivers (deferrable, not hidden blockers)

| Id | Waiver (consolidated from F-58 / F-61 / F-63) |
|----|-----------------------------------------------|
| **W-64-1** | **`DomainException`** (**F-57**, **F-62 M1/M2**, branch unlink) → HTTP status/UX depends on **existing** error middleware — not defined in this lane. |
| **W-64-2** | **F-62 defensive fail-open:** `mCount === 1` but `getSingleActiveOrganizationIdForUser` null/≤0 → skip alignment (**R-63-1**). |
| **W-64-3** | **F-61 fail-open** on **zero** active memberships on branch path — branch-derived org allowed until backfill/rows exist (**W-61-1** lineage). |
| **W-64-4** | **`OrganizationContext` PHPDoc** (lines 10–13) describes branch vs membership-null paths but **does not** mention **F-62** membership authorization on **branch-derived** resolution — **documentation lag**, not runtime ambiguity in resolver. |
| **W-64-5** | **Extra read load** on branch-context requests for authenticated users with ≥1 membership. |
| **W-64-6** | **DB-enforcement** of branch↔membership consistency — **optional**; not required for lane code to run. |

None of the above are **unowned** correctness holes inside the **declared** lane contract; they are **explicit** follow-ups (UX, ops, docs, DB hardening).

---

## 7. Closure outcome (exactly one)

### **CLOSE THIS LANE AS COMPLETE WITH WAIVERS**

**Rationale:** All **in-lane** deliverables from F-46 through F-63 are **present in tree** with **clear** boundaries: reads safe without table, **CLI backfill** as sole membership **write**, **strict gate** + **verifiers** + **resolver** adoption **closed**. **No** single **unresolved in-lane blocker** remains that contradicts the **stated** program goals; residual items are **listed** in §6 and **deferrable**.

---

## 8. Future work that belongs **outside** this lane (do not casually reopen)

Treat as **separate** roadmap tasks **unless** explicitly rescoping the lane:

- **Product UX:** org/branch **picker**, session org pivot, staff-facing **error pages** for **M1/M2** / F-57 messages.
- **Platform:** **DB constraints** / triggers tying `users.branch_id` to `user_organization_memberships`; **scheduled** backfill or **reconciliation** jobs.
- **Cross-cutting:** changes to **F-25** semantics, **`OrganizationRepositoryScope`** rules, **`BranchContextMiddleware`** selection policy, **RBAC** on membership.
- **Broad refactors:** moving resolution out of **`OrganizationContextResolver`**, new **runtime `assert*`** consumers (per F-59).

**Reopening the lane** should require a **named** FOUNDATION/MAINTAINABILITY task that **states** it overrides this closure.

---

## 9. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Consolidation **sound**; closure outcome **justified**; waivers **explicit**. |
| **B** | Sound with **material** residual doc/ops gap. |
| **C** | Closure **unsupported**. |

**FOUNDATION-64 verdict: A**

**Rationale:** §1–§6 are **code-backed**; §7 **closes** the lane; §6/§8 prevent **hidden** debt.

---

## 10. STOP

**FOUNDATION-64** ends here — **FOUNDATION-65** is **not** opened.

**Companion:** `USER-ORGANIZATION-MEMBERSHIP-AND-RUNTIME-TRUTH-LANE-SURFACE-MATRIX-FOUNDATION-64.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-64-MEMBERSHIP-RUNTIME-TRUTH-LANE-CLOSURE-AUDIT-CHECKPOINT.zip`.
