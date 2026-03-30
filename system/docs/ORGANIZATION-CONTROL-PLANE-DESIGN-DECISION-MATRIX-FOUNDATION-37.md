# ORGANIZATION-CONTROL-PLANE-DESIGN-DECISION-MATRIX — FOUNDATION-37

Companion to **`ORGANIZATION-REGISTRY-AND-PLATFORM-CONTROL-PLANE-MINIMAL-DESIGN-FOUNDATION-37.md`**.

| # | Decision topic | **Chosen** | Rejected alternatives | Rationale |
|---|----------------|------------|----------------------|-----------|
| D1 | **User↔org binding** | **`user_organization_memberships`** pivot (`user_id`, `organization_id`, `status`, timestamps; optional `default_branch_id`) | `users.organization_id` only; infer membership only from `users.branch_id` | Multi-org + platform users; clear migration from `branch_id` backfill |
| D2 | **Platform RBAC family** | **`platform.*`** for cross-tenant registry / suspend / create org | `organizations.*` only; `*` wildcard | Auditable separation from in-tenant admin |
| D3 | **In-tenant org profile RBAC** | **`organizations.profile.manage`** (name on TBD; must require resolved org) | Reuse `branches.manage`; reuse `settings.edit` | Branch catalog ≠ org metadata; settings ≠ hosting |
| D4 | **Org activation states** | **`suspended_at`** nullable + existing **`deleted_at`** archive | Single `status` enum only; hard delete | Reversible suspend distinct from archive; aligns with soft-delete culture |
| D5 | **Phase 1 registry CRUD** | Platform: create, list, read, suspend/unsuspend; org admin: update profile in context | Full archive + purge; cross-org branch moves | Minimal viable control plane; avoid FK cascade traps |
| D6 | **Branch ↔ org relation** | **Keep** `branches.organization_id` FK; **no** branch reparent in phase 1 | Multi-org branch sharing | Matches current tree; reduces blast radius |
| D7 | **Single-org fallback (F-09)** | **Keep** through phase 1; **narrow** later via config when memberships complete | Remove immediately | ZIP / single-tenant regression risk |
| D8 | **Rollout order** | **S1 schema → S2 RBAC seeds → S3 read service → S4 mutate → S5 membership+resolver** | Behavior before schema | Backend-first; DB truth before gates |
| D9 | **`users.branch_id` long-term** | **Default operational branch**; membership is authority for org | Drop `branch_id` in phase 1 | Migration-safe; incremental |
| D10 | **Platform user session** | Platform routes **without** tenant `BranchContext` **allowed** in design; resolver modes TBD in F-38+ | Force every user through branch | Registry ops are not branch-scoped |

---

## Cross-reference to F-36 gaps

| F-36 gap | Closed by design (which rows) |
|----------|------------------------------|
| No org registry | D5, S3–S4 |
| No user↔org membership | D1, S5 |
| No platform RBAC family | D2, D3, S2 |
| Org inferred only | D7 (transitional), D1+S5 (target strict mode) |
