# Appointment package auto-detection / auto-consumption — foundation 01 (verdict)

**Task:** `APPOINTMENT-PACKAGE-AUTO-CONSUMPTION-FOUNDATION-01`  
**Status:** **Blocked** — no honest automatic consumption or deterministic service-scoped match preview can be added without new domain data.

**Truth basis:** `APPOINTMENT-SETTINGS-REMAINING-PARITY-TRUTH-WAVE-01-OPS.md` §3; code inspection under `system/modules/packages/**` and appointment consumption paths.

---

## What already exists (safe, explicit)

| Capability | Where |
| --- | --- |
| Manual consume for a **chosen** `client_package_id` | `POST /appointments/{id}/consume-package` → `AppointmentService::consumePackageSessions` → `PackageService::consumeForCompletedAppointment` |
| **Idempotency** per appointment + package | `existsUsageByReference(..., 'use', 'appointment', $appointmentId)` — second consume for same pair fails |
| **Completed-only** gate | `consumePackageSessions` requires `status === completed` |
| Eligible packages for dropdown | `PackageAvailabilityProvider::listEligibleClientPackages($clientId, $branchContext)` → active client packages with **remaining sessions** in tenant branch scope |

---

## Exact blockers (why auto is not implemented)

1. **No package ↔ service linkage in schema**  
   `packages` (migration `036_create_packages_table.sql`) defines session counts, validity, price — **no** `service_id`, pivot table, or FK to `services`. `PackageEntitlementSnapshot` v1 stores package metadata only — **no** covered service list.

2. **Eligibility is not appointment-aware**  
   `listEligibleClientPackages` filters by **client + branch + remaining sessions**, not by `appointments.service_id`. Any “preview” that picked a single package for an appointment would be **arbitrary** (hidden magic) when multiple packages qualify.

3. **No lifecycle auto-write**  
   `AppointmentService::updateStatus` does not call package consumption on `completed` — by design today. Wiring auto-consume would require (1) deterministic target package(s) and (2) an explicit product rule (e.g. quantity=1, which package if several match).

4. **Ambiguity without disambiguation policy**  
   Even with a future `package_services` (or snapshot) model, **multiple** client packages could still match one service; tie-breaking is a separate charter (not invented here).

---

## Required foundations before real auto-consumption

1. **Persistent entitlement:** e.g. `package_services(service_id, package_id)` or versioned snapshot field listing allowed `service_id`s, maintained when packages are edited (admin UX).
2. **Resolver:** given `(client_id, service_id, branch_id)`, return **candidate** `client_package_id`s with remaining sessions whose **definition** covers that service — read-only preview first.
3. **Disambiguation + write policy:** single winner rule or stay manual; only then optional hook at `completed` (or explicit operator confirm), reusing existing `consumeForCompletedAppointment` and idempotency.

---

## Verifier (static, no DB)

Proves repo still matches the “no service linkage on packages” precondition:

```bash
php scripts/verify_appointment_package_auto_consumption_foundation_01.php
```

---

## Intentionally untouched

Pricing, check-in lane, public booking, payments, broad package refactors, appointment settings, fake toggles, and any automatic `consumeForCompletedAppointment` calls.
