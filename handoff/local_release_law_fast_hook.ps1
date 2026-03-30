param()

$ErrorActionPreference = "Stop"

$repoRoot = (git rev-parse --show-toplevel).Trim()
if ([string]::IsNullOrWhiteSpace($repoRoot)) {
    throw "Unable to resolve repository root."
}

Set-Location $repoRoot

$stagedPaths = @(git diff --cached --name-only --diff-filter=ACMR)
if ($stagedPaths.Count -eq 0) {
    exit 0
}

function Resolve-RealCasePath {
    param(
        [string]$RootPath,
        [string]$RelativePath
    )

    $segments = $RelativePath.Replace('\', '/').Trim('/').Split('/', [System.StringSplitOptions]::RemoveEmptyEntries)
    $current = $RootPath
    $resolved = @()
    foreach ($segment in $segments) {
        if (-not (Test-Path -LiteralPath $current)) {
            return $null
        }
        $child = Get-ChildItem -LiteralPath $current -Force | Where-Object { $_.Name.ToLowerInvariant() -eq $segment.ToLowerInvariant() } | Select-Object -First 1
        if ($null -eq $child) {
            return $null
        }
        $resolved += $child.Name
        $current = $child.FullName
    }
    return ($resolved -join '/')
}

$gitTracked = @(git ls-files)
$seen = @{}
$errors = New-Object System.Collections.Generic.List[string]

foreach ($path in $gitTracked + $stagedPaths) {
    $normalized = $path.Replace('\', '/')
    $key = $normalized.ToLowerInvariant()
    if ($seen.ContainsKey($key) -and $seen[$key] -ne $normalized) {
        $errors.Add("duplicate logical path differs only by case: $($seen[$key]) | $normalized")
    } else {
        $seen[$key] = $normalized
    }
}

foreach ($path in $stagedPaths) {
    $normalized = $path.Replace('\', '/')
    $fullPath = Join-Path $repoRoot $normalized
    if (-not (Test-Path -LiteralPath $fullPath)) {
        continue
    }
    $realCase = Resolve-RealCasePath -RootPath $repoRoot -RelativePath $normalized
    if ($null -eq $realCase) {
        $errors.Add("staged path missing from filesystem: $normalized")
        continue
    }
    if ($realCase -cne $normalized) {
        $errors.Add("filesystem case mismatch: staged=$normalized real=$realCase")
    }
}

if ($errors.Count -gt 0) {
    $errors | ForEach-Object { Write-Error $_ }
    Write-Error "Release-law fast hook blocked this commit. Run handoff/run_release_law_linux.ps1 for the canonical Linux gate."
    exit 1
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($null -ne $php) {
    $tempFile = New-TemporaryFile
    try {
        Set-Content -Path $tempFile.FullName -Value ($stagedPaths -join [Environment]::NewLine) -Encoding UTF8
        & $php.Source "system/scripts/release/run_case_path_auditor.php" "--paths-file=$($tempFile.FullName)"
        exit $LASTEXITCODE
    } finally {
        Remove-Item $tempFile.FullName -Force -ErrorAction SilentlyContinue
    }
}

Write-Output "release-law fast hook: PASS"
exit 0
