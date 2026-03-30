# USER-ORGANIZATION-SINGLE-ORG-TRUTH — NEXT NON-HTTP CONSUMER SELECTION TRUTH AUDIT (FOUNDATION-53)

**Mode:** Read-only selection audit after **FOUNDATION-52**. **No** code, schema, routes, middleware, resolver, auth, branch, repository-scope, HTTP, or UI changes.

**Evidence read:** `system/scripts/**/*.php` grep for `UserOrganizationMembershipStrictGateService`, `assertSingleActiveMembershipForOrgTruth`, `UserOrganizationMembership*`, `OrganizationContext`; full read of `audit_user_organization_membership_context_resolution.php`, `backfill_user_organization_memberships.php`; `OrganizationContextResolver.php`, `StaffMultiOrgOrganizationResolutionGate.php` (compatibility only); roadmap F-48–F-52 rows.

---

## 1. FOUNDATION-51 / 52 closed only the verifier-first-consumer sublayer

**Proof:** In-tree PHP references to **`assertSingleActiveMembershipForOrgTruth`** are **only**:

- The **definition** in `UserOrganizationMembershipStrictGateService.php`.
- **Calls** inside `audit_user_organization_membership_backfill_and_gate.php` (negative `assert(0)` + conditional positive path).

No other `system/**/*.php` file invokes **`assert*`** (repo grep). **FOUNDATION-52** documented that F-51 did not add resolver/F-25/mutation paths.

**Conclusion:** The **closed** sublayer is **“first consumer = F-48/F-51 combined verifier”** only. **No** second non-HTTP consumer of **`assert*`** exists in-tree today.

---

## 2. Remaining non-HTTP / operational surfaces relevant to membership org truth (today)

| Surface | Role today | Uses `UserOrganizationMembershipStrictGateService` / `assert*`? |
|---------|------------|------------------------------------------------------------------|
| `audit_user_organization_membership_backfill_and_gate.php` | Gate, dry-run backfill, assert (F-51) | **Yes** |
| `audit_user_organization_membership_context_resolution.php` | F-46: `UserOrganizationMembershipReadService` vs raw SQL; reflection on `OrganizationContext` / `OrganizationContextResolver` ctor | **No** |
| `backfill_user_organization_memberships.php` | Operational **`UserOrganizationMembershipBackfillService::run($dryRun)`** | **No** |
| `verify_organization_registry_schema.php` | DDL presence for **`user_organization_memberships`** | **No** |
| Other `system/scripts/*` | Strings mentioning `OrganizationContext` in **read-only verify** scripts (source text checks) | **No** runtime membership strict gate |

**Direct in-tree consumers of “organization truth”** (HTTP and non-HTTP) outside scripts remain resolver / `OrganizationContext` / F-25 / repos — **compatibility analysis only:** `OrganizationContextResolver` still uses **`UserOrganizationMembershipReadService`**, not **`UserOrganizationMembershipStrictGateService`** (```49:63:system/core/Organization/OrganizationContextResolver.php```). **`StaffMultiOrgOrganizationResolutionGate`** uses **`OrganizationContext`** + **`countActiveOrganizations()`**, not **`assert*`** (```36:43:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php```).

---

## 3. Candidates grouped by risk (verifier-like vs runtime-coupled)

| Tier | Candidate | Risk rationale |
|------|-----------|----------------|
| **Low (verifier-like)** | **Extend `audit_user_organization_membership_context_resolution.php`** with optional gate state + conditional **`assert*`** (same read-only bootstrap pattern as F-51) | No request path; no writes; composes **F-46 read contract** with **F-48 assert** |
| **Low–medium** | **New** dedicated read-only script (e.g. multi-user `STATE_SINGLE` sweep) | Still CLI-only; adds artifact/process surface; not in tree yet |
| **Medium** | Extend **`backfill_user_organization_memberships.php`** with pre/post assert reporting | **Couples** mutation CLI with strict assert semantics; operator confusion; harder to guarantee “read-only verification only” on every invocation |
| **High** | Cron / recurring scripts under `system/scripts` (e.g. `memberships_*.php`) | **No** current strict-gate usage; adding **`assert*`** would tie batch jobs to membership-single semantics without a product decision |
| **Excluded (out of scope for “next non-HTTP”)** | Resolver, middleware, controllers, domain services | Per F-50 / F-52 — **`assert*`** ≠ **`OrganizationContext`** resolution |

---

## 4. Single recommended next adoption target (exactly one)

**Recommended next non-HTTP consumer (implementation target for a future wave, not done in F-53):**  
**`system/scripts/audit_user_organization_membership_context_resolution.php`**

