# System Core

Fully independent foundational layer. **Must not import from `/system/modules`.**

## Subsystems

| Subsystem | Responsibility |
|-----------|----------------|
| `app` | Application bootstrap, container, lifecycle |
| `router` | Route registration, middleware pipeline |
| `middleware` | Cross-cutting middleware (auth, permissions, audit) |
| `auth` | Authentication, session, token management |
| `permissions` | RBAC engine, policy resolution |
| `validation` | Central validation rules, request validation |
| `errors` | Error classes, global error handler |
| `audit` | Audit log service, action history |
| `search` | Global search engine |
| `codes` | Code generator (invoice numbers, gift card codes) |
| `files` | File storage, signed URLs, retention |
| `notifications` | Notification queue, delivery abstraction |
| `workflow` | Business workflow orchestration |
| `status` | Status engine, transition rules |
| `pricing` | Pricing engine, discounts |
| `tax` | Tax engine, VAT calculation |
| `branches` | Branch isolation, multi-tenant scoping |
| `backup` | Backup and restore |

## Dependency Rule

Core provides services. Modules consume them. Core never depends on modules.
