-- FOUNDATION-HARDENING-WAVE: immutable sell-time entitlement snapshots (memberships, packages, public-commerce package purchases).

ALTER TABLE membership_sales
    ADD COLUMN definition_snapshot_json JSON NULL COMMENT 'Immutable snapshot of sold membership definition at sale creation' AFTER sold_by_user_id;

ALTER TABLE client_memberships
    ADD COLUMN entitlement_snapshot_json JSON NULL COMMENT 'Immutable entitlement truth at grant (from sale snapshot or manual assign moment)' AFTER notes;

ALTER TABLE client_packages
    ADD COLUMN package_snapshot_json JSON NULL COMMENT 'Immutable package entitlement snapshot at assignment/fulfillment' AFTER notes;

ALTER TABLE public_commerce_purchases
    ADD COLUMN package_snapshot_json JSON NULL COMMENT 'Immutable package snapshot at purchase initiation (public-commerce)' AFTER membership_definition_id;
