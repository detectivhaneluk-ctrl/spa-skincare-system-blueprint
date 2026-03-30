# CANCELLATION-POLICY-EXPANDED-BACKEND-FOUNDATION-01

## Runtime truth

- Cancellation policy remains tenant-global for writes in this wave.
- `cancellation.enabled` means cancellation permission (`Allow cancellations`), not fee posting enablement.
- `cancellation.min_notice_hours` means minimum notice/cutoff used to block cancellation inside the notice window.
- Expanded cancellation fields are saved under `cancellation.*` and normalized by `SettingsService::getCancellationPolicySettings()`.
- Canonical runtime flags are exposed by `SettingsService::getCancellationRuntimeEnforcement()`.
- Legacy runtime readers that rely on `getCancellationSettings()` stay backward-safe (`enabled`, `min_notice_hours`, `reason_required`, `allow_privileged_override`).
- Structured cancellation reasons are stored in `appointment_cancellation_reasons` with `organization_id` scope and `branch_id = 0`.
- Appointments can now persist structured links:
  - `appointments.cancellation_reason_id`
  - `appointments.no_show_reason_id`

## Public manage contract

- Manage lookup now includes:
  - `cancellation_policy.enabled`
  - `cancellation_policy.min_notice_hours`
  - `cancellation_policy.reason_required` (effective when reasons are enabled)
  - `cancellation_policy.policy_text`
  - `cancellation_reasons` (active reasons applicable to cancellation)
- Public cancel accepts optional:
  - `reason_id` (structured reason id)
  - `reason` (free text note)
- `reason_required` in public manage payload is effective runtime truth (`reasons_enabled && reason_required`).

## Intentionally not operational in this wave

- No automatic invoice creation.
- No automatic payment capture.
- No automatic refunds.
- No staff commission postings.
- No tax ledger postings for cancellation/no-show.

Fee and tax fields are configuration-only in this phase and are exposed for future posting waves.

