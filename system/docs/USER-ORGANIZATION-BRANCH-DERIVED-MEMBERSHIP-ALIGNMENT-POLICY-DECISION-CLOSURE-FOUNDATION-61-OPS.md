# USER-ORGANIZATION — BRANCH-DERIVED MEMBERSHIP ALIGNMENT POLICY DECISION CLOSURE (FOUNDATION-61)

**Mode:** Read-only **policy closure** for a **future** resolver-only implementation wave. **No** code, resolver behavior, middleware, auth, repository scope, routes, schema, or UI changes in this task.

**Evidence read:** `OrganizationContextResolver.php`, `BranchContext.php`, `BranchContextMiddleware.php`, `OrganizationScopedBranchAssert.php`, `OrganizationRepositoryScope.php`, `StaffMultiOrgOrganizationResolutionGate.php`, `UserOrganizationMembershipStrictGateService.php`, `UserOrganizationMembershipReadService.php`, `UserOrganizationMembershipReadRepository.php`, F-60 ops (structural gap + boundary), roadmap §8 tail.

---

## 1. FOUNDATION-60 leaves a real resolver-scoped policy decision unresolved — not an implementation bug fixed elsewhere

**F-60 §2–§3** (unchallenged): On **`getCurrentBranchId() !== null`**, `OrganizationContextResolver` sets **`MODE_BRANCH_DERIVED`** and **returns** at line 48 **without** reading `user_organization_memberships`:

```40:48:system/core/Organization/OrganizationContextResolver.php
        $branchId = $branchContext->getCurrentBranchId();
        if ($branchId !== null) {
            $orgId = $this->activeOrganizationIdForActiveBranch($branchId);
            if ($orgId === null) {
                throw new \DomainException('Branch is not linked to an active organization.');
            }
            $organizationContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

            return;
        }
```

**F-60 §4:** `OrganizationScopedBranchAssert`, F-25, and `OrganizationRepositoryScope` **do not** compare **user membership org set** to **branch-derived org**.

**Conclusion:** The **open** item is **which membership states should deny vs allow** branch-derived org for an **authenticated** user when the **membership table is present** — a **policy** matrix, not a bug fix elsewhere in tree.

---

## 2. Policy cases that must be decided before resolver alignment code (taxonomy)

All cases assume **branch-derived path**: `branchId !== null`, `activeOrganizationIdForActiveBranch` returned positive **`$orgId`** (else existing `DomainException` for unlinked branch applies first).

| # | Case | Detection in resolver (future) |
|---|------|--------------------------------|
| **A** | Guest / no authenticated user (`userId ≤ 0`) | Same as membership block gate: `AuthService::user()` yields missing/zero id (`OrganizationContextResolver` lines 51–52 pattern). |
| **B** | Membership table absent | `UserOrganizationMembershipReadRepository::isMembershipTablePresent()` false → read service counts **0** (`UserOrganizationMembershipReadRepository` lines 60–62). |
| **C** | Table present, **zero** active memberships for user | `countActiveMembershipsForUser === 0`. |
| **D** | Exactly **one** active membership, org id **equals** `$orgId` | `mCount === 1` and `getSingleActiveOrganizationIdForUser === $orgId`. |
| **E** | Exactly **one** active membership, org id **≠** `$orgId` | `mCount === 1` and single org ≠ branch org. |
| **F** | **Multiple** active memberships, **$orgId ∈** distinct org id set | `listActiveOrganizationIdsForUser` contains `$orgId`. |
| **G** | **Multiple** active memberships, **$orgId ∉** distinct org id set | `mCount > 1` and set excludes `$orgId`. |

**Note:** `BranchContextMiddleware` sets **branch null** when session has **no** user (`!$user`), so **case A** overlaps **real traffic** mainly for **theoretical** resolver calls with `userId === 0` while branch non-null; policy still must be fixed for determinism.

```28:35:system/core/middleware/BranchContextMiddleware.php
        $user = $auth->user();
        if (!$user) {
            $context->setCurrentBranchId(null);
            ApplicationTimezone::syncAfterBranchContextResolved();
            ApplicationContentLanguage::applyAfterBranchContextResolved();
            $next();

            return;
        }
```

