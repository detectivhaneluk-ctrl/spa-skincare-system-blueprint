# SPA & Skincare Premium System — Architecture Summary & Implementation Plan

> **Single source of truth:** This document synthesizes the blueprint. No coding yet — plan only.

> **ZIP truth (2026-03-22):** The **implemented** codebase under `system/modules/` has **21** domain module directories (not 14). See `system/modules/README.md` and canonical reconciliation **`system/docs/ZIP-TRUTH-RECONCILIATION-SEAL-01.md`**. Sections below that still say “14” are **superseded** for module count only.

> **ARCHIVAL PACKAGE:** This file lives under `archive/blueprint-reference/`. It is **historical / planning context**, not the live maintainer contract. See **`system/docs/MAINTAINER-RUNTIME-TRUTH.md`**.

---

## 1. Architecture Summary

### 1.1 System Vision
Premium SPA & Skincare management system with:
- **Modular architecture** — strict separation of core, shared, and business modules
- **Settings-driven** — business rules, taxes, durations, policies stored in DB, not code
- **Core-separated-from-UI** — invisible system layer (engines, RBAC, audit) lives in `/system/core`
- **Single entry point** — one application shell

### 1.2 Module count (superseded by live tree)
- **21 business module directories** under `system/modules/` — authoritative alphabetical list: `system/modules/README.md` (e.g. `appointments` … `staff`; includes `branches`, `gift-cards`, `intake`, `memberships`, `notifications`, `packages`, `payroll`, `public-commerce`, etc.).
- **1 independent system core** — not a business module; provides foundational services; has zero dependency on business modules

### 1.3 Layered Structure

| Layer | Location | Responsibility |
|-------|----------|----------------|
| **System core** | `/system/core` | Status engine, workflow engine, audit log, timeline, notification queue, file engine, code generator, soft delete, search, conflict engine, pricing engine, tax engine, permissions engine, branch isolation, backup. **Fully independent** — no imports from `/system/modules`. |
| **Business modules** | `/system/modules` | 21 module directories; may depend on core and each other per dependency graph |
| **Shared** | `/system/shared` | Reusable UI primitives (forms, tables, filters, modals, cards, timelines, calendar, charts, uploads, status-badges) |
| **Data** | `/system/data` | Migrations, seeders, schemas |
| **Public** | `/system/public` | Static assets |
| **Storage** | `/system/storage` | Runtime files, documents, logs, backups |

### 1.4 Core Subsystems (Invisible to User)
- Status engine — centralized state transitions
- Workflow engine — business flow orchestration
- Audit log — action history for all critical ops
- Timeline engine — chronological event display
- Notification queue — reminders, confirmations, follow-ups
- File engine — document storage, consent signatures
- Code generator — invoice numbering, etc.
- Soft delete / archive
- Search engine — global search
- Conflict engine — appointment clashes, resource availability
- Pricing engine — discounts, packages
- Tax engine — VAT calculations
- Permissions engine — RBAC enforcement
- Branch isolation — multi-branch data scoping
- Backup / restore

### 1.5 Golden Rules
1. **Core is independent** — system core must not import or depend on any business module
2. **Do not mix core with UI** — business logic (status transitions, tax calc, conflict detection) lives in core
3. **No config in code** — VAT %, duration, cancellation policy, deposit %, invoice prefix, reminder times → settings
4. **Audit everything** — create/update/delete/approve/cancel/refund must log to audit
5. **Permission checks** — middleware or policy layer, never random `if` in views
6. **No duplicate UI components** — use shared primitives

### 1.6 RBAC
- **Roles:** Owner, Admin, Reception, Specialist, Accountant, Warehouse Manager
- **Levels:** module, action, field
- **Enforcement:** Centralized policy layer (lives in core)

### 1.7 Data Domains (from domain_map.json)
- identity, crm, appointments, sales, inventory, packages, staff, documents, system

---

## 2. Module Dependency Order

