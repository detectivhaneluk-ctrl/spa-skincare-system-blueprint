# ORGANIZATION-SCOPED BRANCH ASSERT — DOWNSTREAM CONSUMER READ-ONLY TRUTH AUDIT (FOUNDATION-74)

**Mode:** Read-only inventory. **No** code, schema, routes, or reopening **FOUNDATION-64** (membership/runtime lane), **FOUNDATION-68** (resolver-only `DomainException` → 403 whitelist), or **FOUNDATION-72** (repository-scope documentation closure) unless contradicted by current tree (**none** found).

**Evidence read:** `OrganizationScopedBranchAssert.php`, `OrganizationContext.php`, `OrganizationContextResolver.php` (baseline throws only), `HttpErrorHandler.php`, `BranchDirectory.php`, `ClientService.php`, `InvoiceService.php`, `PaymentService.php`, `MarketingCampaignService.php`, `PayrollService.php`, `PayrollRuleController.php`, `verify_organization_scoped_choke_points_foundation_11_readonly.php` (reference); grep `assertBranchOwnedByResolvedOrganization` on `system/**/*.php`.

---

## Verdict

**A** — Full **runtime** call-site inventory is **closed** in-tree; **three** `DomainException` messages from the assert/delegate path are **proven** **outside** **F-68**’s whitelist; **one** recommended **documentation-only** follow-up is named (§10).

### Waivers / risks

| ID | Waiver / risk |
|----|----------------|
| **W-74-1** | **`BranchDirectory::updateBranch`** / **`softDeleteBranch`** throw **`InvalidArgumentException`** with **`Branch not found.`** when **`getBranchByIdForAdmin`** returns null (**before** assert). **`OrganizationScopedBranchAssert`** throws **`DomainException`** **`Branch not found.`** when the assert’s `SELECT` finds no row. **Same literal message, different exception types** — log/UX handlers must not assume type from string alone. |
| **W-74-2** | **`InvoiceService::recomputeInvoiceFinancials`** calls **`assertBranchOwnedByResolvedOrganization`** from the **invoice row** **`branch_id`** **without** **`BranchContext::assertBranchMatch`** in that method. Other invoice mutators pair **branch context** match + org assert. **Semantic asymmetry:** org ownership can be enforced while **operator branch context** is not re-checked on this recompute path. |
| **W-74-3** | **`PayrollRuleController::store`** catches **`DomainException`** and maps to form **`_general`** — assert failures surface as **validation-style** feedback, not **`HttpErrorHandler`**, for that action. |
| **W-74-4** | **`MarketingCampaignService::createCampaign`** and **`PayrollRuleController::store`** throw **`DomainException`** with **custom** messages (**`Campaign branch is required…`**, **`Rule branch is required…`**) when org resolved but branch missing — **not** on **F-68** whitelist; same generic **`HttpErrorHandler`** tail as other non-whitelist **`DomainException`**s (unless caught locally). |
| **W-74-5** | **F-64 W-64-4** ( **`OrganizationContext`** PHPDoc vs **F-62** branch alignment) remains **documentation lag** at class level — **not** contradicted or fixed here. |

---

## 1. `OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization` — exact behavior

```26:48:system/core/Organization/OrganizationScopedBranchAssert.php
    public function assertBranchOwnedByResolvedOrganization(?int $branchId): void
    {
        if ($branchId === null || $branchId <= 0) {
            return;
        }
        if ($this->organizationContext->getCurrentOrganizationId() === null) {
            return;
        }

        $row = $this->db->fetchOne(
            'SELECT organization_id FROM branches WHERE id = ? LIMIT 1',
            [$branchId]
        );
        if ($row === null) {
            throw new \DomainException('Branch not found.');
        }
        $orgId = $row['organization_id'] ?? null;
        if ($orgId === null || (int) $orgId <= 0) {
            throw new \DomainException('Branch has no organization assignment.');
        }

        $this->organizationContext->assertBranchBelongsToCurrentOrganization((int) $orgId);
    }
```

- **No-op** when **`$branchId`** null or **≤0**.
- **No-op** when **`OrganizationContext::getCurrentOrganizationId()`** is **`null`** (unresolved org).
- Otherwise: load **`branches.organization_id`** by id (any row, including soft-deleted branch rows — query does **not** filter **`deleted_at`**).
- **Throws `DomainException`** **`Branch not found.`** if no row.
- **Throws `DomainException`** **`Branch has no organization assignment.`** if **`organization_id`** null or **≤0**.
- Else delegates to **`OrganizationContext::assertBranchBelongsToCurrentOrganization`**.

---

## 2. `OrganizationContext::assertBranchBelongsToCurrentOrganization` — exact behavior