---

## 3. Recommended policy per case (single closed matrix)

**Principle:** When the **membership pivot is authoritative** (table present **and** user has **≥1** active membership), **branch-derived org must be ∈** that membership org set. When there is **no** membership signal (guest, absent table, or **zero** rows), **do not** block branch-derived org — preserves F-46/F-48 operational behavior and matches “membership not yet governing this user.”

| Case | Recommended policy | Rationale |
|------|-------------------|-----------|
| **A — Guest / `userId ≤ 0`** | **Skip alignment**; proceed with `setFromResolution($orgId, MODE_BRANCH_DERIVED)`. | No membership **subject**; alignment is undefined. Mirrors membership block’s `if ($userId > 0)` entry (`OrganizationContextResolver` lines 51–53). |
| **B — Table absent** | **Skip alignment**; proceed. | Repository returns **0** memberships when table missing (`UserOrganizationMembershipReadRepository` lines 60–62); F-46 legacy branch/org behavior must remain. |
| **C — Zero active memberships** | **Skip alignment**; proceed. | **Fail-open:** user not represented in pivot yet (backfill gap); deny would surprise operators and matches F-60 **W-60-4** continuity concern. **Explicit risk:** branch pivot could diverge until rows exist — accepted waiver **W-61-1**. |
| **D — Single membership, matches** | **Allow**; proceed. | Consistent authorization. |
| **E — Single membership, mismatch** | **Deny:** throw **`DomainException`** with stable message **M1** (§5). | **Fail-closed:** only definitive contradiction between branch org and sole membership org. |
| **F — Multiple, includes branch org** | **Allow**; proceed. | User is authorized for that org among several. |
| **G — Multiple, excludes branch org** | **Deny:** throw **`DomainException`** with stable message **M2** (§5). | **Fail-closed:** branch points at org user is not a member of. |

---

## 4. Fail-open vs fail-closed posture for the future wave

| Posture | Applies to |
|---------|------------|
| **Fail-open (skip alignment)** | **A**, **B**, **C** — no authenticated subject, no table, or **no** active membership rows. |
| **Fail-closed (deny resolution)** | **E**, **G** — ≥1 active membership and branch org **not** in authorized org set. |
| **Pass-through** | **D**, **F** — branch org **authorized** by membership set. |

**Summary:** **Mixed by case** — open where membership does **not** assert authority; closed where membership **explicitly** contradicts branch org.

---

## 5. Resolver-local failure family and stable deny messages

**Family:** **`DomainException`** only (same class as branch unlink and F-57 membership resolution failure — `OrganizationContextResolver` lines 44, 61–64).

**Stable user-facing messages (exact strings for implementation):**

| Id | When | Message |
|----|------|-----------|
| **M1** | Case **E** (single membership org ≠ branch org) | `Current branch organization is not authorized by the user's active organization membership.` |
| **M2** | Case **G** (multiple memberships, branch org not in set) | `Current branch organization is not among the user's active organization memberships.` |

**Code 0**, **`$previous`:** **`null`** unless wrapping an unexpected **`RuntimeException`** from DB layer (mirror F-57 only if inner throw occurs).

**No new** HTTP status mapping in this audit — remains middleware/error-handler responsibility.

---

## 6. Why F-25, repository scope, `BranchContextMiddleware`, `OrganizationScopedBranchAssert`, and downstream consumers must stay untouched

