# Settings read scope (canonical inventory)

**Task:** SETTINGS-BRANCH-EFFECTIVE-CALLSITE-SEAL-01  
**Merge rule:** All `SettingsService::get*()` paths use `get()` / `all()` merge: branch `B > 0` overlays global `branch_id = 0`; `null` (or internal `?? 0`) ⇒ **global rows only**.

---

## 1) Intentionally global / operator-context only

| Area | Call pattern | Rationale |
|------|----------------|-----------|
| **Security (session)** | `AuthMiddleware` → `getSecuritySettings(null)` | **A-005:** Matches Settings “Security” section (org default `branch_id = 0` only). Branch-level `security.*` rows are **not** enforcement inputs for timeout / password expiry. |
| **Application timezone / language** | `ApplicationTimezone`, `ApplicationContentLanguage` | Follow `BranchContext` after middleware; guests ⇒ `null` ⇒ global establishment merge. |
| **Settings admin UI** | `SettingsController` | Explicit `settingsContextBranch` / save branch from form + context. |
| **Seeders** | `data/seeders/*` | Bootstrap global defaults (`branch_id = 0`). |

---

## 2) Entity-effective (branch = row/API branch) — verified correct

Reads use the **branch of the business record or public API target**, not the operator’s session when they differ.

| Module / service | Settings APIs | Branch source |
|------------------|---------------|---------------|
| **Appointments** | `getCancellationSettings`, `getAppointmentSettings` | Appointment / payload `branch_id` |
| **Waitlist** | `getWaitlistSettings` | Waitlist row or slot context `branch_id` |
| **Online booking (public)** | `getOnlineBookingSettings`, `getCancellationSettings` | Requested public `branch_id` |
| **Sales** | **A-005:** `getPaymentSettings(null)` + `getHardwareSettings(null)` / `isReceiptPrintingEnabled(null)` for payment **policy** (partial/overpay/default), cash-register requirement, and receipt-print dispatch gate; `getEffectiveReceiptFooterText(invoice branch)` + branch invoice context for footer copy; `getEffectiveCurrencyCode`; **`PaymentMethodService`** allowlist on create | Invoice `branch_id` from **`InvoiceRepository::findForUpdate`** row for methods/register session and receipt dispatch target; payment **policy** keys follow org admin, not hidden branch `payments.*` overrides. |
| **Invoice show** | `getPaymentSettings`, `getEstablishmentSettings` | Invoice `branch_id` |
| **PaymentController** | `getPaymentSettings` | Invoice `branch_id` |
| **Memberships** | `getMembershipSettings`, `membershipTermsDocumentBlock` | Client membership / definition / billing row / issuance branch; **staff assign form** uses list scope in section 3. |
| **Membership lifecycle / billing** | `getMembershipSettings` | `branchIdFromRow` / membership row |
| **Notifications** | `shouldEmitInAppNotificationForType` / `shouldEmitOutboundNotificationForEvent` | **A-005:** toggles from `getNotificationSettings(null)`; notification payload `branch_id` is still stored on rows / audits but does **not** change which toggles apply. |
| **Outbound transactional** | `shouldEmitOutboundNotificationForEvent` | Appointment / waitlist / membership row `branch_id` |
| **Clients (marketing)** | `getMarketingSettings` | `ClientController::marketingSettingsReadBranchId` (client row or context); public resolve / registration use request registration branch |
| **Intake (public token)** | `getIntakeSettings` | Assignment `branch_id` (or null ⇒ global merge) |
| **Public commerce** | `getPublicCommerceSettings`, `getEffectiveCurrencyCode` | Purchase / catalog `branch_id` |
| **Gift cards** | `getEffectiveCurrencyCode` | Issuance / operation `branch_id` column |
| **Client sales profile** | `getEffectiveCurrencyCode` | Invoice branch for summaries |
| **Repair script** | `getEffectiveCurrencyCode` | Per-invoice `branch_id` |

---

## 3) Membership manual assign (`ClientMembershipController` + `MembershipService::assignToClient`)

**Runtime truth (MEMBERSHIP-ASSIGN-BRANCH-CONTEXT-SEAL-01):**

| Operator | List scope (definitions / clients / `getMembershipSettings`) | Issuance `branch_id` on POST |
|----------|--------------------------------------------------------------|------------------------------|
| **Branch context set** | `BranchContext::getCurrentBranchId()` | `enforceBranchOnCreate` forces payload to context; service uses context. |
| **HQ (`context` null)** | `assign_branch_id` query/POST (active branch) if present; else **selected client’s** `branch_id` when re-rendering after POST; else **global-only** definitions (`branch_id IS NULL` only) + all clients (up to provider limit). | **Inferred:** client row `branch_id` when client is branch-scoped; optional `assign_branch_id` must match that branch if both are set. **Rejected:** branch-only `assign_branch_id` with a **global** client (cannot force branch issuance without a branch client). |

**Round-trip:** When lists are pinned by `assign_branch_id`, the assign form posts a hidden `assign_branch_id` so scope survives POST (same merge as GET `?assign_branch_id=`).

