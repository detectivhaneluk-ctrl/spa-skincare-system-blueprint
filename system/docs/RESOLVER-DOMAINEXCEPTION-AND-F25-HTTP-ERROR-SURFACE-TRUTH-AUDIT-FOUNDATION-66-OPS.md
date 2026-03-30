# RESOLVER `DomainException` AND F-25 HTTP ERROR SURFACE TRUTH AUDIT (FOUNDATION-66)

**Mode:** Read-only. **No** code, schema, or route changes. **Does not** contradict **FOUNDATION-64** closure: this audit **documents** deferred **W-64-1** behavior; it does **not** require reopening the membership/runtime truth lane.

**Evidence read:** `Dispatcher.php`, `ErrorHandlerMiddleware.php`, `HttpErrorHandler.php`, `OrganizationContextMiddleware.php`, `OrganizationContextResolver.php`, `StaffMultiOrgOrganizationResolutionGate.php`, `AuthMiddleware.php` (F-25 call site), `Response.php` (`jsonError` / `codeToHttp`), grep `getStatusCode` in `system/**/*.php`.

---

## 1. Exact middleware/runtime order (resolver, auth, F-25)

**Global pipeline** (`Dispatcher`):

```20:25:system/core/router/Dispatcher.php
    private array $globalMiddleware = [
        \Core\Middleware\CsrfMiddleware::class,
        \Core\Middleware\ErrorHandlerMiddleware::class,
        \Core\Middleware\BranchContextMiddleware::class,
        \Core\Middleware\OrganizationContextMiddleware::class,
    ];
```

**Per-route** middleware follows (typically `AuthMiddleware` then `PermissionMiddleware` per `Dispatcher` docblock lines 14–15).

**Nested execution:** `Csrf` → `ErrorHandler` (try/catch around inner) → `BranchContext` → **`OrganizationContextMiddleware`** → … → route `AuthMiddleware` → **`StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff`** (```51:52:system/core/middleware/AuthMiddleware.php```).

**Consequence:** **`OrganizationContextResolver::resolveForHttpRequest`** runs **before** **`AuthMiddleware`** and **before** **F-25**. If the resolver **throws**, **`AuthMiddleware` and F-25 are never reached** for that request.

---

## 2. Resolver `DomainException` throw sites (reachable in organization resolution flow)

| # | Location | Message (substance) |
|---|----------|---------------------|
| 1 | Branch path, unlinked org | `Branch is not linked to an active organization.` (```46:46:system/core/Organization/OrganizationContextResolver.php```) |
| 2 | Membership-single path, strict gate failure | `Unable to resolve organization from single active membership.` (wrapped `RuntimeException`, ```66:69:system/core/Organization/OrganizationContextResolver.php```) |
| 3 | F-62 branch alignment, single mismatch | `Current branch organization is not authorized by the user's active organization membership.` (```133:135:system/core/Organization/OrganizationContextResolver.php```) |
| 4 | F-62 branch alignment, multi exclude | `Current branch organization is not among the user's active organization memberships.` (```142:144:system/core/Organization/OrganizationContextResolver.php```) |

**No other** `throw new \DomainException` in this resolver file (grep).

---

## 3. How those `DomainException`s are handled (ErrorHandlerMiddleware + HttpErrorHandler)

**ErrorHandlerMiddleware** catches **any** `Throwable` from inner pipeline and delegates to **`HttpErrorHandler::handleException`**:

```12:19:system/core/middleware/ErrorHandlerMiddleware.php
    public function handle(callable $next): void
    {
        try {
            $next();
        } catch (Throwable $e) {
            $handler = Application::container()->get(\Core\Errors\HttpErrorHandler::class);
            $handler->handleException($e);
        }
    }
```

**HttpErrorHandler::handleException:**

```47:54:system/core/errors/HttpErrorHandler.php
    public function handleException(Throwable $e): void
    {
        $code = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        if (config('app.debug')) {
            throw $e;
        }
        $this->handle($code);
    }
```

**PHP `DomainException`** does **not** define **`getStatusCode()`** (confirmed: only reference to `getStatusCode` in `system/**/*.php` is this line in **`HttpErrorHandler`** — grep). Therefore **`$code` is always `500`** for resolver `DomainException`s.

**`handle($statusCode)`** (lines 37–44): sets response code, then JSON if `Accept` contains `application/json`, else HTML error page.

---

## 4. Current HTTP result for resolver `DomainException`s

**Precondition:** `config('app.debug') === false` (normal production-style handling path).

### 4.1 JSON requests (`Accept` contains `application/json`)

- **`sendJson(500)`** → `Response::jsonError('SERVER_ERROR', 'An error occurred.')` (```61:66:system/core/errors/HttpErrorHandler.php``` + ```27:34:system/core/App/Response.php```).
- **HTTP status:** **500** (`codeToHttp('SERVER_ERROR')` → default **500**, ```44:44:system/core/App/Response.php```).
- **Body:** generic **`SERVER_ERROR`** / **`An error occurred.`** — **not** the resolver exception message.

### 4.2 HTML requests

- **`renderPage(500)`** (```68:80:system/core/errors/HttpErrorHandler.php```): `shared_path('layout/errors/500.php')` if present, else `500.php` fallback, else minimal `<h1>500</h1>…`.
- **HTTP status:** **500**.
- **Body:** **not** the resolver `DomainException` message (template-driven).

### 4.3 `debug === true`

- **`handleException` rethrows `$e`** (```50:52:system/core/errors/HttpErrorHandler.php```) — **no** `HttpErrorHandler` JSON/HTML envelope; exception propagates **out of** `ErrorHandlerMiddleware` (typically **uncaught** at top level unless another handler exists — **out of scope** of files read).

