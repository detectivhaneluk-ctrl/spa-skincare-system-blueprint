# USER-ORGANIZATION-MEMBERSHIP-BACKFILL-AND-STRICT-GATE — POST-IMPLEMENTATION CONSOLIDATED CLOSURE TRUTH AUDIT (FOUNDATION-49)

**Mode:** Read-only closure audit of **FOUNDATION-48** implementation. **No** code, schema, route, or behavior changes in this wave.

**Scope (files read):**

- `system/modules/organizations/repositories/UserOrganizationMembershipReadRepository.php`
- `system/modules/organizations/services/UserOrganizationMembershipReadService.php`
- `system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php`
- `system/modules/organizations/services/UserOrganizationMembershipBackfillService.php`
- `system/modules/bootstrap/register_organizations.php`
- `system/scripts/backfill_user_organization_memberships.php`
- `system/scripts/audit_user_organization_membership_backfill_and_gate.php`
- `system/scripts/audit_user_organization_membership_context_resolution.php` (F-46 verifier; cross-check only)
- `system/docs/USER-ORGANIZATION-MEMBERSHIP-BACKFILL-AND-STRICT-GATE-IMPLEMENTATION-FOUNDATION-48.md`
- `handoff/HandoffZipRules.ps1`, `handoff/build-final-zip.ps1` (checkpoint ZIP exclusion truth)

---

## 1. Strict-gate state model (proof)

**Verdict:** The only `state` string values emitted by `UserOrganizationMembershipStrictGateService::getUserOrganizationMembershipState()` are exactly **`table_absent`**, **`none`**, **`single`**, **`multiple`**, matching the four public constants.

**Evidence — constants map 1:1 to string literals:**

```14:20:system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php
    public const STATE_TABLE_ABSENT = 'table_absent';

    public const STATE_NONE = 'none';

    public const STATE_SINGLE = 'single';

    public const STATE_MULTIPLE = 'multiple';
```

**Evidence — branching exhausts cases:**

- **`table_absent`:** `!$this->repository->isMembershipTablePresent()` → early return with `STATE_TABLE_ABSENT` (lines 38–44).
- **`none`:** Table present and (`$userId <= 0` **or** zero active memberships after list) → `STATE_NONE` (lines 47–54, 60–66).
- **`single`:** Exactly one id in `$ids` → `STATE_SINGLE` (lines 69–75).
- **`multiple`:** `$count > 1` → `STATE_MULTIPLE` (lines 78–83).

No fifth branch returns a `state` value.

---

## 2. `assertSingleActiveMembershipForOrgTruth()` — behavior and stable failure messages

**Success path:** After `getUserOrganizationMembershipState()`, if `state === STATE_SINGLE` and `organization_id` is non-null and `> 0`, returns that **int** (lines 104–109).

**Failure paths — exact `RuntimeException` message strings in source order:**

| Condition | Message |
|-----------|---------|
| `STATE_TABLE_ABSENT` | `user_organization_memberships table is not present (migration 087 not applied).` |
| `STATE_NONE` | `No active organization membership for user.` |
| `STATE_MULTIPLE` | `Multiple active organization memberships; ambiguous.` |
| `STATE_SINGLE` but `organization_id === null` or `<= 0` | `Membership state single but organization id missing.` |

```91:109:system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php
    public function assertSingleActiveMembershipForOrgTruth(int $userId): int
    {
        $s = $this->getUserOrganizationMembershipState($userId);
        if ($s['state'] === self::STATE_TABLE_ABSENT) {
            throw new \RuntimeException('user_organization_memberships table is not present (migration 087 not applied).');
        }
        if ($s['state'] === self::STATE_NONE) {
            throw new \RuntimeException('No active organization membership for user.');
        }
        if ($s['state'] === self::STATE_MULTIPLE) {
            throw new \RuntimeException('Multiple active organization memberships; ambiguous.');
        }

        $id = $s['organization_id'];
        if ($id === null || $id <= 0) {
            throw new \RuntimeException('Membership state single but organization id missing.');
        }

        return $id;
    }
```

**Note:** For `userId <= 0` with table present, `getUserOrganizationMembershipState` returns `STATE_NONE` before membership SQL, so `assert…` throws the **none** message (not **table_absent**).

---

## 3. Backfill decision tree — deterministic, narrow, idempotent

**Engine:** `UserOrganizationMembershipBackfillService::run(bool $dryRun)`.

**Determinism:**

