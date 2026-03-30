# ORGANIZATION-SCOPED BRANCH ASSERT VS FOUNDATION-68 — ERROR SURFACE MATRIX (FOUNDATION-76)

**Companion:** `ORGANIZATION-SCOPED-BRANCH-ASSERT-VS-FOUNDATION-68-HTTP-ERROR-SURFACE-DOCUMENTATION-ONLY-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-76-OPS.md`

---

## A. Assert / delegate `DomainException` messages

| # | Message | Thrown in PHP |
|---|---------|----------------|
| 1 | `Branch not found.` | `OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization` |
| 2 | `Branch has no organization assignment.` | `OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization` |
| 3 | `Branch does not belong to the resolved organization.` | `OrganizationContext::assertBranchBelongsToCurrentOrganization` |

---

## B. **F-68** whitelist (`HttpErrorHandler::isResolverOrganizationResolutionDomainException`)

| Whitelisted message (resolver only) |
|-------------------------------------|
| `Branch is not linked to an active organization.` |
| `Unable to resolve organization from single active membership.` |
| `Current branch organization is not authorized by the user's active organization membership.` |
| `Current branch organization is not among the user's active organization memberships.` |

**Assert-path rows A1–A3:** **not** in this list.

---

## C. Non-debug `HttpErrorHandler::handleException` routing

| Exception | Resolver whitelist match? | Next step (non-debug) |
|-----------|---------------------------|------------------------|
| Assert-path **`DomainException`** (A1–A3) | **No** | **`getStatusCode()`** or **500** → **`handle($code)`** (generic) |
| Resolver **`DomainException`** (four strings) | **Yes** | **403** + **`FORBIDDEN`** + **`getMessage()`** (JSON) or **`renderPage(403)`** |

---

## D. Documented local catch

| Location | Behavior |
|----------|----------|
| `PayrollRuleController::store` | **`catch (\DomainException $e)`** → **`$errors['_general'] = $e->getMessage()`** |

---

## E. String collision (W-74-1 / **F-75** §5)

| Source | Type | Message |
|--------|------|---------|
| `BranchDirectory::updateBranch` / `softDeleteBranch` | **`InvalidArgumentException`** | `Branch not found.` |
| `OrganizationScopedBranchAssert` | **`DomainException`** | `Branch not found.` |

---

## F. **FOUNDATION-75** document cross-check

| **F-75** § | Closure status |
|------------|----------------|
| §1 Three messages | **Match** code |
| §2 Not on F-68 whitelist | **Match** `HttpErrorHandler` |
| §3 Generic handler path | **Match** `handleException` |
| §4 `PayrollRuleController::store` | **Match** controller |
| §5 String collision | **Match** `BranchDirectory` + assert |
| §6 F-68 closed | **Match** handler doc + whitelist scope |
| §7 No TODO / no HttpErrorHandler commitment | **Match** grep |
| §8 Implementation note | **Consistent** with **F-76** findings |

---

## G. STOP

**FOUNDATION-77** not opened here.
