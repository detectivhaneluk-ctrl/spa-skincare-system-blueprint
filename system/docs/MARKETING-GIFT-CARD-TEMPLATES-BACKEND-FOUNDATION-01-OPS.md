# MARKETING-GIFT-CARD-TEMPLATES-BACKEND-FOUNDATION-01 OPS

## Schema added

- `marketing_gift_card_templates`
  - Branch-scoped gift card template catalog with:
    - name
    - `clone_source_template_id`
    - sell flags (`sell_in_store_enabled`, `sell_online_enabled`)
    - `image_id` for current primary image
    - soft-delete/archive via `deleted_at` + `is_active`
- `marketing_gift_card_images`
  - Branch-scoped reusable image library for templates:
    - title/label
    - stored path + filename + mime + size
    - soft-delete/archive via `deleted_at` + `is_active`

Migration file: `system/data/migrations/102_marketing_gift_card_templates_foundation.sql`

## Scope contract chosen

- This domain is treated as **tenant branch-scoped marketing config**, consistent with existing `marketing` backend services in this repository.
- All repository reads/mutations enforce:
  - current branch (`branch_id`)
  - resolved tenant organization ownership via `OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause(...)`
- Cross-branch clone/update/archive/image actions are blocked by design.

## Clone behavior

- Create supports:
  - start from scratch
  - clone from existing template in the same branch scope
- Clone copies only safe editable fields:
  - sell-in-store enabled flag
  - sell-online enabled flag
  - primary image reference (`image_id`)
- Clone stores provenance in `clone_source_template_id`.
- No deep-copy of related entities beyond this domain wave.

## Image storage behavior

- Upload validation:
  - image MIME only (`jpeg/png/webp/gif`)
  - 5 MB max
- Storage path:
  - `system/storage/marketing/gift-card-images/YYYY/MM/<random>.ext`
- Database stores relative storage path/filename/mime/size.
- Delete is soft (`deleted_at`), and blocked when active templates still reference that image.

## Deferred items (intentional)

- No customer-facing buying/redeeming/balance/POS/checkout flows.
- No visual layout editor for template design content.
- No generic media subsystem refactor; only domain-local upload path used.
- No advanced pagination UI polish; backend supports `limit/offset` and active-only listing.

## ZIP / packaging truth (MARKETING-GIFT-CARD-TEMPLATES-ZIP-TRUTH-RECOVERY-02)

If an exported ZIP omits any of the paths below, admin gift card templates will be broken even when the canonical repo is correct. Before publishing a ZIP, run:

`php system/scripts/read-only/verify_marketing_gift_card_templates_zip_truth_recovery_02.php`

That script **exits non-zero** when required files, routes, DI registrations, nav link, migration `102`, or canonical schema DDL are missing from the tree. It optionally probes `storage_ready` / `storage_not_ready_skip_mutations` when the database is available.

## UI / storage honesty (MARKETING-GIFT-CARD-TEMPLATES-STORAGE-HONESTY-HOTFIX-04)

When tables are absent, the admin UI must not mimic an empty catalog. Static proof:

`php system/scripts/read-only/verify_marketing_gift_card_templates_storage_honesty_hotfix_04.php`

## Post-migration runtime (MARKETING-GIFT-CARD-TEMPLATES-POST-MIGRATION-RUNTIME-05)

After migration `102` is applied, full list/create/clone/edit/archive/pager/image flows should pass:

`php system/scripts/read-only/verify_marketing_gift_card_templates_post_migration_runtime_05.php`

If storage is not ready, the script exits with `runtime_05_status=PASS_NOT_READY_ONLY` and still validates static two-state UI contracts.

