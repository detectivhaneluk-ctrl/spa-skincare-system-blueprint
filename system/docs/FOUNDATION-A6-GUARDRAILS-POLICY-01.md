# FOUNDATION-A6 — Mechanical Guardrails Policy

**Status:** ACTIVE  
**Installed:** 2026-03-31 (BIG-03)  
**Root families addressed:** ROOT-01 (id-only tenant scope drift), ROOT-05 (service scope drift)  
**CI scripts:**
- `system/scripts/ci/guardrail_service_layer_db_ban.php`
- `system/scripts/ci/guardrail_id_only_repo_api_freeze.php`

---

## Why guardrails exist

Architectural rules that exist only as written conventions drift.
Every new feature is a new opportunity to violate them.

The 2026 Architecture Reset (FOUNDATION-A1..A5) installed:
- An immutable `TenantContext` kernel (A1)
- A canonical TenantContext-scoped repository API (A4)
- A migrated media pilot lane (A5)

Without mechanical enforcement, a single service call like `$this->db->fetchOne(...)` inside a migrated service silently regrows ROOT-05. A single new repository method taking `int $branchId` without `TenantContext` regrows ROOT-01.

These guardrails make the architecture self-defending. Violations fail the CI pipeline.

---

## Guardrail 1: Service Layer DB Ban

**Script:** `system/scripts/ci/guardrail_service_layer_db_ban.php`  
**Trigger:** Runs in CI on every PR / push affecting protected service files.  
**Local run:** `php system/scripts/ci/guardrail_service_layer_db_ban.php`

### Rule

Protected-domain service files MUST NOT contain any of:
- `->fetchOne(` — direct DB row read
- `->fetchAll(` — direct DB row-set read
- `->query(` — direct DB statement execution
- `->insert(` — direct DB row write
- `->lastInsertId()` — direct DB write ID retrieval

**Permitted:** `->connection()` — for transaction management (`beginTransaction` / `commit` / `rollBack`). This is infrastructure, not data access.

### Rationale

ROOT-05: If services bypass the repository contract and query the database directly, the canonical TenantContext-scoped repository API provides no real ownership guarantee. The guarantee is only as strong as the enforcement.

Services orchestrate. Repositories enforce access-safe retrieval and mutation.

### Currently protected services

| File | Phase | Migrated |
|------|-------|---------|
| `system/modules/clients/services/ClientProfileImageService.php` | MEDIA_PILOT | 2026-03-31 |
| `system/modules/marketing/services/MarketingGiftCardTemplateService.php` | MEDIA_PILOT | 2026-03-31 |

### How to expand scope (A7 migration order)

When a domain completes the A7 migration:
1. Migrate the service(s) to use TenantContext + canonical repo methods (follow BIG-02 pattern)
2. Run the guardrail against the migrated file(s) to confirm they pass
3. Add the file path(s) to `$protectedServices` in the script
4. Commit the guardrail expansion as part of the migration PR

**Do NOT add a file to the protected list before it is clean.** The guardrail will immediately fail CI.

---

## Guardrail 2: Id-Only Repository API Freeze

**Script:** `system/scripts/ci/guardrail_id_only_repo_api_freeze.php`  
**Trigger:** Runs in CI on every PR / push affecting protected repository files.  
**Local run:** `php system/scripts/ci/guardrail_id_only_repo_api_freeze.php`

### Rule

In protected repository files, all NEW public data access methods for tenant-owned resources MUST take `TenantContext` as their first parameter.

A method is flagged as a violation if ALL of these are true:
1. It is a `public function`
2. Its signature contains `int $branchId` (the old id-only scope parameter)
3. Its signature does NOT start with `TenantContext $ctx` (not a canonical method)
4. Its name is NOT in the file's grandfathered legacy allowlist

### Rationale

ROOT-01: The old pattern is `findActiveXxxForBranch(int $id, int $branchId)`. The caller-supplied `$branchId` is trusted as-is; there is no proof that the caller has a valid resolved TenantContext. This is id-only acquisition.

