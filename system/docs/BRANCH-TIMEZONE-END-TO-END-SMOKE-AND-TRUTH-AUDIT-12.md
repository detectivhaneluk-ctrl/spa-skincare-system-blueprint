# BRANCH-TIMEZONE-END-TO-END-SMOKE-AND-TRUTH-AUDIT-12

## Scope

Proof/smoke/audit to verify that branch-selected `establishment.timezone` drives runtime operational behavior branch-by-branch, without UI redesign or architecture changes.

## Branch/Timezone Combinations Tested

- Branch A: `id=11` (`Smoke Branch A`) -> `Europe/Brussels`
- Branch B: `id=12` (`Smoke Branch B`) -> `Asia/Tbilisi`

During smoke execution, both values were explicitly written through `SettingsService::patchEstablishmentSettings(...)`, validated as real IANA IDs (`DateTimeZone` construction succeeded), tested, then restored to original values.

## Runtime Truth Path Verification

Checked and confirmed in code and runtime:

1. `Application::run()` calls `ApplicationTimezone::applyForHttpRequest()`.
2. `BranchContextMiddleware` resolves branch context from authenticated session/user policy.
3. `BranchContextMiddleware` calls `ApplicationTimezone::syncAfterBranchContextResolved()`.
4. `ApplicationTimezone` reads `SettingsService::getEstablishmentSettings(BranchContext::getCurrentBranchId())` and sets `date_default_timezone_set(...)`.

Runtime middleware probe result (synthetic non-platform tenant user with access to branches 11 and 12):

- Session `branch_id=11` -> resolved context `11` -> PHP timezone `Europe/Brussels`
- Session `branch_id=12` -> resolved context `12` -> PHP timezone `Asia/Tbilisi`

This proves per-request branch context can switch PHP default timezone and does not leak prior branch timezone.

## Surface-by-Surface Audit

### 1) Settings save truth

Status: **VERIFIED**

- Branch-specific `establishment.timezone` values were written and read per branch (`11` vs `12`).
- Both tested values are valid IANA timezone identifiers.

### 2) Request timezone application (`Application::run`, `ApplicationTimezone`, `BranchContextMiddleware`)

Status: **VERIFIED**

- Static path is present as designed.
- Runtime middleware probe showed branch switch updates PHP timezone per request context.

### 3) Internal day calendar (`/appointments/calendar/day` and `/calendar/day` backend shape)

Status: **VERIFIED**

- Day calendar defaults and related day computations rely on `date(...)` / `DateTimeImmutable('today')`, therefore follow active PHP timezone.
- Runtime branch probes showed distinct `now_iso` offsets and active timezone identifiers:
  - Branch 11: `+01:00` (`Europe/Brussels`)
  - Branch 12: `+04:00` (`Asia/Tbilisi`)

### 4) Slot generation

Status: **PARTIAL**

- Slot engine path is timezone-driven via active PHP default timezone (`date/strtotime` in availability + branch-hours meta).
- Runtime probes executed for both branches and returned deterministic results in each branch context.
- In current local data, both probes returned `0` slots (`branch 12` also has no configured hours for tested day), so there is no high-signal non-zero slot sample to compare across timezones.

### 5) Appointment display formatting

Status: **PARTIAL**

- Formatting implementation uses `strtotime/date` and therefore follows active PHP timezone.
- Runtime display probe for branch 11 confirmed formatted output under `Europe/Brussels`.
- Branch 12 had no appointment row available for display probe in local data, so cross-branch display comparison could not be fully demonstrated.

### 6) Branch-operating-hours weekday matching

Status: **VERIFIED**

- Weekday resolution is computed from date handling under active runtime timezone and then mapped to branch hours.
- Runtime probes confirmed branch-specific day-hours metadata is resolved inside the active branch timezone context.
- No cross-branch weekday leakage observed when switching 11 -> 12 -> 11.

## Contradictions Found

No direct contradiction was found in the timezone propagation pipeline.

Observed limitations in this environment are data coverage related (missing configured hours/appointments for one branch on tested day), not pipeline inconsistency.

## Patch Applied

No backend patch applied (no proven contradiction requiring correction).

## Final Verdict

Branch-selected timezone is operationally driving runtime behavior through the intended pipeline (`Application::run` bootstrap + post-branch middleware sync), and branch switches update runtime timezone as expected.

Overall classification: **VERIFIED with data-coverage PARTIALs** on slot and appointment-display cross-branch samples in this local dataset.
