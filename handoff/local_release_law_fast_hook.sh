#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

paths_file="$(mktemp)"
trap 'rm -f "$paths_file"' EXIT

git diff --cached --name-only --diff-filter=ACMR > "$paths_file"
if [[ ! -s "$paths_file" ]]; then
  exit 0
fi

if ! command -v php >/dev/null 2>&1; then
  echo "Release-law fast hook requires php for staged PHP path auditing." >&2
  echo "Run the canonical Linux gate instead: handoff/run_release_law_linux.sh" >&2
  exit 1
fi

php system/scripts/release/run_case_path_auditor.php --paths-file="$paths_file"
