# USER-ORGANIZATION-SINGLE-ORG-TRUTH — FIRST RUNTIME CONSUMER RESOLVER POST-IMPLEMENTATION CONSOLIDATED CLOSURE TRUTH AUDIT (FOUNDATION-58)

**Mode:** Read-only closure audit of **FOUNDATION-57**. **No** code, schema, routes, F-25, repository scope, downstream modules, or UI changes.

**Evidence read:** `OrganizationContextResolver.php`, `modules/bootstrap.php`, `register_organizations.php` (strict-gate registration), `audit_user_organization_membership_context_resolution.php`, `UserOrganizationMembershipStrictGateService.php`, `UserOrganizationMembershipReadService.php`, `UserOrganizationMembershipReadRepository.php`, `StaffMultiOrgOrganizationResolutionGate.php`, `OrganizationRepositoryScope.php`, repo-wide grep `assertSingleActiveMembershipForOrgTruth`, F-57 ops + roadmap.

---

## 1. FOUNDATION-57 changed only the intended first runtime consumer surface

**Runtime `assert*` call sites (grep, `system/**/*.php`):**

- **`OrganizationContextResolver`** — one call (membership-single path).
- **CLI verifiers** — unchanged from prior waves (`audit_user_organization_membership_*`).
- **`UserOrganizationMembershipStrictGateService`** — method definition only.

**DI:** `modules/bootstrap.php` passes **`UserOrganizationMembershipStrictGateService`** into **`OrganizationContextResolver`** (lines 41–48). **`register_organizations.php`** still only **registers** the strict gate (lines 15–21); **no** F-57-specific structural change required there beyond pre-existing singleton.

**Conclusion:** The **first HTTP/runtime** adoption is **resolver-only** plus **minimal bootstrap + verifier reflection**; no other runtime modules were edited for F-57.

---

## 2. Resolver precedence remains intact

**Branch-derived** — unchanged early return after `MODE_BRANCH_DERIVED` (lines 40–48):

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

**Membership path only when branch is null** — the membership block (lines 51–77) runs only **after** the branch branch returns.

**Ambiguous membership** — `mCount > 1` still sets **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** (lines 72–75); **no** `assert*`.

**Legacy single-active-org fallback** — unchanged tail from `$activeCount` through **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`** (lines 79–101).

---

## 3. `assertSingleActiveMembershipForOrgTruth($userId)` is called only on the existing membership-single success path

The call is nested under: **`$userId > 0`**, **`$mCount === 1`**, **`$singleOrgId !== null && $singleOrgId > 0`**, inside **`try`** (lines 53–66). No other occurrence in **`OrganizationContextResolver`**.

---

## 4. `setFromResolution()` uses the asserted org id only on that path

On success, **`setFromResolution($assertedOrgId, OrganizationContext::MODE_MEMBERSHIP_SINGLE_ACTIVE)`** (line 67). Other **`setFromResolution`** call sites use **`$orgId`**, **`null`**, **`$id`** as before — not **`$assertedOrgId`**.

---

## 5. `RuntimeException` mapping stays local to the resolver with a stable `DomainException` message

Only the **`assert*`** `try`/`catch` wraps **`RuntimeException`** → **`DomainException('Unable to resolve organization from single active membership.', 0, $e)`** (lines 58–65). **Stable** user-facing message; **`$previous`** preserves the strict-gate **`RuntimeException`**.

No other **`catch (\RuntimeException`** in this class for org resolution.

---

## 6. No F-25 logic drift

**`StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff`** is unchanged in structure: exemptions, **`countActiveOrganizations() <= 1`**, then **`getCurrentOrganizationId()`** (lines 30–45). **No** `assert*` or resolver body edits.

```30:45:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php
    public function enforceForAuthenticatedStaff(): void
    {
        if ($this->isExemptRequestPath()) {
            return;
        }

        if ($this->resolver->countActiveOrganizations() <= 1) {
            return;
        }

        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId !== null && $orgId > 0) {
            return;
        }

        $this->denyUnresolvedOrganization();
    }