**Shape (prescriptive boundary for the implementation wave):** After existing F-46 checks pass for the **same** first sample user (`SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1` — ```67:68:system/scripts/audit_user_organization_membership_context_resolution.php```), resolve **`UserOrganizationMembershipStrictGateService`** from the container; when membership table present and gate state is **`single`** and read-service vs raw SQL already aligned, call **`assertSingleActiveMembershipForOrgTruth($sampleUserId)`** and require return equals **`gate['organization_id']`**; otherwise emit explicit **`skipped_*`** (table absent, no user, not single, mismatch) — **mirror F-51 honesty**.

**Why this is the single safest next choice**

- **Existing** operator entrypoint and **read-only** contract; no new mutation CLI.
- **Compositional proof:** links the **resolver’s membership read dependency** (`UserOrganizationMembershipReadService`, verified here) to the **strict membership-single assert** without editing **`OrganizationContextResolver`**.
- **Lower risk than** backfill CLI or cron: **zero** INSERT path in this script today (```1:19:system/scripts/audit_user_organization_membership_context_resolution.php``` documents read-only verifier).

---

## 5. Why resolver / middleware / controller / service runtime adoption is still not the next step

- **`OrganizationContextResolver`** intentionally resolves org from **branch** and **legacy single-org count** without requiring **`assert*`** (F-50 / F-52). Injecting **`assert*`** into resolver or middleware would **break** those valid code paths.
- **`StaffMultiOrgOrganizationResolutionGate`** only needs a **non-null** context org under multi-org deployments — not membership-strict single truth.
- **Controllers / domain services** widen blast radius to product traffic; F-51 deliberately chose **verifier-first** adoption.

**Compatibility (unchanged in F-53):** Resolver and F-25 files in scope contain **no** references to **`UserOrganizationMembershipStrictGateService`**.

---

## 6. Exact implementation boundary for the next wave

1. **Touch only** `audit_user_organization_membership_context_resolution.php` (+ ops doc / roadmap row for that wave) unless product explicitly chooses the “new script” alternative in a separate decision.
2. **Read-only:** no **`UserOrganizationMembershipBackfillService::run(false)`**; no schema/route/middleware/resolver/auth/branch/repo-scope/controller/UI edits.
3. **Honest skips:** same discipline as F-51 — **no** fake pass when assert not applicable.
4. **Preserve** all existing F-46 assertions (reflection, user 0 reads, table-absent safety, raw SQL parity).

---

## 7. “One more read-only consumer” vs “no safe consumer yet”

**Answer:** **One more read-only consumer is safe and appropriate** — specifically **extending `audit_user_organization_membership_context_resolution.php`**.

**Not** “no safe consumer yet”: the next step stays **CLI verifier** tier; there is **no** requirement to jump to HTTP/runtime services.

**Optional later wave (not the single F-53 pick):** a **new** read-only script scanning **all** users in **`STATE_SINGLE`** to address **F-52 R-52-2** (first-sample-only coverage) — higher value for coverage, **higher** process surface (new file). F-53 ranks it **second** behind the F-46 verifier extension.

---

## 8. Waivers / risks (explicit)

| Id | Waiver / risk |
|----|----------------|
| **W-53-1** | **Overlapping first-sample discipline:** Until a multi-user sweep exists, the F-46 extension may **duplicate** the same first-user positive assert already exercised in **`audit_user_organization_membership_backfill_and_gate.php`** — value is **cross-verifier attestation** (context/resolver-adjacent vs backfill/gate), not new row coverage. |
| **W-53-2** | **Script responsibility:** `audit_user_organization_membership_context_resolution.php` gains **two** concerns (F-46 read + F-51-style assert). Mitigation: tight sectioning + structured JSON fields. |
| **W-53-3** | **Pre-existing doc drift:** `verify_organization_context_resolution_readonly.php` still embeds stale narrative about membership (not edited in F-53). |
| **W-53-4** | **Program gap unchanged:** HTTP **`OrganizationContext`** and F-25 still **do not** use **`assert*`** — intentional; closing that gap is **not** the next non-HTTP consumer wave. |

---

## 9. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | A single defensible next target with **no** material caveats. |
| **B** | Target defensible; **documented** caveats (overlap, script scope). |
| **C** | No safe next consumer. |

**FOUNDATION-53 verdict: B**

**Rationale:** **`audit_user_organization_membership_context_resolution.php`** is the **lowest-risk next** surface among in-tree options; **W-53-1–W-53-4** record overlap and scope trade-offs honestly.

---

## 10. STOP

**FOUNDATION-53** ends here — **no FOUNDATION-54** opened by this audit (implementation of the chosen extension is **explicitly future work**).

**Companion:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-NEXT-NON-HTTP-CONSUMER-SURFACE-MATRIX-FOUNDATION-53.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-53-NEXT-NON-HTTP-CONSUMER-SELECTION-CHECKPOINT.zip`.
