# ORGANIZATION-REGISTRY-AND-PLATFORM-CONTROL-PLANE-MINIMAL-DESIGN — FOUNDATION-37 (R1)

**Mode:** Docs-first design only. **No** runtime code, **no** migrations, **no** UI, **no** seeds, **no** FOUNDATION-38 implementation in this wave.  
**Input truth:** **`ORGANIZATION-REGISTRY-AND-FOUNDER-PLATFORM-ADMIN-BOUNDARY-TRUTH-AUDIT-FOUNDATION-36-OPS.md`** (F-36) unless the repo **directly contradicts** it during review.

---

## 1. Current truth baseline (from F-36)

- **`organizations`** table exists (**086**); **no** application CRUD; lifecycle = migrations/seeds/ops only.
- **`branches.organization_id`** FK is the **structural** org↔branch link; **`BranchDirectory`** mutates branches, not org rows.
- **Organization context** is **inferred**: branch → org JOIN, or **single active org** count fallback, or **null** + F-25 gate.
- **`users`**: **`branch_id`** only; **no** membership pivot; **no** `users.organization_id`.
- **RBAC**: module permissions + staff groups; **no** `platform.*` / org-admin permission family; **`PermissionService`** allows **`*`** if granted in DB (not seeded).
- **No** founder/platform HTTP layer.

---

## 2. Target control-plane model (minimal)

### 2.1 Organization registry

| Concern | Target |
|---------|--------|
| **Lifecycle** | **Create** (platform) → **active** → **suspended** (reversible) → **archived** (soft-delete / `deleted_at`) |
| **Creation** | Platform operator creates org with **name** + optional **code** (unique when non-null); default org seed path remains valid for empty installs until replaced by explicit create flow |
| **Activation state** | **`suspended_at TIMESTAMP NULL`** (or equivalent) on **`organizations`**: non-null ⇒ org **blocked** for normal staff operations (new gate); **`deleted_at`** ⇒ terminal archive (existing pattern) |
| **Read/list** | Platform: **all** orgs (filtered, paginated). Org admin: **single** org row for **resolved** context only |
| **Update** | Platform: any org metadata + suspend/unsuspend. Org admin: **name/code** (and future billing metadata) **only for current org** |
| **Branches** | **Unchanged invariant:** every branch row has exactly one **`organization_id`**. **Reassign branch to another org** is a **platform-only** or **explicit future** wave (dangerous); **not** phase 1 |

### 2.2 Platform operator boundary

| Actor | Authority |
|-------|-----------|
| **Platform operator** | Cross-tenant: registry list, **create** organization, **suspend/unsuspend**, read archived flags, operational support reads. **Does not** replace day-to-day **`settings.*`** on behalf of tenants unless explicitly granted (phase 1: **no** implicit super-settings) |
| **Organization admin** | In-tenant: edit **own** org profile fields; manage **branches** under existing **`branches.*`** when **OrganizationContext** resolves to that org; future: invite users / assign org roles (later slice) |
| **Branch staff / admin** | Existing module permissions + **branch context**; **cannot** create organizations or list other tenants |

### 2.3 User identity / tenancy authority (canonical decision)

**Chosen model:** **`user_organization_memberships`** pivot table:

- Columns (minimal): **`user_id`**, **`organization_id`**, **`status`** (`active` / `invited` / `revoked`), **`created_at`**, **`updated_at`**; optional **`default_branch_id`** (nullable FK) to align with **`users.branch_id`** over time.
- **Primary key:** **`(user_id, organization_id)`** (or surrogate `id` if you prefer audit trails — design prefers **composite** for minimal phase).

**Rejected: `users.organization_id` only** — fails **multi-org** operators and consultants; blocks clean platform-user vs tenant-user stories without duplicate user rows.

**Rejected: org membership encoded only in `users.branch_id`** — indirect, breaks HQ users and platform users without a branch; conflates **operational default branch** with **tenancy membership**.

**Coexistence with `users.branch_id` (migration-safe):**

1. **Phase 1 backfill:** For each user with **`users.branch_id` NOT NULL**, insert **`active`** membership for **`branches.organization_id`** of that branch. Users with **NULL** `branch_id`: **optional** membership to single org if deployment has exactly one org; else **manual** platform assignment before strict enforcement.
2. **Resolver precedence (later slice):** After membership exists, **optional** rule: valid staff session must have **active membership** for **resolved** org (or platform bypass). Until then, **existing** branch→org inference **remains** for backward compatibility.
3. **Long-term:** **`users.branch_id`** = **default login branch** / UX default, **not** proof of org membership.

---

## 3. RBAC design (canonical decisions)

### 3.1 Permission families

| Family | Purpose |
|--------|---------|
| **`platform.*`** | **Hosting / founder** operations that **cross tenant boundaries** |
| **`organizations.*`** (or prefix **`org.*`** — pick one code style in implementation) | **In-tenant** organization administration **scoped to resolved org** |

**Canonical choice:** Use **`platform.organizations.view`**, **`platform.organizations.manage`** for registry CRUD + suspend (names illustrative; implementation may use **`platform.registry.view`** — but **keep `platform.` prefix** for audit clarity).

**In-tenant org profile:** **`organizations.profile.manage`** (requires **resolved** `OrganizationContext`; **cannot** target another org by id in URL without platform permission).

**Rejected: only `organizations.*` without `platform.*`** — blurs **cross-tenant** vs **in-tenant** actions; **cross-tenant list/create** must not be expressible as ordinary **`organizations.*`** without a second guard.

**Rejected: rely on `*` wildcard** for platform ops — not auditable, not seed-safe, discouraged for production.

### 3.2 What moves under platform authority (conceptual)

