# RESOLVER ORGANIZATION `DomainException` HTTP 403 CLASSIFICATION — POST-IMPLEMENTATION CONSOLIDATED CLOSURE TRUTH AUDIT (FOUNDATION-68)

**Mode:** Read-only closure audit of **FOUNDATION-67**. **No** code, schema, or route changes. **Does not** contradict **FOUNDATION-64**: **F-67** touched **`HttpErrorHandler` only** — **no** membership pivot, resolver rules, or backfill path changes.

**Evidence read:** `HttpErrorHandler.php` (full), `ErrorHandlerMiddleware.php`, `OrganizationContextResolver.php` (throw sites), `StaffMultiOrgOrganizationResolutionGate.php` (`denyUnresolvedOrganization`), `Response.php`, F-67 ops, roadmap §8 tail.

---

## 1. FOUNDATION-67 changed only the intended `HttpErrorHandler` surface

**Diff scope (tree truth):** **`HttpErrorHandler`** contains **F-67** behavior (`handleException` branch + `isResolverOrganizationResolutionDomainException` + class docblock). **No** edits required in scoped **`ErrorHandlerMiddleware`**, **`OrganizationContextResolver`**, **F-25**, or **`Response.php`** for this audit — they match **F-67 charter** (implementation notes: **`HttpErrorHandler` only**).

---

## 2. `debug=true` behavior remains unchanged (rethrow path intact)

```49:53:system/core/errors/HttpErrorHandler.php
    public function handleException(Throwable $e): void
    {
        if (config('app.debug')) {
            throw $e;
        }
```

**Any** `Throwable` **rethrows** before classification or generic **`handle($code)`** — same **observable** behavior as **F-66** baseline (pre-F-67 computed `$code` before debug check but never used it when debug was true).

---

## 3. Non-debug: exactly four resolver messages → 403; no broader family

**Whitelist** in **`isResolverOrganizationResolutionDomainException`** (lines 79–88): four **string** literals, **`in_array(..., true)`**, **`instanceof \DomainException`** (lines 72–76).

**Resolver throw sites (exact `getMessage()` text):**

```46:46:system/core/Organization/OrganizationContextResolver.php
                throw new \DomainException('Branch is not linked to an active organization.');
```

```66:69:system/core/Organization/OrganizationContextResolver.php
                        throw new \DomainException(
                            'Unable to resolve organization from single active membership.',
                            0,
                            $e
                        );
```

```133:135:system/core/Organization/OrganizationContextResolver.php
                throw new \DomainException(
                    'Current branch organization is not authorized by the user\'s active organization membership.'
                );
```

```142:144:system/core/Organization/OrganizationContextResolver.php
            throw new \DomainException(
                'Current branch organization is not among the user\'s active organization memberships.'
            );
```

**Byte match:** PHP runtime **`getMessage()`** for the two multi-line throws equals the single-line strings in **`HttpErrorHandler`** (apostrophe in **`user's`** matches escaped form in **`HttpErrorHandler`** line 84–85). **No** fifth string; **no** prefix/substring matching.

---

## 4. JSON (non-debug): contract for classified exceptions

```54:60:system/core/errors/HttpErrorHandler.php
        if ($this->isResolverOrganizationResolutionDomainException($e)) {
            http_response_code(403);
            if ($this->wantsJson()) {
                Response::jsonError('FORBIDDEN', $e->getMessage());

                return;
            }
```

**`Response::jsonError`** (unchanged):

```22:34:system/core/App/Response.php
    public static function jsonError(string $code, string $message, ?array $details = null): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(self::codeToHttp($code));
        echo json_encode([
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
```

**Result:** **`FORBIDDEN`** → **`codeToHttp` → 403**; **`message`** = exception **`getMessage()`** (resolver copy). **Top-level shape** unchanged.

---

## 5. HTML (non-debug): existing 403 page family for those four messages only

Same branch: **`renderPage(403)`** (line 61) — same helper as **`handle(403)`** would use for HTML (```103:115:system/core/errors/HttpErrorHandler.php```): `layout/errors/403.php` if present, else **500.php** fallback, else minimal HTML.

---

## 6. All other exception handling unchanged

After classification **miss**, behavior matches **pre-F-67** tail:

```65:66:system/core/errors/HttpErrorHandler.php
        $code = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        $this->handle($code);
```

**`handle`**, **`sendJson`**, **`renderPage`** bodies **unchanged** aside from **F-67** docblock on class.

---

## 7. No resolver throw-site / message drift

**Grep** `throw new \DomainException` in **`OrganizationContextResolver.php`**: **four** sites only — all listed in §3; **no** F-67 edits to that file in this audit’s tree read.

---

## 8. No F-25 behavior drift

**`denyUnresolvedOrganization`** and **`wantsJson`** unchanged from **F-66** reference (```122:146:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php```): **403**, **`ORGANIZATION_CONTEXT_REQUIRED`**, **`exit`**.

---

## 9. No `ErrorHandlerMiddleware` structure drift

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

**Unchanged** try/catch delegation pattern.

---

## 10. No `Response.php` contract drift

**`jsonSuccess`**, **`jsonError`**, **`codeToHttp`** — **no** F-67 edits; **`HttpErrorHandler`** uses **existing** **`jsonError('FORBIDDEN', …)`** signature only.

---

## 11. Remaining brittleness / waivers after FOUNDATION-67

| Id | Waiver / risk |
|----|----------------|
| **W-68-1** | **Message coupling:** Resolver **copy change** without updating **`HttpErrorHandler`** list → **500** regression. |
| **W-68-2** | **`DomainException` + same literal** thrown elsewhere → **misclassified** as resolver org failure (low likelihood if strings stay unique). |
| **W-68-3** | **JSON path:** **`http_response_code(403)`** then **`Response::jsonError`** sets status again — **redundant** but **harmless**. |
| **W-68-4** | **F-25 JSON** still uses **`error.code` = `ORGANIZATION_CONTEXT_REQUIRED`**; **F-67 path** uses **`FORBIDDEN`** — **intentional** difference (F-66 noted semantic split); clients must **not** assume one code for all **403** org-context failures. |

**F-64:** **No** contradiction — membership lane **closure** did not assert **`HttpErrorHandler`** would never change; **F-67** is **outside** lane per **F-64 §8** (UX/error handling follow-up).

---

## 12. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Closure **complete**; waivers **explicit**. |
| **B** | **Material** gap. |
| **C** | **Unsupported**. |

**FOUNDATION-68 verdict: A**

---

## 13. STOP

**FOUNDATION-68** ends here — **FOUNDATION-69** is **not** opened.

**Companion:** `RESOLVER-ORGANIZATION-DOMAINEXCEPTION-HTTP-403-CLASSIFICATION-SURFACE-MATRIX-FOUNDATION-68.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-68-RESOLVER-ORG-DOMAINEXCEPTION-403-CLOSURE-AUDIT-CHECKPOINT.zip`.
