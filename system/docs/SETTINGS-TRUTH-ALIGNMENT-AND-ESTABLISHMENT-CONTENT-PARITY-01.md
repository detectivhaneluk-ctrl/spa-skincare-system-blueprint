# SETTINGS-TRUTH-ALIGNMENT-AND-ESTABLISHMENT-CONTENT-PARITY-01

Date: 2026-03-24  
Scope: ZIP truth verification + settings taxonomy alignment + establishment content parity map.

## ZIP truth verification result

- `system/modules/settings/views/partials/shell.php`: recent sidebar/taxonomy changes were already present in real source files.
- `system/modules/settings/views/index.php`: recent modern establishment workspace was already present in real source files.
- Action taken: no re-application needed; only alignment/polish updates were applied on top.

## Final sidebar taxonomy (French canonical order)

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
   - Nouvel utilisateur (Backend pending)
6. Prestation
   - Nouvelle prestation
7. Forfaits
   - Nouveau Forfait
8. Séries
   - Nouvelle Série (Backend pending)
9. Memberships
   - Nouveau membership
10. Stockage documents
   - Nouveau type de document (Backend pending)

## Establishment parity classification

| Reference block | Classification | Current implementation status |
|---|---|---|
| Establishment profile identity | A (backed by current settings fields) | Editable via `settings[establishment.name]` and `settings[establishment.address]` |
| Primary contact | A (backed by current settings fields) | Editable via `settings[establishment.phone]` and `settings[establishment.email]` |
| Secondary contact | C (not yet backed by tenant settings backend) | Displayed as explicit Backend pending state |
| Opening hours | B (backed elsewhere in system) | Read-only summary with link to related management area |
| Closure dates | B (backed elsewhere in system) | Read-only summary with link to related management area |
| Website/account/location metadata | B (backed elsewhere in system) | Read-only summary with branch-linked management pointer |

## Establishment blocks by behavior

- Editable now:
  - Profil établissement
  - Paramètres régionaux
  - Actions (save)
- Read-only summaries:
  - Heures d'ouverture
  - Dates de fermeture
  - Métadonnées web / compte / localisation
- Backend pending:
  - Contact secondaire

## Backend-safe boundaries preserved

- No SettingsController flow changes.
- No SettingsService contract/key changes.
- No input name changes for establishment writes.
- No fake working routes were introduced for pending sections.
