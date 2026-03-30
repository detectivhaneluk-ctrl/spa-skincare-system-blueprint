# Founder ops — operating principles

## Purpose

The founder control plane is intentionally evolving into a **no-code operations system**: non-developers can run the platform safely using **truthful, read-mostly surfaces** and **documented routes** into existing tools.

## Rules of the road

1. **Prove it or don’t show it** — Counts and incident types must come from services and queries that already encode domain rules (e.g. `UserAccessShapeService`, registry reads). No decorative metrics.  
2. **Identify, explain, route first** — Explain impact (cause → who is affected → what stays blocked → what changes when fixed → safest next step) using presenter/explainer services (`FounderImpactExplainerService`, `FounderAccessImpactExplainer`, `FounderIncidentImpactExplainer`); keep strings out of views where possible. Incident Center **identifies and routes**; it does not mutate. Detail pages **route** to existing modules; bulk repair, automation, and notifications are separate waves.  
3. **Guided repair reuses hardened writes** — Wizards must call existing `*ManagementService` / registry mutation facades (transactions, validation, invariants) and require `platform.organizations.manage` + CSRF for POST + confirmation before apply. **Do not** invent parallel SQL or bypass audits.  
3b. **Dangerous actions use preview + reason** — High-impact founder mutations (org suspend, account on/off, access repair, support entry, branch deactivate, public kill switches) go through preview routes under `/platform-admin/safe-actions/…` with `FounderSafeActionGuardrailService` (validated reason, explicit confirmation, reversibility labeling, audit metadata). No fake undo; rollback only where the backend supports it.  
4. **Control plane only** — Founder work stays in platform-admin and related founder routes; do not mix tenant billing, backups, or unrelated product experiments into this track.  
5. **One aggregation service** — New incident logic lives in a dedicated service (`FounderIncidentCenterService`), not scattered across controllers.  
6. **Severity = impact** — Labels like critical / high / medium / low must reflect real blast radius (e.g. unknown access-shape evaluation vs. soft-deleted accounts).  
7. **Docs stay current** — After each wave, update `FOUNDER-OPS-NO-CODE-ROADMAP.md`, this file if principles change, and `FOUNDER-OPS-ACTIVE-BACKLOG.md` so the team and assistants share one source of truth.  
8. **Operator simplification does not weaken the system** — UX waves may add page-purpose copy, guides, progressive disclosure, and navigation hints (`FounderPagePurposePresenter`, in-product handbook). **Do not** remove advanced diagnostics, flatten permissions, or bypass guardrails for “simplicity.”

*Last updated: 2026-03-24 — principle 8 added; FOUNDER-OPS-OPERATOR-SIMPLIFICATION-AND-GUIDED-NAVIGATION-01 (presentation-only operator layer).*
