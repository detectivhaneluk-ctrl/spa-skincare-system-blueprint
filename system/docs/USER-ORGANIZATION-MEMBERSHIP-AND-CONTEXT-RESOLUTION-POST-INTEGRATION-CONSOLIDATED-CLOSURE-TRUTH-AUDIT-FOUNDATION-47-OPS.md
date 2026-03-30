# USER-ORGANIZATION-MEMBERSHIP-AND-CONTEXT-RESOLUTION-POST-INTEGRATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT — FOUNDATION-47 (read-only)

**Mode:** Docs-only. **No** code/migrations/UI.  
**Baseline:** ZIP-accepted **FOUNDATION-06**–**FOUNDATION-46** and **FOUNDATION-46-REPAIR**; membership-aware resolution + **schema-absence-safe** reads are the subject of this closure audit.

**Companion matrix:** **`USER-ORGANIZATION-MEMBERSHIP-AND-CONTEXT-RESOLUTION-SURFACE-MATRIX-FOUNDATION-47.md`**.

---

## 1. Schema / runtime read baseline (audited)

| Item | Evidence |
|------|----------|
| **F-38 DDL** | **`system/data/migrations/087_organization_registry_membership_foundation.sql`** — `user_organization_memberships` with **`status VARCHAR(20) NOT NULL DEFAULT 'active'`** (F-37 values **active / invited / revoked** documented; not ENUM-enforced) |
| **Rows used when table exists** | **`INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL`** — memberships to **soft-deleted** orgs are **excluded** |
| **Status filter** | **`m.status = 'active'`** only |
| **Live org** | Join requires **`organizations.deleted_at IS NULL`** |
| **F-46-REPAIR (absent table)** | **`UserOrganizationMembershipReadRepository::membershipTableAvailable()`** — **`information_schema.TABLES`** where **`TABLE_SCHEMA = DATABASE()`** and **`TABLE_NAME = 'user_organization_memberships'`**; result **cached** per request lifetime in the repository instance. When **false**, **`countActiveMembershipsForUser`** → **0**, **`listActiveOrganizationIdsForUser`** → **`[]`** — **no** SQL against **`user_organization_memberships`**, so **no** “table doesn’t exist” query fatal. Resolver then follows the **same path as “zero memberships”** (legacy fallback chain). |

---

## 2. Membership read layer (audited)

| Class | Path |
|-------|------|
| **Repository** | `system/modules/organizations/repositories/UserOrganizationMembershipReadRepository.php` |
| **Service** | `system/modules/organizations/services/UserOrganizationMembershipReadService.php` |

| Method | Behavior when table **exists** | When table **absent** (F-46-REPAIR) |
|--------|----------------------------------|-------------------------------------|
| **`countActiveMembershipsForUser`** | `COUNT(*)` + JOIN + **`active`** filter; **`userId <= 0` → 0** | **0** (skip membership SQL) |
| **`listActiveOrganizationIdsForUser`** | `SELECT` + **`ORDER BY m.organization_id ASC`** | **`[]`** |
| **`getSingleActiveOrganizationIdForUser`** | Via **`list...`**; single id or **null** | **null** |

**DI:** **`system/modules/bootstrap/register_organizations.php`** — repository + service singletons.

---

## 3. Organization context resolution (audited)

| Artifact | Path / note |
|----------|-------------|
| **Resolver** | `system/core/Organization/OrganizationContextResolver.php` — **`Database`**, **`AuthService`**, **`UserOrganizationMembershipReadService`** |
| **Context modes** | `system/core/Organization/OrganizationContext.php` — **`MODE_BRANCH_DERIVED`**, **`MODE_MEMBERSHIP_SINGLE_ACTIVE`**, **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`**, **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`**, **`MODE_UNRESOLVED_NO_ACTIVE_ORG`** |
| **Bootstrap** | **`system/bootstrap.php`** — resolver **not** bound here. **`system/modules/bootstrap.php`** — **`OrganizationContextResolver`** **after** module registrars |
| **Middleware** | **`OrganizationContextMiddleware`** → **`resolveForHttpRequest`**

**Resolver uses from membership service:** **`countActiveMembershipsForUser`**, **`getSingleActiveOrganizationIdForUser`** only (not **`list...`** directly).

---

## 4. Exact PHP call sites (`system/**/*.php`)

