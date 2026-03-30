# Route registration topology — truth audit (read-only)

**Wave:** `MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-02` — **ROUTE-REGISTRATION-TOPOLOGY-TRUTH-AUDIT**  
**Mode:** Proof-only. No route moves, middleware behavior changes, or runtime edits.  
**Date:** 2026-03-22 (workspace audit)

---

## 1. Purpose

Record **authoritative fact** about where HTTP routes are registered, how they are grouped, and what hazards apply if routes are split across files later. This doc is the **single canonical audit** for this wave. **No modularization is implemented here.**

---

## 2. Authoritative files (registration layer)

### 2.1 Files that call `$router->get` / `$router->post`

| File | Role |
|------|------|
| `system/routes/web.php` | **Orchestrator** — `require`s **`system/routes/web/register_*.php`** (**14** fragments, **243** registrations) in fixed order, then four module route files (see §2.2). |
| `system/routes/web/register_*.php` | Domain registrar fragments (mechanical split of the former monolith body; same route order as audit baseline). |
| `system/modules/intake/routes/web.php` | Intake admin + **public** intake URLs (`/public/intake*`). |
| `system/modules/gift-cards/routes/web.php` | Gift card staff routes. |
| `system/modules/packages/routes/web.php` | Package definitions + client packages. |
| `system/modules/memberships/routes/web.php` | Membership definitions, client memberships, lifecycle, sales, refund review. |

**Verified (wave 02 baseline):** `rg '\$router->' --glob '*.php' system` returned **only** the central monolith + four module paths. **Post FOUNDATION-05:** central registrations live under `system/routes/web/register_*.php` plus the orchestrator (no `->get`/`->post` in `web.php` itself); module paths unchanged.

### 2.2 Load chain (application bootstrap)

| Step | Location | Behavior |
|------|----------|----------|
| Router build | `system/core/app/Application.php` — `buildRouter()` | `require $this->basePath . '/routes/web.php';` with `$router` in scope. |
| No alternate loaders | — | No other `require` of route files found outside the orchestrator chain (`web.php` → `routes/web/register_*.php` + four module files). |

**Effective registration order:** Registrars listed in `system/routes/web.php` **in array order** (same relative order as the former single-file monolith), then **in order** the four module `require` statements:

1. `modules/intake/routes/web.php`  
2. `modules/gift-cards/routes/web.php`  
3. `modules/packages/routes/web.php`  
4. `modules/memberships/routes/web.php`

Changing this `require` order in a future refactor could change **which route wins** when multiple patterns match (see §5).

### 2.3 Router implementation (matching semantics)

`system/core/router/Router.php`:

- Routes are stored in **registration order** with a monotonic `order` index.
- `match()` collects all routes where method matches and regex matches URI, then **`usort`** candidates by:
  1. **Higher `static_count`**
  2. **Higher `segment_count`**
  3. **Lower `dynamic_count`** (fewer `{…}` segments preferred)
  4. If still tied: **lower `order` index wins** (earlier registration)

Dynamic segments without a constraint use regex **`[^/]+`** (`pathToRegex`). That means **`{id}`** can match non-numeric path segments unless static routes are registered with **strictly better** scores or win the tie-breaker.

**Global HTTP pipeline** (not per-route file): `system/core/router/Dispatcher.php` order: **`CsrfMiddleware`**, **`ErrorHandlerMiddleware`**, **`BranchContextMiddleware`** (timezone + content language), **`OrganizationContextMiddleware`** (FOUNDATION-09), then per-route middleware (typically **`AuthMiddleware`** then **`PermissionMiddleware`**).

---

## 3. Route counts (machine-verified)

| Source | `$router->get` + `$router->post` count |
|--------|----------------------------------------|
| `system/routes/web.php` + `system/routes/web/register_*.php` (combined) | **243** |
| `system/modules/intake/routes/web.php` | **15** (12 staff + 3 public) |
| `system/modules/gift-cards/routes/web.php` | **9** |
| `system/modules/packages/routes/web.php` | **15** |
| `system/modules/memberships/routes/web.php` | **19** |
| **Total** | **301** |

---

## 4. Inventory by URL prefix / domain (within central route registrars, formerly `system/routes/web.php` monolith)

Counts are **prefix aggregates** on the first path segment(s) after `/` (and `api/public/…` as one public block). Sums to **243**.

