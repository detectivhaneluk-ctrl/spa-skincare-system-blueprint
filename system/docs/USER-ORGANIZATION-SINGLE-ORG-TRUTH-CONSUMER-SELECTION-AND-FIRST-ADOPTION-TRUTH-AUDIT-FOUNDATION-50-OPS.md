# USER-ORGANIZATION-SINGLE-ORG-TRUTH — CONSUMER SELECTION & FIRST ADOPTION TRUTH AUDIT (FOUNDATION-50)

**Mode:** Read-only audit after **FOUNDATION-49**. **No** implementation, schema, routes, middleware, auth, HTTP, UI, or admin/dashboard changes in this wave.

**Scope (evidence read):** `OrganizationContextResolver`, `OrganizationContext`, `OrganizationContextMiddleware`, `BranchContextMiddleware`, `Dispatcher`, `StaffMultiOrgOrganizationResolutionGate`, `AuthMiddleware`, `OrganizationRepositoryScope`, `OrganizationScopedBranchAssert`, `BranchDirectory`, `UserOrganizationMembershipReadRepository`, `UserOrganizationMembershipReadService`, `UserOrganizationMembershipStrictGateService`, membership audit scripts, representative consumers (clients/marketing/payroll/sales DI), `modules/bootstrap.php`, `BOOKER-PARITY-MASTER-ROADMAP.md` (F-46–F-49 rows), F-48/F-49 ops docs.

---

## 1. Exact current organization-truth resolution chain and fallback order

### 1.1 Global middleware order (before route middleware)

`Dispatcher` runs global middleware in this order: **Csrf → ErrorHandler → BranchContext → OrganizationContext**, then per-route middleware (commonly **Auth** then **Permission**).

```20:24:system/core/router/Dispatcher.php
    private array $globalMiddleware = [
        \Core\Middleware\CsrfMiddleware::class,
        \Core\Middleware\ErrorHandlerMiddleware::class,
        \Core\Middleware\BranchContextMiddleware::class,
        \Core\Middleware\OrganizationContextMiddleware::class,
    ];
```

### 1.2 Branch context (`BranchContextMiddleware`)

- Guest → `BranchContext` **null** branch.
- Authenticated → resolves branch from request/session/user row with **active-branch** validation via `BranchDirectory::isActiveBranchId` (inactive assigned branch ⇒ empty allow-list).

### 1.3 Organization context (`OrganizationContextMiddleware` → `OrganizationContextResolver::resolveForHttpRequest`)

**Precedence is explicit in resolver docblock and code (F-46):**

```25:88:system/core/Organization/OrganizationContextResolver.php
     * Precedence (F-46): (1) branch-derived org — (2) single active membership for authenticated user — (3) legacy single active org in DB — (4) unresolved.
...
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
        if ($activeCount === 0) {
            $organizationContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_NO_ACTIVE_ORG);

            return;
        }
        if ($activeCount > 1) {
            $organizationContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_AMBIGUOUS_ORGS);

            return;
        }

        $row = $this->db->fetchOne(
            'SELECT id FROM organizations WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1'
        );
...
        $organizationContext->setFromResolution($id, OrganizationContext::MODE_SINGLE_ACTIVE_ORG_FALLBACK);
```

**Summary chain:**

1. **Branch non-null** → org from **active** `branches` row joined to **live** `organizations`; **`MODE_BRANCH_DERIVED`**; **no membership reads** on this path.
2. **Branch null** + authenticated user → **membership pivot** via `UserOrganizationMembershipReadService`: exactly **one** active membership ⇒ **`MODE_MEMBERSHIP_SINGLE_ACTIVE`**; **>1** ⇒ **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** with null org; **0** memberships → fall through.
3. **Legacy deployment fallback** → count live `organizations`: **0** ⇒ **`MODE_UNRESOLVED_NO_ACTIVE_ORG`**; **>1** ⇒ **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`**; **exactly 1** ⇒ pick that org ⇒ **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`**.

`OrganizationContext` documents the same branch-first + membership + single-org fallback story (no session org id).

---

## 2. Call sites: ambiguous / tolerant vs implicitly “single org”

### 2.1 Explicitly **unresolved / ambiguous** organization id (null context)

Resolver sets **`getCurrentOrganizationId() === null`** for:

- **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** (membership count > 1 **or** deployment has > 1 live org on legacy path).
- **`MODE_UNRESOLVED_NO_ACTIVE_ORG`** (no live org on legacy path).

**Consumers that tolerate null org (legacy / unscoped SQL):** `OrganizationRepositoryScope::resolvedOrganizationId()` returns null when context id is null or ≤ 0, and repository helpers emit **empty SQL fragment** — “legacy unscoped” behavior.

