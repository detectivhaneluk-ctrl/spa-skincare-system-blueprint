# Contributing

## No direct pushes to `main` without checks

**Policy:** Do **not** merge or push to `main` without:

1. A **pull request** reviewed per your org’s rules (see `.github/CODEOWNERS` once handles are real).
2. Passing **GitHub Actions** on that PR, including at least:
   - **PR fast guardrails** (`.github/workflows/pr-fast-guardrails.yml`)
   - **Security guardrails** where applicable (`.github/workflows/security-guardrails.yml`)
   - **Canonical release law** (`.github/workflows/tenant-isolation-gate.yml`) before merge to `main`.

Enforcement is a combination of **branch protection** (configure in GitHub — see `docs/GITHUB-SETTINGS-CHECKLIST.md`) and **local discipline**.

## Proof-first and backbone discipline

- Follow **`system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`** and **`system/docs/ROOT-CAUSE-REGISTER-01.md`**: backbone work should name **ROOT-** families where applicable.
- Hotspot edits must carry **`@release-proof`** metadata as described in **`system/docs/DONE-MEANS-PROVED.md`** (enforced in CI for changed files).
- Architecture contracts are machine-readable in **`system/docs/contracts/ARCHITECTURE-CONTRACTS.json`** and enforced by **`system/scripts/ci/verify_architecture_contracts.php`**.

## Local setup

- PHP **8.2+** and **Composer 2**.
- Optional: install the git hook from `.githooks/pre-commit` (see comments in that file) for fast case-path checks on staged files.

## Canonical verification

- Full gate: `composer run release-law` (or the Linux handoff scripts under `handoff/`).
- Fast local checks: `composer install` (installs PHPStan from `composer.lock`), `composer validate --strict --no-check-publish`, `php system/scripts/ci/verify_forbidden_tracked_paths.php`, and `composer run phpstan`.
