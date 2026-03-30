param(
    [string]$OutputZip = "distribution/spa-skincare-system-blueprint-canonical-release.zip"
)

$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$linuxGate = Join-Path $PSScriptRoot "run_release_law_linux.ps1"
if (-not (Test-Path -LiteralPath $linuxGate)) {
    throw "Canonical Linux release-law entrypoint missing: $linuxGate"
}

Write-Output "Canonical ZIP creation is sealed behind the Linux release law."
Write-Output "Delegating to: $linuxGate"

& $linuxGate -OutputZip $OutputZip
if ($LASTEXITCODE -ne 0) {
    throw "Canonical release law returned non-zero; ZIP is CONTRADICTED and must not be trusted."
}
