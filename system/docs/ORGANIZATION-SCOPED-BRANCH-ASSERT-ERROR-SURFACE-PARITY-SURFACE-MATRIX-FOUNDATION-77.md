# ORGANIZATION-SCOPED BRANCH ASSERT — ERROR-SURFACE PARITY SURFACE MATRIX (FOUNDATION-77)

**Companion:** `ORGANIZATION-SCOPED-BRANCH-ASSERT-ERROR-SURFACE-PARITY-NEXT-PROGRAM-SELECTION-TRUTH-AUDIT-FOUNDATION-77-OPS.md`

---

## A. Baseline references (closed)

| Doc | Role |
|-----|------|
| `ORGANIZATION-SCOPED-BRANCH-ASSERT-DOWNSTREAM-CONSUMER-READ-ONLY-TRUTH-AUDIT-FOUNDATION-74-OPS.md` | Call-site inventory |
| `ORGANIZATION-SCOPED-BRANCH-ASSERT-VS-FOUNDATION-68-HTTP-ERROR-SURFACE-DOCUMENTATION-ONLY-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-76-OPS.md` | **F-75** vs code closure |

---

## B. Global exception dispatch

| Component | Behavior |
|-----------|----------|
| `ErrorHandlerMiddleware` | `catch (Throwable)` → `HttpErrorHandler::handleException` |
| `HttpErrorHandler::handleException` | Resolver whitelist → **403**; else generic **`handle($code)`** |

---

## C. Assert/delegate `DomainException` vs **F-68**

| Assert/delegate message | F-68 **403** whitelist? | Uncaught non-debug |
|-------------------------|-------------------------|---------------------|
| `Branch not found.` | **No** | Generic tail |
| `Branch has no organization assignment.` | **No** | Generic tail |
| `Branch does not belong to the resolved organization.` | **No** | Generic tail |

---

## D. Assert-adjacent local handling (payroll — **F-74**-highlighted)

| Method | Pattern |
|--------|---------|
| `PayrollRuleController::store` | `catch (\DomainException)` → `$errors['_general']` |

**Other `catch (\DomainException)`** sites exist app-wide (grep) — **not** classified here as assert-specific (**W-77-2**).

---

## E. Next-program selection outcome

| Selected | Notes |
|----------|--------|
| **`NONE (explicit deferral)`** | **F-74** + **F-75** + **F-76** complete factual coverage; **optional** **HTTP** parity **deferred** (**W-77-1**) |

---

## F. Rejected (summary)

| Program | Reason |
|---------|--------|
| `HttpErrorHandler` assert parity | **F-68** interaction |
| Local-catch normalization | Scope / conflation risk |
| Assert SQL hardening | Different domain |
| Mandatory doc-only | **F-75**/**F-76** done |
| Mandatory read-only audit | **F-74**/**F-76** done |

---

## G. STOP

**FOUNDATION-78** not opened here.