```

---

## 7. No `OrganizationRepositoryScope` drift

**`resolvedOrganizationId()`** still mirrors **`OrganizationContext::getCurrentOrganizationId()`** (lines 17–21). File unchanged regarding org resolution semantics.

```17:21:system/core/Organization/OrganizationRepositoryScope.php
    public function resolvedOrganizationId(): ?int
    {
        $id = $this->organizationContext->getCurrentOrganizationId();

        return ($id !== null && $id > 0) ? $id : null;
    }
```

---

## 8. No downstream controller/service/module drift (F-57 scope)

**`OrganizationContextResolver`** is constructed **only** from **`modules/bootstrap.php`** (grep: no other `new OrganizationContextResolver` in `system/**/*.php`). Downstream code continues to consume **`OrganizationContext`** / scope **without** new dependencies on **`assert*`**.

---

## 9. Verifier expectation update is truthful and minimal

**`audit_user_organization_membership_context_resolution.php`** requires constructor **≥ 4** parameters and names **`UserOrganizationMembershipStrictGateService`** in the error string (lines 58–61), matching **`OrganizationContextResolver`’s** four constructor dependencies (lines 20–25).

```58:62:system/scripts/audit_user_organization_membership_context_resolution.php
    $resolverRef = new ReflectionClass(\Core\Organization\OrganizationContextResolver::class);
    $ctor = $resolverRef->getConstructor();
    if ($ctor === null || $ctor->getNumberOfParameters() < 4) {
        $errors[] = 'OrganizationContextResolver must accept Database + AuthService + UserOrganizationMembershipReadService + UserOrganizationMembershipStrictGateService';
    }
```

---

## 10. No unintended mutation / update / delete path

- **`assertSingleActiveMembershipForOrgTruth`** only reads state via **`getUserOrganizationMembershipState`** (```91:109:system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php```) — **no** SQL writes.
- **`UserOrganizationMembershipReadRepository`** remains read-only for membership pivot (scoped file; no F-57 edits).
- Resolver path adds **no** INSERT/UPDATE/DELETE.

---

## 11. Remaining waivers / risks after FOUNDATION-57

| Id | Waiver / risk |
|----|----------------|
| **R-58-1** | **`DomainException`** from membership-single resolution propagates to the **global error pipeline**; **HTTP status / UX** depend on existing **`ErrorHandlerMiddleware`** (or equivalent) — **not** defined in F-57. |
| **R-58-2** | **Defense-in-depth cost:** On every membership-single request, **`assert*`** duplicates work already implied by **`mCount === 1`** + **`getSingleActiveOrganizationIdForUser`**. |
| **R-58-3** | **Branch vs membership:** **Branch-derived** org is still **not** cross-checked against membership pivot; skew remains a **separate** product/integrity topic. |
| **R-58-4** | **Impossible-inner inconsistency:** If read service and gate ever diverge while both claim single membership, **`DomainException`** could surface despite **`$singleOrgId`** guard — intentional strictness. |

---

## 12. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | F-57 closure with no material residual caveats. |
| **B** | F-57 met; **documented** HTTP-handler / performance / skew caveats. |
| **C** | Closure unsupported by tree. |

**FOUNDATION-58 verdict: B**

**Rationale:** Tree evidence matches F-57 intent (single runtime call site, precedence preserved, F-25/repo scope untouched); **R-58-1–R-58-4** remain **explicit**.

---

## 13. STOP

**FOUNDATION-58** ends here — **no FOUNDATION-59** opened by this audit.

**Companion:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-FIRST-RUNTIME-CONSUMER-RESOLVER-SURFACE-MATRIX-FOUNDATION-58.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-58-FIRST-RUNTIME-CONSUMER-RESOLVER-CLOSURE-AUDIT-CHECKPOINT.zip`.
