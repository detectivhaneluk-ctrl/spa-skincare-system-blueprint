# Organization-scoped client list — consumer waiver, containment, QA closure (FOUNDATION-20)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-20 — ORGANIZATION-SCOPED-CLIENT-LIST-CONSUMER-WAIVER-CONTAINMENT-QA-CLOSURE  
**Kind:** Documentation / governance / QA closure only — **no** runtime, repo, provider, controller, route, UI, schema, or enforcement implementation.

**Upstream accepted truth:** FOUNDATION-06 through **FOUNDATION-18** (implementation through **`ClientRepository::list` / `count`**); **FOUNDATION-19** (provider surface + five consumers + inheritance proof).

**Companion:** `CLIENT-LIST-PROVIDER-MANUAL-SMOKE-MATRIX-FOUNDATION-20.md` — repeatable manual smoke for ZIP/runtime review.

---

## 1) What this wave closes (and what it does not)

**Closed in the minimal foundation stream (client list org-scope at provider consumers):**

- **Technical org predicate** for provider-backed lists is **exclusively** in **`ClientRepository::list`** (F-18). **`ClientListProviderImpl`** remains a thin delegate (F-19).
- **Cross-module consumer inventory**, route/middleware class, nullable-branch and in-org-wide list semantics are **documented** and **accepted** as the **foundation baseline** pending **product** decisions on stricter branch UX.
- **QA obligation** for these five surfaces is **explicit** (smoke matrix); absence of executed smoke in this wave does **not** block doc closure — **ZIP/runtime review** executes the matrix.

**Explicitly not closed here (moved out of this foundation stream):**

- **Unresolved organization** on staff requests → **legacy unscoped** `list` (empty org fragment per **`OrganizationRepositoryScope`**). Addressing that belongs to **organization-context / middleware** programs, not this client-list consumer waiver.
- **Branch pinning**, **dropdown cardinality tightening**, **provider signature** changes, **controller guards** — **product / controller-specific** workstreams only.
- **`ClientRepository` paths not using `list`** (e.g. duplicates, public locks) — unchanged F-15/F-17 deferrals.
- **Staff `ClientController::index`** smoke remains per **F-18 ops**; F-20 matrix **adds** provider-consumer surfaces only.

---

## 2) Waiver / containment / QA truth — per consumer

**Shared technical baseline (when `resolvedOrganizationId()` is non-null):**

- Provider call → **`ClientRepository::list`** with optional **`branch_id`** filter + org **EXISTS** on **`c.branch_id`**.
- **`branch_id` NULL** on client rows → **excluded** from results.
- Provider argument **`null`** → **no** `branch_id` filter → up to **500** clients **across all branches in the resolved organization** (still **no** cross-org).

**Shared caveat (when org context does not resolve):**

- **No** org fragment → **pre–F-18-style** global list behavior for these dropdowns — **not** claimed as org-isolated (same as F-18/F-19).

| Consumer | Exact screen / action path (HTTP) | Code paths using `ClientListProvider` | `null` branch input possible? | Cross-branch in-org dropdown possible? | Acceptable as minimal org-scoped baseline? | Future action bucket |
|----------|-----------------------------------|----------------------------------------|-------------------------------|----------------------------------------|--------------------------------------------|----------------------|
| **InvoiceController** | **Create invoice:** `GET /sales/invoices/create` (permission `sales.create`). **Edit invoice:** `GET /sales/invoices/{id}/edit` (`sales.edit`). **Re-render:** failed `POST /sales/invoices` → `renderCreateForm`; failed `POST /sales/invoices/{id}` → `renderEditForm`. | `create`, `edit`, `renderCreateForm`, `renderEditForm` | **Yes** — no context branch, no prefill/`branch_id`, or **invoice `branch_id` null** on edit/re-render. | **Yes** whenever provider receives **`null`** and org resolves. | **Yes** for **org isolation** (no foreign-org clients in list). **Product acceptance** recommended for HQ / null-invoice-branch and **membership staff checkout** pairing with **`ClientRepository::find`** (same org fragment, different filters). | **QA ONLY** (confirm lists and saves). **PRODUCT ACCEPTANCE / WAIVER** for multi-branch HQ UX. **FUTURE CONTROLLER-SPECIFIC CONTAINMENT** if product requires per-branch-only pickers or stricter branch on draft invoice. |
| **AppointmentController** | **New appointment:** `GET /appointments/create` (`appointments.create`). **Edit:** `GET /appointments/{id}/edit` (`appointments.edit`). **Waitlist add:** `GET /appointments/waitlist/create` (`appointments.create`). **Re-render:** failed create/update → `renderCreateForm` / `renderEditForm`. | `create`, `edit`, `waitlistCreate`, `renderCreateForm`, `renderEditForm` | **Yes** — `queryBranchId()` is **only** `BranchContext::getCurrentBranchId()`; **null** when session has no current branch. | **Yes** when branch context **null** and org resolves. | **Yes** for org isolation. **Product acceptance** for “operator with no branch sees all in-org clients (capped 500)” vs expectation of forced branch first. | **QA ONLY**. **PRODUCT ACCEPTANCE / WAIVER** for null-context behavior. **FUTURE CONTROLLER-SPECIFIC CONTAINMENT** if product mandates branch-before-client-picker. |
| **GiftCardController** | **Issue:** `GET /gift-cards/issue` (`gift_cards.create`). **Re-render:** failed `POST /gift-cards/issue` (`gift_cards.issue`). | `issue`, `storeIssue` (error branches) | **Yes** — context/GGET branch absent; validation re-render uses **`$data['branch_id'] ?? null`**. | **Yes** when provider **`null`** and org resolves. | **Yes** for org isolation. | **QA ONLY** (happy path + validation re-render). **PRODUCT ACCEPTANCE / WAIVER** optional if HQ issues cards without pinning branch. **FUTURE CONTROLLER-SPECIFIC CONTAINMENT** if issue form must always scope list to selected branch before submit. |
| **ClientPackageController** | **Assign package:** `GET /packages/client-packages/assign` (`packages.assign`). **Re-render:** failed `POST /packages/client-packages/assign`. | `assign`, `storeAssign` (error branches) | **Yes** — same pattern as gift cards (context/GGET; POST re-render uses submitted branch, which may be empty depending on parse). | **Yes** when provider **`null`** and org resolves. | **Yes** for org isolation. | **QA ONLY**. **PRODUCT ACCEPTANCE / WAIVER** optional. **FUTURE CONTROLLER-SPECIFIC CONTAINMENT** if assign must lock list to chosen branch earlier in flow. |
| **ClientMembershipController** | **Assign membership:** `GET /memberships/client-memberships/assign` and failed `POST …/assign` (`memberships.manage`) → `renderAssignForm`. | `renderAssignForm` only | **Yes** — HQ path: no `BranchContext`, no `assign_branch_id`, no selected client → **`listBranchId` null** (see `resolveAssignListScope`). | **Yes** in that HQ scenario when org resolves. | **Yes** for org isolation. **Explicit product waiver** recommended: HQ assign UI + **`ClientRepository::find`** for scope resolution (F-19 coupling). | **QA ONLY** + **PRODUCT ACCEPTANCE / WAIVER** (HQ assign semantics). **FUTURE CONTROLLER-SPECIFIC CONTAINMENT** for stricter branch/client pinning on assign. |

