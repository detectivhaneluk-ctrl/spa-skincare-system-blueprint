# RESOLVER `DomainException` vs F-25 — ERROR SURFACE MATRIX (FOUNDATION-66)

## Pipeline order (summary)

| Stage | Component |
|-------|-----------|
| 1 (global) | `CsrfMiddleware` |
| 2 (global) | `ErrorHandlerMiddleware` ← catches throws from below |
| 3 (global) | `BranchContextMiddleware` |
| 4 (global) | `OrganizationContextMiddleware` → `OrganizationContextResolver::resolveForHttpRequest` |
| 5+ (route) | e.g. `AuthMiddleware` → `StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff` |

**If stage 4 throws:** stages 5+ **do not run**.

---

## Resolver `DomainException` → HTTP (non-debug)

| Trigger | Status | JSON `error.code` | JSON `error.message` (via `HttpErrorHandler`) |
|---------|--------|-------------------|-----------------------------------------------|
| Any resolver `DomainException` | **500** | **`SERVER_ERROR`** | **`An error occurred.`** |

**Resolver messages are not exposed** in this path.

---

## F-25 denial → HTTP

| | Status | JSON `error.code` | Notes |
|--|--------|-------------------|--------|
| JSON | **403** | **`ORGANIZATION_CONTEXT_REQUIRED`** | Gate `MESSAGE` |
| HTML | **403** | — | Plain text `MESSAGE` |

---

## Inconsistency row

| Aspect | Resolver path | F-25 path |
|--------|---------------|-----------|
| Status | **500** | **403** |
| Client signal | Server error | Forbidden / context required |
| Uses `HttpErrorHandler` | **Yes** | **No** (`exit`) |

---

## Recommended follow-up (one program)

**`HttpErrorHandler::handleException`** — classify organization-resolution failures → **403** (+ stable JSON contract). See **`RESOLVER-DOMAINEXCEPTION-AND-F25-HTTP-ERROR-SURFACE-TRUTH-AUDIT-FOUNDATION-66-OPS.md`** §9.
