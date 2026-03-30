# PUBLIC-BOOKING-BRANCH-OPERATING-HOURS-GUARD-09

## Scope
- Focused on public/online booking create enforcement for branch operating hours.
- No public UI redesign, no slot-generation rewrite, no closure-dates integration, no staff-schedule merge.

## Exact Public Booking Write Paths Audited
- Route: `POST /api/public/booking/book` in `routes/web/register_core_dashboard_auth_public.php`
- Controller: `Modules\OnlineBooking\Controllers\PublicBookingController::book()`
- Service chain:
  - `PublicBookingService::createBooking(...)`
  - `AppointmentService::createFromPublicBooking(...)`
  - `AppointmentService::insertNewSlotAppointmentWithLocks(...)`
  - `AppointmentService::assertWithinBranchOperatingHours(...)`

## Guard Coverage Finding
- Public create already reached the canonical appointment write pipeline.
- Since Wave 07 added `assertWithinBranchOperatingHours(...)` inside the shared insert path, public booking create was already backend-enforced for:
  - missing-hours day
  - closed day
  - outside open/close interval
- No bypass path was found for public create writes.

## Enforcement Reuse Applied
- Reused canonical guard with no duplicated scheduling business logic.
- Kept security controls unchanged (rate limits, branch/public-api gates, client resolution, consent enforcement, token mechanisms).
- Added public-safe error mapping for branch-hours denials in `PublicBookingService::mapPublicSafeAppointmentError(...)`.

## Public-Safe Validation Behavior
- Public API now safely returns branch-hours validation messages when denied by canonical service guard:
  - `Opening hours are not configured for this branch on the selected day.`
  - `This branch is closed on the selected day.`
  - `The selected time falls outside this branch's operating hours (HH:MM-HH:MM).`
- Unexpected/internal errors still return generic public-safe message:
  - `Booking could not be completed. Please contact the spa if you need help.`

## Persistence Safety
- Rejected public booking attempts do not persist appointments.
- `PublicBookingService::createBooking(...)` wraps writes in a transaction and rolls back on `DomainException` / `InvalidArgumentException` / `Throwable`.
- No partial appointment row is committed when branch-hours guard rejects.

## Intentionally Not Covered Yet
- Public slot-list generation filtering by branch operating hours.
- Closure-date enforcement for public booking.
- Any UI/UX changes to slot chooser behavior.
