# Settings shell honesty — Wave 02-01

**Task ID:** `SETTINGS-SHELL-HONESTY-WAVE-02-01`  
**Lane:** `BOOKER-SETTINGS-CONTROL-PLANE-LANE-01` — **WAVE 2** (first implementation slice)  
**Date:** 2026-03-25  
**Governing docs:** `BOOKER-SETTINGS-CONTROL-PLANE-LANE-01-CHARTER.md`, truth matrix, mismatch audit, `PUBLIC-BOOKING-CANCELLATION-REASONS-KEY-HOTFIX-01.md` (no changes in this wave to that hotfix).

## What shipped

1. **Series & document storage & users (sidebar)**  
   - Removed false “Backend pending” dead-ends.  
   - **Series:** Plain-language note: series from Appointments / day calendar; no Settings list; staff APIs exist.  
   - **Documents:** Honest note — JSON API under `/documents/…`, no HTML admin in Settings.  
   - **Users:** Honest note — tenant user admin not in Settings; platform tools separate.

2. **VAT distribution**  
   - Sidebar no longer links straight to raw JSON.  
   - New **`GET /settings/vat-distribution-guide`** (`settings.view`): read-only explainer + query contract + sample URL; optional “open JSON in new tab” only when user has **`reports.view`**.

3. **Public channels**  
   - Sidebar label **Public channels** (replaces “Online Booking” for the `section=public_channels` route).  
   - Workspace title/lead updated to separate **online booking**, **public intake**, **public commerce** semantics (copy only).

4. **“All …” launchers** (routes verified in repo)  
   - Spaces → `/services-resources/rooms`  
   - Equipment → `/services-resources/equipment`  
   - Staff → `/staff`  
   - Services → `/services-resources/services`  
   - Packages → `/packages`  
   - Memberships module → `/memberships` (plus existing New)

5. **Internal notifications**  
   - Short help: in-app vs outbound; Sales toggle = in-app payment-related alerts, not all sales email.

6. **Memberships**  
   - Sidebar: **Membership defaults** (settings KV) vs **Memberships (catalog)** module block with cross-pointer.  
   - Workspace: title **Membership defaults**, link to `/memberships`, save button label aligned.

7. **Branches**  
   - **`canViewBranchesLink`** wired: **Branches** link to **`/branches`** when `branches.view` (in main settings + payment-methods + VAT rates + VAT guide shell contexts).

## Files touched

| File | Change |
|------|--------|
| `system/routes/web/register_settings.php` | Route `GET /settings/vat-distribution-guide` |
| `system/modules/settings/controllers/SettingsController.php` | `vatDistributionGuide()` |
| `system/modules/settings/views/vat-distribution-guide.php` | New explainer workspace |
| `system/modules/settings/views/partials/shell.php` | Honest copy, list links, VAT guide nav, branches link |
| `system/modules/settings/views/index.php` | Notifications help, membership defaults, public channels lead |
| `system/modules/settings/controllers/PaymentMethodsController.php` | `canViewBranchesLink` in `sidebarPermissions()` |
| `system/modules/settings/controllers/VatRatesController.php` | `canViewBranchesLink` in `sidebarPermissions()` |

## Intentionally not done (later waves / out of scope)

- HTML VAT report product, fee enforcement, tenant users CRUD, document admin UI, deep memberships UX, outbound logic changes, public booking code paths.

## Wave 3 follow-ups

- Prove high-value write contracts (smoke/tests) per charter.  
- Optional: tighten any remaining copy against `SettingsService` docblocks line-by-line.

---

*End of Wave 02-01 record.*
