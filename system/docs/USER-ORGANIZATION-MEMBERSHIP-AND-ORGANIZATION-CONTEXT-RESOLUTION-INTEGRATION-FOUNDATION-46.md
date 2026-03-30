# USER-ORGANIZATION-MEMBERSHIP-AND-ORGANIZATION-CONTEXT-RESOLUTION-INTEGRATION — FOUNDATION-46 (R1)

**Wave:** F-37 **S5** minimal slice — **read** membership + **resolver** precedence only. **No** membership CRUD/HTTP, **no** auth/session redesign, **no** platform route changes.

---

## 1. F-37 decisions operationalized

| F-37 | This wave |
|------|-----------|
| Canonical **`user_organization_memberships`** pivot with **`status`** (`active` / `invited` / `revoked`) | Only **`status = 'active'`** rows participate in resolution |
| Coexistence with **`users.branch_id`**; membership as explicit tenancy signal | **Branch-derived org remains first**; membership used **only when branch context is null** |
| Phase 1: optional backfill / strict enforcement later | **No** backfill job; **no** new gates beyond existing F-25 consuming resolver output |
| Resolver extensions without broad redesign | **`OrganizationContextResolver::resolveForHttpRequest`** only |

---

## 2. Files / classes added or changed

| Path | Role |
|------|------|
| **`system/modules/organizations/repositories/UserOrganizationMembershipReadRepository.php`** | **New** — count / list ids / single id |
| **`system/modules/organizations/services/UserOrganizationMembershipReadService.php`** | **New** — facade |
| **`system/core/Organization/OrganizationContextResolver.php`** | **Changed** — membership precedence + **`AuthService`** dependency |
| **`system/core/Organization/OrganizationContext.php`** | **`MODE_MEMBERSHIP_SINGLE_ACTIVE`** + docblock |
| **`system/core/middleware/OrganizationContextMiddleware.php`** | Docblock (F-46) |
| **`system/bootstrap.php`** | **Removed** `OrganizationContextResolver` binding (comment pointer) |
| **`system/modules/bootstrap.php`** | **Registers** `OrganizationContextResolver` **after** module registrars |
| **`system/modules/bootstrap/register_organizations.php`** | Membership repo + service DI |
| **`system/scripts/audit_user_organization_membership_context_resolution.php`** | **New** verifier |
| **`system/docs/USER-ORGANIZATION-MEMBERSHIP-AND-ORGANIZATION-CONTEXT-RESOLUTION-INTEGRATION-FOUNDATION-46.md`** | This doc |
| **`system/docs/BOOKER-PARITY-MASTER-ROADMAP.md`** | F-46 row |

---

## 3. Membership read contract

| Method | Behavior |
|--------|----------|
| `countActiveMembershipsForUser(int $userId): int` | Join **`user_organization_memberships`** → **`organizations`** with **`deleted_at IS NULL`**, **`m.status = 'active'`** |
| `listActiveOrganizationIdsForUser(int $userId): array` | Same filter; **`ORDER BY organization_id ASC`**; **`list<int>`** |
| `getSingleActiveOrganizationIdForUser(int $userId): ?int` | Non-null **iff** exactly one qualifying row |

**`userId <= 0`:** count `0`, list `[]`, single `null`.

**`user_organization_memberships` not yet migrated (087 absent):** no queries against that table; count `0`, list `[]`, single `null` — resolver skips membership step and uses legacy active-org logic.

---

## 4. Resolution precedence

### Before F-46 (branch null)

1. Count active organizations globally → 0 / 1 / many → set mode + id or null.

### After F-46 (branch null)

1. **Unchanged:** branch non-null → org from active branch + active org (**`MODE_BRANCH_DERIVED`**) or **`DomainException`**.
2. **New:** authenticated user (`AuthService::user()`), **`userId > 0`**:
   - **Exactly 1** active membership (valid org) → **`MODE_MEMBERSHIP_SINGLE_ACTIVE`** + that **`organization_id`**.
   - **> 1** active memberships → **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** (null id) — **do not** fall through to single-org fallback.
   - **0** memberships → fall through to step 3.
3. **Legacy:** global active-org count (0 / 1 / many) → **`MODE_UNRESOLVED_*`** or **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`** as before.

**Guest / unauthenticated** (`user` null): skip step 2 → legacy only.

### Intentionally unresolved

- Multiple active memberships for the same user (no selector).
- Branch null + zero memberships + multiple active orgs in DB (unchanged).
- Branch null + zero memberships + zero orgs (unchanged).

---

## 5. Intentionally not implemented

- Membership **writes**, HTTP CRUD, backfill from **`users.branch_id`**
- **`organizations.profile.manage`** HTTP
- Platform registry route / F-25 path edits
- Config flag to disable membership resolution
- **`invited` / `revoked`** contributing to resolution
- **`default_branch_id`** influencing org resolution

---

## 6. Backward compatibility

- **Branch-first** unchanged — all branch-scoped flows behave as before.
- Deployments where the membership table **does not exist** yet: membership reads are empty; step 2 behaves like **0** memberships; **legacy fallback** unchanged — **no** fatal on org resolution.
- Deployments with **no** membership rows: step 2 is a no-op; **legacy fallback** identical to F-09.
- **Single-org** ZIPs: unchanged when users have **0** memberships.
- **Core-only bootstrap** (`bootstrap.php` without `modules/bootstrap.php`): **`OrganizationContextResolver`** and **`StaffMultiOrgOrganizationResolutionGate`** are **not** registered — use full app bootstrap (same as any code path needing org resolution, post-auth org gate, or org registry).

---

## 7. Verifier usage

From **`system/`**:

```bash
php scripts/audit_user_organization_membership_context_resolution.php
php scripts/audit_user_organization_membership_context_resolution.php --json
```

**Success:** exit **0**; checks table presence, **`MODE_MEMBERSHIP_SINGLE_ACTIVE`**, resolver constructor arity, service vs raw SQL parity for first **`users.id`**, trivial **`userId = 0`** contract.

Requires DB with **087** table **`user_organization_memberships`**.

---

## 8. Single recommended next wave (name only)

**FOUNDATION-47 — USER-ORGANIZATION-MEMBERSHIP-BACKFILL-AND-STRICT-GATE-OPTION-MINIMAL-R1**

---

## 9. Stop

This wave ends at membership read + resolver + verifier + docs + roadmap + ZIP. **Do not** start FOUNDATION-47 unless explicitly tasked.