| Surface | Code fact | Why untouched in alignment wave |
|---------|-----------|----------------------------------|
| **`StaffMultiOrgOrganizationResolutionGate`** | Only tests **resolved org id** non-null vs **`countActiveOrganizations() > 1`** (`StaffMultiOrgOrganizationResolutionGate` lines 36–43). | Adding membership logic here **duplicates** resolver policy, **splits** deny timing (post-auth vs global org resolution), and **risks** desync with exempt paths. |
| **`OrganizationRepositoryScope`** | **`resolvedOrganizationId()`** mirrors context only (`OrganizationRepositoryScope` lines 17–21). | SQL helper must **not** own **authorization**; keeps scoping **pure**. |
| **`BranchContextMiddleware`** | Resolves **which branch id** is current from session/user/request (`BranchContextMiddleware` lines 22–95). | Membership **authorization** belongs **after** branch id is known and **with** org id — **resolver** already has both; middleware change would blur **navigation** vs **tenant authorization**. |
| **`OrganizationScopedBranchAssert`** | Compares **branch row’s** `organization_id` to **context** org (`OrganizationScopedBranchAssert` lines 35–47). | Does **not** read membership; remains **row/context** integrity for **payload** branch ids. |
| **Downstream repos/services** | Consume **`OrganizationContext`** after resolution. | Single writer of org truth stays **resolver** (F-59). |

---

## 7. Schema / migration / DB-enforcement prerequisite

**Required for resolver-only PHP to run:** **None** beyond what already exists — reads use `user_organization_memberships` when present (`UserOrganizationMembershipReadRepository`).

**Required for skew to be impossible in data:** **Not proved in PHP tree** — no repository file in scope declares FKs enforcing `users.branch_id` → org ∈ user’s memberships. **Optional operational hardening** (DB constraints, triggers, or batch validators) is a **separate** task; **F-61 does not** mandate a migration for the alignment wave.

---

## 8. Exact minimal resolver-only implementation boundary (later wave)

1. **Files:** **`OrganizationContextResolver.php`** only (private helpers allowed on same class).

2. **Insertion point:** After **`$orgId`** is validated on branch path, **immediately before** `setFromResolution($orgId, MODE_BRANCH_DERIVED)` (line 46 today).

3. **Inputs:** `$orgId`, `$branchId` (for diagnostics only if logged later — not required for policy), `userId` from `AuthService::user()` as today.

4. **Reads:** `isMembershipTablePresent()` and/or `countActiveMembershipsForUser`, `listActiveOrganizationIdsForUser` (or equivalent single query) — **no** new repository methods **required** if existing service methods suffice.

5. **Out of scope:** Changing branch precedence order, F-57 membership-null path, legacy org-count fallback, middleware order, new routes, UI.

---

## 9. Waivers / risks remaining after policy closure

| Id | Waiver / risk |
|----|----------------|
| **W-61-1** | **Case C fail-open** allows branch-derived org for users with **zero** active memberships while table exists — **intentional** continuity; **strict** operators must **backfill** before relying on membership-only security story. |
| **W-61-2** | **Case A** rarely coincides with non-null branch in current **`BranchContextMiddleware`** (guest ⇒ branch cleared, lines 28–35); policy is still **normative** for resolver **determinism**. |
| **W-61-3** | **`DomainException`** surface for **M1/M2** depends on existing global error handling (same class of issue as F-57/F-58). |
| **W-61-4** | **Platform / superadmin** scenarios (if any user can see cross-org branches) need **route/product** review — **out of scope** here; matrix assumes **standard staff** tenant rules. |
| **W-61-5** | **Performance:** one extra membership read on branch-context requests for authenticated users with table present — acceptable if indexed; verify in implementation task. |

---

## 10. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Policy matrix **fully** closed; boundary **actionable**; caveats only in §9. |
| **B** | Residual **product** ambiguity remains in matrix. |
| **C** | Closure **unsupported**. |

**FOUNDATION-61 verdict: A**

**Rationale:** All seven cases have **one** explicit recommendation; fail posture is **mixed** per §4; messages and resolver boundary are **fixed**; §9 lists **residual** operational risks without reopening case decisions.

---

## 11. STOP

**FOUNDATION-61** ends here — **FOUNDATION-62** is **not** opened.

**Companion:** `USER-ORGANIZATION-BRANCH-DERIVED-MEMBERSHIP-ALIGNMENT-DECISION-MATRIX-FOUNDATION-61.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-61-BRANCH-MEMBERSHIP-ALIGNMENT-POLICY-CLOSURE-CHECKPOINT.zip`.