**Service authority:** `MembershipService::assignToClientAuthoritative` remains the single issuance rule set (definition branch vs issuance branch, overlaps, billing bootstrap).

---

## 4) VAT runtime scope (table-backed; not `SettingsService`)

**Task seal:** VAT-ADMIN-BRANCH-READ-SEAL-01. VAT is **`vat_rates` only** — no flat settings keys.

**READONLY-DRIFT-VERIFIER-RUNNABILITY-SEAL-01:** Drift SQL in `verify_services_vat_rate_drift_readonly.php` and `verify_service_staff_group_pivot_drift_readonly.php` uses PHP **nowdoc** (`<<<'SQL'` … `SQL;`). A full recursive `php -l` over project `*.php` (excluding `vendor/` and `node_modules/`) completed with **412** files checked and **0** parse errors (PHP 8.3.x, workspace snapshot 2026-03-22). Run from **`system/`**: `php scripts/<verifier>.php` with optional `--json` / `--fail-on-drift` (requires configured DB via bootstrap).

| Concern | Source of truth | Scope rule |
|---------|-----------------|------------|
| **Settings → VAT types UI** (`VatRatesController`) | `VatRateService::listForAdmin(null)` / `create(null, …)` | **Global rows only** (`branch_id IS NULL`). **Edit/update** reject non-global ids (same UX as not found). |
| **Service create/edit VAT dropdown** | `VatRateService::listActive` | `BranchContext::getCurrentBranchId()` when set ⇒ **global ∪ that branch**; HQ (`null`) ⇒ **global only**. Edit form uses `listScope ?? service.branch_id`. |
| **Service create/update (write seal)** | `ServiceService::create` / `update` → `VatRateService::assertActiveVatRateAssignableToServiceBranch` | **Create:** after `enforceBranchOnCreate`, same as before. **Update:** merge current row with payload keys `branch_id` / `vat_rate_id` when present, then assert on **post-merge** branch + VAT (same predicate as `listActive` / `isActiveIdInServiceBranchCatalog`). Null/empty VAT allowed; other-branch / inactive / missing row rejected. **SERVICE-VAT-RATE-UPDATE-SEAL-01** closes the prior update gap. |
| **Drift audit (read-only)** | `system/scripts/verify_services_vat_rate_drift_readonly.php` | Classifies each non-deleted service: `no_vat_assignment`, `ok`, `missing_row`, `inactive`, `wrong_branch`, `non_positive_stored`. Predicate matches `VatRateRepository::isActiveIdInServiceBranchCatalog`. No data changes. Use `--json` / `--fail-on-drift` for automation. |
| **Invoice create/update service lines** | `VatRateService::getRatePercentById` | **`vat_rates.id` only** — resolves `services.vat_rate_id` to `rate_percent`; **no** re-check against invoice `branch_id` (stored line `tax_rate` is authoritative after apply). |
| **VAT distribution report** | `ReportRepository` join | Matches `invoice_items.tax_rate` to `vat_rates.rate_percent` with **`vr.branch_id = invoice.branch_id OR vr.branch_id IS NULL`** (display/label resolution). |
| **Payments / refunds** | — | No VAT catalog reads; amounts follow invoice/payment rows. |

**Legacy / drift classes (planning only):**

| Class | Meaning |
|-------|---------|
| `no_vat_assignment` | `vat_rate_id` IS NULL — valid. |
| `ok` | Positive id, row exists, active, allowed for service `branch_id`. |
| `missing_row` | Positive id, no `vat_rates` row — **invoice** create/update with this service line throws (`getRatePercentById` → null). **Service update** save rejects. |
| `inactive` | Row exists, `is_active ≠ 1` — **invoice** still applies `rate_percent` (`getRatePercentById` uses `find()`, not active filter). **Update** save rejects (write-time requires active). |
| `wrong_branch` | Row exists and active, but not in global∪service-branch catalog — **invoice** still applies `rate_percent`; service **edit** dropdown may not list the id (no `selected` match). After **SERVICE-VAT-RATE-UPDATE-SEAL-01**, **update** save rejects until VAT is changed to an allowed rate. |
| `non_positive_stored` | `vat_rate_id` not NULL but `≤ 0` — app normalizes to “no VAT” on create; DB value is non-canonical. |

**Write-time vs legacy data:** After **SERVICE-VAT-RATE-UPDATE-SEAL-01**, any **save** (create or update) must satisfy the predicate; rows already invalid in the DB are **unchanged** until edited and saved (then update fails until VAT is fixed) or repaired out-of-band. **`verify_services_vat_rate_drift_readonly`** remains the read-only inventory of drift.

**Catalog / broader work:** If the verifier reports problematic rows, plan manual or scripted **data** cleanup before assuming all services obey catalog rules; the verifier does not repair.

**Intentionally global (operator):** Settings VAT admin manages **only** the global slice of `vat_rates`, independent of `BranchContext`.

**Deferred:** Dedicated **branch-scoped VAT admin** (list/create/edit overlay rows per branch) — repository/service already support `branchId !== null` for lists; no HTTP surface yet.