### 2.1 Dependency Graph
- **system-core**: no dependencies; business modules depend on it, never the reverse
- **Cycle resolution**: sales ↔ giftcards-packages → build sales first with gift-card stub, then giftcards-packages

### 2.2 Final Module Build Order (Safe Sequence)

```
1. system-core           (independent; no module deps)
2. settings
3. auth
4. clients
5. staff
6. services-resources
7. appointments
8. inventory
9. sales
10. giftcards-packages
11. reports
12. marketing
13. documents
14. dashboard
15. online-booking
```

---

## 3. Skeleton Project Structure

### 3.1 Final Root Structure (Single Root — All Under /system)

**No paths outside `/system`.** One root only.

```
/system
  /core
    /app
    /router
    /middleware
    /auth
    /permissions
    /validation
    /errors
    /audit
    /search
    /codes
    /files
    /notifications
    /pricing
    /taxes
    /conflicts
    /backup

  /modules
    /auth
    /dashboard
    /appointments
    /clients
    /sales
    /giftcards-packages
    /inventory
    /services-resources
    /staff
    /reports
    /marketing
    /documents
    /settings
    /online-booking

  /shared
    /ui
    /forms
    /tables
    /filters
    /modals
    /cards
    /timelines
    /calendar
    /charts
    /uploads
    /status-badges

  /data
    /migrations
    /seeders
    /schemas

  /public
    /assets
      /css
      /js
      /img
      /icons
      /fonts
      /uploads-temp

  /storage
    /documents
    /consents
    /client-media
    /exports
    /logs
    /backups
```

### 3.2 Per-module skeleton (live tree: **21** module folders; same layout pattern)

```
/system/modules/<module-name>/
  /config
  /controllers
  /services
  /repositories
  /requests
  /policies
  /routes
  /views
  /components
  /actions
  /events
  /listeners
```

---

## 4. FOUNDATION-STANDARDS

### 4.1 Naming Conventions
| Element | Convention | Example |
|---------|------------|---------|
| **Database tables** | snake_case, plural | `invoice_items`, `client_medical_cards` |
| **Entity/Model** | PascalCase, singular | `InvoiceItem`, `ClientMedicalCard` |
| **API routes** | kebab-case, plural, RESTful | `/api/clients`, `/api/appointments/{id}` |
| **Service class** | PascalCase + Service | `AppointmentService`, `CodeGeneratorService` |
| **Policy/Permission** | `module.action` | `appointments.create`, `clients.view` |
| **Setting keys** | `module.subkey` dot-notation | `appointments.buffer_minutes`, `invoice.prefix` |
| **Status codes** | snake_case | `confirmed`, `cancelled`, `no_show` |

### 4.2 ID / Code Generation Strategy
| Use Case | Strategy | Example |
|----------|----------|--------|
| **Primary keys** | Auto-increment (or UUID per stack choice) — consistent across entity type | `id: 12345` |
| **Human-readable codes** | Code generator (core), format from settings | Invoice: `INV-2024-00042`; Gift card: `GC-8XK2-ABCD` |
| **Invoice numbers** | Centralized in core; prefix + year + sequence from settings | `settings.invoice.prefix`, `settings.invoice.next_seq` |
| **External references** | Use `code` column where human lookup needed; `id` for joins | |

### 4.3 Status Enum Strategy
- **Centralized** — status definitions and transitions live in core, not in module code
- **Format** — `entity_type.status` (e.g., `appointment.confirmed`, `invoice.paid`)
- **Transition rules** — stored as configuration (settings or status_transitions table); core status engine enforces
- **No magic strings in views** — use constants or config-driven enums
- **Audit** — every status change logged with `from_status`, `to_status`, `reason`

### 4.4 Soft Delete / Archive Policy
- **Standard columns**: `deleted_at` (nullable timestamp), `archived_at` (nullable timestamp)
- **Soft delete** — set `deleted_at`; exclude from default queries; provide `withTrashed()` / `onlyTrashed()` where needed
- **Archive** — for historical/finalized records (e.g., closed invoices); `archived_at` set when state is terminal
- **Restore** — clear `deleted_at`; audit restore action
- **Hard delete** — allowed only by admin/superuser; must be audited; prefer soft delete for business entities

