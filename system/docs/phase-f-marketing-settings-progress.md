# Phase F — Marketing Settings audit + foundation

Backend-first; settings architecture only. No campaigns, automations, or UI-heavy marketing tools.

---

## Findings (audit)

- **Settings keys:** No marketing-related keys existed before this phase. Roadmap (ADMIN-SETTINGS-BACKLOG-ROADMAP) lists "Marketing settings — Missing backend; clients have marketing_opt_in; no config for consent labels, default opt-in, campaign tracking, or integrations. Can start with settings keys only."
- **Controllers/services/views:** Clients: `ClientController` create/store, edit/update; `ClientService` create/update; `ClientRepository` create/update include `marketing_opt_in`. Client create/edit/show views display a marketing opt-in checkbox or value. No marketing module logic beyond the client field. No campaign/template/preferences logic.
- **Touchpoints:** Client create form: checkbox `marketing_opt_in` (POST); default was unchecked (form shows unchecked when `$client` empty). Client edit form: same checkbox. No email/notification/consent or public-booking use of marketing_opt_in in the codebase beyond storing and displaying it.
- **Conclusion:** The only safe, low-risk settings with clear enforcement points are: (1) **default_opt_in** — default for new clients’ marketing_opt_in checkbox on the create form; (2) **consent_label** — label text next to the checkbox on client create/edit forms. Both have immediate architectural value and a clear place to apply (client create/edit views and create form initial state).

---

## Chosen settings keys

| Key | Type | Default | Why safe now |
|-----|------|---------|--------------|
| `marketing.default_opt_in` | bool | false | Only sets the **initial** checked state of the marketing checkbox when creating a new client. Store still uses POST; no change to validation or persistence rules. |
| `marketing.consent_label` | string | "Marketing communications" | Display-only label on client create and edit forms. No business logic; fallback if empty. Max 255 chars in setter. |

---

## Where they are used

- **Settings:** Group `marketing`; getMarketingSettings(?int $branchId), setMarketingSettings(array, ?int $branchId). Persistence in `settings` table; seed 008 for branch_id 0.
- **Client create view:** Checkbox checked = `isset($client['marketing_opt_in']) ? (int)$client['marketing_opt_in'] === 1 : !empty($marketing['default_opt_in'])`. Label = `$marketing['consent_label'] ?? 'Marketing communications'`.
- **Client edit view:** Label = `$marketing['consent_label'] ?? 'Marketing communications'`. Checkbox value still from `$client['marketing_opt_in']`.
- **ClientController:** create(), store() (error path), edit(), update() (error path) pass `$marketing = $this->settings->getMarketingSettings()` to the view.

---

## Changed files

| File | Change |
|------|--------|
| `system/core/app/SettingsService.php` | MARKETING_KEYS; getMarketingSettings, setMarketingSettings. |
| `system/modules/settings/controllers/SettingsController.php` | index: pass $marketing; isGroupedKey: marketing.; store: block for marketing.*. |
| `system/modules/settings/views/index.php` | Marketing section (default_opt_in, consent_label); Other excludes marketing. |
| `system/data/seeders/008_seed_phase_f_marketing_settings.php` | **New.** Marketing defaults (branch_id 0). |
| `system/scripts/seed.php` | require 008. |
| `system/modules/clients/controllers/ClientController.php` | SettingsService injected; create(), store() error path, edit(), update() error path pass $marketing. |
| `system/modules/clients/views/create.php` | Checkbox initial state from default_opt_in; label from consent_label. |
| `system/modules/clients/views/edit.php` | Label from consent_label. |
| `system/modules/bootstrap.php` | ClientController receives SettingsService. |

---

## What was postponed

- **Campaign tracking, integrations, automations:** Not in scope; no backend for them yet.
- **Per-branch marketing settings in admin UI:** Backend supports branch_id in get/set; settings page uses default branch 0. Branch selector can be added later.
- **Using marketing_opt_in in email/notifications/consent/public-booking:** No touchpoints added; add when those flows are implemented.
- **Marketing module (campaigns, templates, preferences):** Master-data/feature work; not part of settings foundation.
- **Extra keys** (e.g. reminder text, unsubscribe page URL): Add when there is a clear enforcement point.

---

## Manual QA checklist

1. **Persistence and UI**  
   Run seed (include 008). Open /settings → Marketing section. Default: "Default opt-in for new clients" off, "Consent label" = "Marketing communications". Change consent label to "Newsletter and offers", save, reload → value persists. Turn "Default opt-in for new clients" on, save, reload → persists.

2. **default_opt_in**  
   With default opt-in **off**, open /clients/create → marketing checkbox unchecked. Turn default opt-in **on**, save settings, open /clients/create again → checkbox **checked**. Create client without changing it → client saved with marketing_opt_in 1. Create another client and uncheck → saved as 0.

3. **consent_label**  
   Set consent label to "Send me promotions". Open /clients/create → label next to checkbox shows "Send me promotions". Open /clients/{id}/edit → same label. Create/edit client → behaviour unchanged; only label text differs.

4. **Validation re-display**  
   Create client with first name empty, leave marketing checked → form re-displays with errors and marketing still checked. Edit client, break required field, submit → form re-displays with marketing label and value preserved.

5. **Backward compatibility**  
   If 008 has not run, getMarketingSettings() returns default_opt_in false, consent_label "Marketing communications". Create/edit forms still render; label shows "Marketing communications".

---

## Phase F acceptance readiness

**Phase F (Marketing Settings foundation) is acceptance-ready.** Grouped settings are registered, persisted, and retrieved with branch-aware get/set; defaults are seeded; minimal Marketing section exists on the settings page; client create form uses default_opt_in for initial checkbox state and consent_label for the checkbox label; client edit form uses consent_label. No campaigns or automations were added. Only the two low-risk keys with clear enforcement points were implemented.
