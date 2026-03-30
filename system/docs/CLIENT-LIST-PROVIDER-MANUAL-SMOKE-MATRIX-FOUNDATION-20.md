# Client list provider — manual smoke matrix (FOUNDATION-20)

**Purpose:** Repeatable **staff** manual checks for **`ClientListProvider`**-backed client dropdowns after **F-18** / **F-19** / **F-20** closure.  
**Not executed** by this wave — for **ZIP / runtime / QA** review.  
**Primary governance:** `ORGANIZATION-SCOPED-CLIENT-LIST-CONSUMER-WAIVER-CONTAINMENT-QA-FOUNDATION-20-OPS.md`.

---

## 0) Prerequisites

| # | Requirement |
|---|-------------|
| P1 | Staff user with permissions listed per row (see §2). |
| P2 | **Resolved organization** on the request path under test (typical authenticated staff session after F-09 middleware). For **multi-org** fixtures, confirm session resolves the **intended** org. |
| P3 | Optional but valuable: **two or more branches** in the same organization + clients pinned to **different** branches + at least one client with **`branch_id` NULL** (if still present in data) to observe F-18 **NULL-branch exclusion**. |
| P4 | No automation in this document — browser or HTTP client with session cookie. |

**Out of scope for this matrix:** Proving behavior when **`resolvedOrganizationId()`** is **null** (legacy global list). If tested, record separately; **not** part of F-20 org-isolation claim.

---

## 1) Global expectations (when org resolves)

For **every** row in §2, the tester should confirm:

| Check | Pass criterion |
|-------|----------------|
| G1 | Client dropdown contains **no** client whose **`branch_id`** belongs to a **different** organization than the resolved org (requires multi-org fixture or DB cross-check on sample IDs). |
| G2 | No client with **`branch_id` IS NULL** appears in the dropdown (F-18 fail-closed for list). |
| G3 | Dropdown size **≤ 500** (provider limit). |
| G4 | If the tested screen passes **non-null** `branchId` into the provider (e.g. current branch context), every shown client’s **`branch_id`** matches that branch **when** clients are branch-attached (org clause still applies). |

---

## 2) Per-consumer smoke rows

Execute in any order. **Result:** Pass / Fail / N/A (fixture missing). **Notes:** free text (e.g. “HQ null branch — saw in-org branches A+B”).

| ID | Consumer | HTTP (primary) | Permission(s) | Setup hint | Focus |
|----|----------|----------------|---------------|------------|--------|
| S1 | InvoiceController | `GET /sales/invoices/create` | `sales.create` | With **branch context** set vs **cleared** (if your product allows) | Compare dropdown width: single-branch vs possible **in-org multi-branch** when context null |
| S2 | InvoiceController | `GET /sales/invoices/{id}/edit` (draft/open/partial invoice) | `sales.edit` | Invoice with **null** `branch_id` vs **set** `branch_id` | **G1–G3**; edit form client list matches repo scope |
| S3 | InvoiceController | `POST /sales/invoices` with validation error (e.g. empty line items) | `sales.create` | Force `renderCreateForm` | Dropdown still loads; **G1–G3** |
| S4 | InvoiceController | `POST /sales/invoices/{id}` with validation error | `sales.edit` | Same as S2 | Re-render path |
| S5 | AppointmentController | `GET /appointments/create` | `appointments.create` | Branch context **set** vs **null** | **G1–G3**; null context → possible cross-branch in-org list |
| S6 | AppointmentController | `GET /appointments/{id}/edit` | `appointments.edit` | Appointment in known branch | **G1–G4** |
| S7 | AppointmentController | `GET /appointments/waitlist/create` | `appointments.create` | Same branch context variants as S5 | **G1–G3** |
| S8 | AppointmentController | `POST /appointments` or create-path POST with validation error | `appointments.create` | Force `renderCreateForm` | **G1–G3** |
| S9 | AppointmentController | `POST /appointments/{id}` with validation error | `appointments.edit` | Force `renderEditForm` | **G1–G3** |
| S10 | GiftCardController | `GET /gift-cards/issue` | `gift_cards.create` | Branch context / GET `branch_id` variants | **G1–G3** |
| S11 | GiftCardController | `POST /gift-cards/issue` with validation error (e.g. bad amount) | `gift_cards.issue` | Force re-render | **`list($data['branch_id'] ?? null)`** path |
| S12 | ClientPackageController | `GET /packages/client-packages/assign` | `packages.assign` | Branch context variants | **G1–G3** |
| S13 | ClientPackageController | `POST /packages/client-packages/assign` with validation error | `packages.assign` | Force re-render | **G1–G3** |
| S14 | ClientMembershipController | `GET /memberships/client-memberships/assign` | `memberships.manage` | Operator **with** branch context | **G1–G4** |
| S15 | ClientMembershipController | `GET /memberships/client-memberships/assign` (HQ: no branch context, no assign_branch, no client) | `memberships.manage` | If role allows | **In-org wide** list — **product waiver** candidate |
| S16 | ClientMembershipController | `POST /memberships/client-memberships/assign` with validation error | `memberships.manage` | Missing client or plan | `renderAssignForm` + scope resolution |

---

## 3) Optional cross-checks (not required for doc closure)

| ID | Check |
|----|--------|
| O1 | After choosing a client from dropdown, complete save where applicable; confirm **`ClientRepository::find`** (or service) does not contradict list visibility for same org (invoice membership path uses **find** — note mismatches in Notes). |
| O2 | Compare **`GET /clients`** (direct repo list) vs one dropdown under same session — both should respect **same org** predicate when org resolves (different filters/limits allowed). |

---

## 4) Sign-off block (copy for ZIP evidence)

| Field | Value |
|-------|--------|
| Build / commit / ZIP id | |
| Tester | |
| Date | |
| S1–S16 summary (Pass/Fail/N/A) | |
| Multi-org fixture used? (Y/N) | |
| Product waivers referenced (F-20 ops) | |
