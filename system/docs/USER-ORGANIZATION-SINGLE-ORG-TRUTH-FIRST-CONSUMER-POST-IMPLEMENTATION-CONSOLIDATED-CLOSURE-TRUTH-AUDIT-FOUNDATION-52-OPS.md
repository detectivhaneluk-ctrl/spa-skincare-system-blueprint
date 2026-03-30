# USER-ORGANIZATION-SINGLE-ORG-TRUTH — FIRST CONSUMER POST-IMPLEMENTATION CONSOLIDATED CLOSURE TRUTH AUDIT (FOUNDATION-52)

**Mode:** Read-only closure audit of **FOUNDATION-51** (first in-tree consumer of membership-strict single-org truth). **No** code, schema, route, middleware, resolver, auth, branch, repository-scope, HTTP, or UI changes in this wave.

**Evidence read:** `audit_user_organization_membership_backfill_and_gate.php`, `UserOrganizationMembershipStrictGateService.php`, `UserOrganizationMembershipBackfillService.php`, `UserOrganizationMembershipReadService.php`, `UserOrganizationMembershipReadRepository.php`, `OrganizationContextResolver.php`, `StaffMultiOrgOrganizationResolutionGate.php`, repo-wide PHP grep for `assertSingleActiveMembershipForOrgTruth`, F-50/F-51 ops + roadmap rows.

---

## 1. FOUNDATION-51 changed only the intended first-consumer verifier surface

**Proof:** Workspace `assertSingleActiveMembershipForOrgTruth` call sites are **only** the audit script (negative + positive) and the method **definition** in `UserOrganizationMembershipStrictGateService` (grep: `system/**/*.php`).

The strict gate, read service, read repository, backfill service, resolver, and F-25 gate files in scope contain **no** edits attributable to F-51 in this audit: `OrganizationContextResolver` and `StaffMultiOrgOrganizationResolutionGate` reference **`UserOrganizationMembershipReadService`** / **`OrganizationContext`** only — **no** `UserOrganizationMembershipStrictGateService` or `assert*`.

Implementation record: **`USER-ORGANIZATION-SINGLE-ORG-TRUTH-FIRST-CONSUMER-VERIFIER-IMPLEMENTATION-FOUNDATION-51.md`** states verifier-only changes + roadmap row; scoped services listed as **not changed**.

---

## 2. All FOUNDATION-48 checks remain intact

The verifier still performs, in order when the DB is selected:

- `information_schema` table probe vs `UserOrganizationMembershipReadRepository::isMembershipTablePresent()` parity (lines 41–60).
- Reflection presence of `getUserOrganizationMembershipState` and `assertSingleActiveMembershipForOrgTruth` (lines 47–54).
- `getUserOrganizationMembershipState(0)` vs **`STATE_TABLE_ABSENT`** / **`STATE_NONE`** (lines 63–70).
- When table present: membership row count before/after dry-run backfill; **`UserOrganizationMembershipBackfillService::run(true)`** twice; determinism; bucket sum equals `scanned` (lines 72–98).
- Sample user: gate state and `organization_ids` vs raw SQL (lines 107–137).

```41:98:system/scripts/audit_user_organization_membership_backfill_and_gate.php
    $tbl = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $tbl->execute([$dbName, 'user_organization_memberships']);
    $membershipTablePresent = $tbl->fetchColumn() !== false;
...
    if ($membershipTablePresent) {
        $positiveAssertSingleMembershipTruth = [
            'status' => 'skipped_no_single_sample_user',
...
        $membershipCountBefore = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM user_organization_memberships')['c'] ?? 0);

        $dry1 = app(\Modules\Organizations\Services\UserOrganizationMembershipBackfillService::class)->run(true);
        $dry2 = app(\Modules\Organizations\Services\UserOrganizationMembershipBackfillService::class)->run(true);
...
        $membershipCountAfter = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM user_organization_memberships')['c'] ?? 0);
        if ($membershipCountBefore !== $membershipCountAfter) {
            $errors[] = 'verifier must not mutate membership rows; row count changed';
        }
```

Table-absent branch still asserts **`STATE_TABLE_ABSENT`** for user id `1` (lines 163–167).

---

## 3. Negative assert contract for `assertSingleActiveMembershipForOrgTruth(0)` remains intact

```100:105:system/scripts/audit_user_organization_membership_backfill_and_gate.php
        try {
            $gate->assertSingleActiveMembershipForOrgTruth(0);
            $errors[] = 'assertSingleActiveMembershipForOrgTruth(0) must throw';
        } catch (RuntimeException) {
            // expected
        }
```

With table present, `getUserOrganizationMembershipState(0)` yields **`STATE_NONE`** (see ```47:53:system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php```), and **`assert*`** throws **`RuntimeException`** with message **`No active organization membership for user.`** (lines 97–98) — consistent with the catch block.

---

## 4. Positive verifier path is verifier-only and read-only

- Script remains CLI-only (`require` bootstrap + `modules/bootstrap.php`); **no** HTTP entry.
- Backfill is still invoked **only** as **`run(true)`** (lines 82–83); docblock states no `run(false)` (lines 10, 82–83).
- Positive path calls **`getUserOrganizationMembershipState`**, raw SQL **SELECT**, and **`assertSingleActiveMembershipForOrgTruth`** — all read-side or throw-only; **no** INSERT/UPDATE/DELETE in the script.
- `UserOrganizationMembershipStrictGateService` is documented read-only for HTTP/middleware (```9:11:system/modules/organizations/services/UserOrganizationMembershipStrictGateService.php```); **`assert*`** only reads state via **`getUserOrganizationMembershipState`**.

