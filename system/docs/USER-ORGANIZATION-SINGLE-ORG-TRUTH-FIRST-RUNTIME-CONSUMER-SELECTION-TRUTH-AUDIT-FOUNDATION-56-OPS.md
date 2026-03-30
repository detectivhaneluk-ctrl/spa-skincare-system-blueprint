# USER-ORGANIZATION-SINGLE-ORG-TRUTH — FIRST RUNTIME CONSUMER SELECTION TRUTH AUDIT (FOUNDATION-56)

**Mode:** Read-only selection audit after **FOUNDATION-55**. **No** code, schema, routes, middleware/resolver/auth/branch/repo-scope/controller/UI **changes** in this wave.

**Evidence read:** `OrganizationContextResolver.php`, `StaffMultiOrgOrganizationResolutionGate.php`, `OrganizationRepositoryScope.php`, `UserOrganizationMembershipStrictGateService.php`, `UserOrganizationMembershipReadService.php`, `UserOrganizationMembershipReadRepository.php`, `modules/bootstrap.php` (resolver registration), grep of `getCurrentOrganizationId` / `resolvedOrganizationId` in `system/**/*.php`, roadmap F-51–F-55 rows.

---

## 1. FOUNDATION-55 closes only the verifier-consumer sublayer; runtime adoption remains unopened

**Proof:** `assertSingleActiveMembershipForOrgTruth` is invoked **only** from:

- `audit_user_organization_membership_backfill_and_gate.php`
- `audit_user_organization_membership_context_resolution.php`
- plus the **method body** in `UserOrganizationMembershipStrictGateService` (repo grep, `system/**/*.php`).

**No** `OrganizationContextResolver`, `StaffMultiOrgOrganizationResolutionGate`, `OrganizationRepositoryScope`, or domain `*Repository` / `*Service` PHP files call **`assert*`** today.

**Conclusion:** F-51/52 and F-54/55 close **CLI verifier** adoption only. **Runtime-coupled** (HTTP pipeline) adoption of **`assert*`** is **unopened**.

---

## 2. Exact current runtime organization-truth pipeline and fallback behavior

**Entry:** `OrganizationContextMiddleware` → `OrganizationContextResolver::resolveForHttpRequest` (global middleware order documented in `Dispatcher`).

**Precedence in resolver** (docblock + code):

```32:88:system/core/Organization/OrganizationContextResolver.php
    public function resolveForHttpRequest(BranchContext $branchContext, OrganizationContext $organizationContext): void
    {
        $organizationContext->reset();

        $branchId = $branchContext->getCurrentBranchId();
        if ($branchId !== null) {
            $orgId = $this->activeOrganizationIdForActiveBranch($branchId);
            if ($orgId === null) {
                throw new \DomainException('Branch is not linked to an active organization.');
            }
            $organizationContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

            return;
        }

        $user = $this->auth->user();
        $userId = $user !== null && isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId > 0) {
            $mCount = $this->membershipRead->countActiveMembershipsForUser($userId);
            if ($mCount === 1) {
                $singleOrgId = $this->membershipRead->getSingleActiveOrganizationIdForUser($userId);
                if ($singleOrgId !== null && $singleOrgId > 0) {
                    $organizationContext->setFromResolution($singleOrgId, OrganizationContext::MODE_MEMBERSHIP_SINGLE_ACTIVE);

                    return;
                }
            }
            if ($mCount > 1) {
                $organizationContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_AMBIGUOUS_ORGS);

                return;
            }
        }

        $activeCount = $this->queryActiveOrganizationCount();
        ...
        $organizationContext->setFromResolution($id, OrganizationContext::MODE_SINGLE_ACTIVE_ORG_FALLBACK);
    }
```

**Summary:**

1. **Branch non-null** → org from active branch row → **`MODE_BRANCH_DERIVED`** (membership reads **not** used on this path).
2. **Branch null**, authenticated → membership **count** / **single** via `UserOrganizationMembershipReadService` → **`MODE_MEMBERSHIP_SINGLE_ACTIVE`** if exactly one; **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** if **>1**; else fall through.
3. **Legacy** → count live `organizations` → **0** / **>1** unresolved modes → **exactly one** org → **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`**.

**F-25 post-auth gate** (after `OrganizationContext` is already filled): if `countActiveOrganizations() > 1` and context org id null → deny; else allow. Uses **deployment org count**, not **`assert*`**:

```36:45:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php
        if ($this->resolver->countActiveOrganizations() <= 1) {
            return;
        }

        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId !== null && $orgId > 0) {
            return;
        }

        $this->denyUnresolvedOrganization();
