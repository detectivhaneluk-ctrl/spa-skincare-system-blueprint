# Booker Modernization & ZIP Audit — Task Breakdown

**This file is archival only.** It is a **delivered-slice inventory** and historical proof of what shipped; it is **not** a task queue, sprint backlog, or sequencing source. **Do not** pick “next work” from this document.

**Active backlog / execution order:** **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C** (product / Booker parity) and, for platform work, **§6** (maintainability, tenancy, packages, ops). See that file’s **§8** banner: changelog “next” text elsewhere is audit context, not a live driver. **DELIVERED** sections here stay **frozen** unless **`§5.C`** / **`§6`** explicitly reopens a track.

**BKM-001–BKM-010:** All micro-step specifications for the Booker modernization track were **removed from this file** because every item is **DONE** (see `booker-modernization-checklist.md` for status, deliverables, and touched-file summaries). Do not treat former BKM sections here as an active execution queue.

**Execution discipline** (still applies to any new id): one task **IN_PROGRESS** at a time; no hidden side work; log surprises under **New Findings** in `booker-modernization-change-control.md`.

---

### MARKETING-ENGINE-BASELINE-01 — Marketing engine baseline (email, segments, outbound queue)

- **Status:** **DELIVERED** (application code + migration `074`; apply migration to activate).
- **Scope:** Dedicated marketing domain (campaigns, runs, recipient snapshots), repo-provable segments only, email via existing `outbound_notification_messages` + `OutboundNotificationDispatchService` / `system/scripts/outbound_notifications_dispatch.php`. SMS not implemented (deferred at transport layer).
- **Permissions (`owner` role seeded):** `marketing.view`, `marketing.manage`.
- **HTTP:** `GET /marketing/campaigns`, `GET|POST /marketing/campaigns/create|store`, `GET /marketing/campaigns/{id}`, `GET|POST /marketing/campaigns/{id}/edit|update`, `POST .../freeze-run`, `GET .../runs/{runId}/recipients`, `POST .../dispatch`, `POST .../cancel`.
- **CLI:** `php system/scripts/marketing_campaign_enqueue_run.php <run_id>` (same as UI dispatch).

---

### ZIP-AUDIT-03 — Membership refund-review operator workflow

- **Status:** **DELIVERED** (unchanged; see prior checklist / `MembershipRefundReviewController` in-repo).

---

### ONLINE-COMMERCE-EXPANSION-FOUNDATION-01 — Public catalog + purchase (gift cards, packages, memberships)

- **Status:** **DELIVERED** (migration `075`; configure `public_commerce.*` settings + per-product `public_online_eligible`; apply migration).
- **Public HTTP:** `GET /api/public/commerce/catalog`, `POST /api/public/commerce/purchase`, `POST /api/public/commerce/purchase/finalize`, `POST /api/public/commerce/purchase/status` (body: `confirmation_token`).
- **Core services:** `Modules\PublicCommerce\Services\PublicCommerceService`, fulfillment sync `Core\Contracts\PublicCommerceFulfillmentSync` after canonical payment / invoice update paths.

---

### PAYROLL-COMMISSIONS-FOUNDATION-01 — Payroll / commissions (rules, runs, lines)

- **Status:** **DELIVERED** (migration `076`; apply migration; `owner` receives `payroll.view` + `payroll.manage`).
- **Operator HTTP:** rules under `/payroll/rules` (manage only); runs under `/payroll/runs` (view for list/detail; manage for create/calculate/reopen/lock/settle/delete draft).
- **Core service:** `Modules\Payroll\Services\PayrollService` (deterministic calculation; no writes to invoices/appointments/payments).
- **Deferred in this slice:** product / package / gift-card line commissions (no staff on `invoice_items`); refunds and post-lock invoice reversals (no automatic clawback); mixed-currency runs (one currency per line only, never blended).
