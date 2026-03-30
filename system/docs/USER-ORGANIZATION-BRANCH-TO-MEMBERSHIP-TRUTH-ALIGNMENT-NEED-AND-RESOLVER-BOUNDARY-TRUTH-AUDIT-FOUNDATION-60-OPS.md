# USER-ORGANIZATION — BRANCH-TO-MEMBERSHIP TRUTH ALIGNMENT NEED & RESOLVER BOUNDARY TRUTH AUDIT (FOUNDATION-60)

**Mode:** Read-only. **No** code, schema, routes, HTTP/middleware/auth/repository-scope/controller/UI changes.

**Evidence read:** `OrganizationContextResolver.php`, `BranchContext.php`, `BranchContextMiddleware.php`, `OrganizationScopedBranchAssert.php`, `OrganizationRepositoryScope.php`, `StaffMultiOrgOrganizationResolutionGate.php`, `UserOrganizationMembershipStrictGateService.php`, `UserOrganizationMembershipReadService.php`, `UserOrganizationMembershipReadRepository.php`, `OrganizationContext.php`, `BranchDirectory.php`, grep `assertBranchOwnedByResolvedOrganization` / `assertSingleActiveMembershipForOrgTruth`, F-59 ops (§1, §5–§7), roadmap §8 tail.

---

## 1. FOUNDATION-59 correctly leaves second runtime `assert*` consumer unopened

**Current HTTP `assertSingleActiveMembershipForOrgTruth` call sites** remain:

- **`OrganizationContextResolver`** (membership-single path only) — unchanged from F-58/F-59 inventory.
- CLI verifiers + gate definition — non-HTTP.

F-59 **§1** documents **no** second in-process runtime `assert*` module; this audit re-grepped the same pattern — **unchanged**. F-59 **§5–§6** defer a **distinct second** `assert*` site; **alignment** is a **separate** concern (this doc).

---

## 2. Exact branch-derived runtime path and where it becomes authoritative

**2.1 Branch context source** — `BranchContextMiddleware` sets `BranchContext::setCurrentBranchId` from session user, session key `branch_id`, and optional request `branch_id`, constrained by `BranchDirectory::isActiveBranchId` and user-assigned branch rules (inactive user branch ⇒ empty allow-list):

```79:95:system/core/middleware/BranchContextMiddleware.php
        $resolved = null;
        if ($fromRequest !== null && ($allowedBranchIds === null || in_array($fromRequest, $allowedBranchIds, true))) {
            $resolved = $fromRequest;
        } elseif ($fromSession !== null && ($allowedBranchIds === null || in_array($fromSession, $allowedBranchIds, true))) {
            $resolved = $fromSession;
        } elseif ($userBranchId !== null) {
            $resolved = $userBranchId;
        }
        ...
        $context->setCurrentBranchId($resolved);
```

**2.2 Organization resolution** — When `getCurrentBranchId()` is non-null, `OrganizationContextResolver` loads org from **`branches` ⨝ `organizations`**, sets **`MODE_BRANCH_DERIVED`**, and **returns** — membership reads are **not** executed on this path:

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

**2.3 Documented product precedence** — `OrganizationContext` PHPDoc states branch-derived org when branch context is set; membership path applies when branch context is null:

```10:13:system/core/Organization/OrganizationContext.php
 * **Resolution (FOUNDATION-09 + F-46):** When {@see \Core\Branch\BranchContext} has an active branch, organization is read from that row
 * and must reference an **active** organization (`organizations.deleted_at IS NULL`). When branch context is null: if the authenticated
 * user has exactly one active {@code user_organization_memberships} row to a live org, that org is used ({@see self::MODE_MEMBERSHIP_SINGLE_ACTIVE});
```

**Authoritative for HTTP org id:** On branch path, **`branches.organization_id`** (via `activeOrganizationIdForActiveBranch`) is **sole** resolver input; **`user_organization_memberships`** is **skipped**.

---

## 3. Can branch-derived org truth disagree with membership-backed truth for the same user without resolver detection?

**Yes, in code terms:** If the DB holds **both** (a) a resolved current branch whose `organization_id` is **O₁** and (b) active membership rows whose distinct org set is **not** `{O₁}` (e.g. only **O₂**, or multiple orgs excluding **O₁**), the resolver still sets org **O₁** and returns at line 48 **without** reading `user_organization_memberships`.

