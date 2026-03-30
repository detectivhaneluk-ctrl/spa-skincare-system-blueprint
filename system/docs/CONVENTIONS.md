# System Conventions

## 1. Permission Naming (module.action)

**Pattern:** `{module}.{action}`

| Action | Purpose |
|--------|---------|
| `view` | Read/list records |
| `create` | Create new records |
| `edit` | Update existing records |
| `delete` | Delete records |
| `*` | Wildcard for all actions in module |

**Examples:**
- `settings.view`, `settings.edit`
- `clients.view`, `clients.create`, `clients.edit`, `clients.delete`
- `appointments.view`, `appointments.create`, `appointments.edit`, `appointments.cancel`
- `sales.view`, `sales.create`, `sales.refund`
- `clients.*` — all client actions

**Enforcement:** Middleware or policy layer. Never check permissions with ad-hoc `if` in views.

---

## 2. Branch Isolation Rules

### Current Branch Resolution
- **Authenticated user:** `user.branch_id` or session-selected branch
- **Owner/Admin:** May switch branch or view all (scope differs per role)
- **Default:** First branch for new users until branch selector is used

### Branch-Scoped Records
- appointments
- invoices, payments, cash_shifts
- inventory movements (per branch)
- staff assignments
- settings (when branch_id is set)

### Global Records
- users, roles, permissions
- branches
- audit_logs (branch_id for context only)
- settings with branch_id IS NULL

### Owner vs Admin Scope
- **Owner:** Full access; can view all branches; branch filter optional
- **Admin:** Typically scoped to assigned branch; may have cross-branch if permitted
- **Other roles:** Strictly branch-scoped

---

## 3. API Response Format

### Success (JSON)
```json
{
  "success": true,
  "data": { ... },
  "meta": { "page": 1, "per_page": 20, "total": 100 }
}
```

### Error (JSON)
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Human-readable message",
    "details": [{"field": "email", "message": "Invalid format"}]
  }
}
```

### Error Codes
- `UNAUTHORIZED` (401)
- `FORBIDDEN` (403)
- `NOT_FOUND` (404)
- `VALIDATION_FAILED` (422)
- `CONFLICT` (409)
- `TOO_MANY_ATTEMPTS` (429)
- `SERVER_ERROR` (500)

---

## 4. HTML Error Pages
- 403, 404, 500 — use `shared/layout/errors/{code}.php`
- Consistent layout and styling
- Never expose stack traces in production