| Symbol | Call sites |
|--------|------------|
| **`UserOrganizationMembershipReadService`** | **`register_organizations.php`**, **`modules/bootstrap.php`**, **`audit_user_organization_membership_context_resolution.php`** |
| **`countActiveMembershipsForUser`** | **`OrganizationContextResolver`**, audit script, service → repo |
| **`getSingleActiveOrganizationIdForUser`** | **`OrganizationContextResolver`**, audit script, service → repo |
| **`listActiveOrganizationIdsForUser`** | Audit script, service → repo; repo **`getSingle...`** uses **`list...`** |

**`MODE_MEMBERSHIP_SINGLE_ACTIVE`:** only from **`OrganizationContextResolver::resolveForHttpRequest`** when single-membership path succeeds (requires table **present** and exactly one qualifying row).

---

## 5. Precedence after F-46 (unchanged by F-46-REPAIR when 087 applied)

1. **Branch non-null** → **`MODE_BRANCH_DERIVED`** or **`DomainException`**. Membership **skipped**.
2. **Branch null** + authenticated **`userId > 0`**: membership counts/lists (or **empty** if table absent) → same branching as F-46: **1** → membership mode; **>1** → ambiguous; **0** → fall through.
3. **Legacy** global active-org count (F-09).

**F-46-REPAIR effect:** With **no** `user_organization_memberships` table, step 2 sees **`mCount === 0`** → step 3 — **behavior matches pre-F-46** for that branch of the tree.

---

## 6. Closure checks (path behavior)

| Scenario | Result |
|----------|--------|
| Branch-derived org exists | Branch org only |
| Branch null, **1** active membership (table present) | **`MODE_MEMBERSHIP_SINGLE_ACTIVE`** |
| Branch null, **0** memberships **or** table **absent** | Legacy chain (**same effective path** as “no membership rows”) |
| Branch null, **>1** memberships | **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`**, no legacy fallback |
| Ambiguity preserved | Yes |

**Hidden cross-org guess:** None.

**Remaining mandatory gap in this layer:** None for stated F-46 + F-46-REPAIR scope.

---

## 7. Runtime-safety (explicit)

| Question | Answer (code truth) |
|----------|----------------------|
| **Backward-safe if 087 not applied?** | **Yes** — **`membershipTableAvailable()`** prevents queries against a missing **`user_organization_memberships`** table; resolver falls back to legacy org-count logic. |
| **F-46 behavior preserved when 087 exists?** | **Yes** — when the table row exists in **`information_schema`**, full **`COUNT`/`SELECT`** paths run as in the original F-46 design. |
| **Fatal paths inside this layer?** | **`DomainException`** still **only** when branch context is non-null but branch cannot resolve to an active org (**unchanged**). Missing **`organizations`** / **DB** connectivity would fail in SQL **outside** membership-table guard — same as any request. **`information_schema`** probe is standard on MySQL/MariaDB; failure would surface as PDO exception (environmental). |

---

## 8. Risk / waiver list

| ID | Waiver |
|----|--------|
| **W-1** | **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** overloads membership vs deployment ambiguity. |
| **W-2** | **`OrganizationContextResolver`** (core) depends on **`Modules\Organizations\...`**. |
| **W-3** | Resolver bound **after** **`modules/bootstrap.php`** only. |
| **W-4** | **`invited` / `revoked` / `default_branch_id`** unused in resolution. |
| **W-5** | **`membershipTableAvailable`** cached on repository instance — **not** re-checked mid-request if DDL changes (acceptable). |
| **W-6** | Requires **`information_schema`** + **`DATABASE()`** semantics (MySQL/MariaDB norm). |

---

## 9. Final closure verdict (exactly one)

**B) Closed with documented waiver(s).**

The **membership-aware context resolution layer**, including **F-46-REPAIR** schema-absence safety, is **complete** for its scope; **W-1–W-6** are waivers, not a missing mandatory wave inside this layer.

---

## 10. Next backend program (single name)

**FOUNDATION-48 — USER-ORGANIZATION-MEMBERSHIP-BACKFILL-AND-STRICT-GATE-OPTION-MINIMAL-R1**

**Evidence:** **`user_organization_memberships`** may be **empty** or **unapplied**; **F-37** and roadmap **F-46** row point to **backfill** and optional stricter enforcement as **next** program.

---

## 11. Stop

This audit **updates** the FOUNDATION-47 closure docs to include **F-46-REPAIR** acceptance. **No** implementation.
