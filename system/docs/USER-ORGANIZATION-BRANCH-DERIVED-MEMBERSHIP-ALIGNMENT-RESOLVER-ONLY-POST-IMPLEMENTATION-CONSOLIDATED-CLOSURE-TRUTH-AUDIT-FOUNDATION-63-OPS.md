# USER-ORGANIZATION — BRANCH-DERIVED MEMBERSHIP ALIGNMENT, RESOLVER-ONLY POST-IMPLEMENTATION CONSOLIDATED CLOSURE TRUTH AUDIT (FOUNDATION-63)

**Mode:** Read-only closure audit of **FOUNDATION-62**. **No** code, schema, routes, F-25, repository scope, middleware, controllers, or UI changes.

**Evidence read:** `OrganizationContextResolver.php` (full), `audit_user_organization_membership_context_resolution.php` (header + ctor/read checks), `BranchContextMiddleware.php` (sample), `OrganizationScopedBranchAssert.php` (full), `OrganizationRepositoryScope.php` (head), `StaffMultiOrgOrganizationResolutionGate.php` (head), `UserOrganizationMembershipStrictGateService.php` (assert method), `UserOrganizationMembershipReadService.php` / `UserOrganizationMembershipReadRepository.php` (via F-62 ops + resolver usage), grep `assertSingleActiveMembershipForOrgTruth` / `enforceBranchDerivedMembershipAlignmentIfApplicable` in resolver, F-62 implementation doc, roadmap §8 tail.

---

## 1. FOUNDATION-62 changed only the intended resolver-local surface

**HTTP/runtime behavior change for F-62** is confined to **`OrganizationContextResolver`**: new private **`enforceBranchDerivedMembershipAlignmentIfApplicable`** and branch-block call site (lines 48–50) before **`setFromResolution`** (`MODE_BRANCH_DERIVED`).

F-62 ops doc and roadmap row state **no** verifier edits, **no** middleware/F-25/repo scope / **`OrganizationScopedBranchAssert`** changes. Scoped files in this audit match **pre–F-62** structure except resolver body — **no** drift detected in `StaffMultiOrgOrganizationResolutionGate`, `OrganizationRepositoryScope`, `OrganizationScopedBranchAssert`, `BranchContextMiddleware` (read samples), or **`UserOrganizationMembershipStrictGateService`** (definition only; **no** new call sites there).

---

## 2. Resolver precedence remains intact

**Branch-derived first** — non-null `branchId` block still runs before any branch-null logic and **returns** at line 53:

```42:54:system/core/Organization/OrganizationContextResolver.php
        $branchId = $branchContext->getCurrentBranchId();
        if ($branchId !== null) {
            $orgId = $this->activeOrganizationIdForActiveBranch($branchId);
            if ($orgId === null) {
                throw new \DomainException('Branch is not linked to an active organization.');
            }
            $user = $this->auth->user();
            $userId = $user !== null && isset($user['id']) ? (int) $user['id'] : 0;
            $this->enforceBranchDerivedMembershipAlignmentIfApplicable($orgId, $userId);
            $organizationContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

            return;
        }
```

**Membership-single when branch null** — unchanged block: `mCount === 1`, `assertSingleActiveMembershipForOrgTruth`, **`MODE_MEMBERSHIP_SINGLE_ACTIVE`** (lines 56–75).

**Ambiguous membership** — `mCount > 1` → **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** (lines 77–80).

**Legacy single-active-org fallback** — `queryActiveOrganizationCount` and **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`** tail unchanged (lines 84–106).

---

## 3. Branch-derived path applies FOUNDATION-61 matrix (no broader deny policy)

Helper documents cases A–G alignment with F-61; logic:

```118:146:system/core/Organization/OrganizationContextResolver.php
    private function enforceBranchDerivedMembershipAlignmentIfApplicable(int $branchDerivedOrgId, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $mCount = $this->membershipRead->countActiveMembershipsForUser($userId);
        if ($mCount === 0) {
            return;
        }
        if ($mCount === 1) {
            $singleOrgId = $this->membershipRead->getSingleActiveOrganizationIdForUser($userId);
            if ($singleOrgId === null || $singleOrgId <= 0) {
                return;
            }
            if ((int) $singleOrgId !== (int) $branchDerivedOrgId) {
                throw new \DomainException(
                    'Current branch organization is not authorized by the user\'s active organization membership.'
                );
            }

            return;
        }
        $orgIds = $this->membershipRead->listActiveOrganizationIdsForUser($userId);
        if (!in_array((int) $branchDerivedOrgId, $orgIds, true)) {
            throw new \DomainException(
                'Current branch organization is not among the user\'s active organization memberships.'
            );
        }
    }
