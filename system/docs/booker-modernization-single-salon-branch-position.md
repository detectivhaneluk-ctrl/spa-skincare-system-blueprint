# Single-salon branch position (BKM-010)

**Status:** Normative for deploy/ops; **no code or schema change** in BKM-010.  
**Related:** `booker-modernization-master-plan.md` §6 item 8, `booker-modernization-booking-concurrency-contract.md` (branch in writes and §11/§12/§13).

---

## 1. Repo-proven decision (executive)

**Keep the current `branches` + nullable `branch_id` + `BranchContext` foundation.** The codebase is **deeply coupled** to branch-scoped data and request context: schema carries `branch_id` (and FKs to `branches`) across appointments, clients, staff, services, rooms, inventory, sales, documents, notifications, settings, waitlist, blocked slots, staff availability exceptions, etc. (see `system/data/full_project_schema.sql`). As of BKM-010, **38** files under `system/modules/` reference `assertBranchMatch` or `enforceBranchOnCreate` (ripgrep). Booking concurrency and availability logic **filter or match** `branch_id` on writes and reads (see contract W1–W6, W3 public book, §7 move rules, §11 TZ, §12 exceptions, §13 day calendar JSON).

For a **single physical salon**, the supported operational model is: **one row in `branches`**, users scoped to that branch (or branch context resolved from session/request per `BranchContextMiddleware`), and **no** requirement to delete multi-branch *code* or *columns*. Removing `branch_id` or middleware **without** a separate approved migration and dependency audit is **unsafe** and **out of scope** for BKM-010 (per checklist).

---

## 2. Classification (proof-based)

### 2.1 Required to keep (even for one salon)

| Area | Evidence |
|------|----------|
| **`branches` table + FKs** | `system/data/full_project_schema.sql`: pervasive `branch_id` + `FOREIGN KEY … REFERENCES branches(id)` on core domain tables. |
| **`BranchContext` + middleware** | `system/core/branch/BranchContext.php`; `system/core/middleware/BranchContextMiddleware.php` — resolves branch from user/session/`branch_id` query or POST; enforces match on mutating operations. |
| **Booking / availability** | `AvailabilityService` and `AppointmentService` use `branch_id` in overlap and slot paths; contract documents `branch_id` in scheduling mutations (§7). |
| **Settings** | `settings` table uses `(key, branch_id)` uniqueness (`branch_id` default `0` for global); establishment and appointment policies are branch-aware at storage level (BKM-005 notes global TZ resolution vs per-row potential). |
| **Public booking** | W3 passes explicit `branchId` into locked insert pipeline. |
| **BKM-006 exceptions** | `staff_availability_exceptions.branch_id` + repository branch filter pattern. |

### 2.2 Currently harmless overhead (single salon)

- UI or forms that expose **branch dropdowns** when only one branch exists (cosmetic / UX; not backend proof of dead code).
- **Nullable `branch_id` on rows** interpreted as “global” in many queries (`OR branch_id IS NULL`) — allows legacy or cross-scope rows; single-salon deploys can still normalize data to one branch without removing columns.
- **Per-branch settings overrides** stored but unused if only `branch_id = 0` rows are maintained — storage overhead only.

### 2.3 Simplifiable later (separate tasks only; risk)

- **Hide or default branch** in admin UI when `COUNT(branches) = 1` (frontend/product; no schema change).
- **Stricter invariant** “all rows must have non-null `branch_id` for single deploy” — would require data migration + query audit (high touch).
- **Remove `branches` table** — would break FK graph and **all** branch-aware queries; **not** proposed; would need full replacement design.

### 2.4 Unsafe to remove without proof (deep coupling)

- **`BranchContextMiddleware`** global pipeline (`Dispatcher`); removing it breaks `assertBranchMatch` / `enforceBranchOnCreate` assumptions for branch-scoped users.
- **`branch_id` columns** on `appointments`, `clients`, `staff`, `services`, invoices, etc. — indexed and used in WHERE clauses throughout repositories.
- **Booking contract** explicitly names `branch_id` in write inventory and move semantics — see `booker-modernization-booking-concurrency-contract.md`.

---

## 3. Operational playbook (single location)

1. Ensure **at least one** `branches` row exists (baseline seeder e.g. `009_insert_default_branch.sql` in migrations history).
2. Assign **users** to that branch via `users.branch_id` where branch-scoped access is required; `BranchContextMiddleware` will set context accordingly.
3. Prefer **consistent** `branch_id` on new domain rows (create paths use `enforceBranchOnCreate` when context is set).
4. Do **not** treat “single salon” as permission to drop branch columns or FKs — use a **future**, explicitly scoped **schema simplification** task with full grep and migration plan if ever required.

---

## 4. Conclusion

**Position:** **Keep the current branch foundation.** Single-salon deployments should **standardize on one branch id** operationally, not remove branch infrastructure. Optional UX simplifications and stricter data invariants are **future, separate** work with their own risk sign-off.

---

## 5. Document history

| Date | Change |
|------|--------|
| (BKM-010) | Initial repo-evidence position; checklist closure of Booker modernization track BKM-001–BKM-010. |
