# Shared handoff ZIP hygiene rules (packaging + ZIP verification only).
#
# Enforcement: handoff/build-final-zip.ps1 applies these when selecting files AND when scanning the produced ZIP
# (Get-HandoffZipForbiddenEntries), then runs system/scripts/read-only/verify_handoff_zip_rules_readonly.php (must stay in sync).
#
# Test-HandoffPackagedPathForbidden: paths that must NEVER appear inside a distributed source/handoff ZIP.
# Local working trees MAY still contain system/.env.local, system/.env, handoff/*.zip, *.log for runtime/build;
# build-final-zip.ps1 excludes these from the archive. Do not delete local .env.local solely to pass a worktree scan.
# Repository root `.gitignore` ignores `**/*.zip` and `**/*.log` so archives/logs are never treated as canonical source truth.
#
# Forbidden inside ZIP: system/.env, system/.env.local, any repo path `**/.env` / `**/.env.*` except `**/.env.example`,
#   any **/*.zip (nested archives anywhere), any **/*.log,
#   system/storage/logs/, backups/, sessions/, framework/cache|sessions|views/ (except **/.gitkeep),
#   any **/node_modules/**, workers/image-pipeline/node_modules/, .DS_Store, Thumbs.db,
#   system/docs/*RESULT.txt (pasted proof transcripts).
# Always allowed in ZIP: system/.env.example (template; non-secret).

function Normalize-HandoffRepoRelativePath {
    param([string]$RelativePath)
    return $RelativePath.TrimStart('./').Replace('\', '/').ToLowerInvariant()
}

function Test-HandoffPackagedPathForbidden {
    param([string]$NormalizedRelative)
    if ($NormalizedRelative -eq "system/.env") {
        return "forbidden path: system/.env"
    }
    if ($NormalizedRelative -eq "system/.env.local") {
        return "forbidden path: system/.env.local"
    }
    # Any `.env` / `.env.*` secret file anywhere, except `*.env.example` (template).
    if ($NormalizedRelative -match '(^|/)\.env($|\.)') {
        if (-not $NormalizedRelative.EndsWith('.env.example')) {
            return "forbidden path: env secret file ($NormalizedRelative)"
        }
    }
    if ($NormalizedRelative.Contains('/node_modules/')) {
        return "forbidden path: node_modules ($NormalizedRelative)"
    }
    if ($NormalizedRelative -eq ".ds_store" -or $NormalizedRelative.EndsWith('/.ds_store')) {
        return "forbidden path: OS junk (.DS_Store) ($NormalizedRelative)"
    }
    if ($NormalizedRelative -eq "thumbs.db" -or $NormalizedRelative.EndsWith('/thumbs.db')) {
        return "forbidden path: OS junk (Thumbs.db) ($NormalizedRelative)"
    }
    if ($NormalizedRelative.EndsWith(".zip")) {
        return "forbidden path: nested or generated zip archive ($NormalizedRelative)"
    }
    if ($NormalizedRelative.StartsWith("system/storage/logs/") -and $NormalizedRelative -ne "system/storage/logs/.gitkeep") {
        return "forbidden path: local storage log/debug under system/storage/logs/ ($NormalizedRelative)"
    }
    if ($NormalizedRelative.StartsWith("system/storage/sessions/") -and $NormalizedRelative -ne "system/storage/sessions/.gitkeep") {
        return "forbidden path: PHP session files under system/storage/sessions/ ($NormalizedRelative)"
    }
    if ($NormalizedRelative.StartsWith("workers/image-pipeline/node_modules/")) {
        return "forbidden path: worker node_modules ($NormalizedRelative)"
    }
    if ($NormalizedRelative.StartsWith("system/docs/") -and $NormalizedRelative.EndsWith("-result.txt")) {
        return "forbidden path: pasted docs proof transcript ($NormalizedRelative)"
    }
    if ($NormalizedRelative.StartsWith("system/storage/backups/")) {
        return "forbidden path: local storage backup under system/storage/backups/ ($NormalizedRelative)"
    }
    foreach ($fw in @('system/storage/framework/cache/', 'system/storage/framework/sessions/', 'system/storage/framework/views/')) {
        if ($NormalizedRelative.StartsWith($fw) -and -not $NormalizedRelative.EndsWith('/.gitkeep')) {
            return "forbidden path: framework runtime under ${fw} ($NormalizedRelative)"
        }
    }
    if ($NormalizedRelative.EndsWith(".log")) {
        return "forbidden path: runtime log ($NormalizedRelative)"
    }
    return $null
}

function Get-HandoffZipForbiddenEntries {
    param(
        [Parameter(Mandatory = $true)]
        [string]$ZipPath
    )

    Add-Type -AssemblyName System.IO.Compression | Out-Null
    $violations = New-Object System.Collections.Generic.List[string]
    $zipFile = [System.IO.File]::Open((Resolve-Path $ZipPath).Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read)
    try {
        $zipArchive = New-Object System.IO.Compression.ZipArchive($zipFile, [System.IO.Compression.ZipArchiveMode]::Read)
        try {
            foreach ($entry in $zipArchive.Entries) {
                $name = $entry.FullName.TrimEnd('/')
                if ([string]::IsNullOrEmpty($name)) { continue }
                $normalized = Normalize-HandoffRepoRelativePath -RelativePath $name
                $reason = Test-HandoffPackagedPathForbidden -NormalizedRelative $normalized
                if ($null -ne $reason) {
                    $violations.Add("$reason [zip entry: $($entry.FullName)]")
                }
            }
        }
        finally {
            $zipArchive.Dispose()
        }
    }
    finally {
        $zipFile.Dispose()
    }
    return , $violations.ToArray()
}
