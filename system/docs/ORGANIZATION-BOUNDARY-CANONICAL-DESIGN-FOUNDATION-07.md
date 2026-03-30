# Organization boundary — canonical design and enforcement plan

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-07 — ORGANIZATION-BOUNDARY-CANONICAL-DESIGN-AND-ENFORCEMENT-PLAN  
**Status:** Design-only; **no** schema migration, **no** runtime behavior change, **no** UI.  
**Upstream truth (single source for “current facts”):** `ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md` (FOUNDATION-06, accepted FULL ZIP audit).  
**Companion:** `ORGANIZATION-BOUNDARY-ENFORCEMENT-CHECKLIST-FOUNDATION-07.md` (matrix + wave gate checklist).

---

## PROVED CURRENT TRUTH (from FOUNDATION-06; do not reinterpret without re-audit)

- **Deployment / DB:** One application schema, one `Database` connection — no per-tenant database or schema selection in code.
- **Highest *implemented* isolation:** `branches` + nullable `branch_id` on domain rows + `branch_id = 0` / positive branch overlay on `settings`; **no** `organization_id` (or equivalent) in the model today.
- **Request authority:** `BranchContextMiddleware` resolves `BranchContext` from request → session → `users.branch_id`; branch-assigned users cannot pivot to another branch via request; `users.branch_id IS NULL` ⇒ “HQ / superadmin” pattern (still **not** an org boundary).
- **Writes:** Many services use `BranchContext::enforceBranchOnCreate` / `assertBranchMatch`; **null entity `branch_id` is allowed** under branch context (global rows remain editable — by design today).
- **Reads / repositories:** Multiple `find($id)` methods are **ID-only** (e.g. `ProductRepository::find`, `PackageRepository::find`); `DocumentService` resolves owner rows by id then asserts branch on the loaded row — **caller/service enforcement is required** for safety; list paths often pass `branch_id` filters from controllers.
- **RBAC:** `roles`, `permissions`, `user_roles`, etc. are **global catalog** — not branch- or org-scoped (migration-level truth in F-06).
- **Sequences:** `invoice_number_sequences` uses a **global** `sequence_key` — not partitioned by branch or org (F-06).
- **Settings:** `SettingsService::get` / `all` implement deterministic **global (`branch_id = 0`) ∪ branch overlay** for `branch_id > 0`. **Proved inconsistency:** `SettingsController::index` calls `$settingsService->all()` **without** branch merge while domain getters use `$settingsContextBranch` — misc keys in the settings view can diverge from branch-effective reads (F-06 §5).
- **Storage:** Document files live under `storage/documents/YYYY/MM/...` with **no** branch or org prefix on disk; DB row carries `branch_id`; enforcement is app-layer (F-06 §6).
- **Public flows:** Branch is supplied or derived from tokens/entities; gates use branch-effective settings (e.g. `PublicBookingService::requireBranchPublicBookability`) — **not** multi-organization isolation (F-06 §7).
- **Users:** Session user shape includes `branch_id` only — no organization id (F-06 §8).

---

## CANONICAL TARGET MODEL

### 1) Canonical name for the top-level business boundary

**Canonical term: organization** (proposed schema: table `organizations`, column `organization_id` on owned rows).

**Rationale (this codebase, not generic SaaS theory):**

- The domain already uses **`branches`** for physical/operational locations. A second word is needed for the **owning entity** that may one day group many branches, billing, and access — without renaming `branches`.
- **tenant** is rejected as the *primary* name: in ops and licensing it often implies **separate DB or deployment**, which contradicts the accepted direction of **shared DB, row-level isolation** (F-06, §6.2 Phase 2 roadmap).
- **business** is a valid *narrative* synonym (see `BOOKER-PARITY-MASTER-ROADMAP.md` §6) but is overloaded in code discussions (“business logic”, “business record”). **`organization`** is unambiguous in schema and RBAC conversations.
- **Mapping:** Roadmap phrases such as “business / organization foundation” mean: implement the **`organization`** boundary per this document.

### 2) Canonical ownership chain (target)

