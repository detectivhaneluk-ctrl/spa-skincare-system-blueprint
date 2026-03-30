# MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01 SCOPE MATRIX

Date: 2026-03-23  
Status: **CLOSED** (protected tenant runtime for in-scope surfaces; see OPS for deferred items)

| Surface | Protected runtime exposed | Read hardening | Write / transition hardening | Foreign-id behavior | Unresolved tenant context | Notes |
|---|---|---|---|---|---|---|
| Membership definitions (`/memberships/definitions*`) | yes | `findInTenantScope` / `listInTenantScope` / `countInTenantScope` | create pins tenant branch; update via scoped find | denied (scoped load returns null) | controller `tenantBranchOrRedirect` + org-scope SQL throws on unresolved org | assignable list allows org-global definition rows (`branch_id` NULL) consistent with legacy model |
| Client memberships (`/memberships/clients*`) | yes | `findInTenantScope` / `findForUpdateInTenantScope` / list/count scoped | mutations load via scoped `findForUpdate` | denied | controller branch guard | NULL `client_memberships.branch_id` allowed when client’s branch is in same org as context |
| Membership sales / billing cycles (refund review, sale activation paths) | yes (tenant refund review + sale flows touched) | `findInTenantScope` / queue list scoped | reconcile paths require tenant branch + scoped row load | denied | explicit branch requirement on reconcile-style paths | cron/system lifecycle passes intentionally unscoped (documented in OPS) |
| Gift cards (`/gift-cards*`) | yes | `findInTenantScope` / `findLockedInTenantScope` / list/count scoped | redeem/issue/block/consume use locked scoped load + positive branch | denied | scoped repo + `OrganizationRepositoryScope` path | global card (`branch_id` NULL) visible when client’s branch is in resolved org |
| Sales invoice gift redemption / refund (coupling) | yes | `getBalanceSummary` uses `findInTenantScope` under current branch (post-wave) | `redeemForInvoice` / refunds already locked scoped; invoice requires positive `branch_id` for redeem; refund requires branch-scoped invoice | cross-tenant card summary not returned under tenant branch | fails if branch context missing for balance summary | aligns sales read with tenant scope |
| Package definitions (`/packages/definitions*`) | yes | `findInTenantScope` / list/count scoped | create/update via scoped loads + branch guards | denied | controller branch guard | assignable/global package rows follow same org rules as memberships |
| Client packages (`/packages/clients*`) | yes | `findInTenantScope` / list scoped | use/adjust/reverse/cancel/expire/consume via scoped loads | denied | service `requirePositiveBranchId` + scoped repo | — |