| Capability | Phase 1 placement |
|------------|---------------------|
| **List/search all organizations** | **`platform.*`** |
| **Create organization** | **`platform.*`** |
| **Suspend / unsuspend organization** | **`platform.*`** |
| **Edit arbitrary org by id** (cross-tenant) | **`platform.*`** |
| **Edit current org name/code** | **`organizations.profile.manage`** (or equivalent) **+ resolved org** |

### 3.3 What stays org-admin / branch-local

| Capability | Stays |
|------------|--------|
| **Branch catalog CRUD** | **`branches.*`** (already org-aligned via context F-30/32/34) |
| **Staff, settings, VAT, inventory, …** | **Existing** module permissions; **phase 1** does **not** re-scope all modules to org RBAC (F-07 target remains phased) |
| **Staff-group permissions** | **Branch-scoped** merge as today until a **dedicated** org-scoped RBAC wave |

---

## 4. Runtime / `OrganizationContext` (after control plane)

### 4.1 What remains branch-derived

- **Primary staff UX:** **`BranchContext`** still drives timezone, many settings merges, and **branch→org** JOIN for **resolved** org id (F-09) **until** membership enforcement is fully on.
- **Public flows:** Continue **branch-first** → org via **`branches.organization_id`**.

### 4.2 What becomes explicit (later slices)

- **Staff authorization to org:** **`user_organization_memberships`** must **include** `(user, org)` for **strict** mode (config or deployment flag).
- **Platform users:** May use **platform** routes **without** tenant branch; **OrganizationContext** on those routes may be **unset** or **explicit org id** from path (design-only: **platform registry** does not require operator to “be” inside a tenant).

### 4.3 Single-org fallback

| Phase | Policy |
|-------|--------|
| **Phase 1** | **Retain** current F-09 single-org fallback for installs **without** multi-org ambiguity — **zero behavior regression** for single-tenant ZIPs |
| **Phase 2** | **Narrow**: when **`user_organization_memberships`** is populated for **all** staff users, optional **config** “require_membership_for_org_resolution” disables inference-from-count for staff |
| **Sunset** | Multi-tenant production may **deprecate** count-based org guess **only** after membership + gate proven; **not** phase 1 |

### 4.4 Backward compatibility (phase 1 hard constraints)

- **No** removal of **`BranchContextMiddleware`** / **`OrganizationContextMiddleware`** ordering in phase 1 schema slice.
- **No** change to **`branches.manage`** semantics without a **separate** task.
- **New** tables/columns are **additive**; existing **`organizations`** rows remain valid.
- **F-25** gate remains; new **org suspended** gate is **additive** (staff 403 when org suspended unless platform bypass).

---

## 5. Rejected alternatives (summary)

| Alternative | Why rejected |
|-------------|--------------|
| **`users.organization_id` only** | Single-org users only; poor fit for platform + multi-org |
| **Platform power via `*` permission** | Opaque, error-prone, not reviewable in diffs |
| **Org registry only via SQL forever** | F-36 gap: not **operational** SaaS |
| **Merge platform into `settings.edit`** | Settings is **tenant operational**, not **hosting**; violates boundary |
| **Hard-delete organizations** in phase 1 | **RESTRICT** FK from branches; requires archive/purge program — out of minimal scope |

---

## 6. Phased implementation map (backend-first, low-risk)

**Slices are ordered; each slice should be shippable behind flags or with backward compatibility.**

| Stage | Name | Content |
|-------|------|---------|
| **S1** | **Schema design slice** | Add **`user_organization_memberships`**; add **`organizations.suspended_at`** (or status enum); document FK/indexes; **no** behavior change yet optional |
| **S2** | **RBAC / control slice** | Insert **`platform.*`** + **`organizations.profile.manage`** (or chosen codes) into **`permissions`**; **no** route wiring yet **or** wire only **deny-by-default** stubs — product choice in next wave |
| **S3** | **Registry read slice** | Read-only service: list orgs (platform), get org by id; HTTP **optional** in same wave or next |
| **S4** | **Registry mutation slice** | Create/suspend/unsuspend/update (split platform vs org-admin); audit log entries |
| **S5** | **Membership / context integration slice** | Backfill job + **`OrganizationContextResolver`** / **F-25**-class gate extensions: optional **membership check** for staff; **single-org fallback** narrowed behind config |

**Explicit:** **S1 → S2** can swap if product insists permissions exist before DDL — default order above prefers **truth in DB first**.

---

## 7. Phase 1 minimal CRUD scope (canonical)

| Operation | Who | In phase 1 |
|-----------|-----|------------|
| **Create org** | Platform | **Yes** (name, code) |
| **List orgs** | Platform | **Yes** (paginated, exclude deleted per policy) |
| **Read org** | Platform / org admin | **Yes** (scoped) |
| **Update name/code** | Org admin | **Yes** (resolved org only) |
| **Suspend / unsuspend** | Platform | **Yes** |
| **Archive (`deleted_at`)** | Platform | **Optional** phase 1.5 — only when **no** active branches or explicit cascade rule (defer if risky) |
| **Branch reassignment between orgs** | — | **No** (phase 1) |

---

## 8. Single recommended next wave (name only)

**FOUNDATION-38 — ORGANIZATION-REGISTRY-SCHEMA-AND-MEMBERSHIP-PIVOT-MINIMAL-R1**

**Scope of F-38 (for implementers later):** **S1** only — DDL + indexes + **non-breaking** defaults; **no** mandatory behavior flip; **no** FOUNDATION-39 opened in this document.

---

## 9. Companion artifact

**`ORGANIZATION-CONTROL-PLANE-DESIGN-DECISION-MATRIX-FOUNDATION-37.md`** — decision × rationale × rejected options.

---

## 10. Stop

This wave **ends** at design + roadmap row. **FOUNDATION-38** is **named** as the **next** implementation slice, **not** started here.
