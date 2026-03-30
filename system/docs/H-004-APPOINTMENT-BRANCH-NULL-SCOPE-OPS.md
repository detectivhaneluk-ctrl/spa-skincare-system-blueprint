# H-004 — Appointment branch NULL scope (read-side)

## What changed

When a **concrete branch** is supplied, appointment reads and branch-scoped aggregates now require `appointments.branch_id = ?` only. Rows with `branch_id IS NULL` are **not** merged into normal branch calendars, dashboard appointment counts, reports that slice appointments, room conflict checks, or marketing automation/audience queries that join `appointments`.

## Legacy slice

`AppointmentRepository::hasRoomConflict(..., $branchId = null, ...)` still uses `branch_id IS NULL` **only** when `$branchId` is null — an explicit, narrow SQL path for null-branch rows only (not used by normal branch calendar / booking flows).

## Data caveat

`appointments.branch_id` remains nullable in schema. **Legacy NULL-branch rows** will disappear from branch-scoped UI and counts until corrected (set `branch_id` to the owning branch). No automatic migration in this wave.

## Verifier

`php system/scripts/read-only/verify_appointment_branch_null_scope_truth_h004_01.php`
