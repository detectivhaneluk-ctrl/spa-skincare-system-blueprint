# Client list/count caller matrix (FOUNDATION-17)

Read-only index supporting **`ORGANIZATION-SCOPED-CLIENT-LIST-COUNT-TRUTH-AUDIT-FOUNDATION-17-OPS.md`**.

## A) Direct `ClientRepository` calls

| Caller | `list` | `count` | Typical `$filters` | Staff route / notes |
|--------|--------|---------|-------------------|---------------------|
| `ClientController::index` | Yes | Yes | `[]` or `['search' => …]` only | `GET /clients` — `clients.view` |
| `ClientController::registrationsShow` | Yes | No | `['search' => …]` when query non-empty | `GET /clients/registrations/{id}` — `clients.view` |
| `ClientListProviderImpl::list` | Yes | No | `[]` or `['branch_id' => $branchId]` | Used from **multiple** modules (not clients-only) |

## B) Indirect via `ClientListProvider::list` → `ClientListProviderImpl`

| Consumer class | Injected as | Evidence (representative) |
|----------------|-------------|---------------------------|
| `InvoiceController` | `ClientListProvider $clientList` | `clientList->list($branchId)` |
| `AppointmentController` | `ClientListProvider $clientList` | `clientList->list($branchId)` |
| `GiftCardController` | `ClientListProvider $clients` | `clients->list($branchId)` |
| `ClientPackageController` | `ClientListProvider $clients` | `clients->list($branchId)` |
| `ClientMembershipController` | `ClientListProvider $clientListProvider` | `clientListProvider->list($listBranchId)` |

**Does not** call `ClientListProvider`: `ClientController::index` / `registrationsShow` (direct repo).

## C) Repository SQL summary (both methods)

| Feature | Present |
|---------|---------|
| `deleted_at IS NULL` | Yes |
| Optional `branch_id = ?` | Yes |
| Optional search LIKE (name/email/phone) | Yes |
| Org / EXISTS | **No** (as of F-16; F-16 added org only on `find` / `findForUpdate`) |
| `COUNT` aligned with `list` filters | Yes (same filter construction) |