### 4.5 Audit Field Standard
**Every auditable entity includes:**
| Column | Type | Purpose |
|--------|------|---------|
| `created_at` | timestamp | Creation time |
| `updated_at` | timestamp | Last update time |
| `created_by` | FK users, nullable | User who created |
| `updated_by` | FK users, nullable | User who last updated |
| `branch_id` | FK branches, nullable | Branch scope (when multi-branch) |

**Audit log (separate table)** — for action history:
| Column | Purpose |
|--------|---------|
| `action` | create, update, delete, approve, cancel, refund, status_change, restore |
| `entity_type` | appointments, invoices, clients, etc. |
| `entity_id` | Target record ID |
| `user_id` | Actor |
| `branch_id` | Context branch |
| `old_values` | JSON snapshot (for update/delete) |
| `new_values` | JSON snapshot |
| `ip`, `user_agent` | Optional for security |
| `created_at` | When |

### 4.6 Branch Isolation Rule
- **`branch_id`** — required on all branch-scoped entities (appointments, invoices, staff assignments, etc.)
- **Default scope** — all queries filtered by current user's `branch_id` (or selected branch) unless explicitly bypassed (e.g., super admin)
- **Cross-branch** — explicit `branch_id` in request or context; audit cross-branch actions
- **Settings** — can be global or per-branch; `branch_id` nullable for global settings

### 4.7 File Storage Strategy
- **Root** — `/system/storage`
- **Structure** — `/{domain}/{entity_type}/{year}/{month}/{safe_filename}`  
  Example: `documents/consents/2024/03/client_123_consent_456.pdf`
- **Naming** — `{entity_id}_{suffix}_{id}.{ext}` or UUID-based to avoid collisions
- **Temp uploads** — `/system/public/assets/uploads-temp`; move to storage after validation
- **Retention** — configurable per domain; soft-deleted entities keep files until purge policy
- **Access** — signed URLs or authenticated download endpoints; never direct public paths for sensitive data

### 4.8 Global API Response Format
**Success:**
```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 100
  }
}
```

