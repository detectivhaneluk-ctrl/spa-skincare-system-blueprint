# Staff HTTP org resolution — exception / waiver map (FOUNDATION-23)

**Companion:** `STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-DECISION-CLOSURE-FOUNDATION-23-OPS.md`

**Purpose:** Classify flows that **must not** be broken blindly by a **universal** FOUNDATION-24 post-auth gate without **explicit** product/program handling (allowlist, separate wave, or operational process).

---

## 1) Exception bucket definitions

| Bucket ID | Name | Meaning |
|-----------|------|---------|
| **E1** | **Cross-org HQ / super-admin tooling** | Product requires **intentional** visibility across organizations on **specific** routes. |
| **E2** | **Domain global reports** | Marketing, payroll, or other modules need **enumerated** “all org” or “no branch” report entrypoints. |
| **E3** | **Stranded session (inactive assigned branch)** | `BranchContextMiddleware` sets **`allowedBranchIds = []`**; user cannot select branch until **data** fixed. |
| **E4** | **Degenerate org data** | Zero active organizations — **not** a normal multi-tenant state. |
| **E5** | **Future org pivot (session org id)** | Product adds **explicit** organization selection **separate** from branch; gate rules must be **updated** to treat that as resolved. |
| **E6** | **Single-org deployment** | **Not** an exception — baseline **already** resolves org via fallback; gate must **skip** or **no-op** appropriately. |

---

## 2) Foundation treatment per bucket

| Bucket | Blanket F-24 gate without allowlist | Required treatment |
|--------|-------------------------------------|-------------------|
| **E1** | **Unsafe** — may block legitimate product flows | **Product list** of paths + **allowlist** or **separate** audited read-only controllers; **per-route** waiver in ops doc. |
| **E2** | **Unsafe** — may block reports | Same as E1 **or** **dedicated** report wave with **read** scoping audit (not F-24 minimal slice). |
| **E3** | **Acceptable block** if message clear **or** **unsafe** if user cannot recover | **Product:** admin resets `users.branch_id` or reactivates branch; optional **login-time** message spec. |
| **E4** | Block is **acceptable** | **Ops:** fix data / migrations; not a waiver for production multi-org. |
| **E5** | N/A until built | **Amend** F-24 acceptance gates when pivot exists. |
| **E6** | Gate must **not** add friction | **Implement** `active_org_count > 1` condition **exactly** (see closure doc). |

---

## 3) Initial route / domain candidates (inventory placeholders)

**Not exhaustive** — product must confirm before F-24 allowlists:

| Domain | Typical risk if gated | Suggested handling |
|--------|----------------------|--------------------|
| Branch admin / user admin | May need access **before** branch context | **E1/E3** review — often **still** need **some** tenant anchor |
| Global dashboards (if any) | **E1** | Waiver list |
| Marketing / payroll “all branches” lists | **E2** | Waiver or **read** audit wave |

---

## 4) Waiver documentation rule

Any **allowlisted** route for F-24 **must** cite:

- **Bucket** (E1–E5),  
- **Owner** (product role),  
- **Review date**,  
- **Whether reads are org-safe** without gate (F-21 style audit reference if needed).

---

## 5) Explicit non-exceptions

- **Routine CRUD** on clients, invoices, appointments, packages, memberships, etc. in **multi-org** — **no** waiver; **resolved org** is the **intended** foundation baseline.  
- **Client list provider** surfaces (F-19/F-20) — **no** separate waiver beyond **E1/E2** if product insists on global pickers (otherwise **gate** aligns with policy).
