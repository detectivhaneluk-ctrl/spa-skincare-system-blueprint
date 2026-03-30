# POST–ORGANIZATION-CONTEXT PROGRAM EXIT — NEXT MAJOR BACKEND LANE SELECTION TRUTH AUDIT (FOUNDATION-78)

**Mode:** Read-only selection audit. **No** code, schema, routes, or reopening **F-64**, **F-68**, **F-72**, **F-77** unless contradicted by **`BOOKER-PARITY-MASTER-ROADMAP.md`** (**none** found).

**Evidence read:** **`BOOKER-PARITY-MASTER-ROADMAP.md`** — **§1** executive direction, **§5.C** master execution queue (Phases **1–5**), **§5.D** hold list, **§8** tail (**F-46–F-77**); **`ORGANIZATION-SCOPED-BRANCH-ASSERT-ERROR-SURFACE-PARITY-NEXT-PROGRAM-SELECTION-TRUTH-AUDIT-FOUNDATION-77-OPS.md`** (pointer only).

---

## Verdict

**A** — The **product** queue (**§5.C**) yields **one** **next major lane** with a **read-only** first wave; waivers are explicit (**§10**).

---

## 1. Organization-context area — closed vs deferred (from roadmap + F-77)

| State | Programs / docs | Why not reopen casually |
|-------|-----------------|-------------------------|
| **Closed with waivers** | **F-64** membership/runtime lane; **F-68** resolver-only **`HttpErrorHandler` 403** whitelist; **F-72** repo-scope **documentation** closure | Charters are **explicit**; reopen needs **named** task |
| **Documented / audited through F-76** | **F-74** assert consumer inventory; **F-75** assert vs **F-68** doc; **F-76** closure audit | **Executable** facts frozen unless new charter |
| **Explicit deferral** | **F-77** — **`NONE (EXPLICIT DEFERRAL)`** for assert/**`HttpErrorHandler` parity** | Touches **F-68** successor policy; **no** mandatory follow-up |

**Exit posture:** The organization-context **audit/documentation chain** (**F-69→F-77**) is **complete** for its stated goals; **F-77** records **no** mandatory backend wave for assert error-surface parity.

---

## 2. Contradiction check (F-46–F-77)

No **in-tree** requirement found that **forces** immediate reopen of **F-46–F-77** from this read-only audit. **§8** rows are **append-only history**; **§5.C** remains the **active** product queue.

---

## 3. Major candidate lanes (after org-context exit), grouped by risk / dependency

| Lane | Risk / coupling | vs **§5.C** |
|------|-----------------|-------------|
| **A — §5.C Phase 3 — Native catalog** (3.2→3.3→3.4 remaining depth) | **Medium** schema/product surface; **depends** on **1.5** gate (**shipped**) | **Aligned** — macro-phase order |
| **B — §5.C Phase 4 — Mixed sales write path** (**4.1** implementation) | **High** — invoice line domain; **depends** on **3.4** + **2.2** per roadmap | **Premature** until Phase **3** dependencies met |
| **C — §5.C Phase 5 — Storefront exposure** | **High** — public API + catalog truth | **After 4.1** per roadmap |
| **D — Phase 2 residual** (**INVENTORY-OPERATIONAL-DEPTH-01** service consumption, reports) | **Medium–high** product design gap (roadmap: “service-consumption product design”) | **Defer** until product defines stored facts |
| **E — Outbound SMS / ESP** (**§5.C Phase 2** item **1.2** remainder) | **Ops/provider** coupling | **Valid** but **not** the **sequential** next **catalog** macro-phase |
| **F — Membership / payroll / marketing depth** | Cross-domain money/comms | **§5.D** / later phases per roadmap |
| **G — Further org-context (assert 403, repo SQL parity)** | **F-77** rejected | **Deferred** |

---

## 4. Exactly one recommended next major backend lane

**Lane name:** **`§5.C — Phase 3 (Native catalog / commerce foundations)`**

**First concrete backlog unit (sequential):** **`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01`** — **§5.C** table row **3.2** (`BOOKER-PARITY-MASTER-ROADMAP.md` **§5.C** Phase **3**).

**Roadmap text (abridged):** Evolve **`services`** as the **catalog row** (visibility/status, pricing/duration/buffer fields as needed, branch/staff applicability hooks, richer content fields, **future product linkage points**) — **one coherent model pass** to avoid second redesign; pointers to **`022_create_services_table.sql`** + **`ServiceService`**.

**Why this lane:** **§5.C** declares **3.1 → 3.2 → 3.3 → 3.4** **sequential** to avoid splitting migrations (**§5.C** Phase **3** dependency note). Row **3.1** (**SERVICE-CATEGORY-HIERARCHY-FOUNDATION-01**) is marked **shipped** as part of **CATALOG-CANONICAL-FOUNDATION-01**; **3.2** is therefore the **next ordered** Phase **3** task. **§1** executive direction prioritizes **native in-house catalog** before **mixed sales** and **storefront**.

---

## 5. Why rejected lanes wait

| Lane | Reason |
|------|--------|
| **Phase 4 mixed-sales implementation** | Roadmap: **after 3.4** and **2.2** (**§5.C** Phase **4** dependencies) |
| **Phase 5 storefront** | **After 4.1** |
| **Org-context assert / repo parity** | **F-77** **NONE** deferral |
| **Membership billing / payroll / marketing journeys** | Different **§5.C** phases / **§5.D** |
| **Inventory service-consumption** | Roadmap flags **product design** gap first |
| **SMS/ESP** | Provider/ops scope; not the **catalog** macro-phase head |

---

## 6. First phase of the recommended lane

**Read-only truth audit** — inventory current **`services`** schema usage, **`ServiceService`**, staff/admin HTTP surfaces, and report/payroll consumers **before** any migration or field expansion. Matches **“one coherent model pass”** intent without skipping discovery.

---

## 7. Minimal boundary (first wave)

- **In scope:** Read-only docs + optional **`system/docs/*-FOUNDATION-79-*`** or similarly named **ops** artifact when tasked; grep/read **`ServiceService`**, **`ServiceRepository`**, **`services`**, admin **`ServiceController`**, VAT/branch validators already referenced in roadmap **§5.C** / **§8**.
- **Out of scope:** **F-64** membership lane, **F-68** **`HttpErrorHandler`** body, **F-72** **`OrganizationRepositoryScope`** SQL, **F-25**, **resolver** precedence edits, **new** org-registry HTTP, **mixed invoice line** write path (**4.1** implementation).

---

## 8. Surfaces that must stay untouched until a later chartered wave

- **`OrganizationContextResolver`**, **F-25**, **`HttpErrorHandler::isResolverOrganizationResolutionDomainException`** list
- **`OrganizationRepositoryScope`** executable SQL (**F-72** posture)
- **Membership** backfill / **user_organization_memberships** write paths (**F-64** charter)
- **Mixed-sales** **`InvoiceService`** line architecture **implementation** (**4.1** until deps met)

---

## 9. Alignment with F-77 (no drift)

**F-77** does **not** block **§5.C Phase 3**; it only defers **optional** assert/**HTTP** parity. **Service catalog** work proceeds under **product** queue, not organization-context closure docs.

---

## 10. Waivers / risks

| ID | Waiver |
|----|--------|
| **W-78-1** | **3.2** field and visibility choices are **product-dependent**; a read-only audit must **not** fix schema without an explicit **implementation** charter after audit acceptance. |
| **W-78-2** | **Payroll/report** consumers of **`services`** / hierarchy must be **cross-checked** during audit (roadmap **3.1** row already notes “confirm payroll/report consumers against hierarchy”). |
| **W-78-3** | **§5.C** is **canonical** over **§8** “next step” historical cells — **§8** line **542** / **510** point to **§5.C** when wording conflicts. |

---

## 11. STOP

**FOUNDATION-78** ends here. **FOUNDATION-79** is **not** opened (first **3.2** read-only audit wave may be named when tasked).

**Deliverables:** this OPS; **`POST-ORGANIZATION-CONTEXT-NEXT-MAJOR-BACKEND-LANE-SURFACE-MATRIX-FOUNDATION-78.md`**; **§8** row; checkpoint ZIP.
