# Client domain — backend contract freeze (UI readiness)

**Frozen for:** screenshot-based Client UI alignment (no visual redesign in this phase).  
**Authority:** runtime behavior of `modules/clients` + `OrganizationRepositoryScope` + `BranchContext` as implemented at freeze time.

---

## 1. Authoritative read models by surface

All staff client reads require **branch-derived organization context** (`OrganizationContext::MODE_BRANCH_DERIVED`) where the repository uses `OrganizationRepositoryScope` tenant fragments; otherwise access is **fail-closed** (`AccessDeniedException` / HTTP 403 patterns).

### 1.1 Index / list (`ClientRepository::list`, `count`)

- **Row shape:** full `clients` row (`SELECT *` alias `c`), `deleted_at IS NULL`, **`merged_into_client_id IS NULL`** (canonical live rows only). Merge secondaries are normally soft-deleted as well; the merged-into predicate prevents corrupt non-deleted merge rows from appearing in staff lists.
- **Org gate:** `clientProfileOrgMembershipExistsClause('c')` — client must be tenant-visible (branch in org, or `branch_id` NULL with org anchor via appointment/invoice per fragment).
- **Branch envelope (staff):** when a concrete branch applies — **either** `filters['branch_id']` (positive int) **or** `BranchContext::getCurrentBranchId()` — append  
  `(c.branch_id IS NULL OR c.branch_id = ?)`.  
  When **no** such branch (HQ / unset): **no** extra row predicate (org-wide list within membership clause).
- **Search:** optional `filters['search']` — LIKE on names/emails/phones + fast path for email equality and digit-normalized phone on `phone`, `phone_home`, `phone_mobile`, `phone_work`.
- **UI augmentation (list):** controller may set `display_name`, `display_phone` (see §3).

### 1.2 Profile / résumé (controller `show`, `edit`, shell)

- **Primary load:** `ClientRepository::find($id)` + same org-membership fragment as above; **no** `(NULL OR branch)` list envelope on `find()`.
- **Branch access:** `BranchContext::assertBranchMatchOrGlobalEntity($entityBranchId)` on the client row (`ensureBranchAccess`).
- **Merged clients:** `merged_into_client_id` may be set; UI shows redirect/shell state from row.
- **Duplicates on profile:** `ClientService::findDuplicates` / repository `searchDuplicates` / `countSearchDuplicates` use the same **live-row** contract as list (`merged_into_client_id IS NULL`, `deleted_at IS NULL`) plus org-membership, staff branch envelope when applicable, and digit-normalized phone (or email) matching — aligned with `findLiveReadableForProfile` for merged-vs-live semantics.

### 1.3 Provider / search selectors (`ClientListProvider` → `ClientRepository::list`)

- Same as §1.1. Callers often pass `['branch_id' => $branchId]`; envelope becomes `(NULL OR that branch)`, not exact `branch_id = ?` only.

### 1.4 Satellite profile providers (`ClientProfileAccessService`)

- **Path:** `ClientRepository::findLiveReadableForProfile($clientId, $currentBranchId)`.
- **Constraints:** `deleted_at IS NULL`, `merged_into_client_id IS NULL`, org-membership fragment.
- **Branch:** if `$currentBranchId > 0`: `(c.branch_id IS NULL OR c.branch_id = ?)`; if unset/`≤0`: same org envelope as `find()` (no extra branch row predicate).

### 1.5 Merge preview / merge (`ClientService::getMergePreview`, `mergeClients`)

- **Rows:** `find($primaryId)`, `find($secondaryId)` (preview); `findForUpdate` both inside merge transaction.
- **Linked counts / remap:** `countLinkedRecords`, `remapClientReferences` after tenant-scoped existence checks (`assertClientExistsInTenantScope` uses org-membership clause).
- **`client_field_values`:** not remapped in `remapClientReferences`; merged via `mergeCustomFieldValues` (fill-empty rules, then delete secondary values).

### 1.6 Registration requests (`ClientRegistrationRequestRepository`)