---

## 3) Required questions — explicit answers

**A) For each consumer, is the current post-F-18/F-19 behavior acceptable as a minimal org-scoped baseline?**

- **Yes**, when read as: **with resolved org**, lists **cannot** include clients whose **`branch_id`** is outside the resolved organization, and **NULL-branch** clients **do not** appear — matching the **F-18** repository contract.
- **Caveat (not a foundation defect):** **Nullable provider branch** still allows **in-org, cross-branch** lists (cap 500). That is **acceptable as the foundation baseline** until **product** demands stricter UX; it is **not** the same as cross-org leakage.

**B) Which consumers need only QA confirmation versus explicit product waiver?**

- **QA only (minimum):** **AppointmentController**, **GiftCardController**, **ClientPackageController** — confirm expected options under resolved org + typical branch context; document anomalies.
- **QA + explicit product acceptance / waiver (recommended):** **InvoiceController** (null invoice branch, membership checkout pairing), **ClientMembershipController** (HQ **`resolveAssignListScope`** null list branch + `find` coupling).

**C) Which consumers, if ever changed later, must be handled as separate controller/product work rather than foundation org-scope work?**

- **All five.** Any change to **when** `list(null)` vs `list($branchId)` is used, **provider contract**, or **dropdown messaging** is **controller/product** scope — **not** a repeat of the **F-16/F-18** repository foundation wave.

**D) Can the current foundation client-list org-scope stream be considered closed after this wave?**

- **Yes**, for the **defined minimal stream**: **F-16** (`find`/`findForUpdate`) + **F-17** audit + **F-18** (`list`/`count` SQL) + **F-19** (provider inheritance) + **F-20** (this waiver/QA/smoke closure). **Reason:** No further **foundation** artifact is required for **org SQL on `ClientRepository::list`** or **provider delegation**; remaining risk is **operational/product** (smoke matrix execution, optional waivers), and **optional future containment** is **explicitly out of scope** here.

---

## 4) Closure recommendation (client-list org-scope layer)

| Layer | Status |
|-------|--------|
| **`ClientRepository::list` / `count`** (F-18) | **Canonical org-scope enforcement** for list/count SQL when org resolves. |
| **`ClientListProvider` / `ClientListProviderImpl`** | **No additional org logic** — closure = **inheritance acknowledged** + **consumer QA/waiver** (F-20). |
| **Five staff controllers** | **Contained** by documentation + **manual smoke matrix**; **not** subject to further **foundation** waves unless **product** reopens scope. |

---

## 5) Items intentionally unchanged this wave

No edits to PHP, routes, verifiers, or SQL; no new **read-only** verifier (smoke matrix is the proof vehicle for these surfaces).

---

## 6) Checkpoint readiness

FOUNDATION-20 **documentation closure** is **complete**. **Runtime/ZIP readiness** requires executing **`CLIENT-LIST-PROVIDER-MANUAL-SMOKE-MATRIX-FOUNDATION-20.md`** and recording results outside this repo artifact if governance requires evidence.
