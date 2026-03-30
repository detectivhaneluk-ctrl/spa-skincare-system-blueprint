# RESOLVER ORGANIZATION `DomainException` HTTP 403 CLASSIFICATION — SURFACE MATRIX (FOUNDATION-68)

Post–**FOUNDATION-67** closure (read-only).

| File | Modified for F-67? | Role in F-68 audit |
|------|-------------------|---------------------|
| `HttpErrorHandler.php` | **Yes** | **Only** implementation surface: classification + 403 JSON/HTML |
| `ErrorHandlerMiddleware.php` | **No** | Delegates to **`handleException`** — unchanged |
| `OrganizationContextResolver.php` | **No** | **Four** `DomainException` messages must match whitelist |
| `StaffMultiOrgOrganizationResolutionGate.php` | **No** | **403** + **`ORGANIZATION_CONTEXT_REQUIRED`** — unchanged |
| `Response.php` | **No** | **`jsonError`** / **`codeToHttp`** contract — unchanged |

## Classified messages ↔ resolver lines

| Message | Resolver |
|---------|----------|
| Branch is not linked… | line 46 |
| Unable to resolve organization from single… | lines 66–69 |
| Current branch organization is not authorized… | lines 133–135 |
| Current branch organization is not among… | lines 142–144 |

## JSON outcome (non-debug, classified)

- HTTP **403**
- **`Response::jsonError('FORBIDDEN', $e->getMessage())`**

## HTML outcome (non-debug, classified)

- **`renderPage(403)`** (same as generic 403 path in **`HttpErrorHandler`**)

**Cross-reference:** `RESOLVER-ORGANIZATION-DOMAINEXCEPTION-HTTP-403-CLASSIFICATION-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-68-OPS.md`.
