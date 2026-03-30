# Staff multi-org gate R1 — trigger / non-trigger / escape matrix (FOUNDATION-26)

**Companion:** `STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-POST-IMPLEMENTATION-TRUTH-AUDIT-FOUNDATION-26-OPS.md`  
**Mode:** Read-only audit artifact — **no** code changes.

**Path normalization (gate):** `parse_url(..., PHP_URL_PATH)` then `rtrim($path, '/') ?: '/'` — e.g. `/logout/` → `/logout`.

---

## A) Trigger matrix (403 org gate)

| # | `countActiveOrganizations()` | `getCurrentOrganizationId()` | Exempt path? | Reached `enforce`? | Result |
|---|------------------------------|------------------------------|--------------|-------------------|--------|
| T1 | > 1 | `null` | No | Yes | **403** org gate |
| T2 | > 1 | `0` (theoretical) | No | Yes | **403** org gate |
| T3 | > 1 | `null` | No | Yes (typical multi-org HQ / ambiguous F-09) | **403** org gate |

---

## B) Non-trigger matrix (gate returns or never runs)

| # | Condition | Result |
|---|-----------|--------|
| N1 | `countActiveOrganizations() <= 1` | Gate **returns** (no 403 from this gate) |
| N2 | `orgId !== null && orgId > 0` | Gate **returns** |
| N3 | `POST /logout` (normalized) | Gate **returns** |
| N4 | `GET` or `POST /account/password` | Gate **returns** |
| N5 | Route has no `AuthMiddleware` or auth fails | Gate **not invoked** |
| N6 | Inactivity / auth deny | Gate **not invoked** |
| N7 | Password expired + non-exempt path | `denyPasswordExpired`; gate **not invoked** |
| N8 | Password expired + exempt path | `$next()` **without** gate (Auth short-circuit) |

---

## C) Relationship to F-09 resolution modes (typical DB)

| Mode (after global org middleware) | Typical `count` | `orgId` | Gate |
|-----------------------------------|-----------------|---------|------|
| `branch_derived` | any ≥ 1 | > 0 | Pass |
| `single_active_org_fallback` | 1 | > 0 | Pass |
| `unresolved_ambiguous_orgs` | > 1 | null | **403** if auth path hits gate |
| `unresolved_no_active_org` | 0 | null | **No** 403 from gate (`count <= 1`) |

---

## D) Execution order snippet (reference)

**Global:** Branch → Organization context resolved.  
**Auth:** Session OK → (password expiry branch) → **`enforceForAuthenticatedStaff()`** → `$next()` → permission / controller.

See `StaffMultiOrgOrganizationResolutionGate.php`, `AuthMiddleware.php`, `Dispatcher.php` for line-level proof.
