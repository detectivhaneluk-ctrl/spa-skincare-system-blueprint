-- PUBLIC-CLIENT-RESOLUTION-DEDUPE-01: persist how anonymous commerce checkout resolved the purchaser client.

ALTER TABLE public_commerce_purchases
    ADD COLUMN client_resolution_reason VARCHAR(64) NULL DEFAULT NULL AFTER client_id;