**Membership-strict single-org truth (F-57 `assert*`)** runs **only** after the branch block is skipped (branch null) and membership count is 1 (`OrganizationContextResolver` lines 51–69). It **never** runs on **`MODE_BRANCH_DERIVED`**.

Therefore **no** in-resolver comparison ties **O_branch** to **membership org set** today.

---

## 4. In-tree guards: full, partial, or none?

| Guard | What it checks | Membership / branch-org alignment? |
|-------|----------------|--------------------------------------|
| **`OrganizationScopedBranchAssert` / `OrganizationContext::assertBranchBelongsToCurrentOrganization`** | Loaded row’s `branches.organization_id` vs **current** `OrganizationContext` org id | **No** membership read. Ensures **branch row ↔ resolved org** consistency for the **branch id under test**, not “user may act in this org per membership.” On branch-derived context, using the **same** branch that defined the org is **internally consistent**; it does **not** validate the user’s membership pivot. |
| **`StaffMultiOrgOrganizationResolutionGate` (F-25)** | Non-null positive org id when `countActiveOrganizations() > 1` | **No** membership comparison. |
| **`OrganizationRepositoryScope`** | Mirrors `getCurrentOrganizationId()` | **No** membership. |
| **`BranchDirectory` lists** | Filter by resolved org id when non-null | **Consumer** of resolved context; **no** membership. |

**Conclusion:** **No** guard **fully** closes **user membership org set ↔ branch-derived org**. **Partial** defense: any code path that passes a **different** `branch_id` into `assertBranchOwnedByResolvedOrganization` enforces that **other** branch belongs to the **same** org as context — still **not** “user has membership in that org.”

---

## 5. Real runtime correctness gap, policy gap, or hypothetical only?

| Lens | Conclusion |
|------|------------|
| **Code / data-model (structural)** | **Real:** resolver **explicitly** isolates branch path from membership reads; skewed rows are **not** detected at resolution. |
| **Documented precedence (policy)** | **Real:** `OrganizationContext` documents **branch-first** when branch is set — **intentional** ordering, not an accidental omission in comments. |
| **Business wrongness frequency** | **Not provable from code** — depends on operational data hygiene and DB constraints (out of this audit’s read scope). |

**Synthesis:** This is a **real structural gap** relative to the **invariant** “staff org context must be **authorized** by active membership whenever the membership table is authoritative.” Under the **alternate** invariant “branch navigation **defines** org regardless of membership,” current behavior is **policy-consistent** but **membership strictness does not extend** to branch-derived mode (F-57 applies only on branch-null membership-single path).

**Not** “hypothetical only” at the code level: the absence of a join is **definite**.

---

## 6. If a future wave is justified, must it be resolver-scoped only?

**Yes** for minimal, coherent closure:

- F-59 proved a **second** parallel `assert*` consumer is **unsafe / redundant**.
- **Branch-derived org** is **chosen only** in `OrganizationContextResolver` (branch block).
- **`BranchContextMiddleware`** must stay responsible for **which branch id** is current, not for membership policy (separation already in tree).
- Moving alignment into F-25, `OrganizationRepositoryScope`, or scattered services **duplicates** policy and fractures error semantics.

**Allowed scope for a future implementation wave:** **`OrganizationContextResolver` only** (including private methods on the same class), **after** `$orgId` is known on the branch path and **before** `setFromResolution(..., MODE_BRANCH_DERIVED)`, unless a **later** roadmap task explicitly expands scope.

---

## 7. Exact minimal implementation boundary (future wave — not opened here)

When product chooses **membership authorization** even under branch context:

1. **File / type boundary:** **`OrganizationContextResolver`** only — **no** edits to `BranchContextMiddleware`, `OrganizationContextMiddleware`, `AuthMiddleware`, `StaffMultiOrgOrganizationResolutionGate`, `OrganizationRepositoryScope`, `OrganizationScopedBranchAssert`, controllers, routes, repositories’ SQL (except if a **separate** migration/task changes schema — out of this boundary).

2. **Trigger:** `getCurrentBranchId() !== null`, `activeOrganizationIdForActiveBranch` returned positive **`$orgId`**, and **`$userId > 0`** from `AuthService::user()` (same pattern as membership block).