- User set: `SELECT id, branch_id FROM users WHERE deleted_at IS NULL ORDER BY id ASC` (fixed ordering).
- Active-org list for ambiguity checks: `ORDER BY m.organization_id ASC` (lines 151–159), same ordering contract as F-46 reads.

**Narrow eligibility:** A live **INSERT** (when `!$dryRun`) occurs only after **all** of:

1. Table present (`isMembershipTablePresent()`); otherwise immediate zeroed return (lines 41–51).
2. `branch_id` not null and `> 0` (lines 62–66).
3. Branch resolves to a live org via `branches` ∩ `organizations` with both `deleted_at IS NULL` (lines 68–73, 118–136).
4. No existing row for `(user_id, organization_id)` **any status** — `membershipRowExists` uses unqualified `user_organization_memberships` (lines 138–146, 76–80).
5. Active membership count (joined to live orgs, `status = 'active'`) is not `> 1` (lines 85–89).
6. If active count is `1`, that org id equals branch-resolved `organizationId` (lines 91–95); otherwise **skipped_ambiguous**.

**Idempotence:** Second run with unchanged data: step 4 yields **`skipped_existing`** for rows already inserted; no **UPDATE**/**DELETE** in this class.

**INSERT-only mutation path:**

```97:104:system/modules/organizations/services/UserOrganizationMembershipBackfillService.php
            if (!$dryRun) {
                $this->db->query(
                    'INSERT INTO user_organization_memberships (user_id, organization_id, status, default_branch_id)
                     VALUES (?, ?, ?, ?)',
                    [$userId, $organizationId, 'active', $branchId > 0 ? $branchId : null]
                );
            }
```

**CLI precondition (operator gate):** `backfill_user_organization_memberships.php` exits **1** if the table is missing (lines 31–33); it does not rely on `BackfillService::run` alone for that failure mode.

---

## 4. Unintended mutation / update / delete paths

| Component | SQL writes |
|-----------|------------|
| `UserOrganizationMembershipReadRepository` | **None** (docblock states mutations out of scope; only `SELECT` / `information_schema`). |
| `UserOrganizationMembershipReadService` | **None** (delegates to repository). |
| `UserOrganizationMembershipStrictGateService` | **None** (read-only gate). |
| `UserOrganizationMembershipBackfillService` | **Only** the `INSERT` above when `!$dryRun`. |
| `audit_user_organization_membership_backfill_and_gate.php` | **None** — explicitly uses `run(true)` only; asserts membership row count unchanged (script lines 67–83). |
| `audit_user_organization_membership_context_resolution.php` | **None** (read-only checks). |

**Conclusion:** Within audited files, no **UPDATE**/**DELETE** on `user_organization_memberships` was introduced; the only write is the intended **INSERT** path in the backfill service.

---

## 5. Migration **087** absent — backward-safe behavior

**Read repository:** If `user_organization_memberships` is missing from `information_schema`, `membershipTableAvailable()` is false; `count*` / `list*` / `getSingle*` return **0**, **[]**, **null** without querying the pivot (lines 60–62, 86–88).

**Strict gate:** `isMembershipTablePresent()` false → `getUserOrganizationMembershipState` returns **`table_absent`** with zero counts and empty ids (lines 38–44). **No** pivot SQL.

**Backfill service:** If table absent, `run()` returns a zeroed result array immediately (lines 41–51) — **no** user scan, **no** INSERT.

**Backfill CLI:** Fails closed with stderr and exit **1** when table missing (lines 31–33 of `backfill_user_organization_memberships.php`).

**F-46 context verifier:** When table absent, still requires membership reads to be non-throwing and empty (script lines 70–88); aligns with repository behavior above.

**Conclusion:** Pre-087 databases do not execute membership pivot queries from the read repository; gate exposes explicit **`table_absent`**; operators are steered to apply **087** before the backfill CLI.

---

## 6. No HTTP / UI / F-25 / middleware / auth drift from FOUNDATION-48 (proof)

**Ripgrep across the workspace** for `UserOrganizationMembershipStrictGateService`, `UserOrganizationMembershipBackfillService`, and `assertSingleActiveMembershipForOrgTruth` yields:

- Class definitions and **DI** in `register_organizations.php`
- **CLI** `backfill_user_organization_memberships.php`
- **CLI** `audit_user_organization_membership_backfill_and_gate.php`
- **Documentation** (`BOOKER-PARITY-MASTER-ROADMAP.md`, F-48 ops doc)

