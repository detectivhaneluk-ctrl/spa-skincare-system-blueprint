# ORGANIZATION-SCOPED BRANCH ASSERT — ERROR-SURFACE PARITY NEXT PROGRAM SELECTION TRUTH AUDIT (FOUNDATION-77)

**Mode:** Read-only selection audit. **No** code, schema, routes, or reopening **F-64**, **F-68**, **F-72**, **F-74**, **F-75**, **F-76** unless contradicted by current tree (**none** found).

**Evidence read:** `OrganizationScopedBranchAssert.php`, `OrganizationContext.php`, `HttpErrorHandler.php`, `ErrorHandlerMiddleware.php`, `PayrollRuleController.php` ( **`store`** + **`ensureBranchAccess`** ); grep `catch (\DomainException` in `system/modules/**/*.php` (pattern inventory); **FOUNDATION-74** OPS (call-site inventory), **FOUNDATION-76** OPS (closure); roadmap §8 tail.

---

## Verdict

**A** — **F-74** and **F-76** baselines are **consistent** with current code; **one** selection outcome is recorded (**§9**).

### Waivers

| ID | Waiver |
|----|--------|
| **W-77-1** | **Optional** future **HTTP** parity (e.g. classifying assert-path **`DomainException`s** to **403**) is **not** blocked by missing facts — it is **deferred** because it **interacts** with the **closed F-68** charter (**resolver-only** whitelist) and requires a **named successor program**, not an implicit follow-up. |
| **W-77-2** | **`modules/**/*.php`** contains **many** **`catch (\DomainException)`** sites **unrelated** to **`OrganizationScopedBranchAssert`**; any **cross-cutting** “normalize local catch” wave risks **conflating** unrelated domain errors with assert failures. |

---

## 1. Baseline closures (F-74 / F-76) — no contradiction

| Wave | Role | Reopen? |
|------|------|--------|
| **F-74** | **22** assert call sites + **`DomainException`** / **F-68** whitelist facts | **No** — code in audit scope still matches narrative |
| **F-75** | Maintainer doc: assert messages vs **F-68** | **No** |
| **F-76** | Closure cross-check **F-75** vs PHP | **No** — **W-76-1** is SCM attestation only |

---

## 2. Runtime error-surface split (code-proven)

### 2.1 Global path to **`HttpErrorHandler`**

**`ErrorHandlerMiddleware`** wraps the pipeline in **`try/catch (Throwable)`** and forwards to **`HttpErrorHandler::handleException`** (```14:18:system/core/middleware/ErrorHandlerMiddleware.php```).

**`HttpErrorHandler::handleException`** (non-debug): **F-68** resolver whitelist branch → **403**; else **`getStatusCode()`** or **500** → **`handle($code)`** (```49:66:system/core/errors/HttpErrorHandler.php```).

**Assert/delegate messages** (**three** strings from **F-74**) are **not** on the whitelist → **uncaught** assert **`DomainException`s** use the **generic** tail (**typically** **500** for plain **`DomainException`**).

### 2.2 Assert-specific local catch (inventory, **F-74**-scoped)

**`PayrollRuleController::store`** — **`try/catch (\DomainException $e)`** maps **`$e->getMessage()`** to **`$errors['_general']`** (```61:76:system/modules/payroll/controllers/PayrollRuleController.php```), covering **`enforceBranchOnCreate`**, the **“Rule branch is required…”** throw, and **`assertBranchOwnedByResolvedOrganization`**.

**Note (same file, different path):** **`ensureBranchAccess`** catches **`DomainException`** from **`BranchContext::assertBranchMatch`** and calls **`HttpErrorHandler::handle(403)`** (```245:257:system/modules/payroll/controllers/PayrollRuleController.php```) — **branch-context** mismatch, **not** the assert helper; included here only to avoid conflating **two** payroll patterns.

**Other modules:** grep shows **widespread** **`catch (\DomainException)`** — **not** assert-specific; **F-74** did not map each to assert usage.

---

## 3. Candidate next programs (risk grouping)

| Candidate | Risk | Verdict |
|-----------|------|--------|
| **A — No implementation / keep documented asymmetry** | **Lowest** | **Selected** — **F-75**/**F-76** already document behavior vs **F-68** |
| **B — Narrow `HttpErrorHandler` parity** (e.g. whitelist assert messages) | **High** — **reopens F-68** “resolver-only” boundary; status/body coupling (**W-68-1** lineage) | **Defer** until explicit **F-68 successor** charter |
| **C — Local-catch normalization** across controllers | **High** — mixed **`DomainException`** sources (**W-77-2**) | **Defer** |
| **D — Assert-consumer hardening** (SQL/predicates) | **High** — tenant/data-plane; orthogonal to HTTP status | **Defer** (separate product track) |
| **E — Documentation-only cleanup** | **Low** but **redundant** — **F-75**/**F-76** satisfy maintainer need | **Not** a separate mandatory wave |

---

## 4. Exactly one recommended next program

**Name:** **`NONE (EXPLICIT DEFERRAL — NO MANDATORY BACKEND WAVE)`**

**Meaning:** After **F-74** (inventory) + **F-75** (error-surface doc) + **F-76** (closure audit), **no** further **backend** work is **required** solely to make the assert/delegate **`DomainException`** story **accurate** or **discoverable**. **Optional** parity work remains **product-gated**.

---

## 5. Why rejected candidates wait

| Candidate | Why wait |
|-----------|----------|
| **`HttpErrorHandler` parity** | Touches **F-68**-closed surface; needs **explicit** new program scope + message policy |
| **Local-catch normalization** | **Broad** grep footprint; not assert-specific |
| **Assert hardening** | **Data/authorization** problem, not **HTTP code** parity |
| **Another read-only audit** | **F-74**/**F-76** already established facts |
| **Documentation-only** | **F-75**/**F-76** complete the doc trail |

---

## 6. First phase for the selected outcome

**No action yet** — **no** scheduled implementation or audit in this area **unless** a **named** product/security task reopens it.

---

## 7. Minimal boundary (if a future program is ever opened)

Any future **HTTP** classification change for assert **`DomainException`s** must:

- Declare interaction with **F-68** (**resolver-only** whitelist) **explicitly**
- **Not** silently expand **`HttpErrorHandler`** without charter review
- Leave **F-64** membership lane, **F-72** repo-scope doc closure, **resolver** body, **middleware** order, and **routes** **untouched** unless that program’s charter says otherwise

---

## 8. Surfaces that must remain untouched without a new charter

- **`OrganizationContextResolver`** throw sites and messages (**F-68** whitelist sync)
- **`HttpErrorHandler::handleException`** classification list (**F-68**)
- **`StaffMultiOrgOrganizationResolutionGate`** (**F-25**)
- **`OrganizationRepositoryScope`** executable SQL (**F-72** posture)
- **Membership** services / backfill (**F-64**)

---

## 9. Why documentation-only was the safest prior wave; why **none** is safest now

**F-75**/**F-76** delivered **non-executable** truth about assert vs **F-68** — **safest** because it **avoided** reopening **F-68**. **F-77** concludes that **another** wave is **not mandatory**; the **safest** “next program” is **explicit deferral** until stakeholders choose **optional** parity with **full** charter.

---

## 10. STOP

**FOUNDATION-77** ends here. **FOUNDATION-78** is **not** opened.

**Deliverables:** this OPS; **`ORGANIZATION-SCOPED-BRANCH-ASSERT-ERROR-SURFACE-PARITY-SURFACE-MATRIX-FOUNDATION-77.md`**; **§8** roadmap row; checkpoint ZIP.