### 4.1 Service ↔ staff groups (HTTP write branch)

**SERVICE-STAFF-GROUP-BRANCH-MERGE-SEAL-01:** **`ServiceService::create`** validates **`staff_group_ids`** after **`enforceBranchOnCreate`** (effective saved branch). **`ServiceService::update`** uses the same **post-merge** row as VAT (**`branch_id`** from payload when present over current) for **`assertIdsAssignableToService`**. **`ServiceController::validate`** applies the same **`branch_id`** overlay on update so form validation matches the service layer.

**SERVICE-BRANCH-MOVE-STAFF-GROUP-SEAL-01:** On **`ServiceService::update`**, when **`branch_id`** is in the payload and **changes** the effective service branch, and **`staff_group_ids` is not** in the payload, existing links are **pruned** to **`filterIdsAssignableToServiceBranch(new branch, current links)`** — non-assignable pivots are **removed in the same write** (global + new-branch groups kept). No block/error; **`service_staff_groups_replaced`** audit when the set changes.

**SERVICE-STAFF-GROUP-PIVOT-DRIFT-AUDIT-01 — read-only verifier:** **`system/scripts/verify_service_staff_group_pivot_drift_readonly.php`** — still useful for **pre-deploy legacy** rows and **direct SQL** drift; normal branch moves via **`ServiceService::update`** now self-heal assignability. Flags: **`--json`**, **`--fail-on-drift`**.

**Drift → runtime (code-proven):** **`ServiceStaffGroupRepository::hasEnforceableStaffGroupLinks`** and **`AvailabilityService::serviceStaffGroupExistsSql`** only count **active, applicable** links for the booking branch. **`listLinkedStaffGroupIds`** is the raw pivot list (admin read-model).

**Catalog / broader work:** Verifier optional for legacy inventory; not required for integrity of HTTP-updated services after the branch-move seal.

---

## 5) CLI / background jobs

Cron and CLI entrypoints (`waitlist_expire_offers.php`, `memberships_*.php`, etc.) load `modules/bootstrap.php`; `BranchContext` is typically unset. Services they call (**WaitlistService**, **MembershipService::dispatchRenewalReminders**, etc.) resolve settings from **each row’s `branch_id`**, not from context — **correct**.

---

## 6) Code fix in prior settings seal (provable)

- **`PaymentService::create` (inner transaction):** **`SETTINGS-BRANCH-EFFECTIVE-CALLSITE-SEAL-01`** — `getPaymentSettings` / `getHardwareSettings` / receipt audit use `branch_id` from the **`findForUpdate` invoice row**.
- **`PAYMENT-LOCKED-INVOICE-BRANCH-CONSISTENCY-SEAL-01`:** The same locked row is loaded **before** any branch-derived check: **`listForPaymentForm`** / **`isAllowedForRecordedInvoicePayment`** run only after **`findForUpdate`**, so there is **no** pre-lock `invoiceRepo->find()` branch snapshot on this path. **Before lock:** only non-branch guards (`validateStatus`, positive finite `amount`). **After lock:** `assertBranchMatch`, payment-method allowlist, partial/overpayment settings, cash/register, currency, receipt audit fields — all use **`$branchId` from `$inv`** (the locked row).
- **`PAYMENT-RECEIPT-DISPATCH-BRANCH-CONSISTENCY-SEAL-01`:** Post-commit receipt enablement and **`dispatchAfterPaymentRecorded($invoiceId, $paymentId, $branchId)`** use **`branch_id`** copied from that same locked-row **`$branchId`** at successful transaction end (`$receiptDispatchBranchId`). **No** post-commit **`invoiceRepo->find()`** for branch selection on **`PaymentService::create`**. The default in-repo dispatch provider is still a no-op; a real driver could load the invoice by id for **content** — branch for **settings** remains the captured value.
- **`RECEIPT-PRINT-DISPATCH-PROVIDER-TRUTH-AUDIT-01`:** **`Core\Contracts\ReceiptPrintDispatchProvider`** has exactly **one** in-tree implementation: **`NoopReceiptPrintDispatchProvider`** (empty body — no DB, no invoice/payment re-read). **`modules/bootstrap.php`** binds the interface **only** to that class. Call sites: **`PaymentService::create`** (post-commit, after **`isReceiptPrintingEnabled`**) and **`InvoiceService::redeemGiftCardPayment`** (post-commit; **`$branchId`** from the **`findForUpdate`** invoice row). Receipt dispatch is therefore **non-operational** in-repo until a deployment swaps the binding; **no** provider-layer branch drift exists today because the default provider ignores all parameters.

---

## 7) Safest next task

Scoped **catalog / retail / mixed checkout / POS** — only in a dedicated follow-up. Optional later: **branch VAT admin HTTP** (reuse `listForAdmin`/`create` with non-null branch), separate task.

**Roadmap vs tree:** **`system/docs/ZIP-TRUTH-RECONCILIATION-SEAL-01.md`** — documents which prior seals are **proven present** in the current PHP tree (timezone, VAT, service staff pivots, payment branch reads, verifier scripts, module count).
