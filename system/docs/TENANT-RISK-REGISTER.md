# Tenant Risk Register

Date: 2026-03-28  
Scope: backend multi-tenant runtime risks only  
**Platform remediation order:** `BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` §B (maps ranked risks to sequenced work).

## Risk ranking rubric

- Severity: Critical / High / Medium
- Blast radius: Tenant / Multi-tenant / Platform-wide
- Likelihood: High / Medium / Low
- Next wave: first wave where risk must be actively reduced

## Ranked risks

1) Control-plane contamination via permission-string trust  
- Severity: Critical  
- Blast radius: Platform-wide  
- Likelihood: High  
- Evidence: `AuthenticatedHomePathResolver` and dashboard/platform routing hinge on `platform.organizations.view`; legacy role grants can contaminate runtime plane identity.  
- Next wave: `FOUNDATION-100 — CONTROL-PLANE-RBAC-AND-RUNTIME-SEPARATION-REPAIR`

2) Permissive tenant context resolution/fallback behavior  
- Severity: Critical  
- Blast radius: Multi-tenant  
- Likelihood: High  
- Evidence: `OrganizationContextResolver` unresolved and single-active fallback modes; no global hard-fail for all downstream data reads.  
- Next wave: `TENANT-BOUNDARY-HARDENING-CHARTER`

3) Inconsistent repository scoping and caller-discipline dependency  
- Severity: Critical  
- Blast radius: Multi-tenant  
- Likelihood: High  
- Evidence: `OrganizationRepositoryScope` explicitly documents empty SQL on unresolved context and caller responsibility; adoption is partial by module.  
- Next wave: `TENANT-OWNED-DATA-PLANE-HARDENING`

4) Settings isolation flaw (branch/global merge as tenant strategy)  
- Severity: High  
- Blast radius: Multi-tenant  
- Likelihood: High  
- Evidence: `SettingsService` uses branch + global (`branch_id = 0`) precedence; no organization-owned default hierarchy.  
- Next wave: `SETTINGS-TENANT-ISOLATION-REDESIGN-CHARTER`

5) Branch context mutation too implicit (request/session influenced)  
- Severity: High  
- Blast radius: Multi-tenant  
- Likelihood: Medium-High  
- Evidence: `BranchContextMiddleware` consumes request/session `branch_id` and persists session context; risk of confused-deputy behavior if not tightly constrained everywhere.  
- Next wave: `TENANT-BOUNDARY-HARDENING-CHARTER`

6) Tenant lifecycle enforcement gaps (suspension/inactive state)  
- Severity: High  
- Blast radius: Multi-tenant  
- Likelihood: Medium  
- Evidence: org suspension exists in schema lineage, but end-to-end runtime gating is not uniformly enforced across internal + public flows.  
- Next wave: `LIFECYCLE-AND-SUSPENSION-ENFORCEMENT`

7) Public surface governance gaps (branch-valid != tenant-lifecycle-valid)  
- Severity: High  
- Blast radius: Multi-tenant  
- Likelihood: Medium  
- Evidence: public booking/commerce gate primarily on active branch + feature settings; broader tenant lifecycle posture not uniformly applied.  
- Next wave: `LIFECYCLE-AND-SUSPENSION-ENFORCEMENT`

8) Schema compatibility truth drift (migration-state-dependent runtime)  
- Severity: High  
- Blast radius: Platform-wide  
- Likelihood: Medium  
- Evidence: compatibility catches/fallbacks (e.g., auth password-change column guards) allow behavior divergence by schema state.  
- Next wave: `TENANT-BOUNDARY-HARDENING-CHARTER` (with explicit shim burn-down plan)

9) Missing automated tenant isolation proof as release gate  
- Severity: Critical (static sub-risk **mitigated** 2026-03-28 — see below)  
- Blast radius: Platform-wide  
- Likelihood: High for integration-only gaps; **Low** for static invariant drift when gate is run  
- Evidence (prior): scripts and OPS docs existed without a single mandatory runner or packaging hook.  
- Remediation (PLT-REL-01): **`system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php`** — Tier A static proof is **mandatory** before `handoff/build-final-zip.ps1` produces a ZIP; Tier B (`--with-integration`) remains the operator/CI-with-DB bar for cross-tenant runtime smokes.  
- Next wave: treat **Tier B** as mandatory in release runbooks where DB seed is available; extend runner list only via chartered tasks (no ad-hoc one-offs).

## Additional hidden structural risk discovered in this pass

- **Context fixation risk through request-level branch pivot surface**: because branch context can be set from request parameters and persisted to session in middleware, incorrect call-site assumptions can create cross-context integrity failures without explicit user intent.  
  - Severity: High  
  - Blast radius: Multi-tenant  
  - Likelihood: Medium  
  - Next wave: `TENANT-BOUNDARY-HARDENING-CHARTER`