| Domain / prefix | Count | Auth pattern | Notes |
|-----------------|------:|--------------|--------|
| `/` + `/dashboard` | 2 | `AuthMiddleware` | Shell entry. |
| `/login`, `/password/*`, `/logout`, `/account/password` | 9 | Guest vs auth split | Password reset is guest; logout/account are auth. |
| `/api/public/booking/*` | 7 | **`[]`** | No auth middleware; public booking + manage token flows. |
| `/api/public/commerce/*` | 4 | **`[]`** | Anonymous catalog / purchase / finalize / status. |
| `/settings/*` | 12 | Auth + permission | Includes VAT + payment methods. |
| `/branches/*` | 6 | Auth + permission | Branch admin CRUD. |
| `/marketing/campaigns/*` | 10 | Auth + permission | Campaigns + runs. |
| `/payroll/*` | 14 | Auth + permission | Rules + runs. |
| `/notifications/*` | 3 | Auth + permission | |
| `/clients/*` | 23 | Auth + permission | Registrations, merge, flags, notes, CRUD. |
| `/documents/*` | 13 | Auth + `PermissionMiddleware` **unqualified** in file | Same class as FQCN elsewhere via `use` (§5). |
| `/staff/*` | 20 | Auth + permission | Includes staff groups + permissions JSON admin. |
| `/services-resources/*` | 29 | Auth + permission | Categories, services, rooms, equipment. |
| `/appointments/*` | 27 | Auth + permission | Waitlist, series, blocked slots, CRUD. |
| `/calendar/day` | 1 | Auth + permission | **Alias-style** calendar endpoint (also `appointments/calendar/day`). |
| `/sales/*` | 19 | Auth + permission | Register, invoices, payments, **`/sales/public-commerce/*`** (staff public-commerce ops — **cross-module path**). |
| `/inventory/*` | 35 | Auth + permission | Products, taxonomy, suppliers, movements, counts. |
| `/reports/*` | 9 | Auth + permission | JSON-style reports. |

---

## 5. Modularization hazards (explicit)

1. **Scoring + registration order:** Two routes can both match; winner is by §2.3. **Reordering files** or **splitting** without preserving relative order inside tied groups can change behavior. **Mitigation:** preserve monotonic registration order per method or document an explicit sort contract (future work).

2. **Unconstrained `{id}` segments:** Many paths use `{id}` (not `{id:\d+}`), e.g. **`/clients/{id}`**, **`/staff/{id}`**, **`/services-resources/.../{id}`**, **`/sales/invoices/{id}`**, **`/inventory/products/{id}`**, etc. Router treats them as **`[^/]+`**. **Static paths must stay “more specific”** (higher static segment count / correct order) than catch-alls. Current monolith generally registers static paths (e.g. `/clients/create`) **before** dynamic ones — **do not shuffle** without re-auditing.

3. **Dual calendar paths:** `GET /appointments/calendar/day` and `GET /calendar/day` — different URLs, same feature area; keep grouped when extracting to avoid orphan aliases.

4. **Cross-module URL ownership:** **`/sales/public-commerce/*`** targets `Modules\PublicCommerce\Controllers\PublicCommerceStaffController` under the **`sales`** prefix — **mixed ownership** for future “sales routes” vs “public commerce routes” files.

5. **Cross-permission routes:** e.g. `POST /appointments/{id:\d+}/consume-package` includes **`packages.use`** in addition to appointments edit — appointments + packages entanglement.

6. **Invoice + gift cards:** `POST /sales/invoices/{id}/redeem-gift-card` stacks **`sales.pay`** and **`gift_cards.redeem`**.

7. **Public surface:** `/api/public/*` and `/public/intake*` use **empty per-route middleware arrays** — **anonymous POST** endpoints that intentionally skip session CSRF register **`['csrf_exempt' => true]`** on `Router::post` (A-004); other POST remains CSRF-protected. They still rely on **controller-level validation** and (for booking/commerce) service guards. Splitting public routes into a separate file is **safe for auth** only if global pipeline unchanged; **do not** accidentally add `AuthMiddleware` to those entries.

8. **Import style drift (cosmetic / consistency only):** `web.php` uses `\Core\Middleware\PermissionMiddleware::for(...)` almost everywhere; **`documents/*`** uses `PermissionMiddleware::for(...)` after `use Core\Middleware\PermissionMiddleware`. **Intake module** uses unqualified `PermissionMiddleware` similarly. **No evidence of different classes** — still worth normalizing in a dedicated style pass to reduce review noise.

9. **Partial modularization already:** Four modules load **after** the monolith. Any new overlapping path between monolith and module files would be resolved by §2.3 — today **no deliberate overlap** was found between `/gift-cards`, `/packages`, `/memberships`, `/intake` prefixes and earlier registrations.

---

## 6. Extraction buckets (for future implementation)

