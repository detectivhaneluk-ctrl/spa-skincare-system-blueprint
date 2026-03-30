#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_zip="${1:-distribution/spa-skincare-system-blueprint-canonical-release.zip}"
network_name="release-law-net-$$"
mysql_container="release-law-mysql-$$"

cleanup() {
  docker rm -f "$mysql_container" >/dev/null 2>&1 || true
  docker network rm "$network_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

command -v docker >/dev/null 2>&1 || { echo "docker is required" >&2; exit 1; }

docker network create "$network_name" >/dev/null

docker run -d --rm \
  --name "$mysql_container" \
  --network "$network_name" \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=spa_release_law \
  -e MYSQL_USER=spa \
  -e MYSQL_PASSWORD=spa \
  mysql:8.0 \
  --default-authentication-plugin=mysql_native_password >/dev/null

for _ in $(seq 1 60); do
  if docker exec "$mysql_container" mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

docker exec "$mysql_container" mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1 || {
  echo "MySQL container did not become ready." >&2
  exit 1
}

docker run --rm \
  --network "$network_name" \
  -v "$repo_root:/workspace" \
  -w /workspace \
  -e COMPOSER_BIN=composer \
  composer:2 bash -lc "
    set -euo pipefail
    apt-get update >/dev/null
    DEBIAN_FRONTEND=noninteractive apt-get install -y git unzip libzip-dev >/dev/null
    docker-php-ext-install pdo_mysql zip >/dev/null
    cp system/.env.example system/.env.local
    php -r '
      \$path = \"system/.env.local\";
      \$text = file_get_contents(\$path);
      \$replacements = [
        \"APP_ENV\" => \"local\",
        \"APP_DEBUG\" => \"false\",
        \"DB_HOST\" => \"${mysql_container}\",
        \"DB_PORT\" => \"3306\",
        \"DB_DATABASE\" => \"spa_release_law\",
        \"DB_USERNAME\" => \"spa\",
        \"DB_PASSWORD\" => \"spa\",
      ];
      foreach (\$replacements as \$key => \$value) {
        \$text = preg_replace(\"/^\" . preg_quote(\$key, \"/\") . \"=.*/m\", \$key . \"=\" . \$value, \$text);
      }
      file_put_contents(\$path, \$text);
    '
    php system/scripts/release/run_canonical_release_law.php --output-zip='${output_zip}'
  "

report_path="$repo_root/distribution/release-law/canonical-release-law-report.txt"
json_report="$repo_root/distribution/release-law/canonical-release-law-report.json"

echo "Release law completed."
echo "Text report: $report_path"
echo "JSON report: $json_report"
if [[ -f "$report_path" ]]; then
  echo
  sed -n '1,40p' "$report_path"
fi