```68:78:system/core/Organization/OrganizationContext.php
    public function assertBranchBelongsToCurrentOrganization(?int $branchOrganizationId): void
    {
        if ($this->currentOrganizationId === null) {
            return;
        }
        if ($branchOrganizationId === null) {
            return;
        }
        if ((int) $branchOrganizationId !== (int) $this->currentOrganizationId) {
            throw new \DomainException('Branch does not belong to the resolved organization.');
        }
    }
```

- **No-op** when **`currentOrganizationId`** null.
- **No-op** when **`$branchOrganizationId`** null.
- **Throws `DomainException`** **`Branch does not belong to the resolved organization.`** on mismatch.

---

## 3. `DomainException` messages from these two surfaces (complete)

| Message | Source |
|---------|--------|
| **`Branch not found.`** | **`OrganizationScopedBranchAssert`** |
| **`Branch has no organization assignment.`** | **`OrganizationScopedBranchAssert`** |
| **`Branch does not belong to the resolved organization.`** | **`OrganizationContext`** (via delegate) |

**Not** thrown by these two methods: **F-62** / **F-57** resolver messages (those originate in **`OrganizationContextResolver`** only).

---

## 4. `HttpErrorHandler` **F-68** whitelist vs assert messages

**Whitelist** (`isResolverOrganizationResolutionDomainException`) — ```79:86:system/core/errors/HttpErrorHandler.php```:

- `Branch is not linked to an active organization.`
- `Unable to resolve organization from single active membership.`
- `Current branch organization is not authorized by the user's active organization membership.`
- `Current branch organization is not among the user's active organization memberships.`

**Assert-path messages:** **none** of the **three** in §3 appear in this array.

**Effect (non-debug):** **`DomainException`** from assert/delegate follows ```65:66:system/core/errors/HttpErrorHandler.php``` → **`getStatusCode`** absent → **500** + generic **`SERVER_ERROR`** JSON/HTML family — **not** the **403** **`FORBIDDEN`** branch used for the **four** resolver strings.

**F-68 closure:** Still **truthful** — whitelist remains **resolver-only** by code.

---

## 5. Runtime call-site inventory (grep-complete)

**22** invocations in **16** distinct methods across **7** runtime types (excluding verifier script).

| # | Module / class | Method | Branch-id source | Org gate | Mutating? | Branch derivation | Notes |
|---|----------------|--------|----------------|----------|-----------|-------------------|--------|
| 1 | `BranchDirectory` | `updateBranch` | Parameter **`$id`** (target branch) | Helper internal only | **Update** | **Parameter** (admin edit target) | **`InvalidArgumentException` `Branch not found.`** possible before assert (**W-74-1**). |
| 2 | `BranchDirectory` | `softDeleteBranch` | Parameter **`$id`** | Helper internal only | **Delete** (soft) | **Parameter** | Same **W-74-1**. |
| 3 | `ClientService` | `create` | **`$data['branch_id']`** after **`enforceBranchOnCreate`** | Helper internal only | **Create** | **Mixed** (payload + branch context enforcement) | |
| 4 | `ClientService` | `update` | **`$current['branch_id']`** from **`repo->find`** | Helper internal only | **Update** | **Row-derived** | After **`branchContext->assertBranchMatch`**. |
| 5 | `ClientService` | `delete` | **`$client['branch_id']`** | Helper internal only | **Delete** | **Row-derived** | After **`assertBranchMatch`**. |
| 6 | `ClientService` | `addClientNote` | **`$client['branch_id']`** | Helper internal only | **Create** (note) | **Row-derived** | |
| 7 | `ClientService` | `deleteClientNote` | **`$client['branch_id']`** | Helper internal only | **Delete** (note) | **Row-derived** | |
| 8 | `ClientService` | `mergeClients` | **`$primary` / `$secondary` branch_id** | Helper internal only | **Update** / merge | **Row-derived** | **Two** assert calls. |
| 9 | `ClientService` | `createCustomFieldDefinition` | **`$payload['branch_id']`** after **`enforceBranchOnCreate`** | Helper internal only | **Create** | **Mixed** | |
| 10 | `ClientService` | `updateCustomFieldDefinition` | **`$existing['branch_id']`** | Helper internal only | **Update** | **Row-derived** | |
| 11 | `InvoiceService` | `create` | **`$data['branch_id']`** after **`enforceBranchOnCreate`** | Helper internal only | **Create** | **Mixed** | |
| 12 | `InvoiceService` | `update` | **`$current['branch_id']`** | Helper internal only | **Update** | **Row-derived** | After **`assertBranchMatch`**. |
| 13 | `InvoiceService` | `cancel` | **`$inv['branch_id']`** | Helper internal only | **Update** (status) | **Row-derived** | After **`assertBranchMatch`**. |
| 14 | `InvoiceService` | `delete` | **`$inv['branch_id']`** | Helper internal only | **Delete** | **Row-derived** | After **`assertBranchMatch`**. |
| 15 | `InvoiceService` | `recomputeInvoiceFinancials` | **`$inv['branch_id']`** | Helper internal only | **Update** (financial columns) | **Row-derived** | **No** **`assertBranchMatch`** here (**W-74-2**). |
| 16 | `InvoiceService` | `redeemGiftCardPayment` | **`$invoice['branch_id']`** | Helper internal only | **Mutating** (redemption) | **Row-derived** | After **`assertBranchMatch`**. |
| 17 | `PaymentService` | `create` | **`$inv['branch_id']`** from **`findForUpdate`** | Helper internal only | **Create** (payment) + triggers recompute | **Row-derived** | After **`assertBranchMatch`**. |
| 18 | `PaymentService` | `refund` | **`$invoice['branch_id']`** | Helper internal only | **Create** (refund row) | **Row-derived** | After **`assertBranchMatch`**. |
| 19 | `MarketingCampaignService` | `createCampaign` | **`$payload['branch_id']`** | **Explicit** **`getCurrentOrganizationId() !== null`** before assert | **Create** | **Payload** | Custom **`DomainException`** if branch missing when org resolved (**W-74-4**). |
| 20 | `PayrollService` | `createRun` | Parameter **`$branchId`** | **Explicit** **`getCurrentOrganizationId() !== null`** | **Create** | **Parameter** | After **`assertBranchMatch`**. |
| 21 | `PayrollRuleController` | `store` | **`$data['branch_id']`** from POST (via **`enforceBranchOnCreate`**) | **Explicit** **`getCurrentOrganizationId() !== null`** | **Create** | **Payload** + enforcement | **`DomainException`** caught → form (**W-74-3**); missing branch → custom message (**W-74-4**). |

