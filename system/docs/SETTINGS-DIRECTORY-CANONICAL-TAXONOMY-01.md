# SETTINGS-DIRECTORY-CANONICAL-TAXONOMY-01

Date: 2026-03-24  
Scope: IA/navigation-only alignment of Settings directory taxonomy. No business logic refactor.

## Final canonical menu order

1. Paramètres Généraux
   - Informations Etablissement
   - Politique d'annulation
   - Paramètres des Rendez-vous
   - Paramètres de paiement
   - Modes de paiement personnalisés
   - Types de TVA
   - Répartition des TVA
   - Notifications internes
   - Matériel Informatique
   - Sécurité
   - Paramètres Marketing
   - Paramètres de liste d'attente
   - Réservation en Ligne
   - Paramètres des memberships
2. Espaces
   - Nouvel espace
3. Matériel
   - Nouveau Matériel
4. Employés
   - Nouvel Employé
   - Groupes
   - Heures et salaires du personnel
5. Connexions
   - Nouvel utilisateur
6. Prestation
   - Nouvelle prestation
7. Forfaits
   - Nouveau Forfait
8. Séries
   - Nouvelle Série
9. Memberships
   - Nouveau membership
10. Stockage documents
   - Nouveau type de document

## Working routes (live links)

- Informations Etablissement -> `/settings?section=establishment`
- Politique d'annulation -> `/settings?section=cancellation`
- Paramètres des Rendez-vous -> `/settings?section=appointments`
- Paramètres de paiement -> `/settings?section=payments`
- Modes de paiement personnalisés -> `/settings/payment-methods`
- Types de TVA -> `/settings/vat-rates`
- Répartition des TVA -> `/reports/vat-distribution`
- Notifications internes -> `/settings?section=notifications`
- Matériel Informatique -> `/settings?section=hardware`
- Sécurité -> `/settings?section=security`
- Paramètres Marketing -> `/settings?section=marketing`
- Paramètres de liste d'attente -> `/settings?section=waitlist`
- Réservation en Ligne -> `/settings?section=public_channels`
- Paramètres des memberships -> `/settings?section=memberships`
- Nouvel espace -> `/services-resources/rooms/create`
- Nouveau Matériel -> `/services-resources/equipment/create`
- Nouvel Employé -> `/staff/create`
- Groupes -> `/staff/groups`
- Heures et salaires du personnel -> `/payroll/runs`
- Nouvelle prestation -> `/services-resources/services/create`
- Nouveau Forfait -> `/packages/create`
- Nouveau membership -> `/memberships/create`

## Pending placeholders (non-clickable, backend/UI pending)

- Connexions / Nouvel utilisateur
- Séries / Nouvelle Série
- Stockage documents / Nouveau type de document

## Intentional separation note

`Paramètres des memberships` and `Nouveau membership` remain intentionally separate:

- `Paramètres des memberships` belongs to tenant Settings behavior/configuration.
- `Nouveau membership` is catalog/module CRUD creation in the Memberships domain.
- Keeping both entries distinct avoids conflating configuration with entity authoring and preserves existing backend boundaries.
