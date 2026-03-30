# USER-ORGANIZATION-SINGLE-ORG-TRUTH — SECOND NON-HTTP CONSUMER POST-IMPLEMENTATION CONSOLIDATED CLOSURE TRUTH AUDIT (FOUNDATION-55)

**Mode:** Read-only closure audit of **FOUNDATION-54** (second non-HTTP consumer of membership-strict single-org truth). **No** code, schema, routes, middleware, resolver body, auth, branch, repository-scope, HTTP, or UI changes.

**Evidence read:** `audit_user_organization_membership_context_resolution.php`, `audit_user_organization_membership_backfill_and_gate.php`, `UserOrganizationMembershipStrictGateService.php` (assert definition), `UserOrganizationMembershipReadService.php`, `UserOrganizationMembershipReadRepository.php`, `OrganizationContextResolver.php`, `StaffMultiOrgOrganizationResolutionGate.php`, repo-wide grep for `assertSingleActiveMembershipForOrgTruth`, F-54 implementation doc + roadmap.

---

## 1. FOUNDATION-54 changed only the intended second-consumer verifier surface

**Proof — `assert*` call sites (PHP grep, `system/**/*.php`):**

- **`UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth`** — definition only.
- **`audit_user_organization_membership_backfill_and_gate.php`** — negative + positive (F-51); file content still carries full F-48/F-51 flow (information_schema, reflection, dry-run backfill, `assert(0)`, sample gate vs raw).
- **`audit_user_organization_membership_context_resolution.php`** — positive path at lines 171–180 (F-54); **no third** caller.

**Proof — scoped core files:** `OrganizationContextResolver` and `StaffMultiOrgOrganizationResolutionGate` contain **no** `UserOrganizationMembershipStrictGateService` and **no** `assertSingleActiveMembershipForOrgTruth` references (structural read; membership path in resolver uses `UserOrganizationMembershipReadService` only).

**Conclusion:** The **second consumer** is **only** the context-resolution verifier extension; the first-consumer script and gate class were not repurposed as the F-54 *change surface* beyond remaining the first consumer (unchanged F-51 behavior in-tree).

---

## 2. All FOUNDATION-46 checks remain intact (context verifier)

The script still performs:

- DB selected; `information_schema` probe for `user_organization_memberships` (lines 38–50).
- `OrganizationContext::MODE_MEMBERSHIP_SINGLE_ACTIVE` reflection (lines 52–55).
- `OrganizationContextResolver` ctor ≥ 3 params including `UserOrganizationMembershipReadService` (lines 57–61).
- `UserOrganizationMembershipReadService` user **0**: count 0, list `[]`, single `null` (lines 65–73).
- Sample user: `SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1` (lines 75–76).
- Table absent: non-throwing reads + zero/empty/null expectations for sample user when present (lines 78–97).
- Table present: raw SQL vs service count/list/single for **same** `sampleUserId` (lines 106–144).

```52:76:system/scripts/audit_user_organization_membership_context_resolution.php
    $ref = new ReflectionClass(\Core\Organization\OrganizationContext::class);
    if (!$ref->hasConstant('MODE_MEMBERSHIP_SINGLE_ACTIVE')) {
        $errors[] = 'OrganizationContext::MODE_MEMBERSHIP_SINGLE_ACTIVE missing';
    }

    $resolverRef = new ReflectionClass(\Core\Organization\OrganizationContextResolver::class);
    $ctor = $resolverRef->getConstructor();
    if ($ctor === null || $ctor->getNumberOfParameters() < 3) {
        $errors[] = 'OrganizationContextResolver must accept Database + AuthService + UserOrganizationMembershipReadService';
    }

    $svc = app(\Modules\Organizations\Services\UserOrganizationMembershipReadService::class);
...
    $userRow = $db->fetchOne('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
    $sampleUserId = $userRow !== null ? (int) ($userRow['id'] ?? 0) : 0;
```

---

## 3. All FOUNDATION-51 checks remain intact (backfill/gate verifier)

`audit_user_organization_membership_backfill_and_gate.php` still includes:

- `information_schema` vs `isMembershipTablePresent()` (lines 41–60).
- Reflection on gate methods (lines 47–54).
- `getUserOrganizationMembershipState(0)` table_absent / STATE_NONE contract (lines 63–70).
- When table present: membership row count before/after; **`UserOrganizationMembershipBackfillService::run(true)`** ×2; determinism; bucket sum = scanned (lines 72–93); row count unchanged (lines 95–98).
- Negative **`assertSingleActiveMembershipForOrgTruth(0)`** must throw (lines 100–105).
- Sample user gate vs raw SQL + conditional positive assert (lines 107–162).
- Table absent: `getUserOrganizationMembershipState(1)` → `STATE_TABLE_ABSENT` (lines 163–167).

```72:105:system/scripts/audit_user_organization_membership_backfill_and_gate.php
    if ($membershipTablePresent) {
        $positiveAssertSingleMembershipTruth = [
            'status' => 'skipped_no_single_sample_user',
...
        $dry1 = app(\Modules\Organizations\Services\UserOrganizationMembershipBackfillService::class)->run(true);
        $dry2 = app(\Modules\Organizations\Services\UserOrganizationMembershipBackfillService::class)->run(true);
...
        try {
            $gate->assertSingleActiveMembershipForOrgTruth(0);
            $errors[] = 'assertSingleActiveMembershipForOrgTruth(0) must throw';
        } catch (RuntimeException) {
            // expected
        }
```