| Layer | Role |
|--------|------|
| **Organization** | Owns branches, billing/subscription state (future), default policies, and **membership of users** in that org (future). Single deployment may host **many** organizations when implemented. |
| **Branch** | Child of exactly one organization (target invariant). Continues to be the primary **operational** scope for appointments, inventory, most settings overlays, and public “which location” entrypoints. |
| **User** | Authenticates globally (single `users` table pattern retained unless a future wave explicitly splits identity). **Belongs** to one or more organizations (future pivot) with optional **default branch** per org; today’s `users.branch_id` is a **compatibility** field until migrated. |
| **Settings** | **Three-level effective read (target):** organization defaults → branch overlay → (optional) future per-user operator prefs **only where explicitly designed**. Until schema exists, current **global `0` + branch overlay** remains authoritative. |
| **Documents / files** | **Owned** by organization + branch (and linked entity) in metadata; **on-disk paths** must eventually include **organization** (and branch where useful) to support purge/export and co-tenancy. |
| **Public flows** | Resolve **organization** implicitly via **branch** (branch → org) or explicit future slug/key **after** branch rows carry `organization_id`. Order: validate branch active → resolve org → apply org-level suspension/feature flags (future) → apply branch-effective settings. |
| **Invoices / sequences** | **Sequence keys must be scoped** at least by **organization**, and **preferably by branch** where product requires unique per-location numbering (today: global key — F-06). |
| **Roles / permissions** | **Target:** global *permission catalog* definitions remain possible, but **role assignment and effective permission sets** must be **organization-scoped** (and branch where staff-group merge already applies). Staff-group permissions stay **branch-scoped on the group row** under an org-owned branch. |

### 3) Canonical scoping rules

**Must always carry `organization_id` (once introduced):**

- `branches`, and every row that today is “tenant-sensitive” PII or money: clients, users’ org membership (pivot), invoices, payments, documents metadata, intake submissions, consents, memberships, packages tied to money, inventory **when** it is org-isolated (see risk: global products today).
- Any **new** subscription / package / quota tables.

**May remain global (catalog / system):**

- Read-only **permission code definitions** (`permissions` table) if kept as a single catalog.
- System-wide enums, migration version metadata, and **non-tenant** operational tables **only** if proven non-leaking (e.g. no PII).
- **Exception path (transitional):** Existing **nullable `branch_id` “global” product/service rows** remain a compatibility mode until a dedicated wave classifies **org-shared catalog vs org-private catalog** (see risk notes).

**Must be branch-scoped under the organization:**

- Appointments, waitlist, register sessions, room/equipment assignments, branch-scoped staff assignments, branch-scoped notifications where `branch_id` is already used.
- Public commerce purchases already **require** `branch_id` (F-06) — they will also inherit org via branch.

**Special: `branch_id IS NULL` global entity rows (current truth)**

- **Current:** `assertBranchMatch` allows null entity branch — global rows are visible across branches.
- **Target:** For **organization-isolated** mode, each org must have an explicit policy: either **no null-branch rows** for org-owned entities, or null means **“org-wide shared catalog”** inside **that org only** (never cross-org). **This policy must be chosen per entity family** in a future wave — not left implicit.

### 4) Canonical auth / context resolution model

**Current user context (today):** Session user + `BranchContext` from middleware; RBAC via `PermissionService` (global role codes + branch-scoped staff-group merge).

**Target resolution order (authenticated operator):**

1. Resolve **user identity** (session).
2. Resolve **organization context** (future: primary org, org switcher, or inferred from branch).
3. Resolve **branch context** within org (today’s `BranchContext` rules, constrained so **branch always belongs to current org**).
4. Apply **permission check** in **org-effective** set (future), then branch/staff-group rules as today.

**Public / anonymous entrypoints (target order):**

1. Identify **branch** (explicit id, token → entity → branch, or future org slug + branch slug).
2. Resolve **organization** from branch (**mandatory** once `branches.organization_id` exists).
3. Reject if org suspended / not found (future gates).
4. Apply **branch-effective** settings (as today for booking/commerce/intake) — **never** read operator session for entity-effective policy.

**CLI / cron (target):** No `BranchContext`; iteration must use **row’s `branch_id` → organization** for settings and guards (already the pattern for many jobs per `SETTINGS-READ-SCOPE.md` §5).

### 5) Canonical repository / query enforcement model

**Principle:** **Defense in depth** — SQL predicates + service-level asserts. Caller-only checks are **insufficient** for mutating paths and for any **list** that must not leak cross-tenant.

**Where SQL scope predicates must become mandatory (future waves):**

- **List / search** endpoints: `WHERE organization_id = :org` (and branch as today) on all tenant-owned tables.
- **findById used for mutation or sensitive read:** extend to `findForOrg($id, $orgId)` or `findForBranch($id, $branchId)` with **composite** lookup, or **JOIN** through `branches` to assert `branches.organization_id`.
- **Aggregates and reports:** same org predicate as dashboard/report repositories (F-06 references report branch predicates).

**Where caller assertions remain acceptable (short term):**

- Read-only diagnostics, sealed internal tools **after** a single choke-point load that already joined org.
- **Legacy transition:** thin wrappers that delegate to scoped queries once all call sites are migrated.

