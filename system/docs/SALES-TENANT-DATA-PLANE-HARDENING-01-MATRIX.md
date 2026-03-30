# SALES-TENANT-DATA-PLANE-HARDENING-01 SCOPE MATRIX

Date: 2026-03-23  
Status: DONE (protected tenant sales runtime hardening)

| Surface | Protected runtime exposed | Read hardening | Write hardening | Foreign-id behavior | Unresolved tenant context behavior | Notes |
|---|---|---|---|---|---|---|
| Invoices (`/sales/invoices*`) | yes | org-owned branch scoped repository reads (`find/list/count/findForUpdate`) | scoped update/soft-delete + service branch/org assertions | denied (scoped `find`/`findForUpdate` -> null/not found) | denied (controller guard 403) | removed branch-or-null list/count permissive pattern |
| Payments (`/sales/invoices/{id}/payments*`) | yes | payment reads scoped via invoice ownership EXISTS | write path tied to scoped invoice + branch/org assertions | denied (payment/invoice foreign ids not visible in scope) | denied (controller guard 403) | cross-tenant payment-apply blocked |
| Register sessions (`/sales/register*`) | yes | register session reads/list/count scoped by org-owned branch | scoped update + service branch checks on close/move/open | denied (foreign register ids unresolved under scoped query) | denied (controller guard 403) | protected register data-plane isolated |
| Cash movements (register-linked) | yes (through register runtime) | scoped by branch ownership | created via scoped register service path | denied through scoped parent session access | denied (controller guard 403) | no standalone public runtime path |
| Invoice items (invoice-linked) | yes (invoice detail/edit runtime) | scoped by parent invoice ownership | scoped update/delete/deleteByInvoice | denied through scoped parent invoice | denied (controller guard 403) | linked-entity invariant preserved |
