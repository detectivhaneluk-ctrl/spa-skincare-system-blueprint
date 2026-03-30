# NULL-branch catalog policy — authoritative audit (FOUNDATION-HARDENING-WAVE)

## Intent

The anti-pattern `branch_id IS NULL OR branch_id = ?` (and equivalents) is **safe only** when the table models an intentional **global + branch override** settings dimension (e.g. VAT, payment methods), not when it selects **sellable tenant catalog** rows (memberships, packages, public-commerce SKUs) in protected runtime.

## Guardrail

- Script: `system/scripts/verify_null_branch_catalog_patterns.php`
- Scans: `memberships`, `packages`, `public-commerce`, `sales` repositories/services (see script).
- **Path allowlist** documents justified matches; new hits require classification or code change.

## Classification rubric

| Class | Meaning |
|-------|---------|
| **ALLOWED-SYSTEM-ONLY** | Global admin / migrations / repair CLIs; not staff/tenant request path. |
| **ALLOWED-LEGACY-BUT-NEEDS-REDESIGN** | Intentional NULL-branch semantics today; product should move to explicit scope flags. |
| **UNSAFE-IN-PROTECTED-TENANT/PUBLIC/STAFF-RUNTIME** | Any sellable catalog or entitlement read/write using NULL OR for tenant-facing selection. |
| **FOLLOW-UP-REPAIR-CANDIDATE** | Adjacent modules (staff groups, inventory categories) — same pattern class, out of scope for this wave. |

## Snapshot (2026-03-23)

- **Memberships / packages / public-commerce** (repositories + services in scan set): **no** `NULL OR branch_id` matches — **clean** under verifier.
- **Sales — VAT / payment methods**: pattern **present**; **allowlisted** in verifier as settings-backed global+branch override (**ALLOWED-LEGACY-BUT-NEEDS-REDESIGN** for long-term explicit modeling).
- **Other modules** (inventory categories/brands, staff groups, permissions): pattern **present** — see matrix file (**FOLLOW-UP-REPAIR-CANDIDATE** / design review), **not** changed in this wave.

Detailed file-level rows: `NULL-BRANCH-CATALOG-RISK-MATRIX-FOUNDATION-WAVE.md`.
