# USER-ORGANIZATION-SINGLE-ORG-TRUTH — FIRST RUNTIME CONSUMER RESOLVER MINIMAL IMPLEMENTATION (FOUNDATION-57)

**Wave:** FOUNDATION-57 — implements **FOUNDATION-56** selection: **one** runtime `assert*` call on the membership-single success path inside **`OrganizationContextResolver::resolveForHttpRequest`**.

---

## 1. Files changed

| Path | Role |
|------|------|
| `system/core/Organization/OrganizationContextResolver.php` | Injects **`UserOrganizationMembershipStrictGateService`**; on membership-single path calls **`assertSingleActiveMembershipForOrgTruth($userId)`**; uses return value for **`setFromResolution`**; maps **`RuntimeException`** → **`DomainException`**. |
| `system/modules/bootstrap.php` | Passes **`UserOrganizationMembershipStrictGateService`** into resolver singleton. |
| `system/scripts/audit_user_organization_membership_context_resolution.php` | Reflection: ctor must have **≥ 4** parameters (adds strict gate). |
| `system/docs/BOOKER-PARITY-MASTER-ROADMAP.md` | **FOUNDATION-57** row. |

**Not changed:** `register_organizations.php` (strict gate already registered). F-25, **`OrganizationRepositoryScope`**, routes, schema, UI, branch logic, legacy fallback, downstream modules.

---

## 2. Exact call site

**Location:** `OrganizationContextResolver::resolveForHttpRequest`, immediately after the existing guard **`$singleOrgId !== null && $singleOrgId > 0`** (branch context **null**, authenticated **`$userId > 0`**, **`$mCount === 1`**).

**Logic:**

1. **`$assertedOrgId = $this->membershipStrictGate->assertSingleActiveMembershipForOrgTruth($userId);`**
2. **`$organizationContext->setFromResolution($assertedOrgId, OrganizationContext::MODE_MEMBERSHIP_SINGLE_ACTIVE);`**

The prior **`$singleOrgId`** check remains the **gate** to enter this block (same eligibility as before F-57); the **authoritative** org id for context is now **`$assertedOrgId`**.

---

## 3. Why only `MODE_MEMBERSHIP_SINGLE_ACTIVE` changed

- **Branch-derived** path returns earlier — **no** `assert*`.
- **`mCount > 1`** sets ambiguous unresolved — **no** `assert*`.
- **Guest / no user** falls through — **no** `assert*` on membership-single block.
- **Legacy single-active-org fallback** runs only after membership branch completes without return — **unchanged**.

---

## 4. Why F-25, repository scope, and downstream stay untouched

- **F-25** reads **`OrganizationContext`** after resolution; it does not call **`assert*`** and was not edited.
- **`OrganizationRepositoryScope`** only reads **`getCurrentOrganizationId()`**; no resolver constructor or SQL fragment changes.
- **Controllers / marketing / payroll / clients** — no edits; they still consume whatever context the resolver set.

---

## 5. Exception mapping (local to resolver)

**`UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth`** throws **`RuntimeException`** with stable internal messages.

**Mapping:** catch **`RuntimeException`** at this **single** call site; rethrow **`DomainException`** with:

- **Message:** `Unable to resolve organization from single active membership.` (stable, explicit)
- **`$previous`:** original **`RuntimeException`** (preserves operator/debug chain)

This matches the **same exception family** already used in this class for resolution failure (**`DomainException`** for invalid branch → org link at line 40).

**No** new global error handler or middleware changes in this wave.

---

## 6. Behavior unchanged (summary)

| Path | Change |
|------|--------|
| Branch-derived org | **None** |
| Ambiguous membership | **None** |
| Guest / unauthenticated | **None** |
| Legacy single-org fallback | **None** |
| Membership-single success | **Yes** — strict gate assert + **`DomainException`** on strict-gate failure |

---

## 7. DI

**`OrganizationContextResolver`** is constructed only from **`modules/bootstrap.php`**. **`UserOrganizationMembershipStrictGateService`** is already a singleton from **`register_organizations.php`** (loaded before resolver registration).

---

## 8. Verifier

**`audit_user_organization_membership_context_resolution.php`** now requires the resolver ctor to accept **at least four** dependencies, documenting **F-57** wiring truth.

---

## 9. STOP

**FOUNDATION-57** complete — **no FOUNDATION-58** opened here.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-57-FIRST-RUNTIME-CONSUMER-RESOLVER-CHECKPOINT.zip`.
