# ORGANIZATION-SCOPED BRANCH ASSERT — DOWNSTREAM CONSUMER MATRIX (FOUNDATION-74)

**Companion:** `ORGANIZATION-SCOPED-BRANCH-ASSERT-DOWNSTREAM-CONSUMER-READ-ONLY-TRUTH-AUDIT-FOUNDATION-74-OPS.md`

---

## A. Delegate chain

```
OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization(?int $branchId)
  → [optional] DomainException: Branch not found. | Branch has no organization assignment.
  → OrganizationContext::assertBranchBelongsToCurrentOrganization(int $branchOrganizationId)
       → [optional] DomainException: Branch does not belong to the resolved organization.
```

**Early exits:** null/≤0 **`$branchId`**; **`getCurrentOrganizationId() === null`**.

---

## B. `DomainException` messages × **F-68** whitelist

| Message | F-68 403 whitelist? |
|---------|---------------------|
| `Branch not found.` | **No** |
| `Branch has no organization assignment.` | **No** |
| `Branch does not belong to the resolved organization.` | **No** |

**F-68 list** (resolver only): `HttpErrorHandler` ```79:86:system/core/errors/HttpErrorHandler.php```.

---

## C. Runtime call sites (**22** invocations)

| # | Class | Method | Line (approx) | Branch-id source | Call-site org guard | Mutating | Row / payload / param | Paired `BranchContext::assertBranchMatch` |
|---|-------|--------|---------------|------------------|---------------------|----------|------------------------|-------------------------------------------|
| 1 | `Core\Branch\BranchDirectory` | `updateBranch` | 171 | **`$id`** | None (helper) | Yes | **Parameter** | No |
| 2 | `Core\Branch\BranchDirectory` | `softDeleteBranch` | 207 | **`$id`** | None (helper) | Yes | **Parameter** | No |
| 3 | `Modules\Clients\Services\ClientService` | `create` | 40–43 | **`$data['branch_id']`** post-**`enforceBranchOnCreate`** | None (helper) | Yes | **Mixed** | N/A (enforce on create) |
| 4 | `Modules\Clients\Services\ClientService` | `update` | 67–68 | **`$current['branch_id']`** | None (helper) | Yes | **Row** | **Yes** |
| 5 | `Modules\Clients\Services\ClientService` | `delete` | 91–92 | **`$client['branch_id']`** | None (helper) | Yes | **Row** | **Yes** |
| 6 | `Modules\Clients\Services\ClientService` | `addClientNote` | 120–121 | **`$client['branch_id']`** | None (helper) | Yes | **Row** | **Yes** |
| 7 | `Modules\Clients\Services\ClientService` | `deleteClientNote` | 143–144 | **`$client['branch_id']`** | None (helper) | Yes | **Row** | **Yes** |
| 8a | `Modules\Clients\Services\ClientService` | `mergeClients` | 207–208 | **`$primary['branch_id']`** | None (helper) | Yes | **Row** | **Yes** (primary) |
| 8b | `Modules\Clients\Services\ClientService` | `mergeClients` | 210–211 | **`$secondary['branch_id']`** | None (helper) | Yes | **Row** | **Yes** (secondary) |
| 9 | `Modules\Clients\Services\ClientService` | `createCustomFieldDefinition` | 287–290 | **`$payload['branch_id']`** post-**`enforceBranchOnCreate`** | None (helper) | Yes | **Mixed** | N/A |
| 10 | `Modules\Clients\Services\ClientService` | `updateCustomFieldDefinition` | 308–309 | **`$existing['branch_id']`** | None (helper) | Yes | **Row** | **Yes** |
| 11 | `Modules\Sales\Services\InvoiceService` | `create` | 79–80 | **`$data['branch_id']`** post-**`enforceBranchOnCreate`** | None (helper) | Yes | **Mixed** | N/A |
| 12 | `Modules\Sales\Services\InvoiceService` | `update` | 122–123 | **`$current['branch_id']`** | None (helper) | Yes | **Row** | **Yes** |
| 13 | `Modules\Sales\Services\InvoiceService` | `cancel` | 180–181 | **`$inv['branch_id']`** | None (helper) | Yes | **Row** | **Yes** |
| 14 | `Modules\Sales\Services\InvoiceService` | `delete` | 207–208 | **`$inv['branch_id']`** | None (helper) | Yes | **Row** | **Yes** |
| 15 | `Modules\Sales\Services\InvoiceService` | `recomputeInvoiceFinancials` | 250–251 | **`$inv['branch_id']`** | None (helper) | Yes | **Row** | **No** ⚠️ |
| 16 | `Modules\Sales\Services\InvoiceService` | `redeemGiftCardPayment` | 338–339 | **`$invoice['branch_id']`** | None (helper) | Yes | **Row** | **Yes** |
| 17 | `Modules\Sales\Services\PaymentService` | `create` | 84 | **`$inv` → `branch_id`** | None (helper) | Yes | **Row** (locked invoice) | **Yes** |
| 18 | `Modules\Sales\Services\PaymentService` | `refund` | 201 | **`$invoice` → `branch_id`** | None (helper) | Yes | **Row** | **Yes** |
| 19 | `Modules\Marketing\Services\MarketingCampaignService` | `createCampaign` | 75 | **`$payload['branch_id']`** | **Explicit** `getCurrentOrganizationId() !== null` | Yes | **Payload** | N/A |
| 20 | `Modules\Payroll\Services\PayrollService` | `createRun` | 40 | **`$branchId`** arg | **Explicit** `getCurrentOrganizationId() !== null` | Yes | **Parameter** | **Yes** |
| 22 | `Modules\Payroll\Controllers\PayrollRuleController` | `store` | 68 | **`$bid`** from **`$data`** | **Explicit** `getCurrentOrganizationId() !== null` | Yes | **Payload** | N/A (enforceBranchOnCreate) |

⚠️ **Asymmetry:** see **W-74-2** in OPS.

---

## D. Related `DomainException` messages (not from assert helper)

| Location | Message | F-68? |
|----------|---------|-------|
| `MarketingCampaignService::createCampaign` | `Campaign branch is required when organization context is resolved.` | **No** |
| `PayrollRuleController::store` | `Rule branch is required when organization context is resolved.` | **No** |

---

## E. Baseline: `OrganizationContextResolver` (F-68 covered)

Throws **only** the **four** whitelisted strings (see **F-68 OPS**). **Not** part of assert delegate chain.

---

## F. Script reference

| Script | Role |
|--------|------|
| `verify_organization_scoped_choke_points_foundation_11_readonly.php` | String grep for **`assertBranchOwnedByResolvedOrganization`** |

---

## G. STOP

**FOUNDATION-75** not opened here.
