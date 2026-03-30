# Deferred and historical task registry — 01

**Date:** 2026-03-29  
**Purpose:** Classify work that must **not** sit in the **active** backbone queue during Backbone Closure Mode.  
**Active spine:** `BACKBONE-CLOSURE-MASTER-PLAN-01.md` · **Status truth:** `TASK-STATE-MATRIX.md` · **Slim queue:** `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`.

Entries are **not** deleted from the repository; source docs remain for evidence. This registry is the **routing table** for “later / not now.”

---

## Backbone Phase 1 — matrix inventory (not LIVE until charter promotion)

The following are **not** rows in `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` after **ACTIVE-SPINE-TIGHTENING-02**. They remain **true** in `TASK-STATE-MATRIX.md` and relevant charters/matrices. **Promote** one ID at a time into the charter **LIVE** slot only when **`PLT-TNT-01`** is done or policy replaces it.

| ID / theme | Where truth lives |
|------------|-------------------|
| **PLT-LC-01** — lifecycle + suspension end-to-end | `TASK-STATE-MATRIX.md` (**REOPENED**); Phase 1 scope in `BACKBONE-CLOSURE-MASTER-PLAN-01.md` |
| **FND-TNT-05** — org predicates / commerce correlation remainder | `TASK-STATE-MATRIX.md` (**OPEN**); `TENANT-SAFETY-INVENTORY-CHARTER-01.md` |
| **FND-TNT-06** — residual read paths (F-12 / client surfaces) | `TASK-STATE-MATRIX.md` (**PARTIAL** / **OPEN**) |
| **INVENTORY-TENANT-DATA-PLANE-HARDENING-01** | `TASK-STATE-MATRIX.md` (**PARTIAL**); `INVENTORY-TENANT-DATA-PLANE-HARDENING-01-MATRIX.md` |
| **MEMBERSHIPS-GIFTCARDS-PACKAGES-TENANT-DATA-PLANE-HARDENING-01** | `TASK-STATE-MATRIX.md` (**OPEN**); `MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01-MATRIX.md` |
| **PLT-DB-01** — selective integrity / FK remainder | `TASK-STATE-MATRIX.md` (**OPEN**); promote only if a slice **blocks** tenant closure and charter rotates LIVE |

---

## Classification vocabulary

| Label | Meaning |
|-------|--------|
| **DEFERRED** | Valid future work; **frozen** until backbone phases complete (or until matrix + master plan explicitly reprioritize). |
| **HISTORICAL** | Accurate past narrative, audit, or completed wave log; **not** the current execution queue. |
| **OBSOLETE** | Superseded by a newer doc or charter; kept only to avoid silent deletion of evidence. |

---

## DEFERRED (product, parity, polish, growth)

| Item / doc cluster | Source pointers | Reason |
|--------------------|-----------------|--------|
| Booker parity **§5.C** product macro-phases (catalog, mixed sales, storefront, intake expansion, etc.) | `BOOKER-PARITY-MASTER-ROADMAP.md` | **Backend backbone** must close first; product queue competes with tenant/async/privileged/runtime proof. |
| Booker modernization backend parity backlog (calendar CRM, DnD contracts, etc.) | `booker-modernization-master-plan.md`, checklists | Same — **DEFERRED** until backbone closure; not deleted. |
| Admin/settings **expansion** beyond locked pure-settings map (new pages, shell parity waves, VAT/report polish) | `ADMIN-SETTINGS-BACKLOG-ROADMAP.md` | Pure settings map **CLOSED**; further expansion is **not** Phase 1–5 backbone. |
| Founder ops **guardrails extension**, operator copy tuning, playbooks, Phase B ops | `FOUNDER-OPS-ACTIVE-BACKLOG.md`, `FOUNDER-OPS-NO-CODE-ROADMAP.md` | **Privileged security** is **Phase 3**; ops polish is **not** active until backbone allows. |
| **FND-PERF-03** — invoice sequence partitioning / per-tenant sequences | `INVOICE-SEQUENCE-HOTSPOT-CONTRACT-AND-HARDENING-PLAN-01.md`, former `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` row | **Non-critical performance** vs integrity; defer past tenant closure. |
| **FND-CTX-01** — expand request-scope caching | `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` (PROVISIONAL) | Profiling-driven optimization; **not** backbone closure. |
| **§6.2** package/subscription **enforcement engine** (**PH3-PKG-01**), full commercial packaging narrative | `BOOKER-PARITY-MASTER-ROADMAP.md` §6.2, historical reconciliation lists | **Growth / SaaS packaging** — after runtime and portability foundations. |
| Full **onboarding/offboarding lifecycle engine** (product depth) | Matrix **OPEN** “full provision/suspend/archive/purge” | Distinct from **Phase 5** hygiene; defer as product/platform program until chartered. |
| **SMS** as operational channel parity | `TASK-STATE-MATRIX.md` (**OPEN**) | Transport completeness — **Phase 4** or later explicit charter; not Phase 1. |
| **Generalized** feature flags / canary / per-tenant rollout | Matrix **OPEN** vs **PARTIAL** kill switches | Platform product; **Phase 4** resilience bucket. |
| Load/stress strategy, backup/restore/DR runbooks, DB scaling narrative | Matrix default **OPEN** | **Phase 4** unless blocking Phase 1 proof (document exception in matrix). |
| **OUT-OF-SCOPE-MODULE-SCOPE-MATRIX** follow-on **implementation** (reports, documents, notifications, intake, payroll, etc.) | `OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01*` | Matrix treats execution as **AUDIT-ONLY**; domain expansion **DEFERRED** behind backbone. |
| **PLT-INFRA-01** umbrella (custom stack hardening narrative) | `BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` | Consolidate into **Phase 4** concrete IDs when active; not parallel spine. |

---

## HISTORICAL (truth preserved; do not execute from these as “current”)

| Item | Source pointers | Reason |
|------|-----------------|--------|
| Backend hardening **waves 1–6** sequencing (FOUNDATION-100 through automated proof layer narrative) | `BACKEND-HARDENING-WAVE-ROADMAP.md` | Superseded by **backbone phase map**; waves remain factual history. |
| **BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01** §B numbered order | `BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` | Replaced by phase alignment in `BACKBONE-CLOSURE-MASTER-PLAN-01.md`; content still useful as **ID inventory**. |
| Booker parity **§4** phase tables (“reference labels”) | `BOOKER-PARITY-MASTER-ROADMAP.md` §4 | Explicitly historical labels; active queue was §5.C — now **DEFERRED** entirely for execution. |
| Foundation truth reconciliation memos | `FOUNDATION-TRUTH-RECONCILIATION-MEMO-01.md`, `FOUNDATION-TRUTH-RECONCILIATION-ADDENDUM-02.md` | Audit snapshots; matrix + this registry subsume **execution** routing. |

---

## OBSOLETE (superseded; zero unique execution order)

| Item | Source pointers | Reason |
|------|-----------------|--------|
| *(none identified as exact duplicate with zero unique evidence)* | — | Per charter: **do not delete** ambiguous docs; use **HISTORICAL** instead. |

---

## Maintenance

When backbone phases advance, **move** items from **DEFERRED** into `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` only when the master plan **activates** that phase — never duplicate full roadmaps in the active charter.