| Bucket | Routes (current location) | Notes |
|--------|---------------------------|--------|
| **Core / shell** | `/`, `/dashboard` | Often kept in thin orchestrator. |
| **Auth / security** | `/login`, `/logout`, `/password/*`, `/account/password` | Guest vs authenticated split; keep together. |
| **Public / online booking** | `/api/public/booking/*` | No auth middleware. |
| **Public / commerce** | `/api/public/commerce/*` | No auth middleware. |
| **Public / intake** | `/public/intake*` (intake module) | No auth middleware. |
| **Settings** | `/settings/*` | |
| **Branches / platform admin** | `/branches/*` | |
| **Notifications** | `/notifications/*` | Small, low coupling. |
| **Marketing** | `/marketing/*` | |
| **Payroll** | `/payroll/*` | |
| **Reports / tools** | `/reports/*` | |
| **Clients / CRM** | `/clients/*` | Ordering-sensitive `{id}` (§5). |
| **Documents** | `/documents/*` | |
| **Staff / RBAC** | `/staff/*` | Groups before `{id}` ordering matters. |
| **Services & resources** | `/services-resources/*` | Many `{id}` routes. |
| **Appointments / calendar** | `/appointments/*`, `/calendar/day` | Large; series/waitlist blocks should move together. |
| **Sales / payments / invoices** | `/sales/*` | Includes **public-commerce staff** URLs — **mixed ownership** (§5). |
| **Inventory / catalog** | `/inventory/*` | |
| **Gift cards** | `/gift-cards/*` (module file) | Already extracted pattern. |
| **Packages** | `/packages/*` (module file) | Already extracted pattern. |
| **Memberships** | `/memberships/*` (module file) | Already extracted pattern. |
| **Intake (staff)** | `/intake/*` (module file) | Already extracted pattern. |
| **Unresolved / mixed** | `/sales/public-commerce/*` | Path under sales, controllers in **PublicCommerce** module. |

---

## 7. Safest target extraction architecture (proposal only)

1. **Keep a thin central orchestrator** (today’s `system/routes/web.php` or a renamed `routes.php`) that **only** defines `require` order — same relative order as now unless a future task proves a safe reorder. **Do not** scatter `require` across bootstrap without a single visible sequence.

2. **Per-module route files** under `system/modules/<module>/routes/web.php` (pattern already used for intake, gift-cards, packages, memberships). **Optional:** add `routes/web.php` for other domains incrementally.

3. **Group “public anonymous”** routes in one file or clearly marked section — easiest to audit for missing `AuthMiddleware` and for abuse/CSRF review.

4. **Keep cross-domain slices explicit:** When moving `/sales/public-commerce/*`, either (a) keep in **sales** route file with a comment block “PublicCommerce controllers”, or (b) move to `public-commerce/routes/web.php` but **`require` it in the position that preserves registration order** relative to other `/sales/*` routes (if any interaction by scoring is possible — today paths are distinct).

5. **Preconditions before any extraction PR:**  
   - Scripted **count** check or snapshot test that total registrations and **critical path strings** match pre-move.  
   - Documented **require order** table in the orchestrator header.  
   - Optional: read-only CLI that dumps `method + path` in registration order (future enhancement; not in this wave).

---

## 8. Recommended future implementation order (lowest risk first)

1. **Formalize existing module includes** — document order and add header comments only (no path changes).  
2. **Extract vertically cohesive, low-dependency groups:** `reports/*`, `notifications/*`, `marketing/*`, `payroll/*`, `branches/*`.  
3. **Settings** — self-contained prefix.  
4. **Documents** — self-contained; normalize `PermissionMiddleware` import in same task if desired.  
5. **Staff** — preserve `/staff/groups*`-before-`/staff/{id}` ordering inside the extracted file.  
6. **Clients** — preserve static-before-`/clients/{id}` ordering.  
7. **Services-resources** and **inventory** — large files; extract as whole prefix groups.  
8. **Appointments + `/calendar/day`** — move together; avoid splitting series/waitlist blocks across files without internal ordering doc.  
9. **Sales** — extract last or in dedicated sub-phases: register + invoices + payments + **`public-commerce`** subsection with explicit ownership comment.  
10. **Core shell + auth + public APIs** — either first (orchestrator readability) or last (minimal churn); **if moved**, keep **public** routes in a block with **no** `AuthMiddleware`.

---

## 9. What stays central (even after modularization)

- **Single orchestrator** that `require`s domain files in a **documented order** (may be only ~20–40 lines).  
- Optionally **root + dashboard + auth** remain in orchestrator for fastest bootstrapping readability — product choice; not forced by runtime.

---

## 10. Related audits

- **`BOOTSTRAP-REGISTRATION-TOPOLOGY-TRUTH-OPS.md`** — DI/bootstrap (wave 01); complements this route audit.

---

**Implementation note (FOUNDATION-05):** The **243** central routes were mechanically split into `system/routes/web/register_*.php` and loaded in fixed order by `system/routes/web.php`. **`/sales/public-commerce/*`** remains in `register_sales_public_commerce_staff.php` with **PublicCommerce** controllers (mixed URL ownership unchanged). `Application::registerRoutes()` unchanged.

*End of wave 02 audit — further route splits await explicit review.*