```

**Downstream:** `OrganizationRepositoryScope::resolvedOrganizationId()` mirrors **`OrganizationContext::getCurrentOrganizationId()`** (positive id only). Consumers include marketing/payroll repositories, `BranchDirectory`, `OrganizationScopedBranchAssert`, etc. (grep) — they **consume resolved id**, not **`assert*`**.

---

## 3. Runtime-coupled candidate surfaces today

| Bucket | Artifacts | Role vs org truth |
|--------|-----------|-------------------|
| **Resolver body** | `OrganizationContextResolver` | **Authoritative** HTTP org resolution + modes |
| **F-25 gate** | `StaffMultiOrgOrganizationResolutionGate` | **Policy** block when multi-org deployment + unresolved context |
| **Repository scope** | `OrganizationRepositoryScope` | SQL fragments from **resolved** context id only (no `user_id`) |
| **Branch catalog** | `BranchDirectory` | Branch lists/mutations gated on **`getCurrentOrganizationId()`** |
| **Domain repos/services** | Marketing/payroll/client-scoped repos, `MarketingCampaignService`, `PayrollService`, `PayrollRuleController`, `OrganizationScopedBranchAssert` | **Downstream** of context |

---

## 4. Higher-risk candidates (must not be first runtime `assert*` adoption)

| Candidate | Why not first |
|-----------|----------------|
| **`StaffMultiOrgOrganizationResolutionGate`** | Satisfied by **any** positive context org id (e.g. **`MODE_BRANCH_DERIVED`**, **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`**). Adding **`assert*`** would **fail** staff who legitimately resolve org **without** exactly one active membership row. |
| **`OrganizationRepositoryScope`** | No **`AuthService` / user id** in scope; **`assert*`** requires **`$userId`**. Extending scope would force **constructor + DI fan-out** across all repos using scope, without a single narrow choke. |
| **Arbitrary downstream service/controller** | **Unequal** enforcement across product surfaces; duplicates policy in many places; high regression surface. |
| **`BranchDirectory`** | Hot path for branch UX; org id may be **branch-derived** without membership pivot alignment; **`assert*`** is the wrong contract for “branch belongs to org”. |

---

## 5. Single safest first runtime adoption target

**Recommended first runtime-coupled consumer (for a **future** implementation wave only):**  
**`OrganizationContextResolver::resolveForHttpRequest`** — call **`UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth($userId)`** **only** on the **existing** success path that sets **`MODE_MEMBERSHIP_SINGLE_ACTIVE`** (authenticated user, branch null, `mCount === 1`, `singleOrgId` positive), **immediately before** `setFromResolution($singleOrgId, …)`.

**Why this is the narrowest safe runtime hook**

- **`assert*`** semantics match **this mode only** (membership-backed single org). It is **not** invoked for **branch-derived** or **legacy fallback** resolution, preserving current behavior for those modes.
- At the point of call, the resolver has **already** proven `mCount === 1` and a positive `singleOrgId` via the same read stack the gate uses; **`assert*`** is **defense-in-depth** / **contract hardening** against read-vs-gate drift rather than a new policy dimension.
- **Single injection site** vs many downstream consumers.

**“No safe target yet” is not chosen:** A **scoped resolver** addition is **feasible** with explicit boundaries below; deferral is **product/error-handling** risk, not impossibility.

---

## 6. Layer choice for first runtime adoption

**Answer: resolver-level** (exactly one branch inside `resolveForHttpRequest`).

**Not** F-25 gate-level, repository-scope-level, or a narrow downstream consumer **first** — reasons in §4.

---

## 7. Exact implementation boundary for the next wave (if executed)

1. **Files (minimal):** `OrganizationContextResolver.php`, `modules/bootstrap.php` resolver singleton (inject **`UserOrganizationMembershipStrictGateService`**), ops doc + roadmap row + **update** `audit_user_organization_membership_context_resolution.php` reflection/contract if ctor arity is asserted (today: “≥ 3 parameters” — ```57:61:system/scripts/audit_user_organization_membership_context_resolution.php```).
2. **Call site:** **Only** between lines 52–54 and `setFromResolution` (membership-single success). **No** calls on branch-derived, ambiguous, legacy fallback, or guest paths.
3. **Post-condition:** Use **`assert*` return** as the **authoritative** org id for `setFromResolution` **or** assert return **`===`** `singleOrgId` then set — either is provable; pick one in implementation doc.
4. **`RuntimeException`:** `assert*` throws **`RuntimeException`** (```91:99:system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php```). Next wave must define **HTTP error mapping** (e.g. wrap to **`DomainException`**, global handler, or 500 policy) — **out of scope** for F-56.
5. **Do not** change F-25 exemptions, routes, middleware ordering, or repository SQL fragments in the same wave unless explicitly tasked.

---

## 8. Waivers / risks (explicit)

| Id | Waiver / risk |
|----|----------------|
| **W-56-1** | **HTTP-visible failure mode:** Uncaught **`RuntimeException`** from **`assert*`** during org resolution can become a **500** unless mapped — must be designed in the implementation wave. |
| **W-56-2** | **Redundancy:** Resolver already gates **`mCount === 1`**; **`assert*`** duplicates work on the hot path (CPU / consistency trade). |
| **W-56-3** | **Branch vs membership skew:** Staff with **branch-derived** org may still have **zero / many** membership rows; **`assert*`** correctly **does not** run there — **no** cross-check that branch org matches membership truth. |
| **W-56-4** | **StrictGateService** docblock states read-only and **“not … global middleware”** — resolver is **not** middleware but **is** **global resolution**; wording tension only, not a code contradiction. |
| **W-56-5** | **Verifier drift:** F-46 auditor assumes resolver ctor shape; ctor/DI change **must** update verifier + docs in the same program. |

---

## 9. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Single runtime target with **no** material caveats. |
| **B** | Target defensible; **documented** implementation / HTTP caveats. |
| **C** | No defensible runtime target. |

**FOUNDATION-56 verdict: B**

**Rationale:** **Resolver-level, membership-success-only** **`assert*`** is the **single narrowest** runtime hook aligned with strict membership semantics; **W-56-1–W-56-5** record error-handling and scope limits honestly.

---

## 10. STOP

**FOUNDATION-56** ends here — **no FOUNDATION-57** opened by this audit.

**Companion:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-FIRST-RUNTIME-CONSUMER-SURFACE-MATRIX-FOUNDATION-56.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-56-FIRST-RUNTIME-CONSUMER-SELECTION-CHECKPOINT.zip`.