**Error:**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Human-readable message",
    "details": [ { "field": "email", "message": "Invalid format" } ]
  }
}
```

**Pagination** — `meta` present when applicable; omit for single resource.

### 4.9 Global Error Handling Pattern
- **Error codes** — uppercase snake_case: `NOT_FOUND`, `UNAUTHORIZED`, `FORBIDDEN`, `VALIDATION_FAILED`, `CONFLICT`, `STATUS_TRANSITION_INVALID`
- **Structure** — typed error classes; map to HTTP status: 400, 401, 403, 404, 409, 422, 500
- **Logging** — 4xx client errors: log at info/debug; 5xx: log at error with stack
- **Audit** — log failed auth/permission attempts; do not log validation errors as audit events
- **User-facing** — never expose stack traces; generic message for 500

---

## 5. PROJECT-CONVENTIONS

### 5.1 File Placement
- New file: decide core vs shared vs module. Never put core logic in a module; never put module-specific logic in core.
- Shared UI: only truly reusable components; module-specific variants stay in module.

### 5.2 No Duplication
- One source for each UI component, validation rule, status definition.
- Reuse shared primitives; do not copy-paste between modules.

### 5.3 Audit Coverage
- Every create/update/delete/approve/cancel/refund/status_change must call audit service.
- Mandate audit in service layer; do not rely on ad-hoc controller logging.

### 5.4 Status Transitions
- All status changes go through core status engine.
- Clear transition rules; reject invalid transitions with `STATUS_TRANSITION_INVALID`.

### 5.5 Settings Over Code
- Configurable values (VAT, duration, cancellation rules, etc.) come from settings.
- No magic numbers or hardcoded policies in business logic.

### 5.6 Language
- Blueprint primary language: Armenian (`hy`). UI labels, messages, and documentation may follow project locale settings.
- Code identifiers: English (variables, classes, keys, routes).

---

## 6. PHASE-1 NON-NEGOTIABLES

Phase 1 = **Foundation**: system-core, auth, settings. The following must be complete before Phase 2.

### 6.1 Structure
- [ ] Single root `/system` with: core, modules, shared, data, public, storage
- [ ] No paths outside `/system`
- [ ] All module directories created with standard skeleton (live tree: **21** under `system/modules/` — see `system/modules/README.md`)
- [ ] All core subsystem directories created

### 6.2 System Core Independence
- [ ] Core has zero imports from `/system/modules`
- [ ] Core provides interfaces/services consumed by modules
- [ ] No business logic from modules embedded in core

### 6.3 Database
- [ ] Migrations: users, roles, permissions, role_permissions, settings, audit_logs, branches
- [ ] Audit fields on base entities (created_at, updated_at, created_by, updated_by, branch_id where applicable)
- [ ] Seed: default roles and permissions

### 6.4 Auth & Permissions
- [ ] Login, logout, session/token management
- [ ] Password reset flow
- [ ] Auth middleware protecting routes
- [ ] Permission check via policy/middleware; 403 on denial
- [ ] Roles: at least Owner, Admin, Reception seeded

### 6.5 Audit
- [ ] Audit log table and AuditService
- [ ] `log(action, entity_type, entity_id, user_id, ...)` method
- [ ] Auth events (login, logout, failed attempt) logged

### 6.6 Settings
- [ ] Settings table (key-value or grouped)
- [ ] Read API; admin CRUD for settings
- [ ] Settings loader used by core (no hardcoded config for Phase 1 scope)

### 6.7 Standards Applied
- [ ] Naming conventions followed
- [ ] Audit field standard on new entities
- [ ] Global API response format used
- [ ] Global error handling pattern in place
- [ ] Branch isolation: branch_id on users, branches table, query scope ready

### 6.8 Shared & Module Stubs
- [ ] Base layout component/page
- [ ] Auth guard/wrapper
- [ ] 403, 404 error pages
- [ ] Auth module: login UI, logout, reset
- [ ] Settings module: minimal admin UI to read/edit settings
- [ ] No other feature modules implemented

### 6.9 Definition of Done for Phase 1
- User can log in and log out
- Protected routes enforce permissions
- Settings readable and editable (admin)
- Audit log records auth events
- Foundation standards documented and adhered to
- Ready for Phase 2 (clients, services-resources, staff, appointments)

---

## 7. Phase 1 Task Order (Recommended)

1. Create `/system` root and full folder structure
2. Database: migrations for users, roles, permissions, settings, audit_logs, branches
3. Core: app bootstrap, router, middleware chain
4. Core: auth, permissions engine (policy + middleware)
5. Core: audit service + audit log table
6. Core: settings service + read/write API
7. Apply foundation standards (response format, error handling)
8. Module: auth (login UI, logout, reset)
9. Module: settings (admin UI skeleton)
10. Shared: base layout, auth guard, 403, 404

---

## 8. Risks / Ambiguities

| Item | Resolution |
|------|------------|
| system_manifest build_order | Use corrected order: system-core → settings → auth → … |
| sales ↔ giftcards-packages cycle | Build sales first with gift-card stub; then giftcards-packages |
| Tech stack | Define before Phase 1 kickoff |
| Notification delivery | Phase 4+; stub for Phase 1 |

---

## Summary Table

| Aspect | Value |
|--------|-------|
| Business modules | 21 (see `system/modules/README.md`) |
| System core | 1 (independent) |
| Build phases | 5 |
| Core subsystems | 16 |
| Root | `/system` only |
| First deliverable | Phase 1: system-core + auth + settings |

---

*Blueprint constitution preserved. No implementation code — plan only.*
