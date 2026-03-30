# Founder ops — no-code roadmap

> **DEFERRED UNTIL BACKBONE CLOSURE** — Not in the slim active queue; privileged security is Phase 3 in `system/docs/BACKBONE-CLOSURE-MASTER-PLAN-01.md`. Status truth: `system/docs/TASK-STATE-MATRIX.md`.

## Long-term goal

Founder and support staff can **identify, understand, and resolve most common platform incidents** using the control plane **without code changes**. The roadmap is delivered in **phases**; each phase adds persisted truth, human-readable surfaces, and safe routing — not ad-hoc scripts.

## Phase order (program plan)

### Phase A — Incident operations core

1. **Incident Center** — unified entry: what is wrong, category, severity, affected scale, where to go next. **Done** (`FOUNDER-OPS-INCIDENT-CENTER-FOUNDATION-01`, runtime-confirmed).  
2. **Impact explainer** — cause → impact → consequence → recommended action, tied to proven facts on org/branch/access surfaces and Incident Center. **Done** (`FOUNDER-OPS-IMPACT-EXPLAINER-01`, implemented in tree).  
3. **Guided repair** — stepwise flows that reuse existing hardened mutation services (CSRF, manage permission, transactions/audit where applicable). **Done** (`FOUNDER-OPS-GUIDED-REPAIR-WIZARDS-FOUNDATION-01`).  
4. **Safe guardrails** — confirmations, scope limits, and irreversible-action protections (preview + reason + audit + reversibility + post-action summary). **Done** (`FOUNDER-OPS-SAFE-ACTION-GUARDRAILS-01`).

### Phase B — Deeper operations

1. **Audit timeline** — cross-module timeline for investigations.  
2. **Data health** — expanded integrity signals beyond access-shape.  
3. **Settings / policy conflicts** — detectable misconfigurations.  
4. **Playbooks** — short operator checklists per incident class.  
5. **Automation** — only after playbooks and guardrails are trusted.

Explicitly **out of scope** for this program unless a future wave re-opens it: billing, backups, subscriptions, and unrelated tenant product features.

## Latest delivered wave

| Wave | Status | Summary |
|------|--------|---------|
| **SUPER-ADMIN-SALON-CENTRIC-REBUILD-01** | **Delivered** | Founder control plane is **salon-first**: primary nav **Salons · Billing · Problems · Platform**; default entry `/platform-admin` → `/platform-admin/salons`; unified list + `/platform-admin/salons/{id}` detail; `PlatformSalonRegistryReadRepository` + registry/detail/problems/admin-access services; legacy module nav removed from the shell. **Billing** is a placeholder shell. **Problems** (`/platform-admin/problems`) reuses Incident Center data with calmer chrome; `/platform-admin/incidents` remains available. **Platform** links to Access, Branches, Security, guide. |
| **FOUNDER-OPS-OPERATOR-SIMPLIFICATION-AND-GUIDED-NAVIGATION-01** | **Superseded (IA)** | Purpose panels + guide still exist on secondary routes; the **primary** founder experience is no longer “dashboard + module hop” — use **Salons** as home. |

**Next focus:** guardrails follow-up (optional) and live copy tuning — see `FOUNDER-OPS-ACTIVE-BACKLOG.md`.

## Completed (previous waves)

| Wave | Status | Summary |
|------|--------|---------|
| **FOUNDER-OPS-IMPACT-EXPLAINER-01** | **Done** | Reusable explainers: `FounderIncidentImpactExplainer`, `FounderImpactExplainerService`, `FounderAccessImpactExplainer`; surfaces on Incident Center, org detail, branch edit, access user detail. |
| **FOUNDER-OPS-INCIDENT-CENTER-FOUNDATION-01** | **Done (runtime-confirmed)** | `/platform-admin/incidents`; aggregates access-shape + registry; identify-and-route only. |
| **FOUNDER-OPS-GUIDED-REPAIR-WIZARDS-FOUNDATION-01** | **Done** | Blocked-user guided repair, org recovery wizard, pin shortcut; hardened services + audit. |
| **FOUNDER-OPS-SAFE-ACTION-GUARDRAILS-01** | **Done** | `/platform-admin/safe-actions/…` previews; `FounderSafeActionGuardrailService` + `FounderSafeActionPreviewService`. |

## Completed (historical)

- Founder dashboard and platform shell.  
- Access index / detail / provision; access-shape truth.  
- Global branches; organizations registry.  
- Security (public kill switches + audit visibility).  
- Support-entry foundation.  

## Next (after operator UX wave)

- Guardrails follow-up: remaining mutations (e.g. canonicalize platform principal, membership suspend) via preview pattern when agreed.  
- Phase B items only when Phase A explanation + routing are trusted in production.  

## Deferred

- Ticketing, comments, chat, workflow engines.  
- Notifications and paging.  
- Automation / bulk repair from Incident Center.  
- Global severity-engine redesign.  
- Billing, backups, subscriptions.

*Last updated: 2026-03-24 — FOUNDER-OPS-OPERATOR-SIMPLIFICATION-AND-GUIDED-NAVIGATION-01 active; Incident Center + Impact explainer completed in tree.*
