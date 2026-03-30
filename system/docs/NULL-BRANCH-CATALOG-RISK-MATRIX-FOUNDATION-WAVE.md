# NULL-branch catalog risk matrix — FOUNDATION-HARDENING-WAVE

| Module / file | Pattern | Classification | Notes |
|---------------|---------|----------------|-------|
| `modules/memberships/repositories/*.php` | — | **Clean** (scanned) | Verifier passes. |
| `modules/memberships/services/*.php` | — | **Clean** (scanned) | Verifier passes. |
| `modules/packages/repositories/*.php` | — | **Clean** (scanned) | Verifier passes. |
| `modules/packages/services/*.php` | — | **Clean** (scanned) | Verifier passes. |
| `modules/public-commerce/**/*.php` | — | **Clean** (scanned) | Verifier passes. |
| `modules/sales/repositories/VatRateRepository.php` | `branch_id IS NULL OR branch_id = ?` | **ALLOWED-LEGACY-BUT-NEEDS-REDESIGN** | Global VAT row + branch override; allowlisted in `verify_null_branch_catalog_patterns.php`. |
| `modules/sales/repositories/PaymentMethodRepository.php` | `branch_id IS NULL OR branch_id = ?` | **ALLOWED-LEGACY-BUT-NEEDS-REDESIGN** | Global method + branch row; allowlisted in verifier. |
| `modules/inventory/repositories/ProductBrandRepository.php` | `branch_id IS NULL OR branch_id = ?` | **FOLLOW-UP-REPAIR-CANDIDATE** | Not hardened this wave; review catalog semantics. |
| `modules/inventory/repositories/ProductCategoryRepository.php` | `branch_id IS NULL OR branch_id = ?` | **FOLLOW-UP-REPAIR-CANDIDATE** | Not hardened this wave. |
| `modules/staff/repositories/StaffGroupRepository.php` | `branch_id IS NULL OR branch_id = ?` | **FOLLOW-UP-REPAIR-CANDIDATE** | Staff scope, not sellable catalog; separate review. |
| `core/permissions/StaffGroupPermissionRepository.php` | documented in docblock | **FOLLOW-UP-REPAIR-CANDIDATE** | Permission resolution; not scanned by verifier. |