---

## 5. Positive path compares assert return exactly against gate `organization_id` for the same sample user

Same `$sampleUserId` from first non-deleted user by **`id ASC`** (lines 107–108). After raw/gate alignment, when state is **`STATE_SINGLE`**:

```143:156:system/scripts/audit_user_organization_membership_backfill_and_gate.php
                $gateOrgId = isset($g['organization_id']) && $g['organization_id'] !== null ? (int) $g['organization_id'] : 0;
                $positiveAssertSingleMembershipTruth['gate_organization_id'] = $gateOrgId > 0 ? $gateOrgId : null;
...
                        $assertedId = $gate->assertSingleActiveMembershipForOrgTruth($sampleUserId);
                        $positiveAssertSingleMembershipTruth['asserted_organization_id'] = $assertedId;
                        if ($assertedId !== $gateOrgId) {
                            $errors[] = 'assertSingleActiveMembershipForOrgTruth return must equal gate organization_id for single-state sample user';
                        } else {
                            $positiveAssertSingleMembershipTruth['status'] = 'passed';
                        }
```

Comparison uses **strict inequality** `!==` on ints.

---

## 6. Skipped / not-applicable states are honest and explicit (not fake passes)

| `positive_assert_single_membership_truth.status` | When set (code) |
|--------------------------------------------------|-----------------|
| **`skipped_table_absent`** | Initial structure; unchanged if `$membershipTablePresent` is false (branch at 163+ never overwrites the initial block’s value for table-absent — initial is `skipped_table_absent` at lines 25–30; table-absent path skips the `if ($membershipTablePresent)` block, so structure remains). |
| **`skipped_no_single_sample_user`** | When table present, immediately after entering branch (lines 73–78); remains if no user, or first sample user is not **`single`**, or raw/gate mismatch prevents positive block. |
| **`passed`** | Only when assert return equals `gateOrgId` (line 155). |

Skipped statuses do **not** append synthetic success to **`errors`**; overall **`checks_passed`** still reflects **only** `$errors === []`. A run can **pass** all F-48 checks while positive assert is **skipped** — that is an **explicit** “not exercised” outcome via **`status`**, not a claim that assert succeeded.

---

## 7. No HTTP / resolver / middleware / F-25 / controller / service adoption drift

**Ripgrep** `assertSingleActiveMembershipForOrgTruth` in `system/**/*.php`: hits are **only** `audit_user_organization_membership_backfill_and_gate.php` and **`UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth`**.

**Resolver** (`OrganizationContextResolver`) uses **`UserOrganizationMembershipReadService`** only — no strict gate (full file read; membership path lines 49–63).

**F-25** (`StaffMultiOrgOrganizationResolutionGate`) uses **`OrganizationContext`** + **`OrganizationContextResolver::countActiveOrganizations()`** — no strict gate (lines 21–45).

---

## 8. No unintended mutation / update / delete path introduced

- **Verifier:** only **`BackfillService::run(true)`**; no live backfill.
- **`UserOrganizationMembershipBackfillService`:** only **`INSERT`** when **`!$dryRun`** (```97:103:system/modules/organizations/services/UserOrganizationMembershipBackfillService.php```) — verifier never passes `false`.
- **`UserOrganizationMembershipReadRepository`:** docblock: no INSERT/UPDATE/DELETE (lines 9–12); only SELECT / information_schema.
- **`UserOrganizationMembershipStrictGateService`:** no database writes.

---

## 9. Remaining waivers / risks after FOUNDATION-51

| Id | Risk / waiver |
|----|----------------|
| **R-52-1** | **Program semantics:** HTTP **`OrganizationContext`** resolution (branch-first, legacy single-org fallback) remains **independent** of **`assertSingleActiveMembershipForOrgTruth`**. Staff can have resolved org without membership-single assert semantics. |
| **R-52-2** | **Coverage:** Positive assert runs only for the **first** `users` row (by id) that passes raw/gate alignment and **`STATE_SINGLE`**. Other users in **`single`** are not asserted in the same run. |
| **R-52-3** | **Operator interpretation:** **`checks_passed: true`** with **`positive_assert_single_membership_truth.status: skipped_*`** means the assert API was **not** positively proven on that run — rely on **`status`**, not only exit code. |
| **R-52-4** | **Pre-F-51 residuals unchanged:** F-25 **`countActiveOrganizations() <= 1`** bypass; **`information_schema`** table probe; multi-org product ambiguity — still outside this verifier slice. |

---

## 10. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | F-51 objectives fully met; no material residual caveats. |
| **B** | F-51 met; **documented** program-level / coverage caveats remain. |
| **C** | Closure not supported by tree. |

**FOUNDATION-52 verdict: B**

**Rationale:** In-tree evidence supports F-51 verifier-only adoption and preserves F-48 behavior; **`assert*`** has no new HTTP/resolver/F-25 consumers. **R-52-1–R-52-4** are explicit **post-closure** risks (not defects in F-51 implementation).

---

## 11. STOP

**FOUNDATION-52** ends here — **no FOUNDATION-53** opened by this audit.

**Companion:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-FIRST-CONSUMER-SURFACE-MATRIX-FOUNDATION-52.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-52-SINGLE-ORG-TRUTH-FIRST-CONSUMER-CLOSURE-AUDIT-CHECKPOINT.zip`.
