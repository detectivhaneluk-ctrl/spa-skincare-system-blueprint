param(
    # Single canonical upload artifact for FULL ZIP truth audit (not under handoff/, to avoid confusion with ad-hoc handoff/*.zip trees).
    [string]$OutputZip = "distribution/spa-skincare-system-blueprint-HANDOFF-FOR-UPLOAD.zip",
    # Optional: full path to php.exe when `php` is not on PATH (e.g. Laragon).
    [string]$PhpExe = "",
    # Optional: PHP `ext` directory; when set, loads php_zip.dll via -d for PLT-PKG-08 verifier without editing php.ini.
    [string]$PhpExtensionDir = ""
)

$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$outputZipPath = Join-Path $repoRoot $OutputZip
$outputDir = Split-Path -Parent $outputZipPath

# PLT-REL-01: fail-closed static tenant-isolation proof before packaging (Tier A only; no DB).
$tenantGateScript = Join-Path $repoRoot "system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php"
if (-not (Test-Path $tenantGateScript)) {
    throw "Missing mandatory tenant-isolation gate: $tenantGateScript"
}
$phpCmd = if ($PhpExe -ne "") {
    if (-not (Test-Path -LiteralPath $PhpExe)) { throw "PhpExe not found: $PhpExe" }
    $PhpExe
} else {
    $phpExeFound = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $phpExeFound) {
        throw "php not found on PATH; pass -PhpExe and optionally -PhpExtensionDir (see build-final-zip.ps1 header)."
    }
    $phpExeFound.Source
}
$phpArgs = @()
if ($PhpExtensionDir -ne "") {
    if (-not (Test-Path -LiteralPath $PhpExtensionDir)) { throw "PhpExtensionDir not found: $PhpExtensionDir" }
    $extDir = (Resolve-Path -LiteralPath $PhpExtensionDir).Path
    $phpArgs = @("-d", "extension_dir=$extDir", "-d", "extension=php_zip.dll")
}
$zipExtProbe = & $phpCmd @phpArgs -r "echo extension_loaded('zip') ? '1' : '0';"
if ($zipExtProbe -ne '1') {
    throw "PHP ZipArchive extension (ext-zip) is required for PLT-PKG-08: enable extension=zip in php.ini, or pass -PhpExtensionDir <php>\ext (with php_zip.dll present)."
}
& $phpCmd @phpArgs $tenantGateScript
if ($LASTEXITCODE -ne 0) {
    throw "PLT-REL-01 tenant-isolation proof gate failed; fix verifiers before building handoff ZIP."
}

. (Join-Path $PSScriptRoot "HandoffZipRules.ps1")

function Get-RelativePath {
    param(
        [string]$BasePath,
        [string]$TargetPath
    )

    $base = [System.Uri]((Resolve-Path $BasePath).Path.TrimEnd('\') + '\')
    $target = [System.Uri]((Resolve-Path $TargetPath).Path)
    return [System.Uri]::UnescapeDataString($base.MakeRelativeUri($target).ToString())
}

if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

if (Test-Path $outputZipPath) {
    Remove-Item $outputZipPath -Force
}

# Packaging hygiene (must match HandoffZipRules.ps1):
# - Exclude system/.env and system/.env.local (keep system/.env.example). Local .env.local may stay on disk.
# - Exclude any *.zip under the repo (nested generated handoff or other archives)
# - Exclude all runtime *.log files anywhere under the repo
# - Exclude system/storage/logs/**, system/storage/backups/**, and system/storage/sessions/** (debug/log/backup/session artifacts)
# - Exclude workers/image-pipeline/node_modules/** and system/docs/*-RESULT.txt (see HandoffZipRules.ps1)
$files = Get-ChildItem -Path $repoRoot -Recurse -File -Force |
    Where-Object {
        $relative = (Get-RelativePath -BasePath $repoRoot -TargetPath $_.FullName).Replace('\', '/')
        $normalized = Normalize-HandoffRepoRelativePath -RelativePath $relative
        return $null -eq (Test-HandoffPackagedPathForbidden -NormalizedRelative $normalized)
    }

$zipFile = [System.IO.File]::Open($outputZipPath, [System.IO.FileMode]::Create)
try {
    $zipArchive = New-Object System.IO.Compression.ZipArchive($zipFile, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in $files) {
            $entryName = (Get-RelativePath -BasePath $repoRoot -TargetPath $file.FullName).Replace('\', '/')
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zipArchive,
                $file.FullName,
                $entryName,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    }
    finally {
        $zipArchive.Dispose()
    }
}
finally {
    $zipFile.Dispose()
}

$failureReport = Join-Path $repoRoot "handoff/verify-handoff-artifacts.failures.txt"
$violations = Get-HandoffZipForbiddenEntries -ZipPath $outputZipPath
if ($violations.Count -gt 0) {
    Remove-Item $outputZipPath -Force -ErrorAction SilentlyContinue
    $reportDir = Split-Path -Parent $failureReport
    if (-not (Test-Path $reportDir)) {
        New-Item -ItemType Directory -Path $reportDir | Out-Null
    }
    $violations | Set-Content -Path $failureReport -Encoding utf8
    throw "Packaging verification failed; output ZIP removed. See $failureReport"
}

# PLT-PKG-08 + FND-PKG-01: PHP twin must pass (parity with HandoffZipRules.ps1; fail-closed; catches PS/PHP rule drift).
$zipRulesPhp = Join-Path $repoRoot "system/scripts/read-only/verify_handoff_zip_rules_readonly.php"
if (-not (Test-Path $zipRulesPhp)) {
    Remove-Item $outputZipPath -Force -ErrorAction SilentlyContinue
    throw "Missing mandatory ZIP rules verifier: $zipRulesPhp"
}
$resolvedZip = (Resolve-Path $outputZipPath).Path
& $phpCmd @phpArgs $zipRulesPhp $resolvedZip
if ($LASTEXITCODE -ne 0) {
    Remove-Item $outputZipPath -Force -ErrorAction SilentlyContinue
    throw "verify_handoff_zip_rules_readonly.php failed (exit $LASTEXITCODE); output ZIP removed. Fix rules parity or ZIP contents (PLT-PKG-08 + FND-PKG-01)."
}

if (Test-Path $failureReport) {
    Remove-Item $failureReport -Force
}

Write-Output "Created ZIP: $outputZipPath"
Write-Output "UPLOAD_FOR_FULL_ZIP_TRUTH_AUDIT_ONLY: $outputZipPath"