The canonical pattern is `loadVisibleXxx(TenantContext $ctx, int $id)`. The context carries the verified, immutable `organization_id` + `branch_id` from the request entry point. The method cannot run without them.

### Currently protected repositories

| File | Phase | Grandfathered methods frozen |
|------|-------|-----------------------------|
| `system/modules/marketing/repositories/MarketingGiftCardTemplateRepository.php` | MEDIA_PILOT | 2026-03-31 |
| `system/modules/clients/repositories/ClientProfileImageRepository.php` | MEDIA_PILOT | 2026-03-31 |

### Grandfathered legacy methods policy

The legacy methods in protected repositories may remain for backward compatibility with callers not yet migrated. They are recorded in the allowlist in the script (with a dated comment). They are not newly-acceptable patterns — they are frozen debt with a documented migration path.

**Rule for legacy methods:**
- They are allowlisted by name
- No new methods may be added to the allowlist without a dated comment and a corresponding canonical TenantContext replacement
- When the module is fully migrated (A7 phase), the allowlist for that file should shrink to empty (legacy methods removed or replaced)

### How to expand scope (A7 migration order)

When a repository domain completes the A7 migration:
1. Migrate the repository to add canonical TenantContext-scoped methods
2. Record the existing legacy methods as the initial allowlist
3. Add the repository to `$protectedRepositories` in the script
4. Commit the guardrail expansion as part of the migration PR

---

## Protected domain summary (current)

| Domain | Services protected | Repositories protected | Phase |
|--------|-------------------|----------------------|-------|
| Media / Client image library | `ClientProfileImageService` | `ClientProfileImageRepository` | MEDIA_PILOT |
| Marketing gift card templates | `MarketingGiftCardTemplateService` | `MarketingGiftCardTemplateRepository` | MEDIA_PILOT |

### Domains NOT yet protected (A7 migration order)

| Domain | Planned migration phase | Blocking condition |
|--------|------------------------|--------------------|
| Appointments | PHASE-1 | Awaiting A7 migration wave |
| Online-booking | PHASE-2 | Awaiting A7 migration wave |
| Sales | PHASE-3 | Awaiting A7 migration wave |
| Client-owned resources | PHASE-4 | Awaiting A7 migration wave |

These domains are NOT currently protected. Adding their files before migration is complete will immediately fail CI. Do not add them until the migration is done.

---

## Integration with existing CI pipeline

The guardrail scripts follow the same pattern as `system/scripts/ci/verify_architecture_contracts.php`:
- Exit code 0 = PASS
- Exit code 1 = FAIL (with stderr output)
- Designed to be added to `.github/workflows/pr-fast-guardrails.yml`

### Recommended CI step addition

In `.github/workflows/pr-fast-guardrails.yml`, add:

```yaml
- name: FOUNDATION-A6 Service DB ban
  run: php system/scripts/ci/guardrail_service_layer_db_ban.php

- name: FOUNDATION-A6 Id-only repo API freeze
  run: php system/scripts/ci/guardrail_id_only_repo_api_freeze.php
```

---

## What these guardrails do NOT enforce (honest limits)

| Limit | Reason |
|-------|--------|
| Does not detect DB access in helper traits/base classes used by protected services | Scope limited to file-level analysis. Expand if a base class pattern emerges. |
| Does not detect id-only patterns in repository methods that don't use `int $branchId` naming | Methods using `$tenantId` or other parameter names are not caught. Expand pattern list if new variants appear. |
| Does not enforce authorization (`AuthorizerInterface`) calls | A2 authorization kernel is a separate enforcement concern not yet enforced in CI. |
| Does not cover domains outside the protected list | By design — incremental expansion as A7 migration phases complete. |

---

## Verification

Run the BIG-03 verification script to confirm both guardrails detect violations and pass on the pilot lane:

```bash
php system/scripts/read-only/verify_big_03_guardrails_01.php
```