- **Scope:** `OrganizationRepositoryScope::clientRegistrationRequestTenantExistsClause('r')` on **find / list / count / update**:
  - `branch_id` NOT NULL → branch row must belong to resolved org; **or**
  - `branch_id` NULL → only if `linked_client_id` set **and** that client passes `clientProfileOrgMembershipExistsClause`.
- **Invisible:** `branch_id` NULL **and** `linked_client_id` NULL — no org anchor (intentional fail-closed).
- **Services:** `ClientRegistrationService` uses `TenantOwnedDataScopeGuard::requireResolvedTenantScope()` + `OrganizationScopedBranchAssert` on branch-bearing paths.

### 1.7 Issue flags (`ClientIssueFlagRepository`)

- **Scope:** `clientIssueFlagTenantJoinSql` — every **find / listByClient / update** joins `clients` with the same org-membership proof as profile reads.
- **Services:** `ClientIssueFlagService` tenant guard + branch assert aligned with client row.

### 1.8 Custom fields

- **Definitions:** `ClientFieldDefinitionRepository::list/find/update/softDelete` use `clientFieldDefinitionTenantBranchClause` — only definitions whose `branch_id` is a **non-null** branch in the resolved org. **`branch_id` NULL definitions are excluded** (schema has no org FK on definition; fail-closed).
- **Values:** `ClientFieldValueRepository::listByClientId` joins definitions through the same tenant clause; **upsert** ignores unknown/out-of-scope definition ids (no throw).
- **Staff forms:** definition set resolved from **client’s `branch_id`** if positive, else **current** `BranchContext` branch, else all definitions in org (`list(null, …)`).

### 1.9 Page layouts (`ClientPageLayoutService`)

- Org-scoped layout profiles/items; custom field keys in layouts validated via `ClientFieldDefinitionRepository::find` (tenant-scoped).

---

## 2. Canonical write payload (create / update)

**Single staff contract:** associative array produced by `ClientController::parseInput(?array $current)` and consumed by `ClientService::create` / `update` (after unsetting `custom_fields` for service layer).

**Core keys (string unless noted):**

`first_name`, `last_name` (required strings), `email`, `phone_home`, `phone_mobile`, `mobile_operator`, `phone_work`, `phone_work_ext`, `home_address_1`, `home_address_2`, `home_city`, `home_postal_code`, `home_country`, `delivery_same_as_home` (0|1), `delivery_address_1`, `delivery_address_2`, `delivery_city`, `delivery_postal_code`, `delivery_country`, `birth_date`, `anniversary`, `gender`, `occupation`, `language`, `preferred_contact_method`, `marketing_opt_in`, `receive_emails`, `receive_sms`, `booking_alert`, `check_in_alert`, `check_out_alert`, `referral_information`, `referral_history`, `referred_by`, `customer_origin`, `emergency_contact_name`, `emergency_contact_phone`, `emergency_contact_relationship`, `inactive_flag` (0|1), `notes`, **`custom_fields`** (array: `field_definition_id => scalar`). The read-only script `system/scripts/read-only/verify_clients_domain_contract_freeze_truth_audit_01.php` asserts this key set against `ClientController::parseInput()`.

**Semantics:**

- **Create:** all keys from POST; empty optional strings → `null`.
- **Update:** omitted POST keys keep DB value; present empty clears nullable fields (`$sKeep` / `$cb` rules).

**Persistence:** `ClientService::finalizeContactAddressForPersistence` runs before insert/update; **`clients.phone`** is set from **`ClientCanonicalPhone::resolvePrimaryForPersistence`** (see §3).

**Validation:** `ClientInputValidator::validate($data, $activeDefinitions)` — server-side email, dates, gender enum, phone length/digits, required custom fields, custom field type checks.

**Not in POST payload:** `branch_id` on update is immutable when branch-scoped (enforced in `BranchContext`); create uses `enforceBranchOnCreate`.

---

## 3. Canonical phone and `display_phone`