**Patterns (target):**

| Operation | Rule |
|-----------|------|
| **Create** | Set `organization_id` from resolved org (from branch or explicit org context); set `branch_id` via `enforceBranchOnCreate` equivalent extended for org. |
| **Update / delete** | Load with **scoped** query OR `find` + **assertOrgMatch** + **assertBranchMatch**; prefer scoped query to avoid ID oracle. |
| **List** | Mandatory org filter; branch filter as today for branch operators. |

### 6) Canonical settings model (target)

- **Levels:** `organization` default → **branch overlay** (retain current `SettingsService` merge semantics **within** an org) → **no** ad hoc mixed reads.
- **Storage shape (future):** Either `organization_id` on `settings` rows (with nullable branch) or separate org-level table — **decision deferred to implementation wave**; behavior must match: org-wide defaults with branch wins for same key.
- **Prohibited patterns:** Loading `all()` without the same branch/org dimension as domain getters (today’s `SettingsController::index` bug — F-06); mixing operator session branch for **entity-effective** reads (already documented as wrong in `SETTINGS-READ-SCOPE.md`).

### 7) Canonical storage model (target)

- **Path partitioning:** `storage/documents/{organization_id}/{branch_id}/YYYY/MM/...` (or org-only + branch in filename — implementation wave chooses; **org must appear**).
- **Document ownership:** DB row carries `organization_id` + `branch_id` (branch may remain nullable only if policy allows global-within-org documents).
- **Exports / report artifacts:** Same org prefix; **no** shared temp directory across orgs without unique names + metadata.
- **Logs / outbound debug:** Application logs may remain deployment-local; **customer-exportable** or **PII-bearing** artifacts **must** be org-scoped. Outbound mail transport paths today are not branch-prefixed (F-06) — **future** queue dumps must not cross org paths.

### 8) Canonical RBAC direction (target)

- **Global:** Permission *definitions* (codes, labels) may stay in one table.
- **Organization-scoped:** Role assignments, custom roles (if introduced), **user ↔ org** membership, **feature flags** driven by package.
- **Branch-scoped (existing):** Staff groups, group permissions — remain under branches that belong to one org; **effective permissions** = org-scoped role ∪ branch-scoped group merge (exact formula in implementation wave).
- **Superadmin / platform:** Separate **platform** operator concept (optional future) **outside** organization RBAC — not conflated with `users.branch_id IS NULL` salon-HQ semantics.

### 9) Canonical onboarding / offboarding boundary (target)

| Concern | Direction |
|---------|-----------|
| **User creation** | Provision user **into** an organization; assign branches; **never** create “floating” users visible across orgs without explicit platform role. |
| **Disable / delete** | Revoke org membership; session invalidation; branch reassignment only within same org unless platform operator. |
| **Branch reassignment** | Allowed only when branch ∈ same organization. |
| **Offboarding org** | Suspend → archive → purge pipeline with **storage deletion** and **sequence / invoice** legal retention rules (policy outside this doc). |

---

## ENFORCEMENT RULES (normative for implementers)

1. **No new feature** may assume cross-org uniqueness of integer IDs without org predicate or stable public UUID (future).
2. **Every mutating HTTP path** that loads by id must use a **scoped repository method** or **assert org + branch** after load before side effects — **ID-only find + trust** is legacy, not target.
3. **Settings reads** for a screen must use **one** effective scope dimension per surface (org+branch merge helper); **never** raw `all()` mixed with branched getters on the same view.
4. **Public APIs** must not accept **organization_id** from the client until **authenticated**; resolution is **branch/token → org** server-side.
5. **RBAC checks** must eventually receive **organization context**; until then, **document** that RBAC is global and **not** sufficient for SaaS isolation (F-06).

---

## MIGRATION / IMPLEMENTATION ORDER (future waves — one risky theme per wave where possible)

**Gate:** Subscriptions/packages (Phase 3 roadmap) **must not** ship before **organization context exists** and **choke-point resolution** is wired (middleware + service contracts).

**Implemented (schema only):** Wave **A** — **`086_organizations_and_branch_ownership_foundation.sql`**, canonical snapshot + verifier + minimal branch-create default org — see **`ORGANIZATION-SCHEMA-BRANCH-OWNERSHIP-FOUNDATION-08-OPS.md`**. Middleware/query/RBAC waves remain open.

**Implemented (runtime context, no query scoping):** Wave **B (minimal)** — **`OrganizationContext`**, **`OrganizationContextResolver`**, **`OrganizationContextMiddleware`** — see **`ORGANIZATION-CONTEXT-RESOLUTION-FOUNDATION-09-OPS.md`**. No session org picker, no `users.organization_id`, no repository predicates in this slice.

