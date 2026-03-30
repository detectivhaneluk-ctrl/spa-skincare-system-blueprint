# Staff HTTP org resolution — policy options matrix (FOUNDATION-22)

**Companion:** `STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-TRUTH-AND-DESIGN-AUDIT-FOUNDATION-22-OPS.md`

**Legend:** ✓ = strong fit; ◐ = partial; ✗ = poor fit with accepted F-09/F-21 architecture.

| Option | Description | Single-org UX | Multi-org safety | Aligns with F-09 non-guess | Repo dual-path (F-21) | Implementation spread |
|--------|-------------|----------------|------------------|----------------------------|------------------------|------------------------|
| **1. Status quo** | Resolver only; staff may hit `unresolved_ambiguous_orgs` | ✓ | ✗ (legacy SQL) | ✓ | Relies on legacy | None |
| **2. Staff universal fail-closed** | Any staff + null org → block | ◐ (fallback rare) | ✓ | ✓ | Avoids legacy when blocked | Low if one middleware |
| **3. Multi-org branch-mandatory** | If `active_orgs>1`, staff must have `BranchContext` non-null | ✓ | ✓ | ✓ | Avoids ambiguous branch-null | Low–medium |
| **4. Explicit org session pivot** | New session org id, resolver or successor reads it | ✓ | ✓ | ✓ | Clean | **Higher** (schema/session/API) |
| **5. Route-tiered strict** | Strict only on listed routes | ✓ | ◐ (gaps) | ✓ | Partial | **High** (inventory + drift) |
| **6. Mixed (recommended)** | F-09 resolver unchanged; **post-auth** gate: multi-org + staff + null org → fail closed; single-org unchanged | ✓ | ✓ | ✓ | Legacy rare for staff multi-org | **Low** for minimal gate |

---

## Recommended down-select (design)

**Primary:** **Option 6 (Mixed)** — same as **Option 3** in effect for **multi-org** (staff without resolved org almost always implies null branch), but **explicitly preserves** **single-org fallback** without requiring branch.

**Optional add-on (later program):** **Option 4** if product needs **HQ** to work **without** picking branch but still pick **org** — not required for minimal F-23 boundary.

**Not recommended as default:** **Option 1** for multi-org production; **Option 5** as **sole** mechanism; **Option 2** without **single-org exception** (too blunt).

---

## Current mode → policy trigger (staff)

| `OrganizationContext` mode | Single-org deployment | Multi-org deployment |
|----------------------------|----------------------|----------------------|
| `branch_derived` | OK | OK |
| `single_active_org_fallback` | OK | N/A (only one org) |
| `unresolved_ambiguous_orgs` | N/A | **Gate candidate** |
| `unresolved_no_active_org` | **Error state** | **Error state** |
| Resolver **DomainException** | Fail request | Fail request |
