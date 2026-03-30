# Booker Settings control plane — Lane 01 charter

**Lane ID:** `BOOKER-SETTINGS-CONTROL-PLANE-LANE-01`  
**Status:** chartered — **WAVE 1 baseline documentation may exist; implementation waves start only after matrix acceptance**  
**Upstream context:** Booker reference / ZIP intake is **non-authoritative** unless reconciled to **current repo code** (see **§10 Non-drift rules**).

---

## 1. Lane purpose (mandatory)

This lane is **not** UI beautification or visual redesign. It is **backend-truth-aligned Settings / control-plane hardening**: classify what exists, remove false launcher promises, prove high-value writes, and make the operator **Settings** layer **honest**, **launcher-safe**, and **ready for downstream Booker parity lanes** — without inventing product scope.

---

## 2. Primary objectives

1. Build a **subsection-by-subsection truth matrix** from **live routes, controllers, services, views, migrations** (not from `archive/` or stale docs alone).
2. Classify every Settings subsection and settings-launched surface using **§5** vocabulary.
3. Harden the **Settings shell** (navigation, labels, disabled/pending semantics) so it does not **over-promise**.
4. Prove **high-value write contracts** that change **runtime behavior** (Wave 3).
5. **Close launch-surface ambiguity** for module launchers (spaces, material, employees, groups, users, services, packages, series, memberships, documents).
6. Hand off a **clear baseline** to the next implementation lane (no duplicate discovery).

---

## 3. In scope (subsections and surfaces)

| Area | Notes |
|------|--------|
| Establishment information | Org-default identity/contact fields |
| Opening hours | Branch weekly hours (establishment screen subtree) |
| Closure dates | Branch closure dates (establishment screen subtree) |
| Cancellation policy | KV + reasons editor |
| Appointment settings | Branch-scoped operational settings |
| Payment settings | KV |
| Custom payment methods | CRUD (table-backed) |
| Tax types | VAT rates CRUD |
| Tax allocation | VAT distribution (linked from settings directory) |
| Internal notifications | KV toggles |
| Hardware / device settings | KV |
| Security | KV (password / session timeout) |
| Marketing settings | KV (narrow: consent defaults) |
| Waitlist settings | KV |
| Online booking settings | **As implemented:** `public_channels` (online booking + intake + commerce) |
| Spaces | Launcher → services-resources rooms |
| Material (equipment) | Launcher → services-resources equipment |
| Employees (staff) | Launcher → staff module |
| Employee groups | Launcher → staff groups |
| Personnel compensation / timekeeping | Launcher → payroll module (not KV `settings`) |
| Users / connections / credentials | Tenant-level user admin vs platform admin — classify gap |
| Services | Launcher → service catalog |
| Packages | Launcher → packages module |
| Series | Launcher + appointment series API |
| Memberships | KV slice + memberships module |
| Document storage | Definitions/files API vs operator UI |

---

## 4. Out of scope (explicit)

- Visual polish, redesign, new placeholder UI, or “make it pretty”
- Marketing feature parity beyond honest labeling / gating
- Reports / BI parity (including building full HTML report suites in this lane)
- Deep memberships product rebuild
- Full sales parity, public booking redesign, unrelated frontend work
- Broad refactors (framework, ORM, cross-module rewrites)

---

## 5. Classification vocabulary (mandatory)

| Class | Meaning |
|-------|--------|
| **REAL** | Read/write paths and runtime consumers align with what the UI implies. |
| **REAL-BUT-PARTIAL** | Some wiring exists; documented gaps (enforcement, scope, or IA). |
| **READ-ONLY** | No honest write path, or writes are no-ops / misleading. |
| **UI-WITHOUT-BACKEND** | UI or nav suggests capability with no matching backend contract. |
| **LAUNCHER-PENDING** | Destination incomplete, misleading, or undefined for operators. |
| **DEFER** | Explicitly not closed in this lane; dependency recorded. |

**Rule:** Nothing is **REAL** or **done** without **code path proof** (routes, persistence, consumers).

---

## 6. Required deliverables

| # | Deliverable |
|---|-------------|
| 1 | `system/docs/BOOKER-SETTINGS-CONTROL-PLANE-TRUTH-MATRIX-01.md` |
| 2 | `system/docs/BOOKER-SETTINGS-CONTROL-PLANE-MISMATCH-AUDIT-01.md` |
| 3 | Wave closure memos (shell, write contracts, launch surfaces) — produced in later waves |
| 4 | `system/docs/BOOKER-SETTINGS-CONTROL-PLANE-LANE-01-CLOSURE.md` — end of lane |
| 5 | `system/docs/BOOKER-PARITY-MASTER-ROADMAP.md` — Lane 01 row / pointer |

---

## 7. Wave order (execution)

| Wave | Name | Intent |
|------|------|--------|
| **WAVE 0** | ZIP truth intake | Reconcile ZIP/Booker map to **current tree**; record paths; **no code changes** |
| **WAVE 1** | Truth matrix + mismatch audit | Baseline docs; **no feature fixes before acceptance** |
| **WAVE 2** | Settings shell hardening | Nav, labels, honest launchers — **no invented product UI** |
| **WAVE 3** | High-value write contracts | Prove runtime effect (tests, smoke, traces) |
| **WAVE 4** | Launch surface closure | Each launcher has a defined truth state |
| **WAVE 5** | Lane closure memo | Closure doc + roadmap status sync |

---

## 8. Definition of done (lane)

The lane is **complete** only when:

1. Truth matrix and mismatch audit are **accurate to the repo** at closure time.  
2. Shell and launch surfaces **do not advertise** false backend or “pending” states.  
3. Highest-value runtime writes are **proven** or **explicitly gated** with rationale.  
4. `BOOKER-SETTINGS-CONTROL-PLANE-LANE-01-CLOSURE.md` exists and **BOOKER-PARITY-MASTER-ROADMAP.md** reflects Lane 01 status using **§3.1 vocabulary** (`DONE` / `PARTIAL` / `OPEN` / `AUDIT-ONLY`, etc.).

---

## 9. Non-drift rules (mandatory)

1. **Live code wins:** Current `system/routes`, `system/modules`, `system/core`, and `system/data/migrations` are authoritative.  
2. **`archive/` and old reference docs** are **non-authoritative** unless a claim is **re-verified in current code**.  
3. **No repairs** in WAVE 0–1 except documentation files explicitly listed as deliverables.  
4. **No placeholder UI** — prefer remove, disable, relabel, or document **DEFER**.  
5. **No parity claims** without traced read/write + consumer proof.  
6. **Modular, minimal, verifiable** changes in WAVE 2+; one lane concern at a time.  
7. Do **not** expand scope into marketing, reports/BI, or memberships rebuild under this lane charter.

---

## 10. References (WAVE 0+)

- Locked Booker↔repo settings map (historical): `ADMIN-SETTINGS-BACKLOG-ROADMAP.md` — **verify against code before trusting**.  
- Settings read scope: `SETTINGS-READ-SCOPE.md`, `SETTINGS-PARITY-ANALYSIS-01.md`  
- ZIP / screenshot intake location: *(record during WAVE 0)*  

---

*End of charter.*
