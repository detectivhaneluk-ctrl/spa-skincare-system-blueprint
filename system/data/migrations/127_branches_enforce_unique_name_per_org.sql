-- Migration 127: Enforce unique branch name per organisation (application-level rule).
--
-- Root cause (BIG-05B): BranchDirectory::createBranch() and updateBranch() lacked an isNameTaken()
-- guard. Two branches with identical names within the same organisation could be created, causing
-- getActiveBranchesForSelection() to return both rows and the branch <select> to render visually
-- duplicate options inside a single selector.
--
-- This migration soft-deletes the higher-id duplicate active branches (keeping the lowest id per
-- organisation+name pair) so that any pre-existing duplicates are resolved in existing deployments.
-- Application-level enforcement is added in BranchDirectory (isNameTaken check) to prevent recurrence.
-- No schema-level UNIQUE constraint is added because soft-delete rows would violate it on reuse.

UPDATE branches b1
INNER JOIN branches b2
    ON  b1.organization_id = b2.organization_id
    AND b1.name            = b2.name
    AND b1.id              > b2.id
    AND b2.deleted_at IS NULL
SET b1.deleted_at  = CURRENT_TIMESTAMP,
    b1.updated_at  = CURRENT_TIMESTAMP
WHERE b1.deleted_at IS NULL;