**Note:** F-48 dry-run / row-count checks are **part of** this file’s contract; they remain present (lines 80–98).

---

## 4. Positive path in context verifier is verifier-only and read-only

- Script is CLI-only (`require` bootstrap + modules); **no** HTTP.
- **No** `UserOrganizationMembershipBackfillService` (no mutation path).
- Positive block uses **`getUserOrganizationMembershipState`**, **`assertSingleActiveMembershipForOrgTruth`**, and existing **SELECT** reads only (lines 160–180).

---

## 5. Positive path uses the same sample user already selected by that verifier

Single assignment ` $sampleUserId` from lines 75–76; assert invoked as **`assertSingleActiveMembershipForOrgTruth($sampleUserId)`** (line 171). No second user query in the positive path.

---

## 6. Positive path compares assert return exactly against `gate['organization_id']` for that user

```165:176:system/scripts/audit_user_organization_membership_context_resolution.php
                $gateOrgId = isset($g['organization_id']) && $g['organization_id'] !== null ? (int) $g['organization_id'] : 0;
                $positiveAssertSingleMembershipTruth['gate_organization_id'] = $gateOrgId > 0 ? $gateOrgId : null;
                if ($gateOrgId <= 0) {
                    $errors[] = 'F-54 assert path: gate state single requires positive organization_id';
                } else {
                    try {
                        $assertedId = $gate->assertSingleActiveMembershipForOrgTruth($sampleUserId);
                        $positiveAssertSingleMembershipTruth['asserted_organization_id'] = $assertedId;
                        if ($assertedId !== $gateOrgId) {
                            $errors[] = 'assertSingleActiveMembershipForOrgTruth return must equal gate organization_id for context-resolution sample user';
                        } else {
                            $positiveAssertSingleMembershipTruth['status'] = 'passed';
                        }
```

Strict **`!==`**; **`passed`** only in the else branch of that comparison.

---

## 7. Skipped / not-applicable states are honest (not fake passes)

| Status | Set when (code) |
|--------|------------------|
| `skipped_table_absent` | Initial structure; unchanged if table missing (branch 78+ never overwrites). |
| `skipped_no_sample_user` | Table present; reset at 99–104; remains if `sampleUserId <= 0` (155–156). |
| `skipped_sample_readparity_mismatch` | `sampleUserId > 0` but `$readParityOk` false (157–158). |
| `skipped_gate_state_not_single` | Parity ok but gate state not `STATE_SINGLE` (162–163). |
| `passed` | Only when assert return equals `gateOrgId` (176). |

Skipped statuses do **not** clear F-46 parity **errors** when parity fails — `errors[]` is still populated (lines 136–143). **`checks_passed`** is **`$errors === []`** only (lines 191–197).

---

## 8. No HTTP / resolver-body / middleware / F-25 / controller / service adoption drift

- **`assert*`** grep: **two scripts + gate class** only.
- **`OrganizationContextResolver`:** still injects **`UserOrganizationMembershipReadService`**; no strict gate.
- **`StaffMultiOrgOrganizationResolutionGate`:** **`OrganizationContext`** + **`countActiveOrganizations()`**; no strict gate.
- **`UserOrganizationMembershipReadRepository` / `ReadService`:** unchanged read facades (audit scope: no new `assert*` references).

---

## 9. No unintended mutation / update / delete path

- **Context verifier:** no backfill service; no `INSERT`/`UPDATE`/`DELETE`.
- **Backfill/gate verifier:** only **`BackfillService::run(true)`** (lines 82–83); no `run(false)` in script.
- **Strict gate service:** read-only / throw-only **`assert*`** (existing design).

---

## 10. Remaining waivers / risks after FOUNDATION-54

| Id | Waiver / risk |
|----|----------------|
| **R-55-1** | **Dual verifier overlap:** Both verifiers use the **same** first-sample-user query (`ORDER BY id ASC`). Positive **`assert*`** may run twice for the same user when both scripts pass preconditions — redundant but consistent cross-check. |
| **R-55-2** | **Coverage:** Still **first user only** per script; no multi-user sweep in-tree. |
| **R-55-3** | **`checks_passed` vs `positive_assert…status`:** Exit **0** can occur with **`passed`** not exercised (`skipped_*`) — operators must read **`positive_assert_single_membership_truth.status`**. |
| **R-55-4** | **Program gap:** HTTP **`OrganizationContext`** and F-25 still **do not** call **`assert*`** — unchanged by F-54. |

---

## 11. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | F-54 closure with no material residual caveats. |
| **B** | F-54 met; **documented** program / coverage caveats remain. |
| **C** | Closure unsupported by tree. |

**FOUNDATION-55 verdict: B**

**Rationale:** Tree evidence supports F-54 as **second** verifier-only consumer with F-46 + F-48/F-51 preserved in their respective scripts; **R-55-1–R-55-4** remain explicit.

---

## 12. STOP

**FOUNDATION-55** ends here — **no FOUNDATION-56** opened by this audit.

**Companion:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-SECOND-NON-HTTP-CONSUMER-SURFACE-MATRIX-FOUNDATION-55.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-55-SECOND-NON-HTTP-CONSUMER-CLOSURE-AUDIT-CHECKPOINT.zip`.
