# GitHub settings checklist (manual)

Repo-side config lives under `.github/` and `docs/`. Enable the following in the **GitHub UI** so guardrails actually gate merges.

## Branch protection for `main`

- [ ] **Require a pull request before merging**
- [ ] **Require approvals** (align with `.github/CODEOWNERS` once handles are real)
- [ ] **Dismiss stale pull request approvals when new commits are pushed** (recommended)
- [ ] **Require status checks to pass** before merging, including at least:
  - [ ] `pr-fast-guardrails` job (workflow **PR fast guardrails**)
  - [ ] `gitleaks`, `composer-audit`, and `dependency-review` jobs (workflow **Security guardrails**)
  - [ ] `canonical-release-law` (workflow **Canonical release law**)
  - [ ] **CodeQL** — workflow `.github/workflows/codeql.yml` (enable **Code scanning** in the Security tab if prompted)
- [ ] Optional: **OpenSSF Scorecard** if enabled for the org/repo
- [ ] **Require branches to be up to date before merging** (recommended for isolation work)
- [ ] **Restrict who can push to matching branches** — no direct pushes except admins / break-glass policy

Prefer **rulesets** (Settings → Rules → Rulesets) if your org uses them; mirror the same requirements.

## Code security and analysis

- [ ] **Dependency graph** enabled (org/repo settings) so Dependabot and dependency review can see the graph.
- [ ] **Dependabot alerts** enabled.
- [ ] **Dependabot security updates** enabled; use **grouped security updates** (see `.github/dependabot.yml` for grouped Composer security grouping).
- [ ] **Code scanning** — enable **CodeQL** for PHP (`.github/workflows/codeql.yml` + `.github/codeql/codeql-config.yml`; turn on “Code scanning” in the Security tab if prompted).
- [ ] **Secret scanning** — enable for the repo (and org if available).
- [ ] **Push protection** for secrets — enable to block accidental credential pushes.

## Private vulnerability reporting

- [ ] Enable **Private vulnerability reporting** (Settings → Security) so `SECURITY.md` matches repo behavior.

## CODEOWNERS

- [ ] Replace placeholder handles in `.github/CODEOWNERS` with real users or `org/team` slugs.
- [ ] Confirm **Require review from Code Owners** (or equivalent via rulesets) if you want ownership enforcement.

## What is already in-repo

- PR and issue templates, `SECURITY.md`, `CONTRIBUTING.md`, contracts under `system/docs/contracts/`, CI workflows, Dependabot config, and verification scripts under `system/scripts/ci/`.
