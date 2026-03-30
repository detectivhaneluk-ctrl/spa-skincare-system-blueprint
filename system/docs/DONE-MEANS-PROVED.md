# Done means proved

This repository is **backend-first** and **proof-first**. A change is not “done” because it compiles locally or “looks right.”

## Minimum bar

1. **Concrete risk** — State what failure mode is removed or narrowed (e.g. cross-tenant read, silent global fallback), not vague quality claims.
2. **Concrete proof** — Point to a **command** others can rerun: a read-only verifier under `system/scripts/read-only/`, `composer run release-law`, or another scripted gate documented in the PR.
3. **Task linkage** — Tie work to a charter / backlog **task id** when one exists (`FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`).
4. **ROOT family** — If the edit touches tenant scope, repositories, or isolation proofs, name the **ROOT-01**–**ROOT-05** family from `ROOT-CAUSE-REGISTER-01.md`, or **`NONE`** with a one-line justification (e.g. comment-only / docs-only with no scope impact).

## Hotspot metadata (`@release-proof`)

If you change files matched by `system/docs/contracts/hotspot-path-patterns.json`, CI requires a PHPDoc block **in the first ~200 lines** of **each changed file** with all of:

- `@release-proof` — anchor tag (no value).
- `@task-id` — e.g. `PLT-TNT-01` or `SELF-DEFENDING-PLATFORM-GUARDRAILS-2026-01`.
- `@risk-removed` — short phrase describing the exact regression class addressed.
- `@proof-command` — one or more commands (repeat the tag on multiple lines if needed).
- `@root-family` — `ROOT-01` … `ROOT-05` or `NONE`.

Example:

```php
/**
 * …
 * @release-proof
 * @task-id PLT-TNT-01
 * @risk-removed Id-only payment read without branch-derived invoice-plane entry
 * @proof-command php system/scripts/read-only/verify_payment_repository_helper_invoice_plane_closure_23_readonly_01.php
 * @root-family ROOT-01
 */
```

**Deleted files** in a PR are not required to carry metadata. **New or modified** hotspot files must.

## What stays human

Some architecture rules (no silent fallback, modules honoring contracts) are **partially** enforced by review and release law because a single low-noise static checker does not exist. That is documented as **OPEN** in `system/docs/contracts/ARCHITECTURE-CONTRACTS.json`.
