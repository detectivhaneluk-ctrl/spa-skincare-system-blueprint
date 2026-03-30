# MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01

Status: **CLOSED**

## Scope completed in this wave

Protected tenant runtime paths for **memberships**, **gift cards**, and **packages** were hardened to fail closed using org-owned branch scoping (`OrganizationRepositoryScope` + branch pin) on the operational controllers and services in this wave.

## Runtime contract now enforced

1. Protected reads for definitions, client rows, and gift/package entities used by tenant UI flows go through `*InTenantScope` repository methods (branch pin + resolved-organization EXISTS), not unscoped `find`/`list` by id alone.
2. Writes and state transitions (assign, redeem, use session, refund review reconciliation, membership definition updates, etc.) load rows with `findForUpdateInTenantScope` / `findLockedInTenantScope` or equivalent scoped reads before mutating.
3. Foreign-tenant ids do not resolve under another tenant’s branch context: scoped queries return null / operations throw “not found” or domain errors.
4. Gift card and package cross-tenant redeem/use attempts are denied (verified by smoke).
5. Unresolved organization context on scoped repository methods fails closed via the shared org-scope SQL builder exception path (smoke: gift card list with ambiguous org).
6. Invoice gift-card redemption: invoice must have a positive `branch_id`; balance summary for redemption uses **tenant-scoped** gift card read under **current branch context** (must be set to the invoice branch before call, as sales layer already asserts).

## Files touched (implementation + proof)

Repositories (scoped methods + `OrganizationRepositoryScope` wiring):

- `system/modules/memberships/Repositories/MembershipDefinitionRepository.php`
- `system/modules/memberships/Repositories/ClientMembershipRepository.php`
- `system/modules/memberships/Repositories/MembershipSaleRepository.php`
- `system/modules/memberships/Repositories/MembershipBillingCycleRepository.php`
- `system/modules/gift-cards/repositories/GiftCardRepository.php`
- `system/modules/packages/repositories/PackageRepository.php`
- `system/modules/packages/repositories/ClientPackageRepository.php`

Bootstrap:

- `system/modules/bootstrap/register_memberships_repositories.php`
- `system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php`
- `system/modules/bootstrap/register_gift_cards.php`
- `system/modules/bootstrap/register_packages.php`

Services:

- `system/modules/memberships/Services/MembershipService.php`
- `system/modules/memberships/Services/MembershipLifecycleService.php`
- `system/modules/memberships/Services/MembershipSaleService.php`
- `system/modules/memberships/Services/MembershipRefundReviewService.php`
- `system/modules/gift-cards/services/GiftCardService.php` (including scoped `getBalanceSummary`)
- `system/modules/packages/services/PackageService.php`

Sales coupling (minimal):

- `system/modules/sales/services/InvoiceService.php` (gift redeem: invoice branch required)
- `system/modules/sales/services/PaymentService.php` (gift-card refund: branch-scoped invoice required)

Controllers:

- `system/modules/memberships/controllers/MembershipDefinitionController.php`
- `system/modules/memberships/controllers/ClientMembershipController.php`
- `system/modules/gift-cards/controllers/GiftCardController.php`
- `system/modules/packages/controllers/PackageDefinitionController.php`
- `system/modules/packages/controllers/ClientPackageController.php`

## Intentionally permissive / out-of-band (documented)

- **Cron / system lifecycle** (`MembershipLifecycleService::runExpiryPass`, `syncLifecycleFromCanonicalTruth`): **`runExpiryPass`** uses **`listExpiryTerminalCandidatesForGlobalCron`** + tenant-scoped row lock only (**FND-TNT-14**). **`syncLifecycleFromCanonicalTruth`** may still use repair **`findForUpdate`** when branch context is unset; not protected tenant HTTP runtime.
- **`GiftCardService::listUsableForClient` / `getCurrentBalance`**: list path still mixes `listEligibleForClient` with legacy `find` for presentation resilience; **redemption and invoice settlement** remain strict via scoped locked loads. Further tightening of list/balance helpers is **DEFERRED** to avoid storefront/UX blast radius in this wave.
- **Legacy global rows**: membership definitions and packages with `branch_id` NULL remain supported where the model already allowed org-wide catalog rows; visibility is constrained by organization ownership rules in scoped SQL, not by removing legacy columns.

## Executed runtime proof

### Focused verifier (this wave)

- Script: `system/scripts/smoke_memberships_giftcards_packages_hardening_01.php`
- Runtime: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- Result: **16 passed, 0 failed** (after final `getBalanceSummary` scoping)

Covers: own-tenant scoped reads, foreign id denied for definitions/client memberships/gift cards/packages/client packages, cross-tenant redeem/use denied, in-tenant redeem/use still works, unresolved org on scoped gift list fails closed, regression `TenantBranchAccessService` empty for invalid user.

### Relevant regression verifier

- Script: `system/scripts/smoke_tenant_owned_data_plane_hardening_01.php`
- Runtime: same as above  
- Result: **14 passed, 0 failed**

## Companion matrix

- `system/docs/MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01-MATRIX.md`
