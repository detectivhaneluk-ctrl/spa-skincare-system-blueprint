# RESOLVER ORGANIZATION `DomainException` — HTTP 403 CLASSIFICATION MINIMAL IMPLEMENTATION (FOUNDATION-67)

**Wave:** Implements **FOUNDATION-66** follow-up **only** in **`HttpErrorHandler`**. **No** membership/runtime truth lane (F-46–F-64) reopening; **no** resolver, F-25, **`ErrorHandlerMiddleware`**, **`Response.php`**, routes, or schema changes.

---

## 1. Classification rule added

**Method:** `HttpErrorHandler::isResolverOrganizationResolutionDomainException(Throwable $e): bool`

**Logic:** `instanceof \DomainException` **and** `in_array($e->getMessage(), [...], true)` against **exactly four** string literals (see §2).

**Invocation:** First branch inside **`handleException`** after **`config('app.debug')`** check: if **true**, **rethrow** (unchanged). If **false** and classification **true** → **403** handling; else existing **`getStatusCode()`** / **500** path.

---

## 2. Why only these four messages

They are the **only** `DomainException` messages thrown from **`OrganizationContextResolver`** for organization-resolution failures (F-66 inventory):

1. `Branch is not linked to an active organization.`
2. `Unable to resolve organization from single active membership.`
3. `Current branch organization is not authorized by the user's active organization membership.`
4. `Current branch organization is not among the user's active organization memberships.`

**No** other `DomainException` strings are remapped — avoids broad message-based policy.

---

## 3. Why `debug=true` is unchanged

**First statement** in **`handleException`** (after F-67 reorder): `if (config('app.debug')) { throw $e; }` — same behavior as before: **no** classification, **no** 403 mapping, exception propagates for developer visibility.

---

## 4. Why F-25 and resolver stay untouched

- **F-25** remains **`exit`**-based **403** with **`ORGANIZATION_CONTEXT_REQUIRED`** — **no** edits.
- **Resolver** throw sites and copy are **unchanged** — classification **consumes** existing messages only.

---

## 5. Resulting behavior (non-debug)

### JSON (`Accept` contains `application/json`)

- **`Response::jsonError('FORBIDDEN', $e->getMessage())`**
- HTTP **403**, body includes resolver’s **exact** message under `error.message`, `error.code` = **`FORBIDDEN`**, `success` = **false** (existing **`Response`** contract).

### HTML

- **`http_response_code(403)`** then **`renderPage(403)`** (same path as **`handle(403)`** would use for HTML — `layout/errors/403.php` if present, else fallback chain per **`HttpErrorHandler`**).

### All other exceptions

- Unchanged: **`getStatusCode()`** if present, else **500**, then **`handle($code)`**.

---

## 6. Brittleness (message-based classification)

- Any **edit** to resolver **exception text** without updating **`HttpErrorHandler`** list will **fall back** to **500** again.
- **No** `instanceof` narrowing for “org resolution only” beyond **`DomainException`** + whitelist — another subsystem reusing the **same** message string would be misclassified (unlikely if strings stay resolver-specific).

---

## 7. STOP

**FOUNDATION-67** complete. **ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-67-RESOLVER-ORG-DOMAINEXCEPTION-403-CHECKPOINT.zip`.
