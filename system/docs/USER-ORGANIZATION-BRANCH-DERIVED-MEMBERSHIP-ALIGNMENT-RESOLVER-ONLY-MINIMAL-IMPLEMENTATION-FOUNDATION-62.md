# USER-ORGANIZATION — BRANCH-DERIVED MEMBERSHIP ALIGNMENT, RESOLVER-ONLY MINIMAL IMPLEMENTATION (FOUNDATION-62)

**Wave:** FOUNDATION-62 implements **FOUNDATION-61** policy on the **branch-derived** path only. **No** F-25, repository scope, middleware, `OrganizationScopedBranchAssert`, routes, schema, UI, or membership-single / legacy fallback changes.

---

## 1. Insertion point

**File:** `system/core/Organization/OrganizationContextResolver.php`  
**Method:** `resolveForHttpRequest`  
**Location:** Immediately **after** `activeOrganizationIdForActiveBranch` returns a positive org id and **before** `setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED)`.

Flow:

1. Resolve `$orgId` from branch (unchanged).
2. Throw if branch unlinked (unchanged).
3. **NEW:** `$userId` from `AuthService::user()` → `enforceBranchDerivedMembershipAlignmentIfApplicable($orgId, $userId)`.
4. `setFromResolution` + `return` (unchanged).

---

## 2. FOUNDATION-61 cases A–G → code

| Case | Condition | Implementation |
|------|-----------|----------------|
| **A** | Guest / `userId <= 0` | First line of helper: `if ($userId <= 0) return;` |
| **B** | Membership table absent | `countActiveMembershipsForUser` returns `0` (repository short-circuit) → `if ($mCount === 0) return;` |
| **C** | Table present, zero active memberships | Same as **B** (`$mCount === 0`) |
| **D** | Single membership, org = branch org | `$mCount === 1` and `(int) $singleOrgId === (int) $branchDerivedOrgId` → return without throw |
| **E** | Single membership, org ≠ branch org | `$mCount === 1` and ids differ → `DomainException` **M1** |
| **F** | Multiple memberships, branch org ∈ list | `$mCount > 1` and `in_array(..., true)` → return |
| **G** | Multiple memberships, branch org ∉ list | `$mCount > 1` and not in list → `DomainException` **M2** |

**M1 (verbatim):** `Current branch organization is not authorized by the user's active organization membership.`  
**M2 (verbatim):** `Current branch organization is not among the user's active organization memberships.`

**Edge:** `$mCount === 1` but `getSingleActiveOrganizationIdForUser` is null or non-positive → **return** (fail-open), not specified in F-61; avoids throwing on inconsistent read state.

---

## 3. Why only the branch-derived path changed

- **Membership-single path** (branch null, `mCount === 1`, F-57 `assert*`) is **unchanged** — same block, same lines of logic.
- **Ambiguous membership** (branch null, `mCount > 1`) **unchanged**.
- **Legacy single-active-org fallback** **unchanged**.
- **Precedence:** Branch block still runs **first** and returns before any membership-null path.

---

## 4. Why F-25, repository scope, middleware, downstream stay untouched

- **Single policy choke point** for “branch org vs membership set” remains **resolver** (F-59/F-60/F-61).
- **F-25** only tests resolved org non-null in multi-org deployments — **no** membership matrix.
- **`OrganizationRepositoryScope`** mirrors context — **no** authorization logic added.
- **`BranchContextMiddleware`** selects branch id only — alignment uses **existing** `AuthService` + read service inside resolver.
- **`OrganizationScopedBranchAssert`** remains row/context integrity for **branch_id** payloads — **orthogonal** to membership pivot.
- **No** new DI, repositories, or module services.

---

## 5. Verifier: `audit_user_organization_membership_context_resolution.php`

**Not modified.** Checks: DB name, `OrganizationContext` constant, resolver ctor ≥4 params, read-service parity, optional F-54 `assert*` path. None of these assert branch-derived alignment strings or resolver branch-block structure. **Expectations unchanged.**

---

## 6. STOP

**FOUNDATION-62** implementation complete per narrow scope. **ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-62-BRANCH-DERIVED-MEMBERSHIP-ALIGNMENT-CHECKPOINT.zip`.
