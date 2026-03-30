-- MEMBERSHIP-AUTHORITATIVE-ISSUANCE-HARDENING-01
-- Narrow index for overlap / duplicate issuance checks keyed by client + definition + branch scope.

ALTER TABLE client_memberships
    ADD INDEX idx_client_memberships_client_def_branch (client_id, membership_definition_id, branch_id);
