## OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01 OPS

Status: DONE (audit-only truth wave)

### Objective reached

- Completed system-wide triage of remaining tenant-exposed modules outside already-closed hardening waves.
- Classified runtime/public exposure, read/write surfaces, scoping style, lifecycle governance presence, blast radius, and next action.
- Produced prioritized next-wave execution order based on code evidence, not roadmap optimism.

### Truth decisions

- Highest-risk remaining lane is **Sales** due to:
  - financial write blast radius,
  - mixed scoping styles (branch-or-null repo filters + unscoped id repo methods),
  - heavy cross-module dependencies (payments, invoices, memberships settlement, public-commerce reconciliation),
  - safety depending substantially on service/controller discipline.
- Second risk lane is **Inventory** (same structural pattern: mixed branch/global filters + repo id lookup reliance).
- Third lane is **Memberships/GiftCards/Packages** bundle (strong service checks but still mixed repo patterns and indirect public-commerce coupling).

### Notable remaining-risk patterns

- Branch-or-null/global blending still present in multiple read paths.
- Repository id methods in several modules are not intrinsically tenant-fail-closed.
- Org-scope repository fragments exist in Marketing/Payroll, but unresolved-org fallback paths still allow broader/global behavior.
- Lifecycle/suspension governance for these modules is mostly inherited from global runtime gate, not module-local invariants.

### What this wave intentionally did not do

- No module hardening implementation.
- No feature work.
- No broad refactor.
- No already-closed wave changes.

### Next-up determination

- Single next implementation wave: `SALES-TENANT-DATA-PLANE-HARDENING-01`.
- Why next: it has the largest immediate confidentiality + integrity blast radius and the densest caller-discipline dependency surface.
- Why others wait: Inventory and Memberships/GiftCards/Packages risks are real but lower immediate financial/systemic impact than Sales.
