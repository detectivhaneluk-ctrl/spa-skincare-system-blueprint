# No-show alert — operationalization 01

**Task:** `APPOINTMENT-NO-SHOW-ALERT-OPERATIONALIZATION-01`  
**Scope:** Existing settings `appointments.no_show_alert_enabled` and `appointments.no_show_alert_threshold` drive a **canonical structured payload** on real read paths used when reviewing clients and appointments. **No** new notifications, cron, email/SMS, or booking-rule changes.

**Related:** `APPOINTMENT-SETTINGS-BACKEND-CONTRACT-FOUNDATION-01-OPS.md` (key definitions).

---

## Counting semantics (unchanged)

- **Source:** `ClientAppointmentProfileProviderImpl::getSummary` — SQL counts non-deleted appointments for the client with `status = 'no_show'`, scoped by `OrganizationRepositoryScope` on `appointments` (same as historical summary).
- **Trigger:** `no_show_alert_enabled` **and** `recorded_no_show_count >= threshold` (threshold clamped 1–99 in settings read).

---

## Canonical payload: `no_show_alert`

Returned on **every** `getSummary()` call (including access-denied / empty profile), additive to existing flat keys:

| Field | Type | Notes |
| --- | --- | --- |
| `active` | bool | **True** only when settings enabled **and** count ≥ threshold |
| `code` | string | Stable: `client_no_show_threshold` |
| `severity` | string | `warning` |
| `settings_enabled` | bool | Mirror of `no_show_alert_enabled` |
| `recorded_no_show_count` | int | Same as top-level `no_show` |
| `threshold` | int | Effective threshold |
| `message` | string | Non-empty when `active`; empty when inactive |

**Backward compatibility:** Top-level `no_show_alert_enabled`, `no_show_alert_threshold`, and `no_show_alert_triggered` remain. **`no_show_alert_triggered`** is defined as **`no_show_alert.active`**.

---

## Read surfaces

| Surface | Mechanism |
| --- | --- |
| **`ClientController::show`** / `clients/views/show.php` | Renders hint when `no_show_alert.active` (uses `message`). |
| **`AppointmentController::show`** / `appointments/views/show.php` | Loads `getSummary(client_id)`; banner when `no_show_alert.active`. |
| **`GET` day calendar JSON** (`AppointmentController::dayCalendar`) | Each appointment with `client_id` includes **`client_no_show_alert`**: same structure as `no_show_alert`, or **`null`** if no client. Cached per distinct `client_id` in the response build. |
| **Day calendar UI** (`calendar-day.php`) | When `client_no_show_alert.active`, block gets class `ops-block-appt--no-show-alert` and `title` with message. |

---

## Proof

```bash
cd system
php scripts/verify_appointment_no_show_alert_operationalization_01.php --branch-code=SMOKE_A
```

**May SKIP:** no client with at least one `no_show` row in scope for the branch.

---

## Intentionally out of scope

Push/in-app notification engine, scheduled jobs, email/SMS, changing how `no_show` status is recorded, appointment create/edit forms (no new client-picker API), public booking payloads.
