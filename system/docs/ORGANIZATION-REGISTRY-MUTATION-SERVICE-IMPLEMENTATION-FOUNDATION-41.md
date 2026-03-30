# ORGANIZATION-REGISTRY-MUTATION-SERVICE — FOUNDATION-41 (R1)

**Wave:** F-37 **S4** mutation **service/repository layer only** — **no** HTTP, **no** route/middleware guards, **no** permission enforcement wiring, **no** membership/context resolver changes, **no** audit-log table writes (F-37 S4 mentions audit entries as a fuller slice; not required for this minimal backend contract).

---

## 1. F-37 decisions operationalized (exact)

| F-37 source | This wave |
|-------------|-----------|
| §2.1 **Create** (platform) with **name** + optional **code** (unique when non-null) | `createOrganization` inserts **only** `name` / `code`; timestamps and `suspended_at` / `deleted_at` follow schema defaults |
| §2.1 **Activation:** **`suspended_at` TIMESTAMP NULL** — non-null ⇒ suspended | `suspendOrganization` sets non-null timestamp; `reactivateOrganization` sets **`suspended_at` NULL** |
| §7 Phase-1 **Update name/code** | `updateOrganizationProfile` allows **only** `name` and `code` (other keys ignored); `code` may be set to **null** to clear |
| §7 **Suspend / unsuspend** (platform) | Exposed as `suspendOrganization` / `reactivateOrganization` |
| §6 S4 “split platform vs org-admin” | **Not** enforced here — service is **global**; future callers attach `platform.organizations.manage` vs `organizations.profile.manage` + resolved org |
| §6 S4 “audit log entries” | **Not** implemented (no side effects outside `organizations` unless explicitly mandated for this wave) |
| §7 **Archive (`deleted_at`)** optional / deferred | **Not** implemented |
| §7 **Branch reassignment** | **Not** implemented |
| §3 **Permission catalog** | **Unchanged** — F-39 rows exist; **no** `role_permissions` or middleware wiring |

---

## 2. Files / classes added or changed

| Path | Role |
|------|------|
| **`system/modules/organizations/repositories/OrganizationRegistryMutationRepository.php`** | INSERT + profile UPDATE + `suspended_at` set/clear |
| **`system/modules/organizations/services/OrganizationRegistryMutationService.php`** | Public mutation API + validation + duplicate-`code` checks |
| **`system/modules/bootstrap/register_organizations.php`** | Singletons for mutation repository + service |
| **`system/scripts/audit_organization_registry_mutation_service.php`** | Transaction + rollback verifier |
| **`system/docs/ORGANIZATION-REGISTRY-MUTATION-SERVICE-IMPLEMENTATION-FOUNDATION-41.md`** | This document |
| **`system/docs/BOOKER-PARITY-MASTER-ROADMAP.md`** | FOUNDATION-41 row appended |

**Unchanged:** `OrganizationRegistryReadRepository` / `OrganizationRegistryReadService`, `modules/bootstrap.php` registrar list (still loads `register_organizations.php`).

---

## 3. Public mutation contracts

| Method | Returns | Not found / invalid id |
|--------|---------|-------------------------|
| `createOrganization(array $payload): array` | Full org row (same shape as F-40 read service) | **Throws** `InvalidArgumentException` for validation / duplicate **code**; `RuntimeException` if reload fails |
| `updateOrganizationProfile(int $organizationId, array $payload): ?array` | Updated row | **`null`** if `organizationId <= 0` or row missing |
| `suspendOrganization(int $organizationId): ?array` | Row with non-null `suspended_at` | **`null`** if `organizationId <= 0` or row missing |
| `reactivateOrganization(int $organizationId): ?array` | Row with `suspended_at === null` | **`null`** if `organizationId <= 0` or row missing |

Duplicate **non-null** `code` on create/update throws **`InvalidArgumentException`** (deterministic, pre-`INSERT`/`UPDATE`).

---

## 4. Field scope

### 4.1 `createOrganization($payload)`

| Key | Required | Notes |
|-----|----------|--------|
| `name` | **Yes** | Non-empty after trim; max **255** (schema) |
| `code` | No | Omitted or **`null`** ⇒ stored **`NULL`**; empty/whitespace string ⇒ **`NULL`**; max **50** (schema); must be unique among non-null codes |

No other keys are written.

### 4.2 `updateOrganizationProfile($organizationId, $payload)`

| Key | Effect |
|-----|--------|
| `name` | If **present**: trim, non-empty, max 255 |
| `code` | If **present**: trim; empty ⇒ **`NULL`**; else unique vs other orgs |
| *any other* | **Ignored** (not an error) |

If neither `name` nor `code` is present, returns the **existing** row (no `UPDATE`).

---

## 5. Suspend / reactivate semantics

- **Suspend:** `UPDATE organizations SET suspended_at = CURRENT_TIMESTAMP WHERE id = ?` then re-read. **Idempotent** for already-suspended rows (still non-null `suspended_at`).
- **Reactivate:** `UPDATE organizations SET suspended_at = NULL WHERE id = ?` then re-read. **Idempotent** for already-active rows.

**Staff “org suspended” gate** (F-37 §4.4) is **out of scope** — not wired in this wave.

---

## 6. Intentionally not implemented

- HTTP routes/controllers/views, platform middleware, `PermissionMiddleware` wiring  
- Membership-aware auth, `user_organization_memberships` writes, backfill jobs  
- `OrganizationContext` / resolver / F-25 gate extensions  
- Permission assignment (`role_permissions`)  
- Founder dashboard  
- Organization **archive** / `deleted_at` mutation, **hard-delete**  
- S4 **audit log** persistence  
- Branch-domain or `users.branch_id` changes  

---

## 7. Backward compatibility

- **Additive only:** existing F-40 read API unchanged; default org seed / branch FK model unchanged (F-38 schema).  
- **No** change to how runtime resolves org from branch or single-org fallback.  
- Callers that do not use `OrganizationRegistryMutationService` behave as before.

---

## 8. Verifier usage and success criteria

From **`system/`**:

```bash
php scripts/audit_organization_registry_mutation_service.php
php scripts/audit_organization_registry_mutation_service.php --json
```

**Success:** exit code **0**; `checks_passed: true`. The script:

1. Asserts **null** behavior for invalid / missing ids where specified.  
2. Asserts **InvalidArgumentException** for whitespace-only **name**.  
3. Opens a transaction, runs **create → update name → suspend → reactivate → clear code**, then **`ROLLBACK`**.  
4. Asserts organization **count** unchanged and rolled-back **id** not readable.

**Failure:** exit code **1** — inspect `errors` (JSON) or `ERROR:` lines.

Requires a reachable MySQL database with **087** schema applied (`organizations.suspended_at` present).

---

## 9. Single recommended next wave (name only)

**FOUNDATION-42 — USER-ORGANIZATION-MEMBERSHIP-AND-ORGANIZATION-CONTEXT-INTEGRATION-MINIMAL-R1**

---

## 10. Stop

This wave ends at mutation service + verifier + docs + roadmap row. **Do not** start FOUNDATION-42 in the same change set unless explicitly tasked.
