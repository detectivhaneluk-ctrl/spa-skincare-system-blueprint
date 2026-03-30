# Release integrity (SLSA-aligned framing)

This document states **what we treat as trustworthy** for this blueprint. It is not a formal SLSA attestation; it maps the same questions to **concrete repo artifacts and workflows**.

## What is built

- The **canonical application source** is this repository’s `system/` tree plus Composer autoload metadata (`composer.json`).
- **Handoff ZIPs** and other distributable bundles are **not** trusted unless produced by the **canonical release law** path (see below).

## From where

- **Git** history on the protected default branch (`main`) after review and CI.
- **Dependencies**: PHP runtime `^8.2` per `composer.json`. **`composer.lock`** is committed to pin **dev-only** tooling (e.g. PHPStan); runtime `require` remains PHP-only. Supply-chain review uses **Dependabot**, **`composer audit`**, and **dependency review** on pull requests when applicable.

## With which workflow

| Step | Mechanism |
|------|-------------|
| Fast feedback on PRs | `.github/workflows/pr-fast-guardrails.yml` |
| Security scans / audits | `.github/workflows/security-guardrails.yml` |
| Full tenant + packaging truth | `.github/workflows/tenant-isolation-gate.yml` (canonical **release law**) |
| Optional static analysis | `composer run phpstan` (`phpstan.neon.dist`; scoped paths) |
| Code scanning (PHP) | `.github/workflows/codeql.yml` |
| Dependabot | `.github/dependabot.yml` |

Local parity: `handoff/run_release_law_linux.sh` / `.ps1` and `composer run release-law`.

## Canonical artifact

- The **canonical integrity record** for automation is the **release-law report** emitted under `distribution/release-law/` **during CI or a local Linux run**. That directory is **gitignored**; do not commit reports as source of truth.
- A **merge to `main` with green release-law** is the bar for trusting the tree for downstream packaging.

## OPEN (honest limits)

- Without **signed tags** or **provenance attestations**, consumers cannot cryptographically verify builder identity beyond GitHub’s platform guarantees.
- Expanding **`composer.lock`** beyond dev tooling (to pin all runtime Composer packages) is a separate adoption decision.
- **Scorecard** (if enabled) provides a third-party readout; it does not replace release law.