| Concern | Rule |
|--------|------|
| **Stored primary (`clients.phone`)** | After merge of contact fields: first non-empty of **`phone_mobile` → `phone_home` → `phone_work` →** incoming **`phone`** → else existing row columns in that order (`ClientCanonicalPhone::resolvePrimaryForPersistence`). |
| **Display / single-string match** | Same order via `ClientCanonicalPhone::displayPrimary($row)` / `ClientService::getCanonicalPrimaryPhone($row)`. |
| **List UI** | Controller sets `display_phone` from service; view may fall back to legacy column order if missing. |
| **Duplicate detection** | Digit-normalized comparison on `phone`, `phone_home`, `phone_mobile`, `phone_work` (`PublicContactNormalizer` + SQL expr); email unchanged; short/non-digit search may use raw LIKE fallback. |

---

## 4. Visibility rule (branch-scoped vs org-anchored branchless)

| Context | Client row `branch_id` | Visible in staff list/count/duplicate search? |
|--------|----------------------|-----------------------------------------------|
| **HQ / no current branch** | any (within org membership) | Yes, if org membership clause passes |
| **Current branch B > 0** | `NULL` (org-anchored) | **Yes** (`NULL OR B`) |
| **Current branch B > 0** | `B` | **Yes** |
| **Current branch B > 0** | other branch `A ≠ B` | **No** (excluded by envelope) |

**Profile page `find()`:** org membership only **no** `(NULL OR B)` — **but** `ensureBranchAccess` denies cross-branch non-global rows when session branch is set.

**Providers:** `findLiveReadableForProfile` matches the same **`(NULL OR B)`** rule as list when branch is set.

---

## 5. Intentional exclusions / fail-closed

- **Tenant scope unresolved** (`TenantOwnedDataScopeGuard`): mutations / guarded reads throw or 403.
- **Registration requests:** orphan `branch_id` + `linked_client_id` both NULL — **never** returned or updated via repository scope.
- **Custom field definitions** with `branch_id` NULL — **never** listed or mutated through tenant repository (data must be assigned a branch or schema extended with org ownership).
- **Custom field upsert** for alien definition id — **silent no-op** (defensive).
- **Merge:** `client_field_values` remapped only through merge service logic, not blind `UPDATE client_id`.
- **Merge dedupe:** `client_consents` / `marketing_contact_list_members` — DELETE secondary rows that would violate UNIQUE before UPDATE (destructive to redundant secondary rows).
- **Public / anonymous paths:** `lockActiveByEmailBranch`, `lockActiveByPhoneDigitsBranch`, `findActiveClientIdByPhoneDigitsExcluding` — **positive branch pin** + **`publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause`** (live branch/org; no session org) — **FND-TNT-16** / **FND-TNT-17** / **CLOSURE-10**–**11**.

---

## 6. Proof scripts (regression)

- `system/scripts/read-only/verify_clients_domain_wave_01.php`
- `system/scripts/read-only/verify_clients_domain_wave_01_contracts.php`
- `system/scripts/read-only/verify_clients_domain_contract_freeze_truth_audit_01.php` (contract vs code: merged live surfaces, registration/field/issue-flag shapes, parseInput keys, canonical phone samples, merge path strings)
- `system/scripts/read-only/verify_clients_domain_wave_01_db_audit.php` (optional DB; `.env.local`)

---

## 7. Related code map

| Topic | Primary types |
|-------|----------------|
| Client rows | `Modules\Clients\Repositories\ClientRepository` |
| Org SQL fragments | `Core\Organization\OrganizationRepositoryScope` |
| Provider read | `Modules\Clients\Services\ClientProfileAccessService` |
| List provider | `Modules\Clients\Providers\ClientListProviderImpl` |
| Writes / merge | `Modules\Clients\Services\ClientService` |
| HTTP entry | `Modules\Clients\Controllers\ClientController` |
| Phone | `Modules\Clients\Support\ClientCanonicalPhone`, `PublicContactNormalizer` |
| Validation | `Modules\Clients\Services\ClientInputValidator` |
| Registrations | `ClientRegistrationRequestRepository`, `ClientRegistrationService` |
| Flags | `ClientIssueFlagRepository`, `ClientIssueFlagService` |
| Custom fields | `ClientFieldDefinitionRepository`, `ClientFieldValueRepository` |
