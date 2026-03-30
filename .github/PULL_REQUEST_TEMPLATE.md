## Summary

<!-- What this PR changes and why (1–3 sentences). -->

## Release-law / proof

- [ ] This branch is **not** intended to merge with failing **Canonical release law** (full gate on the PR or latest push).
- [ ] **Fast PR guardrails** (composer validate, PHP lint, architecture/forbidden-path checks) are expected to pass.
- [ ] **Hotspot files** (repositories under `system/modules`, `system/core`, `system/scripts/read-only`, tenant gate script) include the required `@release-proof` metadata (see `system/docs/DONE-MEANS-PROVED.md`).
- [ ] **Task id** (charter / backlog id): 
- [ ] **Risk removed** (concrete failure mode, not “improve quality”): 
- [ ] **Proof command(s)** (scripts or release-law steps that demonstrate the claim): 
- [ ] **ROOT family** (from `system/docs/ROOT-CAUSE-REGISTER-01.md`, or `NONE` with justification): 

## Tenant / scope

- [ ] Changes respect **tenant / org / branch** scope rules (no silent widening; repair/global paths are explicitly named where applicable).

## Notes for reviewers

<!-- Optional: migration notes, follow-ups, OPEN items. -->
