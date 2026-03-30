# SETTINGS-SIBLING-SURFACES-CONSISTENCY-WAVE-03C

Short closure memo: Settings **sibling** surfaces use the same shell rules and permission-driven sidebar as the main Settings workspace.

## What changed

- **`Modules\Settings\Support\SettingsShellSidebar::permissionFlagsForUser`** — single source for sidebar booleans: `settings.view`, `branches.view`, `services-resources.view`, `staff.view` / `staff.create`, `packages.view`, `memberships.view`, `payroll.view`, `payment_methods.view`, `vat_rates.view`, `reports.view`, etc.
- **`SettingsController`**, **`PaymentMethodsController`**, **`VatRatesController`** — all use this helper (via `extract` or `sidebarPermissions()` return).
- **`partials/shell.php`** — General Settings links gated by permission (e.g. core `/settings?section=…` items require `settings.view`; Custom Payment Methods requires `payment_methods.view`; VAT Types requires `vat_rates.view`; VAT distribution guide requires `settings.view`). Related launchers: spaces/equipment/services require `services-resources.view`; staff links use `staff.view` / `staff.create` / `payroll.view` as appropriate; packages and memberships catalog links use `packages.view` / `memberships.view`.
- **Layout capture** — `ob_start()` / `ob_get_clean()` pattern corrected on the main Settings index and payment/VAT CRUD views (removed mistaken `$content = ob_start()` assignment).
- **Branch context** — Payment methods and VAT rate views set `online_booking_branch_id` / `appointments_branch_id` to **0** before shell so `settingsUrl()` branch query params stay consistent (global default).
- **VAT guide** — Sample JSON link help text states it uses the same URL and `reports.view` as the live endpoint; forbidden message aligned.

## Launchers (unchanged honesty)

- **Proven GET routes:** `/services-resources/rooms`, `…/create`, equipment, services, `/staff`, `/staff/create`, `/packages`, `/packages/create`, `/memberships`, `/memberships/create`, `/branches` (when `branches.view`).
- **Series:** No list/index launcher — copy still points to Appointments + day calendar only.

## Intentionally not done

- HTML VAT report, reports runtime changes, tax engine, BI.
- New permissions beyond wiring existing flags to the shell.

## Verification checklist

- Open `/settings`, `/settings/vat-distribution-guide`, `/settings/vat-rates`, `/settings/payment-methods` — sidebar items match what the current user can access per permissions.
- VAT guide: without `reports.view`, no working sample link; with `reports.view`, link hits JSON-only endpoint.