```

**No** additional throws, **no** membership writes, **no** `assert*` in this method (docblock line 111).

**Narrow extension vs F-61 letter:** If `mCount === 1` but `getSingleActiveOrganizationIdForUser` is null/non-positive, code **returns** (fail-open). F-61 did not name this edge; documented as F-62 implementation choice (**R-63-1**).

---

## 4. Guest / table-absent / zero-membership remain allow/skip

- **Guest:** `userId <= 0` → immediate **return** (lines 120–122).
- **Table absent / zero rows:** `countActiveMembershipsForUser` → `0` per repository contract when table missing or no rows → **return** (lines 123–126). Same pattern as pre-F-62 membership block input.

---

## 5. Single mismatch denies with exact M1 message

String in code (PHP `\'` → apostrophe in output):

`Current branch organization is not authorized by the user's active organization membership.`

(lines 133–135)

---

## 6. Multiple excluding branch denies with exact M2 message

`Current branch organization is not among the user's active organization memberships.`

(lines 142–144)

---

## 7. No `assertSingleActiveMembershipForOrgTruth()` on branch-derived path

Grep in **`OrganizationContextResolver.php`:**

- **`assertSingleActiveMembershipForOrgTruth`** appears **only** at line **64** inside the **branch-null** membership-single block.
- Branch path invokes **`enforceBranchDerivedMembershipAlignmentIfApplicable`** only (line 50).

Helper docblock explicitly states it does **not** call **`assert*`** (line 111).

---

## 8. F-57 membership-single success path remains intact

Same structure: `try` / `catch (\RuntimeException)` → **`DomainException`** `Unable to resolve organization from single active membership.` with **`$previous`**, then **`setFromResolution($assertedOrgId, MODE_MEMBERSHIP_SINGLE_ACTIVE)`** (lines 63–74).

---

## 9. No F-25 drift

**`StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff`** unchanged: exemptions, **`countActiveOrganizations() <= 1`**, **`getCurrentOrganizationId()`**, **`denyUnresolvedOrganization`** (lines 30–45 in scoped read).

---

## 10. No `OrganizationRepositoryScope` drift

**`resolvedOrganizationId()`** still mirrors **`OrganizationContext::getCurrentOrganizationId()`** only (lines 17–21).

---

## 11. No `BranchContextMiddleware` or `OrganizationScopedBranchAssert` drift

**`BranchContextMiddleware::handle`** opening: unauthenticated user still clears branch context (lines 28–35).

**`OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization`** unchanged (full file read).

---

## 12. No unintended mutation/update/delete path

- Resolver alignment uses **`UserOrganizationMembershipReadService`** count/list/getSingle only — read path.
- **`UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth`** remains read/state only (```91:109:system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php```).
- **No** SQL writes added in resolver.

---

## 13. Verifier expectations remain truthful (unchanged script)

**`audit_user_organization_membership_context_resolution.php`** still validates: DB selected, **`MODE_MEMBERSHIP_SINGLE_ACTIVE`**, resolver ctor ≥4 params, read-service contract, optional F-54 **`assert*`** path. It does **not** assert branch-derived alignment text or branch-block structure — still **accurate** after F-62; F-62 explicitly left script **unchanged**.

---

## 14. Remaining waivers / risks after FOUNDATION-62

| Id | Waiver / risk |
|----|----------------|
| **R-63-1** | **`mCount === 1`** with **`getSingleActiveOrganizationIdForUser === null`** (or ≤0): **fail-open skip** — outside F-61 seven-case table; avoids deny on inconsistent read state. |
| **R-63-2** | **`DomainException`** (**M1/M2**, branch unlink, F-57) **HTTP mapping** unchanged — still depends on global error middleware (same class of caveat as F-58). |
| **R-63-3** | **Extra membership reads** on every authenticated **branch-context** request when `userId > 0` and `mCount > 0` — performance/index assumption. |
| **R-63-4** | **AuthService::user()`** read on branch path **before** per-route **`AuthMiddleware`** — same session model as membership-null path; anonymous with non-null branch remains **theoretical** given **`BranchContextMiddleware`**. |

---

## 15. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | F-62 closure fully supported; waivers minor. |
| **B** | Closure supported; **material** documented caveat. |
| **C** | Unsupported by tree. |

**FOUNDATION-63 verdict: A**

**Rationale:** Tree matches F-62 scope and F-61 matrix for all **named** cases; **R-63-1–R-63-4** are **explicit** residuals, not contradictions of the wave charter.

---

## 16. STOP

**FOUNDATION-63** ends here — **no** FOUNDATION-64 opened.

**Companion:** `USER-ORGANIZATION-BRANCH-DERIVED-MEMBERSHIP-ALIGNMENT-RESOLVER-SURFACE-MATRIX-FOUNDATION-63.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-63-BRANCH-DERIVED-MEMBERSHIP-ALIGNMENT-CLOSURE-AUDIT-CHECKPOINT.zip`.
