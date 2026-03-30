# ORGANIZATION-SCOPED BRANCH ASSERT VS FOUNDATION-68 HTTP ERROR SURFACE — DOCUMENTATION ONLY (FOUNDATION-75)

**Mode:** Maintainer documentation **only**. **No** PHP, SQL, schema, routes, resolver, **`HttpErrorHandler`**, middleware, auth, services, controllers, or UI changes.

**Parent:** **FOUNDATION-74** inventory and waivers. This file records **current** relationships between **`OrganizationScopedBranchAssert`** / **`OrganizationContext`** branch assertions and **`HttpErrorHandler`** as implemented under **FOUNDATION-67** / **FOUNDATION-68**.

---

## @see (canonical evidence)

- @see system/docs/ORGANIZATION-SCOPED-BRANCH-ASSERT-DOWNSTREAM-CONSUMER-READ-ONLY-TRUTH-AUDIT-FOUNDATION-74-OPS.md
- @see system/docs/ORGANIZATION-SCOPED-BRANCH-ASSERT-DOWNSTREAM-CONSUMER-MATRIX-FOUNDATION-74.md
- @see system/docs/RESOLVER-ORGANIZATION-DOMAINEXCEPTION-HTTP-403-CLASSIFICATION-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-68-OPS.md

---

## 1. Exact `DomainException` messages (assert / delegate path)

Thrown by **`OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization`** and **`OrganizationContext::assertBranchBelongsToCurrentOrganization`** (delegate):

| Message |
|---------|
| `Branch not found.` |
| `Branch has no organization assignment.` |
| `Branch does not belong to the resolved organization.` |

Source: **FOUNDATION-74** OPS §1–§3; PHP throw sites unchanged by this wave.

---

## 2. Not on FOUNDATION-68’s `HttpErrorHandler` 403 whitelist

**FOUNDATION-68** classifies **exactly four** strings from **`OrganizationContextResolver`** as resolver organization-resolution **`DomainException`s → HTTP 403** (non-debug). The **three** assert-path strings above are **not** in that **`in_array`** list (`isResolverOrganizationResolutionDomainException`).

---

## 3. Non-debug consequence (unless caught locally)

When **`config('app.debug')`** is false, a **`DomainException`** that is **not** matched by the resolver whitelist falls through **`HttpErrorHandler::handleException`** to the branch that uses **`getStatusCode()`** if present, else **500**, then **`handle($code)`** — i.e. the **generic** handler path, **not** the resolver-only **403** + **`FORBIDDEN`** JSON/HTML branch.

So for the **three** assert messages: **unless** some caller catches them first, they follow that **generic** path, **not** the **F-68** **403** path.

---

## 4. Local-catch asymmetry (FOUNDATION-74–proven)

**`Modules\Payroll\Controllers\PayrollRuleController::store`** wraps **`enforceBranchOnCreate`** and **`assertBranchOwnedByResolvedOrganization`** in **`try/catch (\DomainException $e)`** and maps **`$e->getMessage()`** to **`$errors['_general']`** for the create form, so assert failures there **do not** bubble to **`HttpErrorHandler`** on that action.

Other call sites listed in **FOUNDATION-74** do not share this pattern uniformly.

---

## 5. String collision (W-74-1)

- **`BranchDirectory::updateBranch`** / **`softDeleteBranch`** may throw **`InvalidArgumentException`** with message **`Branch not found.`** when **`getBranchByIdForAdmin`** returns null (**before** assert runs).
- **`OrganizationScopedBranchAssert`** throws **`DomainException`** **`Branch not found.`** when its `SELECT` on **`branches`** finds no row.

Same literal message; **different exception types** and code paths. Do not infer handler behavior from the string alone.

---

## 6. FOUNDATION-68 remains closed and truthful

**F-68**’s closure charter is **`HttpErrorHandler`** exact-match classification for **`OrganizationContextResolver`** messages **only**. Assert-path messages were **out of scope** for **F-67** implementation and **F-68** verification. This documentation **does not** reopen **F-68** or contradict its OPS.

---

## 7. Scope boundary (this document)

No **TODO** list, no **roadmap** for product UX, no commitment to extend **`HttpErrorHandler`**. Content is limited to what **FOUNDATION-74** already proved.

---

## 8. Implementation note (FOUNDATION-75)

| Claim | Proof |
|-------|--------|
| **Exact truths** | Sections §1–§6 align with **FOUNDATION-74** OPS/matrix and **F-68** OPS whitelist description. |
| **No executable behavior changed** | Deliverables are **this file** + **§8** row in **`BOOKER-PARITY-MASTER-ROADMAP.md`** only; **zero** `.php` (or other runtime) edits. |
| **Why documentation-only first** | Any **403** / classification change for assert **`DomainException`s** would be a **separate** program (explicit charter, possible **F-68** interaction review). Recording **current** behavior avoids implementers assuming assert errors are **403** like resolver errors. |

---

## 9. STOP

**FOUNDATION-75** ends here. **FOUNDATION-76** is **not** opened by this document.
