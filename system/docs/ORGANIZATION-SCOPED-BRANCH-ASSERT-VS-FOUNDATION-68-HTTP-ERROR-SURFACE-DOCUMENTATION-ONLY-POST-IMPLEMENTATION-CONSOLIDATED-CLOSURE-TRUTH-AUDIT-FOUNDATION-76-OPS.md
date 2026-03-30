# ORGANIZATION-SCOPED BRANCH ASSERT VS FOUNDATION-68 — DOCUMENTATION-ONLY POST-IMPLEMENTATION CONSOLIDATED CLOSURE TRUTH AUDIT (FOUNDATION-76)

**Mode:** Read-only closure audit of **FOUNDATION-75**. **No** PHP, resolver, **`HttpErrorHandler`**, middleware, auth, routes, schema, or reopen of **F-64**, **F-68**, **F-72**, **F-74** unless contradicted (**none** found).

**Evidence read:** `ORGANIZATION-SCOPED-BRANCH-ASSERT-VS-FOUNDATION-68-HTTP-ERROR-SURFACE-DOCUMENTATION-ONLY-FOUNDATION-75.md`; `OrganizationScopedBranchAssert.php`; `OrganizationContext.php`; `HttpErrorHandler.php`; `BranchDirectory.php` (update/soft-delete); `PayrollRuleController.php` (`store`); **BOOKER-PARITY-MASTER-ROADMAP.md** §8 tail; **FOUNDATION-74** / **FOUNDATION-68** OPS pointers cited by **F-75**.

---

## Verdict

**B** — Every **substantive** **F-75** claim cross-checks **cleanly** against current PHP; **one** waiver applies to **historical file-delta** proof without SCM.

### Waivers

| ID | Waiver |
|----|--------|
| **W-76-1** | This workspace is **not** a git repository. **FOUNDATION-75**’s claim of **docs + roadmap only** cannot be **re-proven** here via `git diff`. Closure accepts **(a)** **`ORGANIZATION-SCOPED-BRANCH-ASSERT-VS-FOUNDATION-68-HTTP-ERROR-SURFACE-DOCUMENTATION-ONLY-FOUNDATION-75.md` §8**, **(b)** roadmap §8 **F-75** row, and **(c)** **F-76** static read of scoped PHP — **no** contradiction between doc and code. |
| **W-76-2** | **Residual posture (explicit):** Assert-path **`DomainException`s** remain on the **generic** **`HttpErrorHandler`** tail (non-debug) unless locally caught — **documented**, not **changed** by **F-75**/**F-76**. |

---

## 1. FOUNDATION-75 scope vs executable drift

**F-75** document states deliverables = **one** `system/docs/*.md` + **§8** roadmap row, **zero** `.php` edits.

**F-76** verification: audited PHP files match the **F-74**-documented throw sites and handler logic; **no** evidence in this read that **F-75** introduced executable drift (**W-76-1** governs historical delta proof).

---

## 2. Exact three assert/delegate `DomainException` messages — doc vs code

| Message | Code source |
|---------|-------------|
| `Branch not found.` | ```40:40:system/core/Organization/OrganizationScopedBranchAssert.php``` |
| `Branch has no organization assignment.` | ```44:44:system/core/Organization/OrganizationScopedBranchAssert.php``` |
| `Branch does not belong to the resolved organization.` | ```77:77:system/core/Organization/OrganizationContext.php``` |

**F-75** §1 table matches **byte-for-byte** (including trailing periods).

**Precision:** The first **two** strings originate in **`OrganizationScopedBranchAssert`**; the **third** in **`OrganizationContext::assertBranchBelongsToCurrentOrganization`** (invoked from the assert helper). **F-75** groups them as one assert/delegate path — **accurate** for the call chain.

---

## 3. Not on **F-68** `HttpErrorHandler` whitelist — verified

**Whitelist literals** — ```79:86:system/core/errors/HttpErrorHandler.php``` — are the **four** **`OrganizationContextResolver`** messages only. None equal the **three** assert-path strings (string compare). **F-75** §2 **true**.

---

## 4. Non-debug consequence — verified

**`HttpErrorHandler::handleException`** (non-debug): resolver branch first (```54:64```); else ```65:66``` — **`DomainException`** typically has **no** **`getStatusCode()`** → **`$code` = 500** → **`handle($code)`** (generic JSON/HTML via **`sendJson`** / **`renderPage`** for that status).

**F-75** §3 **accurate** for uncaught assert-path **`DomainException`s**.

---

## 5. `PayrollRuleController::store` local catch — verified

```61:76:system/modules/payroll/controllers/PayrollRuleController.php
        try {
            $data = $this->branchContext->enforceBranchOnCreate($data, 'branch_id');
            if ($this->organizationContext->getCurrentOrganizationId() !== null) {
                $bid = $data['branch_id'] ?? null;
                if ($bid === null || $bid === '') {
                    throw new \DomainException('Rule branch is required when organization context is resolved.');
                }
                $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization((int) $bid);
            }
        } catch (\DomainException $e) {
            $errors = ['_general' => $e->getMessage()];
```

**F-75** §4 **accurate** (`_general` key, **`getMessage()`**).

---

## 6. String collision (W-74-1) — verified

**`InvalidArgumentException` `Branch not found.`** — ```169:169:system/core/Branch/BranchDirectory.php```, ```205:205:system/core/Branch/BranchDirectory.php``` (when **`getBranchByIdForAdmin`** null).

**`DomainException` `Branch not found.`** — assert helper ```40:40:system/core/Organization/OrganizationScopedBranchAssert.php```.

**F-75** §5 **accurate**.

---

## 7. **FOUNDATION-68** closure remains truthful

**`HttpErrorHandler`** class doc (```14:15```) and **`isResolverOrganizationResolutionDomainException`** doc (```70:70```) tie whitelist to **`OrganizationContextResolver`** only. **F-75** §6 **consistent** with **F-68** charter; **no** code contradiction forcing **F-68** reopen.

---

## 8. No aspirational / task **TODO** language in **F-75**

Grep: only occurrence of “TODO” is in the **prohibition** “No **TODO** list” (**F-75** §7). **No** hidden implementation promises beyond **F-74**-bounded scope.

---

## 9. Contradiction check vs **F-64** / **F-72** / **F-74**

- **F-64 / F-72:** Scoped files are **not** membership lane or **`OrganizationRepositoryScope`** consumers; **no** contradiction asserted.
- **F-74:** **F-75** content is a **subset/summary** of **F-74** OPS facts on error surface + **W-74-1**; **aligned**.

---

## 10. STOP

**FOUNDATION-76** closes here. **FOUNDATION-77** is **not** opened.

**Deliverables:** this OPS; **`ORGANIZATION-SCOPED-BRANCH-ASSERT-VS-FOUNDATION-68-ERROR-SURFACE-SURFACE-MATRIX-FOUNDATION-76.md`**; **§8** roadmap row; checkpoint ZIP.
