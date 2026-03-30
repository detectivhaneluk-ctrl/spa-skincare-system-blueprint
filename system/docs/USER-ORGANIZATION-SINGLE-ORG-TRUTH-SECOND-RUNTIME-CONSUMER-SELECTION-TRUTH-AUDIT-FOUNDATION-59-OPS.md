# USER-ORGANIZATION-SINGLE-ORG-TRUTH — SECOND RUNTIME CONSUMER SELECTION TRUTH AUDIT (FOUNDATION-59)

**Mode:** Read-only. **No** code, schema, routes, HTTP/middleware/resolver/auth/branch/repository-scope/controller/UI changes in this task.

**Evidence read:** `OrganizationContextResolver.php`, `StaffMultiOrgOrganizationResolutionGate.php`, `OrganizationRepositoryScope.php`, `UserOrganizationMembershipStrictGateService.php`, `UserOrganizationMembershipReadService.php`, `UserOrganizationMembershipReadRepository.php`, `Dispatcher.php`, `OrganizationContextMiddleware.php`, `AuthMiddleware.php`, `OrganizationContext.php`, `OrganizationScopedBranchAssert.php`, `BranchDirectory.php`, `bootstrap.php`, `modules/bootstrap.php`, grep `assertSingleActiveMembershipForOrgTruth` and `OrganizationRepositoryScope` / `getCurrentOrganizationId` under `system/**/*.php`, F-58 ops, roadmap §8 tail.

---

## 1. FOUNDATION-58 closes only the first resolver runtime `assert*` sublayer; second runtime adoption remains unopened

**Runtime `assertSingleActiveMembershipForOrgTruth` call sites (`system/**/*.php`, grep):**

| Location | Role |
|----------|------|
| `OrganizationContextResolver` | **Only** HTTP/runtime producer of strict membership org id on membership-single path (F-57). |
| `audit_user_organization_membership_backfill_and_gate.php` | CLI verifier (F-51). |
| `audit_user_organization_membership_context_resolution.php` | CLI verifier (F-54). |
| `UserOrganizationMembershipStrictGateService` | Definition only. |

**Conclusion:** After F-57/58, **no** second in-process runtime module invokes `assert*`. F-58 documented closure of **resolver-first** adoption only; a **distinct second runtime assert consumer** is **not** present in tree.

---

## 2. Exact current runtime organization-truth pipeline (after F-57/58)

**Global middleware order** (`Dispatcher`):

```20:25:system/core/router/Dispatcher.php
    private array $globalMiddleware = [
        \Core\Middleware\CsrfMiddleware::class,
        \Core\Middleware\ErrorHandlerMiddleware::class,
        \Core\Middleware\BranchContextMiddleware::class,
        \Core\Middleware\OrganizationContextMiddleware::class,
    ];
```

**Organization fill** — `OrganizationContextMiddleware` resolves **before** route middleware; it only calls `resolveForHttpRequest` (no `assert*` here):

```21:28:system/core/middleware/OrganizationContextMiddleware.php
    public function handle(callable $next): void
    {
        $resolver = Application::container()->get(OrganizationContextResolver::class);
        $branchContext = Application::container()->get(BranchContext::class);
        $organizationContext = Application::container()->get(OrganizationContext::class);

        $resolver->resolveForHttpRequest($branchContext, $organizationContext);
```

**Resolver** — branch-derived first; when branch id is null and membership-single preconditions hold, **`assertSingleActiveMembershipForOrgTruth`** then `setFromResolution` with asserted id (F-57 body unchanged by this audit).

**Post-resolution staff gate (F-25)** — runs **after** successful auth, **after** org context was already populated globally:

```20:21:system/core/middleware/AuthMiddleware.php
 * FOUNDATION-25: after successful auth, {@see StaffMultiOrgOrganizationResolutionGate} blocks multi-org staff when organization context is unresolved.
```

```51:52:system/core/middleware/AuthMiddleware.php
        Application::container()->get(StaffMultiOrgOrganizationResolutionGate::class)->enforceForAuthenticatedStaff();
        $next();
```

**F-25 gate** — uses `OrganizationContext::getCurrentOrganizationId()` and `OrganizationContextResolver::countActiveOrganizations()`; **does not** call `assert*`:

