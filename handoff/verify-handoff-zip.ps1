# Release / acceptance: same forbidden-entry rules as build-final-zip.ps1 (HandoffZipRules.ps1).
# Example: .\verify-handoff-zip.ps1 -ZipPath distribution/spa-skincare-system-blueprint-HANDOFF-FOR-UPLOAD.zip  (relative to repo root)
param(
    [Parameter(Mandatory = $true)]
    [string]$ZipPath,
    [string]$FailureReportPath = ""
)

$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
if ([string]::IsNullOrWhiteSpace($FailureReportPath)) {
    $FailureReportPath = Join-Path $repoRoot "handoff/verify-handoff-artifacts.failures.txt"
}

. (Join-Path $PSScriptRoot "HandoffZipRules.ps1")

$resolvedZip = if ([System.IO.Path]::IsPathRooted($ZipPath)) { $ZipPath } else { Join-Path $repoRoot $ZipPath }
if (-not (Test-Path $resolvedZip)) {
    Write-Error "ZIP not found: $resolvedZip"
    exit 2
}

$violations = Get-HandoffZipForbiddenEntries -ZipPath $resolvedZip
if ($violations.Count -eq 0) {
    if (Test-Path $FailureReportPath) {
        Remove-Item $FailureReportPath -Force
    }
    Write-Output "OK: no forbidden artifacts in $(Resolve-Path $resolvedZip)"
    exit 0
}

$reportDir = Split-Path -Parent $FailureReportPath
if (-not (Test-Path $reportDir)) {
    New-Item -ItemType Directory -Path $reportDir | Out-Null
}
$violations | Set-Content -Path $FailureReportPath -Encoding utf8
Write-Error "Handoff ZIP verification failed; see $FailureReportPath"
exit 1
