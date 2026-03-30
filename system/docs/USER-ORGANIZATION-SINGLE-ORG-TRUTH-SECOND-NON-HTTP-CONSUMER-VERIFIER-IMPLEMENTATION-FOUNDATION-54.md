# USER-ORGANIZATION-SINGLE-ORG-TRUTH — SECOND NON-HTTP CONSUMER VERIFIER IMPLEMENTATION (FOUNDATION-54)

**Wave:** FOUNDATION-54 — implements the **exact** next consumer selected in **FOUNDATION-53** (extend F-46 context-resolution verifier only).

---

## 1. What changed

| Artifact | Change |
|----------|--------|
| `system/scripts/audit_user_organization_membership_context_resolution.php` | **F-46 checks preserved.** **F-54:** `positive_assert_single_membership_truth` + optional positive **`assertSingleActiveMembershipForOrgTruth`** when preconditions hold. |
| `system/docs/BOOKER-PARITY-MASTER-ROADMAP.md` | **FOUNDATION-54** row appended. |

**Not changed:** HTTP, middleware, `OrganizationContextResolver` implementation, auth, branch logic, `OrganizationRepositoryScope`, controllers, routes, schema, UI, strict gate / read repository / read service source (reference-only).

---

## 2. F-46 contract (intact)

- Reflection: `OrganizationContext::MODE_MEMBERSHIP_SINGLE_ACTIVE`; `OrganizationContextResolver` ctor accepts `UserOrganizationMembershipReadService`.
- User id **0:** count 0, list `[]`, single `null`.
- Table absent: sample user reads safe and empty (no throw).
- Table present: `UserOrganizationMembershipReadService` count / list / single vs raw SQL for the **same** first sample user (`ORDER BY id ASC`).

---

## 3. F-54 positive path (second non-HTTP `assert*` consumer)

**Preconditions (all required):**

1. Membership table present (`information_schema`).
2. `sample_user_id > 0` (first non-deleted user).
3. **Read parity:** `svcCount === rawCount`, `svcIds === rawIds`, `svcSingle === expectedSingle` (same definitions as F-46).
4. `UserOrganizationMembershipStrictGateService::getUserOrganizationMembershipState($sampleUserId)['state'] === STATE_SINGLE`.

**Then:** `assertSingleActiveMembershipForOrgTruth($sampleUserId)`; return value must **`===`** `gate['organization_id']` (positive int). Mismatch or unexpected throw → **`errors[]`**, exit **1**.

**Read-only:** No backfill, no INSERT/UPDATE/DELETE, no resolver edits.

---

## 4. Skipped / not-applicable statuses (`positive_assert_single_membership_truth.status`)

| Status | Meaning |
|--------|---------|
| `skipped_table_absent` | Pivot table missing |
| `skipped_no_sample_user` | No `users` row with `deleted_at IS NULL` |
| `skipped_sample_readparity_mismatch` | F-46 parity failed for sample user (errors also recorded) |
| `skipped_gate_state_not_single` | Gate state not `single` for sample user |
| `passed` | Assert return equals `gate_organization_id` |

Skipped paths do **not** set `passed`; overall `checks_passed` remains driven by `$errors === []`.

---

## 5. Why this is the **second** non-HTTP consumer

**First:** `audit_user_organization_membership_backfill_and_gate.php` (F-51) — gate + dry-run backfill verifier.  
**Second:** this script — ties **`UserOrganizationMembershipReadService`** (resolver’s membership input) to **`assert*`** under the same sample user, without touching **`OrganizationContextResolver`**.

---

## 6. Why resolver / middleware / F-25 / controllers / services are untouched

- **`OrganizationContextResolver`** is unchanged; the verifier only **reflects** its ctor and uses the **existing** read service from the container.
- No middleware, F-25, routes, or HTTP handlers were modified.
- Domain services beyond DI resolution of the gate are **not** edited.

---

## 7. Application behavior

**Unchanged for web/runtime:** Only the audit script and docs were updated. The script runs only when an operator executes it from `system/`.

---

## 8. Deliberate non-duplication of F-51

This verifier does **not** re-implement F-48 dry-run backfill checks or negative **`assert(0)`** — those remain owned by **`audit_user_organization_membership_backfill_and_gate.php`**. F-54 adds only the **minimum** gate + assert slice needed for the **context-resolution** verifier’s contract.

---

## 9. Usage

```text
php scripts/audit_user_organization_membership_context_resolution.php
php scripts/audit_user_organization_membership_context_resolution.php --json
```

---

## 10. STOP

**FOUNDATION-54** complete — **no FOUNDATION-55** opened here.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-54-SECOND-NON-HTTP-CONSUMER-VERIFIER-CHECKPOINT.zip`.
