# Documents / Consents Foundation — Progress

Minimal backend-first documents and consents linked to clients and appointments. Branch-aware, audit on state changes. Metadata/record only in this phase (no file upload or storage).

---

## Changed / added files

| File | Role |
|------|------|
| `system/data/migrations/045_create_documents_consents_tables.sql` | Tables: document_definitions, client_consents, service_required_consents |
| `system/data/full_project_schema.sql` | Same three tables added |
| `system/modules/documents/repositories/DocumentDefinitionRepository.php` | CRUD and list for document_definitions |
| `system/modules/documents/repositories/ClientConsentRepository.php` | Client consents + getConsentStatusForClientAndDefinitions, getRequiredDefinitionIdsForService |
| `system/modules/documents/repositories/ServiceRequiredConsentRepository.php` | service_required_consents: getRequiredDefinitionIds, setRequired, add/remove |
| `system/modules/documents/services/ConsentService.php` | checkClientConsentsForService, listDefinitions, listClientConsents, recordSigned, setStatus, createDefinition, setServiceRequiredConsents; branch + audit |
| `system/modules/documents/controllers/DocumentController.php` | listDefinitions, createDefinition, listClientConsents, signClientConsent, checkClientConsents |
| `system/modules/appointments/services/AppointmentService.php` | Injects ConsentService; assertRequiredConsents before create; consent check in createFromSlot |
| `system/routes/web.php` | GET/POST /documents/definitions, GET /documents/clients/{id}/consents, POST .../consents/sign, GET .../consents/check |
| `system/modules/bootstrap.php` | DocumentDefinitionRepository, ClientConsentRepository, ServiceRequiredConsentRepository, ConsentService, DocumentController; AppointmentService gets ConsentService |
| `system/docs/documents-consents-foundation-progress.md` | This doc |

---

## Data model added / used

- **document_definitions**  
  - branch_id, code (unique per branch), name, description, valid_duration_days (NULL = no expiry), is_active, deleted_at.  
  - Consent “templates”; no file content stored.

- **client_consents**  
  - client_id, document_definition_id, status (pending, signed, expired, revoked), signed_at, expires_at, branch_id, notes.  
  - One row per client per definition (re-sign updates same row).  
  - Unique (client_id, document_definition_id).

- **service_required_consents**  
  - service_id, document_definition_id.  
  - Which consents are required to book that service.

---

## Consent / document rules

1. **Definitions:** Branch-scoped or global (branch_id NULL). Branch-scoped users can only create definitions for their branch. List returns definitions for branch or global.
2. **Client consent status:** pending → signed (record signed_at, compute expires_at from definition’s valid_duration_days); signed can be revoked. Signed + expires_at &lt; today is treated as **expired** for booking checks (status in DB may still be signed).
3. **Appointment requirement:** Before creating an appointment (create and createFromSlot), the service’s required consents are resolved. For each required definition, client must have a consent with status **signed** and, if expires_at is set, expires_at ≥ today. If any required consent is **missing** (no row or pending/revoked) or **expired**, appointment creation fails with a clear message listing missing and expired consent names.
4. **Branch:** Definitions and client_consents are filtered by branch_id. recordSigned and createDefinition use BranchContext; branch-scoped users cannot act on another branch’s definitions.
5. **Audit:** client_consent_created, client_consent_signed, client_consent_revoked, client_consent_pending; document_definition_created; service_required_consents_updated.

---

## Endpoints / services added

- **GET /documents/definitions** — List definitions for branch (query branch_id or context). Optional `?all=1` to include inactive.  
- **POST /documents/definitions** — Create definition (body: code, name, description?, valid_duration_days?, is_active?).  
- **GET /documents/clients/{id}/consents** — List client’s consent records for branch.  
- **POST /documents/clients/{id}/consents/sign** — Record consent as signed (body: document_definition_id, notes?).  
- **GET /documents/clients/{id}/consents/check?service_id=** — Returns { ok, missing, expired } for that client + service + branch.

**ConsentService:**  
- checkClientConsentsForService(clientId, serviceId, branchId) → { ok, missing[], expired[] }  
- listDefinitions(branchId, activeOnly), listClientConsents(clientId, branchId)  
- recordSigned(clientId, documentDefinitionId, branchId, notes), setStatus(clientId, documentDefinitionId, status)  
- createDefinition(data, branchId), setServiceRequiredConsents(serviceId, definitionIds, branchId), getRequiredDefinitionIdsForService(serviceId)

---

## Manual smoke test checklist

1. **Migration**  
   - Run 045. Confirm document_definitions, client_consents, service_required_consents exist.

2. **Definitions**  
   - POST /documents/definitions with code, name. GET /documents/definitions → new definition in list (branch from context or query).

3. **Client consent**  
   - POST /documents/clients/{clientId}/consents/sign with document_definition_id. GET /documents/clients/{clientId}/consents → record with status signed, signed_at and optionally expires_at set.

4. **Service required**  
   - Insert into service_required_consents (service_id, document_definition_id). GET /documents/clients/{clientId}/consents/check?service_id=X → ok false, missing contains that definition if client has no signed consent.

5. **Appointment block**  
   - Set a service to require a consent; ensure client has no signed consent (or expired). Create appointment (form or slot) for that client + service → expect error “Required consent missing or expired: …”.

6. **Appointment allow**  
   - Sign the required consent for the client. Create same appointment again → success.

7. **Branch**  
   - As branch-scoped user, create definition (should get your branch). List definitions without branch_id → only your branch + global. Record consent for client in your branch → audit and record have correct branch_id.

8. **Audit**  
   - After recording signed consent and creating definition, check audit_logs for client_consent_created/signed and document_definition_created with expected target_type/target_id and metadata.

---

## Final hardening pass

A later **final backend hardening pass** added **PermissionMiddleware** to all document routes: `documents.view` on GET (list definitions, list client consents, check consents) and `documents.edit` on POST (create definition, sign client consent). Permission codes `documents.view` and `documents.edit` must exist in the `permissions` table and be assigned to roles; no seed was added — document and assign manually if needed. See `system/docs/archive/system-root-summaries/HARDENING-SUMMARY.md` §5 and `system/docs/archive/system-root-summaries/BACKEND-STATUS-SUMMARY.md`.

---

## Postponed

- **File upload / storage:** No file or binary storage; definitions and consents are metadata only. Attachments or signed PDFs to be added in a later phase.
- **UI:** No document/consent admin UI; endpoints are for API use and future check-in/booking.
- **Service-required-consents UI:** setServiceRequiredConsents is in ConsentService; no endpoint or UI to assign required consents to a service (can be done via DB or a future admin screen).
- **Cron to set status=expired:** Expiry is computed on read; no background job to update client_consents.status to expired.
- **Legal workflows:** No multi-step e-sign or witness flows; single “signed” recording only.
