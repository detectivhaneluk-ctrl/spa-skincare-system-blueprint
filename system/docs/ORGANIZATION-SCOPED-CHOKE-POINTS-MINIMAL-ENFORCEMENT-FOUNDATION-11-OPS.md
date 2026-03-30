# Organization-scoped choke points — minimal enforcement (FOUNDATION-11)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-11 — ORGANIZATION-SCOPED-CHOKE-POINTS-MINIMAL-ENFORCEMENT  
**Source of truth:** FOUNDATION-06 through FOUNDATION-10 (especially FOUNDATION-09 `OrganizationContext`, FOUNDATION-10 perimeter audit).  
**Scope:** Minimal runtime assertions only — no repository-wide query scoping, no public booking/commerce/intake changes, no subscriptions/packages/storage/RBAC/UI.

---

## What was added

| Piece | Role |
|--------|------|
| `Core\Organization\OrganizationScopedBranchAssert` | Canonical helper: when `OrganizationContext::getCurrentOrganizationId()` is non-null, loads `branches.organization_id` for a given `branches.id` and calls `OrganizationContext::assertBranchBelongsToCurrentOrganization`. No request-derived org ids. |
| `Core\Branch\BranchDirectory` | `createBranch` sets `organization_id` to the **resolved** org when context is non-null; otherwise keeps `defaultOrganizationIdForNewBranch()`. `updateBranch` / `softDeleteBranch` call the helper for the target branch id. |
| `Modules\Branches\Controllers\BranchAdminController` | Catches `DomainException` (in addition to `InvalidArgumentException`) so org/branch guard failures surface as flash errors. |
| `Modules\Sales\Services\InvoiceService` | After existing `BranchContext` checks on id-loaded / create paths, asserts invoice `branch_id` ownership vs resolved org (`create`, `update`, `cancel`, `delete`, `recomputeInvoiceFinancials`, `redeemGiftCardPayment`). |
| `Modules\Sales\Services\PaymentService` | Same pattern on `create` and `refund` after invoice load + branch match. |
| `Modules\Clients\Services\ClientService` | Same pattern on all mutating/id-loaded paths that already used `BranchContext` (clients, notes, merge, custom field definitions). |

Dependency injection: `OrganizationScopedBranchAssert` is registered in `system/bootstrap.php`; `BranchDirectory`, `InvoiceService`, `PaymentService`, and `ClientService` wiring updated in bootstrap/register files.

---

## Behavior summary

- **Resolved org (non-null):** Mutations that target a concrete `branch_id` must belong to that organization or the operation fails closed with `DomainException`. Missing branch row when org is resolved also fails closed (`Branch not found.`).
- **Unresolved org (null):** Helper is a no-op — same as FOUNDATION-09/10 policy for ambiguous multi-org without branch; no new session/org picker.
- **Null / omitted `branch_id` on entity:** Helper no-ops (unchanged global/null-branch semantics where already allowed by `BranchContext`).
- **Single-org deployments:** With one active org, middleware resolves org id; valid same-tenant requests behave as before; cross-tenant branch ids (if ever addressable) are rejected when org resolves.

---

## Proof commands (read-only)

From `system/`:

```bash
php scripts/verify_organization_context_resolution_readonly.php
php scripts/verify_organization_branch_ownership_readonly.php
php scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php
```

The F-11 script inventories the **exact** service methods expected to contain `assertBranchOwnedByResolvedOrganization` (explicit map in script).

---

## Explicitly out of scope (this wave)

- Public API mutating routes, subscriptions, packages, storage partitioning, RBAC rewrite, settings rewrite, UI.
- Repository-wide `WHERE` org predicates (future waves).
- Fail-closed policy for **all** staff mutates when `OrganizationContext` is null (deferred; see FOUNDATION-10).

---

## Checkpoint ZIP

Full-project audit ZIPs for this wave should exclude secrets, logs, backups, loose `*.log` files, and nested `*.zip` per project packaging rules.
