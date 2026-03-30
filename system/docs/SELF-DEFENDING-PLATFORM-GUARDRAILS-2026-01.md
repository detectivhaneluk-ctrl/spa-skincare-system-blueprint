# SELF-DEFENDING-PLATFORM-GUARDRAILS-2026-01

Repo-side **self-defending** guardrails: CI workflows, verification scripts, contracts, and contributor-facing docs. No application UI changes.

## Implemented (in-repo)

| Area | Location |
|------|----------|
| Fast PR checks | `.github/workflows/pr-fast-guardrails.yml` (`pr-fast-guardrails` job) |
| Security scans / audit / secret scan / dependency review | `.github/workflows/security-guardrails.yml` |
| Canonical release law | `.github/workflows/tenant-isolation-gate.yml` (`canonical-release-law` job) |
| Code scanning (PHP) | `.github/workflows/codeql.yml` |
| Dependabot (Composer + Actions) | `.github/dependabot.yml` |
| Path + architecture gates | `system/scripts/ci/*.php` |
| Machine-readable contracts | `system/docs/contracts/` |
| Ownership hints | `.github/CODEOWNERS` |
| Contributor + GitHub UI checklist | `CONTRIBUTING.md`, `docs/GITHUB-SETTINGS-CHECKLIST.md` |
| Scoped PHPStan | `composer.json` (`composer run phpstan`), `phpstan.neon.dist` |

## OPEN (honest limits)

1. **Branch protection / rulesets** — Must be enabled in GitHub (see `docs/GITHUB-SETTINGS-CHECKLIST.md`); the repo cannot enforce merges from YAML alone.
2. **`CODEOWNERS` placeholders** — Replace `@REPLACE_WITH_GITHUB_USER_OR_ORG_TEAM` with real users or teams for review routing to take effect.
3. **PHPStan scope** — Analysis is intentionally limited to `system/scripts/ci/` until a baseline or cleanup covers broader `system/`.
4. **Lock file scope** — `composer.lock` pins dev dependencies (PHPStan), not a broad application dependency tree; expanding locked `require` is a separate adoption decision.
5. **Dependency review** — Requires dependency graph / appropriate GitHub plan; may no-op or warn in some setups.
6. **Gitleaks / CodeQL** — Policy and secret scanning must be allowed by org/repo settings; first-time CodeQL may need enabling in the Security tab.

## Proof / local parity

- Fast scripts: `composer validate --strict --no-check-publish`, `php system/scripts/ci/verify_forbidden_tracked_paths.php`, `composer run phpstan`
- Full gate: `composer run release-law` (Linux / CI)