**Material difference:** **debug true** exposes exception type/message/stack; **debug false** maps to **generic 500**.

---

## 5. Current HTTP result for F-25 denial

**`denyUnresolvedOrganization`** (```122:140:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php```):

### 5.1 JSON (`Accept` contains `application/json`)

- **403**, `Content-Type: application/json; charset=utf-8`
- Payload: `success: false`, `error.code: ORGANIZATION_CONTEXT_REQUIRED`, `error.message:` gate **`MESSAGE`** constant.

### 5.2 HTML

- **403**, `text/plain`, body = gate **`MESSAGE`**.

**F-25** does **not** use **`HttpErrorHandler`** — it **`exit`s** after output.

---

## 6. Inconsistency: resolver `DomainException` vs F-25 denial

| Dimension | Resolver `DomainException` (non-debug) | F-25 denial |
|-----------|----------------------------------------|-------------|
| **HTTP status** | **500** | **403** |
| **JSON `error.code`** | **`SERVER_ERROR`** | **`ORGANIZATION_CONTEXT_REQUIRED`** |
| **JSON `error.message`** | Generic **`An error occurred.`** | Gate copy: *Organization context is required…* |
| **When reached** | Global org resolution **before** auth | **After** auth, multi-org + unresolved org |
| **Mechanism** | `ErrorHandlerMiddleware` → `HttpErrorHandler` | Direct **`exit`** in gate |

**Yes — inconsistent** status, code, message, and **pipeline stage**.

---

## 7. Cosmetic vs operational vs correctness

- **Cosmetic:** Staff sees different wording — **yes**, but not only cosmetic.
- **Operationally meaningful:** **Yes** — **500** implies **server fault** for conditions that are often **authorization / context** failures; monitoring/retries/API clients will treat differently than **403**.
- **Correctness-affecting:** **Partially** — behavior is **deterministic** and **secure** (request stops); wrong **status semantics** can break **client contracts** and **SLO** classification. **Not** a data-integrity bug.

---

## 8. Where a later fix should target (single primary locus)

| Option | Assessment |
|--------|------------|
| **`HttpErrorHandler` only** | **Best single choke point** — classify **organization-resolution** failures into **403** (and stable JSON) **without** duplicating F-25’s **`exit`** pattern everywhere. |
| **`ErrorHandlerMiddleware` only** | **Thin** — only wraps catch; logic belongs in **`HttpErrorHandler`** or shared helper. |
| **Resolver exception type / mapping only** | **Useful** if introducing **`getStatusCode()`** or a **dedicated exception** — but **must still** be **consumed** by **`HttpErrorHandler`**; **alone** insufficient. |
| **F-25 output contract only** | **Does not fix** resolver **500**s — F-25 never runs when resolver throws. |
| **No change** | **Not** justified if product/API expects **4xx** for “cannot establish org context.” |

---

## 9. Exactly one recommended next follow-up program

### **Program: Narrow implementation — `HttpErrorHandler::handleException` classification for organization-resolution failures**

**Scope:** Extend **`HttpErrorHandler::handleException`** (and, if needed, **minimal** shared helpers **in the same file** or **`Core\Errors`**) to map **known** **`OrganizationContextResolver`** `DomainException` cases (the **four** messages in §2, or a **future** typed exception with **`getStatusCode()`**) to **HTTP 403** and a **JSON** shape **documented** as either **aligned with F-25** (`success` / `error.code` / `error.message`) or a **parallel** stable contract — **product choice in implementation task**.

**Explicitly not in this program:** Changing **F-25** body, changing **resolver** resolution rules (F-64 lane), changing **ErrorHandlerMiddleware** structure beyond any unavoidable one-liner (prefer **none**).

**Alternative sub-step (optional same wave):** Introduce **`OrganizationContextResolutionException`** (or similar) with **`getStatusCode(): int`** **403** thrown from resolver **instead of** bare `DomainException` — **touches resolver**; use **only** if message-based classification in **`HttpErrorHandler`** is rejected as brittle.

**“No follow-up”** — **rejected** here: **W-64-1** remains **true**; **500** for **context/authorization-like** failures is **operationally misleading**.

---

## 10. Waivers / risks

| Id | Waiver / risk |
|----|----------------|
| **W-66-1** | **Message-based** mapping in **`HttpErrorHandler`** is **brittle** if resolver copy changes — prefer **typed exception** or **central constant list**. |
| **W-66-2** | **debug=true** path **bypasses** user-facing mapping — **intentional** for developers; still **inconsistent** with production. |
| **W-66-3** | Harmonizing JSON with **F-25** exactly may **blur** meanings (org **unresolved** vs **resolved-but-denied** vs **misaligned membership**) — product may want **distinct** `error.code`s while keeping **403**. |
| **W-66-4** | Resolver throws **before** auth — some failures may surface as **403/500** **without** hitting **login** — **expected** given pipeline order; document for UX. |
| **W-66-5** | **`Response::jsonError`** uses **`codeToHttp`** — using **`FORBIDDEN`** yields **403**; ensure **chosen** string codes map as intended. |

---

## 11. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Audit **complete**; follow-up **clear**. |
| **B** | **Material** ambiguity remains. |
| **C** | **Unsupported**. |

**FOUNDATION-66 verdict: A**

---

## 12. STOP

**FOUNDATION-66** ends here — **FOUNDATION-67** is **not** opened.

**Companion:** `RESOLVER-DOMAINEXCEPTION-F25-ERROR-SURFACE-MATRIX-FOUNDATION-66.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-66-RESOLVER-DOMAINEXCEPTION-F25-ERROR-SURFACE-CHECKPOINT.zip`.