```17:34:system/core/Organization/OrganizationRepositoryScope.php
    public function resolvedOrganizationId(): ?int
    {
        $id = $this->organizationContext->getCurrentOrganizationId();

        return ($id !== null && $id > 0) ? $id : null;
    }
...
        if ($orgId === null) {
            return ['sql' => '', 'params' => []];
        }
```

Repositories using `resolvedOrganizationId()` (marketing, payroll, client, etc.) **branch on null** to skip org predicates — they **tolerate** ambiguous/unresolved context rather than requiring a single org.

### 2.2 **StaffMultiOrgOrganizationResolutionGate** (F-25) — partial enforcement, not membership-strict

After auth, the gate blocks only when **deployment** has **> 1** active organization **and** context org is unresolved:

```36:43:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php
        if ($this->resolver->countActiveOrganizations() <= 1) {
            return;
        }

        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId !== null && $orgId > 0) {
            return;
        }
```

So **`countActiveOrganizations() <= 1` ⇒ gate is a no-op** even if membership is ambiguous or missing. A resolved org from **branch** or **single-org fallback** satisfies the gate **without** any membership row.

### 2.3 Implicitly **single-org-truth** when id is non-null

Any code path using **`getCurrentOrganizationId()`** or **`resolvedOrganizationId()`** as non-null treats that id as **the** tenant org for filtering/asserts — but that id may have been obtained via **`MODE_BRANCH_DERIVED`** or **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`**, **not** via membership.

---

## 3. Candidate adoption points, grouped by risk

| Risk tier | Candidate locus | Why it matters |
|-----------|-----------------|----------------|
| **Critical (HTTP blast radius)** | `OrganizationContextMiddleware` / **`OrganizationContextResolver::resolveForHttpRequest`** | Every staff/guest request; changing semantics here affects all downstream context. |
| **Critical** | `StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff` (via **`AuthMiddleware`**) | Broad staff request surface; interacts with F-43/F-44 exemptions. |
| **High** | `BranchDirectory` (multiple methods gate on `getCurrentOrganizationId()`) | Core branch listing/mutations; high fan-out. |
| **High** | Domain **services/controllers** that use `OrganizationContext` + `OrganizationScopedBranchAssert` (e.g. marketing, payroll, sales/invoices, clients) | Many product flows; assert would couple them to **membership** truth, not just **context** truth. |
| **Medium** | Individual **repositories** using `OrganizationRepositoryScope` | SQL scope changes; still wide replication across modules. |
| **Low** | **`system/scripts/*` read-only verifiers** | No user-facing behavior; proves DI + contract; failure = operator CI/audit signal only. |

---

## 4. Single recommended first adoption target (exactly one)

**Recommended first consumer:** extend **`system/scripts/audit_user_organization_membership_backfill_and_gate.php`** so that, when the membership table is present and the verifier already computes a **sample user** whose strict-gate state is **`single`**, it also invokes **`UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth($sampleUserId)`** and proves the returned **int** equals the gate’s **`organization_id`** (mirroring existing raw-SQL cross-checks). **This wave does not implement that change** — it is the **named target only**.

**Why this is narrowest / safest**

- The script already loads bootstrap, resolves `UserOrganizationMembershipStrictGateService`, performs **dry-run** backfill and **membership row count** integrity checks — it is the **natural home** for **`assert*` contract proof**.
- **Zero HTTP / auth / middleware / route** surface area.
- **No collision** with **`MODE_BRANCH_DERIVED`** or **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`**: the check runs only for users where the **strict gate** itself reports **`single`**, i.e. membership-backed single org is already the premise.

---

## 5. Why other candidates must not be first

| Rejected first | Reason (from code) |
|----------------|-------------------|
| **`OrganizationContextResolver` / `OrganizationContextMiddleware`** | Resolver **intentionally** resolves org from **branch** with **no membership** (lines 36–44). It also uses **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`** without membership. `assertSingleActiveMembershipForOrgTruth` **requires** exactly one **active membership** row; using it here would **break** valid requests that today rely on branch-first or legacy fallback. |
| **`StaffMultiOrgOrganizationResolutionGate` / `AuthMiddleware`** | Gate uses **`countActiveOrganizations() <= 1`** short-circuit and treats **any** positive **`getCurrentOrganizationId()`** as sufficient. Replacing or augmenting with membership assert would **reshape** who may access staff routes and **fight** branch-pinned staff without membership rows. |
| **`BranchDirectory`** | Org scoping for selectors/admin reads is tied to **context id**, not membership; assert would **deny** operations for staff whose org is **branch-derived** but pivot is empty or non-single. |
| **Arbitrary controller** | Controllers fan out across domains; picking one **without** a product decision **hides** inconsistent membership vs context semantics elsewhere. |
| **Single domain service** (marketing/payroll/clients/sales) | Each already uses **context** + optional org scope; none in-tree today expresses **membership-only** entitlement. First adoption there **without** a resolver policy decision risks **partial** enforcement and **unequal** tenant behavior across modules. |

---

## 6. Service vs controller vs resolver vs middleware for “first” adoption

**Answer:** The **first** adoption should be **verifier-script-level** (the F-48 audit script above), which is **outside** the four HTTP layers.

Among **service / controller / resolver / middleware**:

- **None** is appropriate as the **first** `assertSingleActiveMembershipForOrgTruth` consumer, because **HTTP-resolved organization truth is deliberately not equivalent to membership-single truth** (see §1 and §5).
- **If a later product wave requires HTTP enforcement** of membership-single org, the **least catastrophic** layering is typically **service-level** (one narrowly defined operation with explicit contract), **after** policy defines when membership must override branch/fallback — **not** resolver or middleware as the first hook.

---

## 7. Implementation status (this wave)

**No code path in this repository currently calls `UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth` outside:**

- The **method definition** in `UserOrganizationMembershipStrictGateService.php`.
- The **F-48 verifier** calling **`assertSingleActiveMembershipForOrgTruth(0)`** expecting a throw (negative contract test).

```85:90:system/scripts/audit_user_organization_membership_backfill_and_gate.php
        try {
            $gate->assertSingleActiveMembershipForOrgTruth(0);
            $errors[] = 'assertSingleActiveMembershipForOrgTruth(0) must throw';
        } catch (RuntimeException) {
            // expected
        }
```

**No production HTTP consumer** of `assertSingle…` exists yet — consistent with F-48/F-49 closure docs.

---

## 8. Risks / waivers (explicit)

| Id | Risk / waiver |
|----|----------------|
| **W-1** | **Semantic gap:** `OrganizationContext` may be **`MODE_BRANCH_DERIVED`** or **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`** while **`assertSingleActiveMembershipForOrgTruth`** still **throws** (no / multiple membership). Any future HTTP use of **`assert*`** must **not** blindly equate it with **`getCurrentOrganizationId()`**. |
| **W-2** | **F-25 gate** does **not** require membership; **`countActiveOrganizations() <= 1`** bypass leaves **multi-tab** membership ambiguity unaddressed at the gate layer. |
| **W-3** | **`OrganizationRepositoryScope` null org** ⇒ legacy unscoped reads — **tolerated ambiguity** remains a product/security concern outside membership assert. |
| **W-4** | **`verify_organization_context_resolution_readonly.php`** embedded narrative still describes pre-F-46 behavior (“**No user org membership**” in its summary string) — **stale operator text** vs current resolver; does not change runtime but can mislead audits until refreshed in a docs/script wave. |
| **W-5** | **087 absent:** `UserOrganizationMembershipReadService` returns empty membership answers; strict gate is **`table_absent`**; **`assert*`** throws — any future script/HTTP consumer must handle **table_absent** vs **none** separately from resolver fallback. |

---

## 9. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | A single safest first target is **identified with no material caveats**. |
| **B** | Target is sound; **documented** ecosystem semantics limit earlier alternatives. |
| **C** | No defensible first target. |

**FOUNDATION-50 verdict: B**

**Rationale:** The **verifier-script** target is uniquely aligned with **membership-strict** semantics and avoids **resolver / gate / branch / repository** contradictions; waivers **W-1–W-5** remain **explicit** (especially **W-1**).

---

## 10. Out of scope for the **first** `assert*` adoption wave (when implemented)

- **`OrganizationContextResolver`** / **`OrganizationContextMiddleware`** behavior changes.
- **`StaffMultiOrgOrganizationResolutionGate`** / **`AuthMiddleware`** integration.
- **`BranchDirectory`** org-scoping or mutation paths.
- **Any** new **HTTP route**, UI, dashboard/admin work, or **F-25** exemption edits.
- **Repository-wide** replacement of `OrganizationRepositoryScope` null-org tolerance with **`assert*`**.
- **Schema** changes to `user_organization_memberships` / `users` / `organizations`.

---

## 11. STOP

**FOUNDATION-50** ends here — **no FOUNDATION-51** opened by this audit.

**Companion:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-CONSUMER-SURFACE-MATRIX-FOUNDATION-50.md`.
