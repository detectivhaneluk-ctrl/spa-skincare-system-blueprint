# Booker Settings control plane — mismatch audit 01

**Foundation ID:** `SETTINGS-CONTROL-PLANE-TRUTH-MATRIX-FOUNDATION-01`  
**Date:** 2026-03-25  
**Paired matrix:** `BOOKER-SETTINGS-CONTROL-PLANE-TRUTH-MATRIX-01.md`  
**Implementation status:** **No code or UI repairs were performed** for this audit — documentation only.

---

## 0. Discipline (mandatory)

- **Live code wins:** Claims are backed by current `system/routes`, `system/modules`, `system/core`, and migrations — not by `archive/` or unchecked legacy docs.  
- **Booker / ZIP / old specs** are **inputs only**; parity statements require **traced** read/write + consumer paths.  
- This file lists **code-truth-backed** mismatches only (no speculative product gaps).

---

## 1. Categories scanned

- **False launcher promises** (nav implies a capability the repo does not expose usefully)  
- **Subsections visible in Settings but not wired** to the implied backend (or wired only as JSON/API)  
- **Write UI with no real runtime consumer** (persisted but not enforced)  
- **Runtime behavior controlled outside** the shown UI (or different semantics)  
- **Duplicated / conflicting control points** (same domain, two surfaces)  
- **“Backend pending”** or similar **misleading** states in the shell  
- **Deferrals** that belong to **later lanes** (documented here, not hidden)

---

## 2. Findings (detailed)

### M-01 — Cancellation economic policy: stored, not enforced on cancel

- **Symptom:** Fees, tax flag, customer scope, and related fields save to `settings`; cancel path does not apply charging or branch on those fields.  
- **Code:** `AppointmentService::cancel` → `getCancellationRuntimeEnforcement` only; UI copy in `index.php` marks many rows as configuration-only / future enforcement.  
- **Type:** Write UI + persistence without matching runtime consumer  
- **Risk:** H  

### M-02 — VAT distribution: settings link → raw JSON

- **Symptom:** Operator expects a report “page”; `GET /reports/vat-distribution` returns JSON only.  
- **Code:** `ReportController::vatDistribution`; `shell.php` anchor.  
- **Type:** False launcher promise (browser UX)  
- **Risk:** H  

### M-03 — “Online Booking” sidebar vs `public_channels` triple bundle

- **Symptom:** One workspace section edits online booking, public intake, and public commerce.  
- **Code:** `shell.php` label; `index.php` `section=public_channels`; `SettingsController::SECTION_ALLOWED_KEYS['public_channels']`.  
- **Type:** IA / Booker mapping mismatch  
- **Risk:** M  

### M-04 — Sales notifications: in-app vs outbound semantics differ

- **Symptom:** Checkbox reads as global “sales notifications”; outbound path does not use `sales_enabled` as implied.  
- **Code:** `SettingsService` file header comment; `shouldEmitOutboundNotificationForEvent` vs in-app gate.  
- **Type:** Runtime not aligned with implied UI  
- **Risk:** M  

### M-05 — Create-only module launchers (no index link)

- **Symptom:** Spaces, equipment, services, packages, staff: only “New …” from settings shell.  
- **Code:** `shell.php`; full `index` routes exist on module registrars.  
- **Type:** Incomplete launcher  
- **Risk:** M  

### M-06 — Series: “Backend pending” vs existing series POST API

- **Symptom:** Sidebar implies no backend; `POST /appointments/series` (+ materialize/cancel) exists.  
- **Code:** `register_appointments_calendar.php`; `AppointmentController` series actions (JSON).  
- **Type:** False “pending” labeling  
- **Risk:** H  

### M-07 — Document storage: “Backend pending” vs live document routes

- **Symptom:** Sidebar disabled item suggests missing backend; `DocumentController` serves JSON definitions/files.  
- **Code:** `register_documents.php`; `DocumentController` class docblock (“No UI; JSON”).  
- **Type:** False pending; **UI-WITHOUT-BACKEND** for operators  
- **Risk:** H  

### M-08 — Users / connections: no tenant admin CRUD from settings

- **Symptom:** Booker-style connections; no tenant `/users` management in `system/routes/web`.  
- **Code:** `shell.php` pending span; platform routes under `/platform-admin/...` are a different plane.  
- **Type:** Genuine gap (defer to dedicated lane)  
- **Risk:** H  

### M-09 — `PublicBookingService` manage lookup: `reasons_enabled` on wrong array

- **Symptom:** `lookupManageToken` tests `$policy['reasons_enabled']` where `$policy = getCancellationRuntimeEnforcement(...)` — that method **does not** return `reasons_enabled`.  
- **Code:** `PublicBookingService.php` (~386); `SettingsService::getCancellationRuntimeEnforcement` return shape (~668).  
- **Type:** Bug risk / wrong branch (PHP 8+ undefined array key)  
- **Risk:** H  

### M-10 — Appointment settings: read vs save scope perception

