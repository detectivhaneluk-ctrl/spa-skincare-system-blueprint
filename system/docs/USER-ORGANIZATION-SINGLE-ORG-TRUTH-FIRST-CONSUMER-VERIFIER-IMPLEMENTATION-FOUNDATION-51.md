# USER-ORGANIZATION-SINGLE-ORG-TRUTH — FIRST CONSUMER VERIFIER IMPLEMENTATION (FOUNDATION-51)

**Wave:** FOUNDATION-51 — implements the **exact** first adoption target from **FOUNDATION-50** (read-only verifier extension only).

---

## 1. What changed

| Artifact | Change |
|----------|--------|
| `system/scripts/audit_user_organization_membership_backfill_and_gate.php` | **F-48 checks preserved.** **F-51:** structured `positive_assert_single_membership_truth` report + positive path calling `UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth($sampleUserId)` when applicable. |
| `system/docs/BOOKER-PARITY-MASTER-ROADMAP.md` | One **FOUNDATION-51** row appended. |

**Not changed:** HTTP, middleware, `OrganizationContextResolver`, auth, branch logic, `OrganizationRepositoryScope`, controllers, routes, schema, UI, membership services’ implementation files (reference-only).

---

## 2. Verifier behavior (proof)

### 2.1 Unchanged F-48 coverage

- `information_schema` vs `isMembershipTablePresent()` parity.
- `getUserOrganizationMembershipState(0)` contract when table present/absent.
- Dry-run backfill determinism, bucket sum = scanned, membership row count unchanged.
- **Negative:** `assertSingleActiveMembershipForOrgTruth(0)` must throw `RuntimeException`.
- Sample user (first `users.id` with `deleted_at IS NULL`, `ORDER BY id ASC`): gate state and `organization_ids` vs raw SQL.

### 2.2 FOUNDATION-51 positive path (single new consumer)

**Runs only when:**

1. Membership table is **present**, and  
2. A sample user exists (`id > 0`), and  
3. Gate vs raw SQL contract holds (`$sampleGateMatchesRawSql`), and  
4. `getUserOrganizationMembershipState($sampleUserId)['state'] === STATE_SINGLE`.

**Then:** `assertSingleActiveMembershipForOrgTruth($sampleUserId)` is invoked; the returned **int** must equal `gate['organization_id']`. Mismatch or unexpected throw → **`errors[]`** → exit **1**.

**Read-only:** Still no `UserOrganizationMembershipBackfillService::run(false)`; no INSERT/UPDATE/DELETE.

### 2.3 Honest skip / not-applicable

| Condition | `positive_assert_single_membership_truth.status` |
|-----------|---------------------------------------------------|
| Table absent | `skipped_table_absent` |
| Table present but no non-deleted users | `skipped_no_single_sample_user` (`sample_user_id` null) |
| Table present, sample user exists, but gate state is not `single` (or raw/gate mismatch failed earlier checks) | `skipped_no_single_sample_user` |
| All preconditions + assert matches | `passed` |

**No fake pass:** skipped paths do not add a success error; they emit explicit status in JSON and text.

### 2.4 Output

- **`--json`:** `positive_assert_single_membership_truth` object with `status`, optional `sample_user_id`, `gate_organization_id`, `asserted_organization_id`.
- **Text:** `foundation_wave: FOUNDATION-51` and `positive_assert_single_membership_truth.*` lines (deterministic keys).

---

## 3. Why this is the first adopted consumer (FOUNDATION-50 alignment)

- **FOUNDATION-50** selected this script as the narrowest surface: it already bootstraps the gate, runs membership integrity checks, and stays **off** the HTTP pipeline.
- **`assertSingleActiveMembershipForOrgTruth`** encodes **membership-pivot single-org** truth, which is **not** equivalent to `OrganizationContextResolver` outcomes (**branch-derived** and **single-active-org fallback** do not require membership). A verifier-only call proves the assert API without colliding with resolver semantics.

---

## 4. Why resolver / middleware / F-25 / controllers / services were untouched

- **Resolver / middleware:** Would redefine global org context for every request; `assert*` would invalidate branch-first and legacy single-org deployments that lack exactly one membership row.
- **F-25 gate:** Uses `countActiveOrganizations()` and resolved context id — not membership-strict single truth.
- **Controllers / domain services:** Wide blast radius; first adoption belongs in **operator-verified** CLI, not product traffic.

---

## 5. Application behavior

**Unchanged for web/runtime:** Only `audit_user_organization_membership_backfill_and_gate.php` and docs were modified. No autoloaded code path invokes this script unless an operator runs it from `system/`.

---

## 6. Usage

```text
php scripts/audit_user_organization_membership_backfill_and_gate.php
php scripts/audit_user_organization_membership_backfill_and_gate.php --json
```

---

## 7. STOP

**FOUNDATION-51** complete — **no FOUNDATION-52** opened here.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-51-SINGLE-ORG-TRUTH-VERIFIER-CONSUMER-CHECKPOINT.zip` (handoff `build-final-zip.ps1` hygiene).
