# USER-ORGANIZATION — BRANCH-DERIVED MEMBERSHIP ALIGNMENT DECISION MATRIX (FOUNDATION-61)

**Precondition:** Branch-derived path: `BranchContext::getCurrentBranchId() !== null`, `OrganizationContextResolver::activeOrganizationIdForActiveBranch` returned positive **`$orgId`**.

| Case | Condition | Recommended policy | Fail-open / fail-closed | Deny message id |
|------|-----------|---------------------|-------------------------|-----------------|
| **A** | `userId ≤ 0` (guest / no auth user id) | Skip alignment; `setFromResolution($orgId, MODE_BRANCH_DERIVED)` | **Open** | — |
| **B** | `user_organization_memberships` **absent** (`isMembershipTablePresent() === false`) | Skip alignment | **Open** | — |
| **C** | Table present, `userId > 0`, **0** active memberships | Skip alignment | **Open** | — |
| **D** | Exactly **1** active membership, org **=** `$orgId` | Allow | **Pass** | — |
| **E** | Exactly **1** active membership, org **≠** `$orgId` | **`DomainException` M1** | **Closed** | **M1** |
| **F** | **>1** active memberships, `$orgId` **∈** set | Allow | **Pass** | — |
| **G** | **>1** active memberships, `$orgId` **∉** set | **`DomainException` M2** | **Closed** | **M2** |

**Stable messages (implement verbatim):**

- **M1:** `Current branch organization is not authorized by the user's active organization membership.`
- **M2:** `Current branch organization is not among the user's active organization memberships.`

**Future wave:** Resolver-only — see **`USER-ORGANIZATION-BRANCH-DERIVED-MEMBERSHIP-ALIGNMENT-POLICY-DECISION-CLOSURE-FOUNDATION-61-OPS.md`** §8.
