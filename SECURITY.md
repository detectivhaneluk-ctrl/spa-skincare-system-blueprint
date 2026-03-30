# Security policy

## Supported versions

This repository is an application **blueprint** / source tree. Security fixes are applied on the **default branch** (`main`) unless a maintainer publishes a separate support policy for a named release line.

## Reporting a vulnerability

**Please do not** open a public GitHub issue for undisclosed security vulnerabilities.

1. Use **GitHub private vulnerability reporting** for this repository (enable under **Settings → Security → Code security and analysis** if not already on), **or**
2. Contact the repository maintainers through the private channel your organization defines.

Include:

- A concise description and impact (confidentiality / integrity / availability).
- Affected component (e.g. auth, tenant scope, file upload, public API).
- Steps to reproduce or a proof-of-concept where safe.
- Any known CVEs or dependency advisories you believe apply.

We aim to acknowledge reports within a few business days; resolution time depends on severity and release-law / proof requirements for this codebase.

## Scope notes

- **Tenant isolation** and **org/branch scope** are treated as security-relevant; regressions there are in scope for coordinated disclosure.
- Generated artifacts under `distribution/release-law/` and `vendor/` are not authoritative source; do not report issues against generated CI output alone.

## Supply chain

See `system/docs/RELEASE-INTEGRITY.md` for how canonical build and verification are defined in this repo.