3. **Table absence:** When `UserOrganizationMembershipReadRepository::isMembershipTablePresent()` is false, **preserve** current branch-only behavior (align with F-46 “empty membership” semantics).

4. **Policy matrix (must be decided before coding — not specified here):**
   - **0 active memberships:** fail-open (today-equivalent) vs **DomainException** / deny (stricter).
   - **1 active membership:** require **`$orgId ===` that membership org** vs allow branch to win (reject alignment wave).
   - **Multiple active memberships:** require **`$orgId ∈` listed org ids** vs treat as ambiguous and **fail** vs **skip** alignment.

5. **Strict gate reuse:** `assertSingleActiveMembershipForOrgTruth` is **only** valid for **exactly one** active membership; multi-membership alignment **cannot** blindly call it — use **`listActiveOrganizationIdsForUser`** / **`getUserOrganizationMembershipState`** or equivalent read-only probe.

6. **Exception mapping:** Match existing resolver norms (`DomainException` for HTTP-facing resolution failure; preserve **`$previous`** if wrapping inner exceptions).

7. **Precedence:** **Do not** reorder F-46 branch-first rule without explicit product sign-off; alignment is an **additional** gate **on** the branch success path, not swapping membership before branch.

---

## 8. Surfaces that must remain untouched in that future wave (per task rules + minimalism)

- **HTTP behavior / global middleware:** `BranchContextMiddleware`, `OrganizationContextMiddleware`, `Dispatcher` ordering, `ErrorHandlerMiddleware` handling.
- **Auth:** `AuthMiddleware` and session/auth services (resolver already has `AuthService`).
- **F-25:** `StaffMultiOrgOrganizationResolutionGate` body and exemptions.
- **Repository scope:** `OrganizationRepositoryScope` (continues to mirror context only).
- **Downstream choke-point helpers:** `OrganizationScopedBranchAssert` — **no** change required for alignment if resolver pre-validates; changing it would broaden blast radius.
- **Controllers, routes, schema, UI.**

---

## 9. Waivers / risks (explicit)

| Id | Waiver / risk |
|----|----------------|
| **W-60-1** | **Product ambiguity:** Alignment may **contradict** documented **branch-first** precedence if product intends branch to **override** membership; wave needs **explicit** invariant. |
| **W-60-2** | **Resolver runs before per-route `AuthMiddleware`:** `AuthService::user()` is still used on membership path today; alignment must **handle** anonymous / missing user the same way as lines 51–52 (`userId` 0 ⇒ no membership checks). |
| **W-60-3** | **Multi-membership semantics** are **underspecified** for “branch org must be in allowed set” vs “deny all branch-derived multi-org staff” — wrong choice **locks out** valid operators. |
| **W-60-4** | **Zero-membership** users with a valid branch row: strict alignment could **break** deployments that rely on branch pivot **without** backfilled membership (F-48 backfill may not cover every user). |
| **W-60-5** | **Performance:** Extra membership read(s) on **every** branch-context request — acceptable only if bounded (indexed queries; no N+1 in resolver). |
| **W-60-6** | This audit **does not** inspect DB FK/check constraints; production might **already** prevent skew — alignment would then be **redundant** (operational verification needed). |

---

## 10. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Unambiguous gap + resolver-only wave mandatory with minimal debate. |
| **B** | Gap **or** boundary **sound**; **material** product/data caveats. |
| **C** | Claims unsupported by tree. |

**FOUNDATION-60 verdict: B**

**Rationale:** Tree **proves** branch path **never** consults membership and **no** downstream guard restores **membership authorization** for branch-derived org. That is a **definite structural** gap relative to a **membership-authorization** invariant, while **documented branch-first** policy and **unknown** DB enforcement make **business severity** and **wave necessity** **product/ops-dependent** (**W-60-1**, **W-60-6**).

---

## 11. STOP

**FOUNDATION-60** ends here — **FOUNDATION-61** is **not** opened.

**Companion:** `USER-ORGANIZATION-BRANCH-TO-MEMBERSHIP-TRUTH-ALIGNMENT-SURFACE-MATRIX-FOUNDATION-60.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-60-BRANCH-MEMBERSHIP-ALIGNMENT-BOUNDARY-AUDIT-CHECKPOINT.zip`.