```30:45:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php
    public function enforceForAuthenticatedStaff(): void
    {
        if ($this->isExemptRequestPath()) {
            return;
        }

        if ($this->resolver->countActiveOrganizations() <= 1) {
            return;
        }

        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId !== null && $orgId > 0) {
            return;
        }

        $this->denyUnresolvedOrganization();
    }
```

**Repository scoping** — `OrganizationRepositoryScope` mirrors **`OrganizationContext::getCurrentOrganizationId()`** only; no membership gate:

```17:21:system/core/Organization/OrganizationRepositoryScope.php
    public function resolvedOrganizationId(): ?int
    {
        $id = $this->organizationContext->getCurrentOrganizationId();

        return ($id !== null && $id > 0) ? $id : null;
    }
```

**DI wiring** — `StaffMultiOrgOrganizationResolutionGate` receives `OrganizationContext` + `OrganizationContextResolver` (registered in `system/modules/bootstrap.php` immediately after `OrganizationContextResolver`, A-001); `OrganizationRepositoryScope` receives `OrganizationContext` only (`system/bootstrap.php`).

---

## 3. Remaining runtime-coupled candidate surfaces (post–first `assert*` adoption)

These **consume** resolved org truth **after** middleware resolution; **none** currently call `assert*`:

| Surface | Coupling (code-proven) |
|---------|-------------------------|
| **`StaffMultiOrgOrganizationResolutionGate`** | Reads `OrganizationContext` + org count via resolver (`StaffMultiOrgOrganizationResolutionGate.php`). |
| **`OrganizationRepositoryScope`** | `resolvedOrganizationId()` from context; SQL fragments when id non-null (`OrganizationRepositoryScope.php`). Injected into e.g. `ClientRepository`, marketing repositories, payroll repositories (`grep` / `register_clients.php`, `register_marketing.php`, `register_payroll.php`). |
| **`OrganizationScopedBranchAssert`** | Uses `OrganizationContext::getCurrentOrganizationId()` for branch/org consistency (`OrganizationScopedBranchAssert.php`). |
| **`BranchDirectory`** | Multiple methods gate listing/lookup on `getCurrentOrganizationId()` (`BranchDirectory.php`). |
| **Module services** | e.g. `MarketingCampaignService` checks `getCurrentOrganizationId()` (`MarketingCampaignService.php` grep hit). |
| **`PayrollService` / controllers** | Receive `OrganizationContext` / scoped repos per bootstrap registrations. |

---

## 4. Higher-risk candidates (must not be the second `assert*` consumer)

| Candidate | Why unsafe / disallowed as second `assert*` site |
|-----------|---------------------------------------------------|
| **`StaffMultiOrgOrganizationResolutionGate` (F-25)** | Touches **403 JSON/plain exit**, **path exemptions** (`logout`, `/account/password`, platform org registry). Adding `assert*` changes **HTTP denial semantics** and intersects **multi-org policy** without a dedicated exemption matrix. User task forbids touching F-25 behavior in implementation waves aligned to this audit’s constraints. |
| **`OrganizationRepositoryScope`** | Hot path: many queries per request. Would **conflate** SQL fragment helper with **membership policy**, duplicate reads, and introduce **unclear** exception mapping for repository callers. |
| **`OrganizationContextMiddleware` / `AuthMiddleware`** | User-scoped **global** HTTP ordering; any new `assert*` here duplicates resolver or shifts **failure timing** relative to `ErrorHandlerMiddleware`. |
| **Arbitrary module repository/service** | **Wide blast radius**, inconsistent error surfaces, violates “one choke point” established by F-56/F-57. |

---

## 5. Single safest second runtime adoption target — **none yet** (distinct `assert*` site)

**Proof:**

1. **Same-request redundancy:** `OrganizationContext` is **request-singleton** (`bootstrap.php`); resolver runs **once** per request before authenticated routes. A second `assert*` on the same user in the same request **re-reads** membership state without new concurrency model in PHP request lifecycle.

2. **Downstream surfaces read context only:** `OrganizationRepositoryScope`, `BranchDirectory`, `OrganizationScopedBranchAssert`, and module repos **trust** the id already set by `OrganizationContextResolver`. They do not **derive** membership org id independently — adding `assert*` there is **policy duplication**, not a new truth source.