**Recommended sequence:**

| Wave | Focus | Dependency |
|------|--------|------------|
| **A** | **`organizations` table + `branches.organization_id` NOT NULL** (backfill single org for existing deployments) | **Shipped (FOUNDATION-08)** |
| **B** | **Request / session org resolution** (derive from branch; constrain branch picker to org) | **Partial (FOUNDATION-09): derived org context + middleware; no branch picker / membership yet** |
| **C** | **Scoped find/list queries** for **highest-risk** tables (users, clients, invoices, documents) — one module family per sub-wave | B |
| **D** | **Settings:** fix mixed `all()` read; add org dimension to storage/merge | B |
| **E** | **`invoice_number_sequences` (and similar) org/branch scoping** | A + C (sales) |
| **F** | **RBAC: org-scoped role assignments** (keep global permission catalog if desired) | B |
| **G** | **Tenant-scoped storage paths + migration script** for existing files (or copy-on-read) | A, documents module |
| **H** | **Package / subscription / quota** enforcement | F + D (feature flags) |
| **I** | **Onboarding/offboarding / suspend / purge** | H + G |

**Before tenant-scoped storage (G):** Complete **A**, **B**, and **document row org attribution** (documents, exports) so path migration does not orphan files.

**Before subscriptions (H):** Complete **A**, **B**, **F** (minimum), and **choke-point** org denial for mutating routes.

---

## NON-GOALS (this document and immediate follow-up discipline)

- Implementing schema, middleware, repository refactors, storage rewrite, RBAC rewrite, or public API changes.
- Defining commercial package SKUs, prices, or feature matrices (Phase 3 product work).
- Choosing DB-per-tenant vs shared-row RLS product — **default** remains **shared schema + org column** aligned with F-06.

---

## RISK NOTES

- **Nullable global `branch_id` rows:** Org isolation **fails** unless rules for null-branch are tightened per entity.
- **Inventory products:** Global products are **cross-branch** today; org isolation may require **org-level catalog** or explicit “shared catalog” product flag — **do not** blindly add `organization_id` without catalog policy.
- **VAT rates:** Admin manages global slice; branch overlay exists in data model — org layer must not break `VatRateService` predicates without a dedicated VAT org wave.
- **HQ users (`branch_id` null):** Today implies all branches; with orgs, HQ must mean **all branches in org**, not all orgs.
- **Integer ID guessing:** Until scoped queries ship, **permission + ID** is not tenant security (F-06).

---

## ACCEPTANCE GATES FOR FUTURE WAVES

- **Org schema wave:** All existing rows backfilled; all branches reference valid org; **zero** NULL `organization_id` on branches in production configs that claim isolation.
- **Middleware wave:** Every authenticated request has **OrgContext** (name TBD) or equivalent; branch resolution **rejects** cross-org branch ids.
- **Repository wave:** CI or scripted audit: **no** `find(int $id)` for tenant-owned entities on mutating paths without scoped alternative (allow-list exceptions documented).
- **Settings wave:** No code path renders **misc** settings keys with a different merge than domain getters for the same screen.
- **Storage wave:** No new file written without org prefix; manifest of moved legacy files.
- **RBAC wave:** Role assignment changes for user A in org X **never** affect user B in org Y.
- **Subscriptions wave:** Feature gate checks **org id** from context, not from request body.

---

## Canonical scope matrix by module/domain (summary)

| Domain | Current (F-06) | Target org obligation |
|--------|----------------|------------------------|
| Branches | Top location entity | Carry `organization_id` |
| Clients / appointments / waitlist | `branch_id` | Derive org via branch; scoped queries |
| Sales / invoices / payments | `branch_id`; sequences global | Org-scoped sequences + scoped finds |
| Inventory / products | `branch_id` nullable | Org + catalog policy |
| Services / packages / gift cards | `branch_id` nullable | Same |
| Documents | Row `branch_id`; disk unpartitioned | Org + branch paths |
| Settings | `branch_id` 0 + overlay | Org level added |
| RBAC | Global | Org-scoped assignments |
| Public booking / commerce / intake | Branch-explicit | Branch → org |
| Reports / dashboard | Branch predicates | Add org predicate |
| Notifications / outbound | Nullable `branch_id` | Org via branch or org id on queue row |

---

## Document control

- **Supersedes:** Informal “next steps” bullets in F-06 §10 for **wording of sequence** — F-06 remains authoritative for **current** code truth.
- **Next review:** After FULL ZIP audit on checkpoint that includes this file; any code change to boundaries requires updating **PROVED CURRENT TRUTH** via a new audit wave.