**Row count:** **22** invocations; **`ClientService::mergeClients`** contributes **two** (primary + secondary **`branch_id`**).

---

## 6. Explicit vs implicit “org resolved” gating

- **Implicit (helper-only):** Most call sites invoke assert **unconditionally**; **`OrganizationScopedBranchAssert`** returns early if **`getCurrentOrganizationId()`** is **`null`**.
- **Explicit (`if (getCurrentOrganizationId() !== null)`):** **`MarketingCampaignService::createCampaign`**, **`PayrollService::createRun`**, **`PayrollRuleController::store`** — redundant with helper for the assert call itself but used to enforce **branch required** or UI flow before assert.

---

## 7. Semantically asymmetric / surprising vs cluster norm

1. **`InvoiceService::recomputeInvoiceFinancials`** — org assert **without** **`BranchContext::assertBranchMatch`** (**W-74-2**).
2. **`BranchDirectory`** — assert on **parameter** branch id for **admin** branch CRUD; other modules mostly **row** or **invoice**-locked branch.
3. **Verifier script** — static string search; does not prove runtime coverage of **`recomputeInvoiceFinancials`** vs **F-11** narrative (out of **F-74** charter to change script).

---

## 8. Follow-up type (question 8)

| Option | Justification |
|--------|----------------|
| **No implementation** | Acceptable **short-term** — behavior is **known** and **consistent** with **F-68** charter. |
| **Narrow error-surface parity** | Would add assert messages (and possibly campaign/rule messages) to **403** classification — **reopens** **F-68**’s “resolver-only” boundary; requires **explicit** new program charter. |
| **Narrow assert-consumer hardening** | **Premature** without product decision on **W-74-2** and **soft-deleted** branch rows in assert `SELECT`. |
| **Documentation-only cleanup** | **Justified** — centralizes **F-68 vs assert** truth for implementers (**recommended**). |

---

## 9. Exactly one recommended next program

**`ORGANIZATION-SCOPED-BRANCH-ASSERT-VS-FOUNDATION-68-HTTP-ERROR-SURFACE-DOCUMENTATION-ONLY`**

- **Deliverable:** One maintainer-facing doc under `system/docs/` that **pins** the §3 messages, **F-68** whitelist, **observed** **500** path, **`PayrollRuleController`** local catch, and **W-74-1** string collision — **no** PHP edits.

**Not** opened as **FOUNDATION-75** in this file.

---

## 10. Verifier / script reference

**`system/scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php`** — greps for **`assertBranchOwnedByResolvedOrganization`**; useful **regression** signal, **not** a substitute for this audit’s per-method semantics (**recompute** path, **explicit** org gates).

---

## 11. STOP

**FOUNDATION-74** closes here. **FOUNDATION-75** is **not** opened by this document.

**Artifacts:** this OPS; **`ORGANIZATION-SCOPED-BRANCH-ASSERT-DOWNSTREAM-CONSUMER-MATRIX-FOUNDATION-74.md`**; **§8** roadmap row; checkpoint ZIP.
