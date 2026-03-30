# Public booking — cancellation reasons key hotfix 01

**Task ID:** `PUBLIC-BOOKING-CANCELLATION-REASONS-KEY-HOTFIX-01`  
**Date:** 2026-03-25  
**Scope:** Backend correctness only (no UI copy changes).

## Contract (canonical)

| Concern | Source | Keys / shape |
|--------|--------|----------------|
| Runtime enforcement (cancel allowed, min notice, **effective** reason requirement, override) | `SettingsService::getCancellationRuntimeEnforcement($branchId)` | `cancellation_allowed`, `min_notice_hours`, `reason_effectively_required_for_cancellation`, `allow_privileged_override`, … — **does not** expose `reasons_enabled` |
| Stored cancellation policy (including whether reasons feature is on) | `SettingsService::getCancellationPolicySettings($branchId)` | includes `reasons_enabled`, `reason_required`, `policy_text`, fee fields, etc. |

## Bug

In `PublicBookingService::getManageLookupByToken()`, the reason list was gated on `$policy['reasons_enabled']` where `$policy` was **only** the runtime enforcement array. That key was **never** set → always empty/falsy in strict PHP (and undefined-key risk), so **`cancellation_reasons` was always `[]`** even when reasons were enabled in settings.

## Fix

Gate the reason list on `$rawPolicy['reasons_enabled']` where `$rawPolicy = getCancellationPolicySettings($branchId)` (already loaded in the same method). Leave `cancellation_policy` payload fields that come from enforcement unchanged (`enabled`, `min_notice_hours`, `reason_required` via `reason_effectively_required_for_cancellation`, etc.).

## Verification

- **Code:** `system/modules/online-booking/services/PublicBookingService.php` — search `getManageLookupByToken` and confirm the `if` uses `rawPolicy['reasons_enabled']`.  
- **SettingsService:** `getCancellationPolicySettings` return array includes `'reasons_enabled'` (bool).  
- **Cancel path:** `cancelByManageToken` continues to use `getCancellationRuntimeEnforcement` + `reason_effectively_required_for_cancellation` (unchanged).

## Intentionally unchanged

- Fee / tax / customer_scope policy (storage-only for economics).  
- `AppointmentService::cancel` enforcement.  
- Settings shell / Lane 01 documentation waves.

---

*End of hotfix note.*
