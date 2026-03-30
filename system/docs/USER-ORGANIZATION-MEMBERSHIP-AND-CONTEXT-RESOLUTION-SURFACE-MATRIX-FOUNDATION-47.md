# USER-ORGANIZATION-MEMBERSHIP-AND-CONTEXT-RESOLUTION-SURFACE-MATRIX — FOUNDATION-47

Companion to **`USER-ORGANIZATION-MEMBERSHIP-AND-CONTEXT-RESOLUTION-POST-INTEGRATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-47-OPS.md`**.

## 1. Resolver decision tree (branch null path)

| Step | Condition | Outcome | `OrganizationContext` mode |
|------|-----------|---------|------------------------------|
| A | `branchId !== null` | Org from branch JOIN | `MODE_BRANCH_DERIVED` |
| B | `branchId === null` **and** `userId > 0` **and** `mCount === 1` **and** `getSingle...` ok | Membership org id | `MODE_MEMBERSHIP_SINGLE_ACTIVE` |
| C | `branchId === null` **and** `userId > 0` **and** `mCount > 1` | Null org | `MODE_UNRESOLVED_AMBIGUOUS_ORGS` (stop) |
| D | `branchId === null` **and** (`userId === 0` **or** `mCount === 0`) | Fall through | — |
| E | Global `activeCount === 0` | Null | `MODE_UNRESOLVED_NO_ACTIVE_ORG` |
| F | Global `activeCount > 1` | Null | `MODE_UNRESOLVED_AMBIGUOUS_ORGS` |
| G | Global `activeCount === 1` | That org id | `MODE_SINGLE_ACTIVE_ORG_FALLBACK` |

**F-46-REPAIR:** When **`user_organization_memberships`** is **absent**, **`mCount` is always 0** from the service → always **step D** → legacy **E/F/G**.

## 2. Membership SQL invariants (when table exists)

| Invariant | Clause |
|-----------|--------|
| User scope | `m.user_id = ?` |
| Active membership | `m.status = 'active'` |
| Live organization | `organizations.deleted_at IS NULL` (via JOIN) |
| Ordering (list) | `ORDER BY m.organization_id ASC` |

## 3. F-46-REPAIR: table probe

| Check | Implementation |
|-------|----------------|
| Table present? | `information_schema.TABLES` where **`TABLE_SCHEMA = DATABASE()`** and **`TABLE_NAME = 'user_organization_memberships'`** |
| Cache | **`private ?bool $membershipTableAvailable`** on **`UserOrganizationMembershipReadRepository`** |

## 4. Public API surface (membership read)

| Method | Resolver uses? | Audit script uses? |
|--------|------------------|-------------------|
| `countActiveMembershipsForUser` | Yes | Yes |
| `getSingleActiveOrganizationIdForUser` | Yes | Yes |
| `listActiveOrganizationIdsForUser` | No (direct) | Yes; repo uses for `getSingle` |

## 5. Bootstrap / DI

| Binding | Location |
|---------|----------|
| `UserOrganizationMembershipReadRepository` | `register_organizations.php` |
| `UserOrganizationMembershipReadService` | `register_organizations.php` |
| `OrganizationContextResolver` (3-arg) | End of **`modules/bootstrap.php`** |

## 6. Schema reference (F-38)

| Column | Used when table exists? |
|--------|-------------------------|
| `user_id` | Yes |
| `organization_id` | Yes |
| `status` | Yes (`active` only) |
| `default_branch_id` | **No** |
| `created_at` / `updated_at` | **No** |
