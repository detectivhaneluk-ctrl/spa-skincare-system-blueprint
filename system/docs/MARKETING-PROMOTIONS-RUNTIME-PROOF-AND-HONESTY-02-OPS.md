# MARKETING-PROMOTIONS-RUNTIME-PROOF-AND-HONESTY-02

## Exact commands run

1. Validate Laragon PHP CLI:
   - `& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -v`
2. Run migrations:
   - `& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'scripts/migrate.php'`
3. Start local runtime server:
   - `& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -S 127.0.0.1:8899 -t 'c:\laragon\www\spa-skincare-system-blueprint\system\public' 'c:\laragon\www\spa-skincare-system-blueprint\system\public\router.php'`
4. Provision branch-scoped test users:
   - `& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'scripts/create_user.php' --tenant-admin promo_admin_b11@example.com 'PromoPass123!' 'Promo Admin B11' --org-id=1 --branch-id=11`
   - `& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'scripts/create_user.php' --tenant-admin promo_admin_b12@example.com 'PromoPass123!' 'Promo Admin B12' --org-id=1 --branch-id=12`
5. Run backend foundation verifier:
   - `& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'scripts/dev-only/proof_marketing_promotions_admin_foundation_hardening_01.php'`
6. Run live HTTP/UI proof (PowerShell session against `http://127.0.0.1:8899`) for create/edit/duplicate/toggle/date/delete/empty-state/reorganize/cross-branch checks.
7. Run focused flash-proof command (PowerShell) to verify immediate POST response flash for:
   - same-branch duplicate rejection
   - invalid date window rejection

## Exact migration state

- `migrations` record:
  - `migration_108_recorded=yes`
  - `migration_108_run_at=2026-03-26 19:14:44`
- Schema runtime checks:
  - `column_offer_option_exists=yes`
  - `column_start_date_exists=yes`
  - `column_end_date_exists=yes`
  - `column_is_active_exists=yes`

## Exact pages/routes tested

- `GET /login`
- `POST /login`
- `GET /marketing/promotions/special-offers`
- `GET /marketing/promotions/special-offers?name=__no_results__`
- `POST /marketing/promotions/special-offers`
- `POST /marketing/promotions/special-offers/{id}`
- `POST /marketing/promotions/special-offers/{id}/toggle-active`
- `POST /marketing/promotions/special-offers/{id}/delete`
- `POST /logout`

## Exact pass/fail results

### Backend verifier (`proof_marketing_promotions_admin_foundation_hardening_01.php`)
- PASS: route edit/update/toggle present
- PASS: migration 108 recorded
- PASS: branch scoping proof
- PASS: create/update/delete proof
- PASS: code uniqueness proof (same branch blocked, cross-branch allowed)
- PASS: active toggle proof
- PASS: date-window validation proof
- PASS: options persistence truth proof
- PASS: empty result count truth proof

### Live HTTP/UI proof (branch 11 then branch 12)
- PASS: login branch 11
- PASS: empty list text honesty (`Results 0 of 0`)
- PASS: reorganize honesty message shown as unavailable
- PASS: create offer (`PROMO-RT-02-X`)
- PASS: edit/update offer
- PASS: activate/deactivate
- PASS: soft delete
- PASS: login branch 12
- PASS: cross-branch duplicate code allowance (`PROMO-RT-02-X`)
- PASS: `offer_option` persistence visibility in list
- INITIAL FAIL (method artifact), then PASS after focused check:
  - same-branch duplicate code rejection flash
  - invalid date-window rejection flash

## Exact contradictions fixed

1. Migration contradiction (real DB engine):
   - `ADD COLUMN IF NOT EXISTS` in migration 108 failed at runtime.
   - Fixed by replacing with `information_schema` guards + dynamic `ALTER TABLE`.

2. Migration runner contradiction:
   - Using `SELECT 1` as dynamic no-op caused unbuffered-result error during `DEALLOCATE PREPARE`.
   - Fixed by using `DO 0` no-op statements instead.

3. Proof harness contradiction (not application behavior):
   - Initial HTTP proof marked duplicate/date rejection as fail because flash was checked on a second GET after flash consumption.
   - Fixed proof method by validating immediate POST-redirect response content.
