# MARKETING-GIFT-CARD-TEMPLATES-REAL-BACKEND-INTEGRATION-03

This note is the **ZIP inclusion manifest** for the gift card templates domain. If any path below is missing from an exported archive, the UI will fall back to stubs or 500s.

## Physical paths (must appear in ZIP)

| Path |
|------|
| `system/modules/marketing/repositories/MarketingGiftCardTemplateRepository.php` |
| `system/modules/marketing/services/MarketingGiftCardTemplateService.php` |
| `system/modules/marketing/controllers/MarketingGiftCardTemplatesController.php` |
| `system/modules/marketing/views/gift-card-templates/index.php` |
| `system/modules/marketing/views/gift-card-templates/create.php` |
| `system/modules/marketing/views/gift-card-templates/edit.php` |
| `system/modules/marketing/views/gift-card-templates/images.php` |
| `system/modules/marketing/views/gift-card-templates/partials/storage-not-ready-notice.php` |
| `system/data/migrations/102_marketing_gift_card_templates_foundation.sql` |
| `system/routes/web/register_marketing.php` (must register all gift-card-template routes) |
| `system/modules/bootstrap/register_marketing.php` (must register repository, service, controller) |
| `system/modules/marketing/views/partials/marketing-top-nav.php` (`gift_cards` → `/marketing/gift-card-templates`) |
| `system/data/full_project_schema.sql` (canonical DDL for both tables) |
| `system/scripts/read-only/verify_marketing_gift_card_templates_post_migration_runtime_05.php` |

## Verifiers (run before zipping)

```text
php system/scripts/read-only/verify_marketing_gift_card_templates_zip_truth_recovery_02.php
php system/scripts/read-only/verify_marketing_gift_card_templates_backend_foundation_01.php
php system/scripts/read-only/verify_marketing_gift_card_templates_storage_honesty_hotfix_04.php
php system/scripts/read-only/verify_marketing_gift_card_templates_post_migration_runtime_05.php
```

Exit code **non-zero** on the recovery script means the tree is not safe to ship.

## Domain detail

See `MARKETING-GIFT-CARD-TEMPLATES-BACKEND-FOUNDATION-01-OPS.md` for schema, scope, clone rules, and image storage.