**No** matches in controllers, middleware, `AuthMiddleware`, routes, or views.

**Conclusion:** F-48 surfaces are **bootstrap + scripts + services** only; no new HTTP/UI/F-25/middleware/auth integration was introduced in-tree for the strict gate or backfill.

---

## 7. Checkpoint ZIP packaging truth (exclusions)

Canonical rules live in **`Test-HandoffPackagedPathForbidden`** — forbidden inside the archive:

- `system/.env`
- `system/.env.local`
- Any path ending in **`.zip`** (nested archives)
- Any path under `system/storage/logs/`
- Any path under `system/storage/backups/`
- Any path ending in **`.log`**

```17:37:handoff/HandoffZipRules.ps1
function Test-HandoffPackagedPathForbidden {
    param([string]$NormalizedRelative)
    if ($NormalizedRelative -eq "system/.env") {
        return "forbidden path: system/.env"
    }
    if ($NormalizedRelative -eq "system/.env.local") {
        return "forbidden path: system/.env.local"
    }
    if ($NormalizedRelative.EndsWith(".zip")) {
        return "forbidden path: nested or generated zip archive ($NormalizedRelative)"
    }
    if ($NormalizedRelative.StartsWith("system/storage/logs/")) {
        return "forbidden path: local storage log/debug under system/storage/logs/ ($NormalizedRelative)"
    }
    if ($NormalizedRelative.StartsWith("system/storage/backups/")) {
        return "forbidden path: local storage backup under system/storage/backups/ ($NormalizedRelative)"
    }
    if ($NormalizedRelative.EndsWith(".log")) {
        return "forbidden path: runtime log ($NormalizedRelative)"
    }
    return $null
}
```

**`build-final-zip.ps1`** filters files with the same helper before creating the archive and post-verifies with `Get-HandoffZipForbiddenEntries` (lines 35–45, 69–78).

---

## 8. Remaining waivers / risks after FOUNDATION-48

| Id | Waiver / risk | Basis |
|----|----------------|--------|
| **R-1** | **F-47-class resolver and product ambiguity** (e.g. `MODE_UNRESOLVED_AMBIGUOUS_ORGS` overload, multi-org product truth) | Explicitly **not removed** in F-48 ops doc §1 / §5. |
| **R-2** | **`information_schema` dependency** for table presence | Same probe as F-46-REPAIR; gate and repository depend on it. |
| **R-3** | **`UserOrganizationMembershipBackfillService::run()`** with table absent returns **zeroed stats without throwing** | Safe for reads; **CLI** enforces exit **1** for operators — programmatic misuse could ignore empty result. |
| **R-4** | **No in-tree HTTP consumer** of `assertSingleActiveMembershipForOrgTruth` / gate state | Adoption is **opt-in** for future call sites; no automatic enforcement layer in this wave. |
| **R-5** | **Invited / revoked** rows block insert (existing PK) with **no status upgrade** in F-48 | F-48 ops §5; data repair is a separate program. |
| **R-6** | **Concurrent live backfill** processes could race on the same user/org INSERT | No application-level locking in backfill service. |
| **R-7** | Users **without** `branch_id` or with **unresolvable** branch→org are skipped permanently by backfill | By design; manual membership remains required. |

---

## 9. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Closure with **no** material residual waivers affecting this slice. |
| **B** | Implementation matches stated design; **documented** residual / operational waivers remain. |
| **C** | Material mismatch or untrusted closure. |

**FOUNDATION-49 verdict: B**

**Rationale:** In-scope code **proves** the four-state gate model, stable assert messages, INSERT-only backfill with deterministic ordering, **087**-absent safety on reads/gate, and **no** new HTTP/F-25/middleware wiring for F-48 services. Residual items **R-1–R-7** are explicit and do not contradict the implementation but prevent a strict **A**.

---

## 10. STOP

**FOUNDATION-49** ends here — no **FOUNDATION-50** opened by this audit.

**Companion:** `USER-ORGANIZATION-MEMBERSHIP-BACKFILL-AND-STRICT-GATE-SURFACE-MATRIX-FOUNDATION-49.md`.

**Checkpoint ZIP (fresh audit):** `distribution/spa-skincare-system-blueprint-FOUNDATION-49-MEMBERSHIP-BACKFILL-GATE-CLOSURE-AUDIT-CHECKPOINT.zip` (built via `handoff/build-final-zip.ps1` with that `-OutputZip`).
