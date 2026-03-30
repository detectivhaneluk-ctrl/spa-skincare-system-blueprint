# USER-ORGANIZATION-MEMBERSHIP-BACKFILL-AND-STRICT-GATE — FOUNDATION-48

**Mode:** Single backend wave — deterministic backfill CLI, strict-gate service (read-only contract), verifier script, DI registration. **No** UI, **no** new HTTP routes, **no** F-25/middleware/auth redesign, **no** FOUNDATION-49 opened by this document.

**Baseline:** FOUNDATION-47 closure audit (F-46 + F-46-REPAIR); optional/empty **`user_organization_memberships`** risk (W-1–W-6 class waivers) reduced operationally via backfill + explicit gate API.

---

## 1. FOUNDATION-47 waivers / risk addressed here

| Waiver / risk (F-47) | F-48 mitigation |
|----------------------|-----------------|
| Empty pivot while **`users.branch_id`** remains the staff anchor | Deterministic **branch → organization** INSERT for safe users only |
| Need for a code-level “is membership truth usable?” probe without HTTP | **`UserOrganizationMembershipStrictGateService::getUserOrganizationMembershipState`** + **`assertSingleActiveMembershipForOrgTruth`** |
| Operational ambiguity between “no rows” and “table missing” | State **`table_absent`** vs **`none`** vs **`single`** vs **`multiple`** |

**Not removed:** multi-org product ambiguity, **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** overload in resolver, **`information_schema`** dependency for table presence — unchanged by F-48.

---

## 2. Files and classes added or changed

| Path | Role |
|------|------|
| `system/modules/organizations/repositories/UserOrganizationMembershipReadRepository.php` | **`isMembershipTablePresent()`** public wrapper (same cache as F-46-REPAIR probe). |
| `system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php` | **New** — membership state model + assert helper. |
| `system/modules/organizations/services/UserOrganizationMembershipBackfillService.php` | **New** — **`run(bool $dryRun)`** backfill engine. |
| `system/modules/bootstrap/register_organizations.php` | Registers strict-gate + backfill services. |
| `system/scripts/backfill_user_organization_memberships.php` | **New** — CLI backfill; **`--dry-run`**. |
| `system/scripts/audit_user_organization_membership_backfill_and_gate.php` | **New** — read-only verifier (dry-run only; no live backfill). |

---

## 3. Deterministic backfill rules

**Precondition:** migration **087** applied (`user_organization_memberships` exists). Script exits with error if the table is missing.

**Input:** all **`users`** with **`deleted_at IS NULL`**, ordered by **`id ASC`** (scanned count).

For each user:

1. **`branch_id` null or ≤ 0** → **`skipped_no_branch`** (no speculative row).
2. Resolve **live** branch + **live** organization: **`branches`** join **`organizations`** with **`branches.deleted_at IS NULL`**, **`organizations.deleted_at IS NULL`**. If not resolvable → **`skipped_missing_branch_org`**.
3. If **any** row exists in **`user_organization_memberships`** for **`(user_id, organization_id)`** (any status) → **`skipped_existing`** (no UPDATE; no delete).
4. Count **active** memberships joined to live orgs (same predicate as F-46 reads). If **> 1** → **`skipped_ambiguous`**.
5. If count **=== 1** and that org **≠** branch-resolved org → **`skipped_ambiguous`** (would create a second org).
6. If count **=== 0** → **INSERT** **`status = 'active'`**, **`default_branch_id = users.branch_id`** (valid branch already resolved).

**Idempotent:** second run yields **`inserted = 0`** unless new users/data appear.

---

## 4. Strict-gate state model

**`UserOrganizationMembershipStrictGateService::getUserOrganizationMembershipState(int $userId): array`**

| `state` | Meaning |
|---------|---------|
| **`table_absent`** | **`user_organization_memberships`** not in **`information_schema`** (087 not applied). |
| **`none`** | Table present; **zero** active memberships (live org join). |
| **`single`** | Exactly **one** active membership (live org). |
| **`multiple`** | **More than one** active membership. |

Keys: **`active_count`**, **`organization_id`** (single or null), **`organization_ids`** (ordered list, F-46 **`ORDER BY organization_id ASC`**).

**`assertSingleActiveMembershipForOrgTruth(int $userId): int`** — returns **`organization_id`** or throws **`RuntimeException`** with a stable message (table absent / none / multiple).

---

## 5. Cases intentionally left unresolved

- Users **without** **`branch_id`** — no backfill row.
- Users with **multiple** active memberships — no insert; gate stays **`multiple`**.
- Users with **invited/revoked** rows — existing PK blocks insert; no status upgrade in this wave.
- **Cross-org** staff truth beyond branch anchor — not inferred.
- **HTTP / F-25 / middleware** — no integration in F-48.

---

## 6. Intentionally NOT implemented

- Membership CRUD HTTP, UI, org switching.
- Global resolver/middleware/gate changes.
- Permission redesign, branch-domain changes, platform dashboard work.
- **FOUNDATION-49** (not opened).

---

## 7. Backward compatibility

- **087 not applied:** **`UserOrganizationMembershipReadRepository`** / read service / gate behave as F-46-REPAIR (empty reads; **`table_absent`**). **Backfill script exits with error** — operator must apply 087 before running CLI.
- **F-46 resolver** unchanged; gate is additive for callers that opt in.

---

## 8. Script usage and success criteria

**Backfill (from `system/`):**

```text
php scripts/backfill_user_organization_memberships.php --dry-run
php scripts/backfill_user_organization_memberships.php
```

**Success:** exit code **0**; counts printed; **`--dry-run`** has **`inserted: 0`** semantics vs live run on same data; live run is idempotent on re-execution.

**Verifier (read-only):**

```text
php scripts/audit_user_organization_membership_backfill_and_gate.php
php scripts/audit_user_organization_membership_backfill_and_gate.php --json
```

**Success:** exit code **0**; proves dry-run determinism, bucket sum equals **`scanned`**, membership row count unchanged during audit, gate matches raw SQL for a sample user when table present.

---

## 9. Single recommended next step (name only)

**FULL-ZIP acceptance audit — STOP** (no new foundation wave id opened from F-48).

---

## 10. Acceptance

This document describes implementation evidence only; **ZIP acceptance and product sign-off remain external** to this wave.