- **Symptom:** UI warns calendar/client profile may read using operator branch while saves target selected scope — easy to read as conflicting controls.  
- **Code:** `index.php` hint; `SettingsController` branch resolution.  
- **Type:** Perceived duplicate control  
- **Risk:** M  

### M-11 — Memberships: KV defaults vs full module

- **Symptom:** Two surfaces without strong separation in the directory.  
- **Code:** `section=memberships` vs `modules/memberships/routes/web.php`.  
- **Type:** Duplicate control plane (conceptual)  
- **Risk:** M  

### M-12 — `canViewBranchesLink` unused in shell

- **Symptom:** Computed in `SettingsController::index` but not passed into sidebar UX.  
- **Code:** `SettingsController.php`; `shell.php` has no `canViewBranchesLink` usage.  
- **Type:** Dead / incomplete launcher wiring  
- **Risk:** L  

### M-13 — Sections deferred to later lanes (explicit)

- **Full marketing parity** — beyond `marketing.default_opt_in` / `consent_label` (`/marketing/campaigns*` is out of this lane’s scope).  
- **Reports / BI HTML** — VAT distribution consumer UX; broader dashboard drilldown (**§5.C** P2+).  
- **Deep memberships rebuild** — charter out of scope.  
- **Time clock / full payroll compliance** — **§5.D** / Phase 8 hold items vs current commission runs.  
- **Type:** DEFER (not mismatches to “fix” inside Lane 01 without charter change)  
- **Risk:** L (if mis-labeled as “done”)  

---

## 3. Top 10 highest-risk mismatches (ordered)

1. **M-01** — Cancellation fees/tax/scope persisted without cancel-time enforcement.  
2. **M-02** — VAT distribution navigation → raw JSON in browser.  
3. **M-09** — Wrong array key for `reasons_enabled` in public manage lookup (undefined-key / logic hazard).  
4. **M-07** — Documents: false “backend pending”; APIs exist without operator UI.  
5. **M-08** — Tenant users/connections admin missing.  
6. **M-06** — Series: false “backend pending” vs POST series API.  
7. **M-03** — “Online booking” label hides intake + commerce.  
8. **M-04** — Sales notification toggle vs outbound behavior.  
9. **M-05** — Create-only launchers omit management entry points.  
10. **M-11** — Dual membership surfaces without clear IA.

---

## 4. Recommended WAVE 2 scope only (no code in this task)

Shell / directory **honesty** — **no** new product screens, **no** refactors, **no** contract fixes (those are Wave 3+):

1. **Badges / copy:** Series + Document storage — replace false “Backend pending” with API-only / no admin UI / use calendar or API as code proves.  
2. **VAT distribution:** Relabel link or avoid sending operators to raw JSON without a viewer.  
3. **Public channels:** Sidebar or workspace sub-labels for booking vs intake vs commerce.  
4. **Launchers:** Add “All …” links for spaces, equipment, services, packages, staff (alongside “New …”).  
5. **Notifications:** Short text for in-app vs outbound under toggles.  
6. **Memberships:** Distinguish “Membership defaults (settings)” vs “Membership catalog (module)”.  
7. **Branches:** Wire `canViewBranchesLink` to `/branches` in shell **or** document removal in a later cleanup — minimal change only if agreed.

**Explicitly not Wave 2:** M-09 code fix, fee enforcement, tenant user CRUD, HTML VAT report, series management UI.

---

## 5. Statement of record

**No repairs, refactors, UI redesign, or placeholder UI were implemented** as part of `SETTINGS-CONTROL-PLANE-TRUTH-MATRIX-FOUNDATION-01` — only the charter, matrix, mismatch audit, and roadmap pointer were authored/updated.

---

---

## Appendix — Wave 02-01 (2026-03-25)

Several items above were addressed as **shell / copy / routing honesty only** (no backend economics, no outbound logic changes). See `SETTINGS-SHELL-HONESTY-WAVE-02-01.md` for the closed list (e.g. M-02 VAT entry, M-05 create-only launchers, M-06/M-07 pending badges, M-03 public channels label, M-04/M-11 copy, M-12 branches link). **M-09** (manage-token `reasons_enabled`) was fixed under **`PUBLIC-BOOKING-CANCELLATION-REASONS-KEY-HOTFIX-01`**, not this wave. **M-01, M-08** (fee enforcement, tenant users) remain for later waves.

## Appendix — Wave 03C sibling shell (2026-03-25)

Settings-adjacent pages (`/settings/vat-distribution-guide`, `/settings/vat-rates`, `/settings/payment-methods`) now share **`SettingsShellSidebar::permissionFlagsForUser`** with the main Settings index so sidebar gates match route permissions (`settings.view`, `payment_methods.view`, `vat_rates.view`, module launchers). VAT guide copy restates that the sample JSON link uses the same permission as `GET /reports/vat-distribution`. See `SETTINGS-SIBLING-SURFACES-CONSISTENCY-WAVE-03C.md`.

*End of mismatch audit 01.*