3. **F-25 is a null-org guard, not a resolver:** When `getCurrentOrganizationId()` is null in multi-org deployments, gate **denies** (`denyUnresolvedOrganization`). It does not **compute** org from membership; injecting `assert*` on the **null** path would amount to **re-resolution** or **new recovery behavior** (out of scope for “second consumer” without a product decision).

4. **Branch vs membership alignment** (F-58 **R-58-3**) is **not** solved by a **parallel** `assert*` consumer: strict gate answers **pivot state for user**, not **branch row vs org row** consistency. Addressing skew is a **resolver policy** or **dedicated alignment** task, not a second assert hook in F-25/repo scope.

**Therefore:** The audit’s **single** recommendation for a **second runtime `assert*` consumer** is **defer — no safe distinct second site** under current architecture and constraints.

---

## 6. Classification for “second runtime adoption” (question 6)

| Option | Verdict |
|--------|---------|
| F-25 gate-level | **No** — HTTP/exemption risk; wrong role (post-hoc guard vs membership truth producer). |
| Repository-scope-level | **No** — wrong abstraction; performance and error-surface explosion. |
| One narrow downstream runtime consumer | **No** — redundant with resolver; would require new DI and duplicate throws. |
| **None yet** | **Yes** — **only** option that is **safe, minimal, and consistent** with F-57/58 closure. |

**Corollary:** The **next** membership-strict improvement that **requires code** is **not** “second consumer” but a **newly scoped** wave (e.g. **resolver extension** for branch↔membership alignment) **explicitly** allowed to edit resolver/branch policy — **outside** “second assert site” framing.

---

## 7. Exact implementation boundary for the **next** wave (when opened)

When a future FOUNDATION task **reopens** runtime work:

1. **Do not** add a **second** `assertSingleActiveMembershipForOrgTruth` call site for **redundant** same-request enforcement without a **written** product reason (e.g. cross-request cache — **not** present today).

2. **If** branch↔membership consistency is required, scope the task as **resolver-level** (or explicit branch-context alignment) **with** listed changes to **precedence** and **documented** HTTP exceptions — **not** F-25 or `OrganizationRepositoryScope` as the primary lever.

3. **Preserve** F-25 contract: **exemptions**, **403** payload, and **`countActiveOrganizations() <= 1`** short-circuit unless a **dedicated** task audits every exempt path.

4. **Preserve** `OrganizationRepositoryScope` as **read-from-context** SQL helpers only.

5. **Continue** CLI verifiers for `assert*` parity **without** conflating them with HTTP runtime consumers.

---

## 8. Waivers / residual risks (explicit)

| Id | Waiver / risk |
|----|----------------|
| **W-59-1** | **No second runtime assert** leaves **defense-in-depth** entirely on **one** resolver path; hypothetical **resolver bypass** (future bug or alternate entrypoint) would not be caught by a second hook — **mitigation** = keep **single** resolution entry (`OrganizationContextMiddleware` + `resolveForHttpRequest`). |
| **W-59-2** | **Branch↔membership skew** remains **unvalidated** at runtime (F-58 **R-58-3**); deferring second consumer does **not** close that gap. |
| **W-59-3** | If product later **mandates** re-assertion (e.g. long-lived workers), that is **out of tree** today — this audit does **not** prove safety for non-PHP-request runtimes. |
| **W-59-4** | **Verdict “none yet”** assumes **no** org context mutation between global middleware and handler; if future code **mutates** `OrganizationContext` without re-resolution, second assert could become justified — **not** observed in scoped files. |

---

## 9. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Exactly **one** safe second runtime `assert*` target identified. |
| **B** | Audit sound; **no** safe distinct second target **or** material caveats documented. |
| **C** | Claims not supported by tree. |

**FOUNDATION-59 verdict: B**

**Rationale:** Tree proves **one** runtime `assert*` consumer (`OrganizationContextResolver`); remaining surfaces are **context readers** or **F-25**; **none** qualify as a **safe second `assert*` site** without redundancy or policy conflict (**W-59-1–W-59-4**).

---

## 10. STOP

**FOUNDATION-59** ends here — **no** FOUNDATION-60 opened by this audit.

**Companion:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-SECOND-RUNTIME-CONSUMER-SURFACE-MATRIX-FOUNDATION-59.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-59-SECOND-RUNTIME-CONSUMER-SELECTION-CHECKPOINT.zip`.
