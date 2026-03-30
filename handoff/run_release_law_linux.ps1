param(
    [string]$OutputZip = "distribution/spa-skincare-system-blueprint-canonical-release.zip"
)

$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$networkName = "release-law-net-$PID"
$mysqlContainer = "release-law-mysql-$PID"

function Cleanup-ReleaseLawDocker {
    docker rm -f $mysqlContainer *> $null
    docker network rm $networkName *> $null
}

try {
    $null = Get-Command docker -ErrorAction Stop
    docker network create $networkName | Out-Null

    docker run -d --rm `
        --name $mysqlContainer `
        --network $networkName `
        -e MYSQL_ROOT_PASSWORD=root `
        -e MYSQL_DATABASE=spa_release_law `
        -e MYSQL_USER=spa `
        -e MYSQL_PASSWORD=spa `
        mysql:8.0 `
        --default-authentication-plugin=mysql_native_password | Out-Null

    $ready = $false
    for ($i = 0; $i -lt 60; $i++) {
        docker exec $mysqlContainer mysqladmin ping -h 127.0.0.1 -uroot -proot --silent *> $null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            break
        }
        Start-Sleep -Seconds 2
    }

    if (-not $ready) {
        throw "MySQL container did not become ready."
    }

    $containerScript = @'
set -euo pipefail
apt-get update >/dev/null
DEBIAN_FRONTEND=noninteractive apt-get install -y git unzip libzip-dev >/dev/null
docker-php-ext-install pdo_mysql zip >/dev/null
cp system/.env.example system/.env.local
php -r '
  \$path = "system/.env.local";
  \$text = file_get_contents(\$path);
  \$replacements = [
    "APP_ENV" => "local",
    "APP_DEBUG" => "false",
    "DB_HOST" => "__MYSQL_CONTAINER__",
    "DB_PORT" => "3306",
    "DB_DATABASE" => "spa_release_law",
    "DB_USERNAME" => "spa",
    "DB_PASSWORD" => "spa",
  ];
  foreach (\$replacements as \$key => \$value) {
    \$text = preg_replace("/^" . preg_quote(\$key, "/") . "=.*/m", \$key . "=" . \$value, \$text);
  }
  file_put_contents(\$path, \$text);
'
php system/scripts/release/run_canonical_release_law.php --output-zip='__OUTPUT_ZIP__'
'@
    $containerScript = $containerScript.Replace('__MYSQL_CONTAINER__', $mysqlContainer).Replace('__OUTPUT_ZIP__', $OutputZip)

    docker run --rm `
        --network $networkName `
        -v "${repoRoot}:/workspace" `
        -w /workspace `
        -e COMPOSER_BIN=composer `
        composer:2 bash -lc $containerScript

    if ($LASTEXITCODE -ne 0) {
        throw "Canonical release law returned non-zero."
    }

    $textReport = Join-Path $repoRoot "distribution/release-law/canonical-release-law-report.txt"
    $jsonReport = Join-Path $repoRoot "distribution/release-law/canonical-release-law-report.json"
    Write-Output "Release law completed."
    Write-Output "Text report: $textReport"
    Write-Output "JSON report: $jsonReport"
} finally {
    Cleanup-ReleaseLawDocker
}
