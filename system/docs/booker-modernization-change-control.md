# Booker Modernization Change Control

## Purpose

**No implementation step may go outside the locked plan.** This protocol prevents scope drift, mixed UI/backend work, and unapproved deletions while executing Booker modernization tasks (BKM-xxx).

## Rules

1. **Always read all 3 planning files** before any future implementation step:  
   - `system/docs/booker-modernization-master-plan.md`  
   - `system/docs/booker-modernization-checklist.md`  
   - `system/docs/booker-modernization-change-control.md`

2. **Work on one task ID only** per change set (the active BKM-xxx).

3. **Never start a LOCKED task**—update checklist status only through defined transitions (e.g. prior task DONE → next READY).

4. **Never mix UI work** into backend phases (no new views, CSS, or JS unless a master-plan exception explicitly ties a minimal client contract to a backend change—and default is **no**).

5. **Never broad-refactor** unrelated code (no cleanup sweeps, renames, or style-only churn outside the files justified by the active task).

6. **Never delete code** unless that exact deletion was **justified in the master plan** (or added under “New Findings” with plan revision)—otherwise stop and report.

7. **If a new issue is discovered**, append it under **“New Findings”** at the bottom of this file (or the master plan append-only section) instead of derailing the active task.

8. **Every future implementation response must start** by stating:  
   - **Active task ID**  
   - **Why it is currently unlocked** (e.g. READY, or unblocked after dependency DONE)  
   - **What is explicitly not being touched**

9. **If scope pressure appears** (requests for UI, payments, new features, or extra refactors), **stop and report** instead of improvising.

---

## New Findings

_(Append-only. Each entry: date, finding, suggested task ID or “plan revision required”.)_

---

## Future Execution Response Format

All future implementation responses must use this **7-part** format:

1. **Scope completed** — Which BKM-xxx objectives were satisfied.  
2. **Exact files changed** — Paths only; no unrelated files.  
3. **Root cause / target gap** — What repo-proven gap was addressed.  
4. **Exact changes made** — Behavioral/summary level (no dump of entire files unless necessary).  
5. **What was intentionally not touched** — Explicit exclusions.  
6. **Risk check** — Concurrency, TZ, permissions, audit, branch rules considered.  
7. **Next locked task** — Which checklist item becomes READY and why.
