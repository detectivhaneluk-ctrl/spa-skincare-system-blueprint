# FOUNDATION-A7 — Canonical Migration Map

**Status:** ACTIVE — migration order defined, phases NOT yet open for implementation  
**Installed:** 2026-03-31 (BIG-03)  
**Prerequisites:** FOUNDATION-A1..A6 must be complete before opening any phase below  
**Live execution queue:** `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`

---

## Purpose

This document defines the canonical, phased migration order for applying the 2026 Architecture Reset kernel model (FOUNDATION-A1..A5) across remaining domains.

**This is a planning document only.** No domain listed here is open for implementation until the preceding phase is complete and its guardrails are in place.

The media pilot lane (FOUNDATION-A5 / BIG-02) proved the model end-to-end. The map below applies that pattern — TenantContext-scoped repositories, no direct service DB access, canonical `loadVisible` / `mutateOwned` / `deleteOwned` API — to each domain in the order defined here.

---

## Ordering principles

The migration order is determined by:

1. **Risk surface**: Domains that carry the highest ownership/isolation risk if not migrated are highest priority. Appointments and online-booking are the critical scheduling surface; incorrect tenant isolation there has direct financial and operational consequence.

2. **Architectural coupling**: Online-booking depends on appointment availability; migrating appointments first gives online-booking a clean foundation to build on.

3. **Transaction surface**: Sales has complex payment + invoice flows. Migrating it after the simpler scheduling/booking surface means the migration pattern is proven before it touches financial records.

4. **Data model complexity**: Client-owned resources (custom fields, page layouts, registration, merge) have the most complex data model. They are last so the migration machinery is fully proven before opening the most complex domain.

---

## PHASE-1: Appointments

**Migration goal:**  
Migrate the appointments domain service and repository layer to:
- Remove `Database $db` direct data access from protected services
- Replace `BranchContext` / raw ID scope with `TenantContext`-scoped canonical repository methods
- Install `loadVisible`, `loadForUpdate`, `mutateOwned`, `deleteOwned`, `countOwnedReferences` API family on affected repositories
- Extend `guardrail_service_layer_db_ban.php` and `guardrail_id_only_repo_api_freeze.php` with appointments scope

**Primary files:**
- `system/modules/appointments/services/AppointmentService.php`
- `system/modules/appointments/services/AppointmentSeriesService.php`
- `system/modules/appointments/services/AvailabilityService.php`
- `system/modules/appointments/services/BlockedSlotService.php`
- `system/modules/appointments/services/WaitlistService.php`
- `system/modules/appointments/repositories/AppointmentRepository.php`
- `system/modules/appointments/repositories/AppointmentSeriesRepository.php`
- `system/modules/appointments/repositories/BlockedSlotRepository.php`
- `system/modules/appointments/repositories/WaitlistRepository.php`

**Why first:**  
Appointments is the core scheduling surface of the platform. Root-01 id-only acquisition patterns here mean appointment lookups and mutations can cross tenant boundaries if miscalled. This is the highest-consequence domain. Migrating it first closes the most critical structural gap.

The PLT-TNT-01 wave era already touched `AvailabilityService` (availability/public-booking cluster closure) with hotspot patches. PHASE-1 replaces those patches with the proper kernel model, closing the remaining gaps.

**Out of scope for this phase:**  
- Online-booking, sales, client flows
- Payments, invoices, VAT — those belong to PHASE-3
- Appointment controller rewrite — architecture change in service + repository layer only

**Done criteria:**  
- All appointment services pass `guardrail_service_layer_db_ban`
- All appointment repositories have canonical TenantContext-scoped methods for tenant-owned entities
- Old legacy methods frozen in `guardrail_id_only_repo_api_freeze` allowlist
- Behavior verification script added and passing
- Guardrail scripts expanded to include appointment scope

---

## PHASE-2: Online-Booking

**Migration goal:**  
Migrate the public booking / online-booking surface to consume TenantContext and canonical scoped repository methods.

**Primary files:**
- `system/modules/online-booking/services/PublicBookingService.php`
- Any repository files in `system/modules/online-booking/repositories/`

**Why second:**  
Online-booking is the public-facing booking flow. It relies on appointment availability (PHASE-1) and must trust that the appointment data model is already using the kernel model. Migrating availability first means PHASE-2 builds on a clean foundation.

The PLT-TNT-01 wave era also touched `PublicBookingService` as part of the availability/public-booking cluster. PHASE-2 replaces those patches with the kernel model.

**Out of scope for this phase:**  
- Payment processing for bookings — that belongs to PHASE-3
- Client registration triggered by booking — handled in PHASE-4
- Online-booking frontend / JS layer

**Done criteria:**  
- `PublicBookingService` passes `guardrail_service_layer_db_ban`
- Online-booking repositories have canonical TenantContext-scoped methods
- Guardrail scripts expanded to include online-booking scope
- Behavior verification passing

---

## PHASE-3: Sales

**Migration goal:**  
Migrate the sales domain (invoices, payments, VAT, payment methods) to TenantContext + canonical repository API.

**Primary files:**
- `system/modules/sales/services/PaymentMethodService.php`
- `system/modules/sales/services/VatRateService.php`
- Any other services in `system/modules/sales/services/`
- `system/modules/sales/repositories/PaymentMethodRepository.php`
- `system/modules/sales/repositories/VatRateRepository.php`
- Invoice and payment repositories

