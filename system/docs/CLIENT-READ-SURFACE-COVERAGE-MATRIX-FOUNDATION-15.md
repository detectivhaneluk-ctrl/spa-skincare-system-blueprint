# Client read-surface coverage matrix (FOUNDATION-15)

**Parent:** `ORGANIZATION-SCOPED-CLIENT-READ-SURFACES-TRUTH-AUDIT-FOUNDATION-15-OPS.md`  
**Scope:** Read-only inventory; no runtime truth claims beyond current PHP sources.

## Legend

- **F-11:** `ClientService` mutating paths use `OrganizationScopedBranchAssert` after load (see `verify_organization_scoped_choke_points_foundation_11_readonly.php`).
- **Branch UI:** `ClientController::ensureBranchAccess` → `BranchContext::assertBranchMatch`.
- **Org repo:** Organization-scoped SQL at repository layer (**not** present for clients as of F-14).

| Read / query path | Class::method | ID-only | Optional branch | Search unscoped | NULL/global tolerant | F-11 | Typical caller |
|-------------------|---------------|---------|-----------------|-----------------|------------------------|------|----------------|
| Client row | `ClientRepository::find` | Yes | — | — | — | No* | `ClientController`, `ClientService`, `ClientIssueFlagService`, `InvoiceController`, `IntakeFormService`, … |
| Client row lock | `ClientRepository::findForUpdate` | Yes | — | — | — | No* | `ClientService::mergeClients` |
| List/count | `ClientRepository::list` / `count` | — | If `filters['branch_id']` | `search` LIKE | No org | No | `ClientController::index`, `registrationsShow`, `ClientListProviderImpl` |
| Duplicate search | `ClientRepository::searchDuplicates` | — | No | Yes | No org | No | `ClientService::searchDuplicates` ← `ClientController::index` |
| Duplicate find | `ClientRepository::findDuplicates` | Partial | No | — | No org | No | `ClientService::findDuplicates` ← `ClientController::show` |
| Merge preview data | `ClientService::getMergePreview` | Via `find` | No | — | No | No | `ClientController::mergePreview` |
| Linked counts | `ClientRepository::countLinkedRecords` | `client_id` | No | — | Cross-table | No | `ClientService::getMergePreview`, `mergeClients` |
| Notes | `ClientRepository::listNotes` | `client_id` | No | — | — | No | `ClientController::show` |
| Note row | `ClientRepository::findNote` | `noteId` | No | — | — | No | `ClientService::deleteClientNote` (mutate) |
| Audit | `ClientRepository::listAuditHistory` | `client_id` | No | — | — | No | `ClientController::show` |
| Field defs | `ClientFieldDefinitionRepository::list` | — | Optional | — | **All rows if `$branchId` null**; **NULL def branch** included when branch set | No (mutates elsewhere) | `ClientService::getCustomFieldDefinitions` |
| Field def row | `ClientFieldDefinitionRepository::find` | Yes | — | — | — | No (mutates elsewhere) | `ClientService::updateCustomFieldDefinition` |
| Field values | `ClientFieldValueRepository::listByClientId` | `client_id` | No | — | — | No | `ClientService::getClientCustomFieldValuesMap` |
| Issue flags | `ClientIssueFlagRepository::listByClient` | `client_id` | No | — | — | No | `ClientController::show` |
| Sales aggregates | `ClientSalesProfileProviderImpl::*` | `client_id` | No | — | No org in SQL | No | `ClientController::show` |
| Client picker list | `ClientListProviderImpl::list` | — | Optional | — | **Unfiltered if `$branchId` null** | No | `InvoiceController`, `ClientMembershipController`, … |
| Public match | `ClientRepository::lockActiveByEmailBranch` / `lockActiveByPhoneDigitsBranch` | — | **Required** branch param | — | Email/phone key | No | `PublicClientResolutionService` |

\*F-11 applies **after** `find` on **mutate** paths in `ClientService`, not on read-only controller loads.
