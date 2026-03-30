# Repo / runtime local-file awareness (informational; always exit 0).
#
# Paths listed here match what handoff/HandoffZipRules.ps1 EXCLUDES from the source ZIP.
# They are EXPECTED on a dev machine (e.g. system/.env.local) and must NOT be deleted
# just to satisfy packaging. The strict gate is verify-handoff-zip.ps1 on the built artifact.
#
# See also: handoff/build-final-zip.ps1, handoff/verify-handoff-zip.ps1

$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
. (Join-Path $PSScriptRoot "HandoffZipRules.ps1")

function Get-RepoRelativePath {
    param(
        [string]$BasePath,
        [string]$TargetPath
    )
    $base = [System.Uri]((Resolve-Path $BasePath).Path.TrimEnd('\') + '\')
    $target = [System.Uri]((Resolve-Path $TargetPath).Path)
    return [System.Uri]::UnescapeDataString($base.MakeRelativeUri($target).ToString()).Replace('\', '/')
}

$found = New-Object System.Collections.Generic.List[string]
Get-ChildItem -LiteralPath $repoRoot -Recurse -File -Force -ErrorAction SilentlyContinue | ForEach-Object {
    if ($_.FullName -match '[\\/]\.git[\\/]') {
        return
    }
    $relative = Get-RepoRelativePath -BasePath $repoRoot -TargetPath $_.FullName
    $normalized = Normalize-HandoffRepoRelativePath -RelativePath $relative
    $reason = Test-HandoffPackagedPathForbidden -NormalizedRelative $normalized
    if ($null -ne $reason) {
        $found.Add($relative)
    }
}

Write-Output "Handoff ZIP exclusion awareness (local working tree under $repoRoot)"
Write-Output "These paths are OK on disk for local runtime/build; they are excluded from handoff ZIPs by build-final-zip.ps1."
Write-Output "Strict check: after build, run verify-handoff-zip.ps1 -ZipPath <zip> (must report OK)."
Write-Output ""
if ($found.Count -eq 0) {
    Write-Output '(none found: no system/.env, system/.env.local, repo-wide *.zip, repo-wide *.log, or system/storage/logs|backups|sessions artifacts)'
} else {
    Write-Output 'Present locally (excluded from ZIP when packaging):'
    foreach ($p in ($found | Sort-Object -Unique)) {
        Write-Output "  - $p"
    }
}
Write-Output ''
Write-Output 'OK: local tree scan complete (informational only; exit 0).'
exit 0