**Why third:**  
Sales has the most compliance-sensitive data (payment records, financial audit trails). Migrating it after appointments and online-booking means:
1. The migration pattern is proven in two domains before touching financial data
2. The dependency chain (appointment → invoice) is clean when payments are migrated

The PLT-TNT-01 wave era touched several sales repositories. PHASE-3 replaces those patches with the kernel model.

**Out of scope for this phase:**  
- Gift card redemption flows — those touch both sales and marketing; coordinate at phase start
- Membership invoices — coordinate timing with the memberships module

**Done criteria:**  
- All sales services pass `guardrail_service_layer_db_ban`
- Sales repositories have canonical TenantContext-scoped methods
- Guardrail scripts expanded to sales scope
- Invoice + payment behavior verification passing

---

## PHASE-4: Client-Owned Resources

**Migration goal:**  
Migrate client-related services and repositories to TenantContext + canonical repository API.

**Primary files:**
- `system/modules/clients/services/ClientService.php`
- `system/modules/clients/services/ClientMergeJobService.php`
- `system/modules/clients/services/ClientRegistrationService.php`
- `system/modules/clients/services/ClientIssueFlagService.php`
- `system/modules/clients/repositories/ClientRepository.php`
- Other client-domain repositories

**Why fourth (last):**  
The client domain has the most complex data model: custom fields, page layouts, merge jobs, registration requests, issue flags. The migration machinery should be fully proven in three simpler domains before opening it. Additionally, client data is cross-referenced by appointments (PHASE-1), online-booking (PHASE-2), and sales (PHASE-3) — migrating clients last means the calling surfaces are already on the kernel model.

Note: `ClientProfileImageService` and `ClientProfileImageRepository` are already migrated (MEDIA_PILOT / BIG-02) and are NOT in scope for PHASE-4.

**Out of scope for this phase:**  
- Membership service (may need a separate PHASE-5 or be added to PHASE-4 scope at planning time)
- Staff module (not a tenant-owned-resource domain in the same sense)

**Done criteria:**  
- All client services (except already-migrated `ClientProfileImageService`) pass `guardrail_service_layer_db_ban`
- Client repositories have canonical TenantContext-scoped methods
- Guardrail scripts expanded to client scope
- Client data behavior verification passing

---

## Phase order quick reference

| Phase | Domain | Blocking condition |
|-------|--------|--------------------|
| MEDIA_PILOT | Media / client image / gift card templates | **DONE** (BIG-02, 2026-03-31) |
| PHASE-1 | Appointments | Awaiting FOUNDATION-A6 guardrails (done) |
| PHASE-2 | Online-booking | Awaiting PHASE-1 complete |
| PHASE-3 | Sales | Awaiting PHASE-2 complete |
| PHASE-4 | Client-owned resources | Awaiting PHASE-3 complete |

---

## Domains explicitly NOT in this migration map

These domains are either:
- Not tenant-owned-data surfaces in the ROOT-01 / ROOT-05 sense
- Deferred to later backbone phases per `BACKBONE-CLOSURE-MASTER-PLAN-01.md`

| Domain | Reason not in map |
|--------|------------------|
| Staff module | Staff records are branch-admin-owned, not tenant-user-owned resources; separate migration concern |
| Notifications | Infrastructure service; does not own tenant data rows directly |
| Inventory | Product catalog is org-scoped but not user-owned; separate migration risk profile |
| Packages | Deferred — complex membership+sales interaction; evaluate after PHASE-3 |
| Memberships | Separate complexity; evaluate for inclusion after PHASE-4 |
| Settings | Mostly org/branch configuration; lower risk profile |

These domains have hotspot closure evidence from PLT-TNT-01 wave era. That evidence stands. They are not re-opened here unless promoted by the LIVE charter.

---

## Migration pattern (reference for each phase)

Each phase follows the BIG-02 pilot lane pattern:

1. **Read the domain truth** — audit current service + repository files for direct DB calls and id-only patterns
2. **Add canonical TenantContext-scoped methods** to repositories (add, don't remove legacy yet)
3. **Rewrite services** to use `RequestContextHolder` + canonical repo methods (remove DB injection for data ops; keep for transaction management only)
4. **Update bootstrap registrations** to inject `RequestContextHolder` instead of `BranchContext` where applicable
5. **Freeze the legacy allowlist** in `guardrail_id_only_repo_api_freeze.php`
6. **Expand `guardrail_service_layer_db_ban.php`** to include the domain's service files
7. **Run and pass both guardrails**
8. **Add a focused behavior verification script** in `system/scripts/read-only/`
9. **Close the phase** in `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`

---

## Related canonical references

- `docs/ARCHITECTURE-RESET-2026-CANONICAL-ROADMAP.md` — why the reset exists; what is superseded
- `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` — live execution queue
- `system/docs/FOUNDATION-A6-GUARDRAILS-POLICY-01.md` — guardrail scripts and expansion policy
- `system/docs/FOUNDATION-KERNEL-ARCHITECTURE-01.md` — kernel contracts (TenantContext, AuthorizerInterface, etc.)
- `system/docs/ROOT-CAUSE-REGISTER-01.md` — ROOT-01, ROOT-05 root family definitions
