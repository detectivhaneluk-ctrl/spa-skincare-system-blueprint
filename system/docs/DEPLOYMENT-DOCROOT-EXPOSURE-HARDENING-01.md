# Deployment document-root exposure — DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01

**Scope:** Production-safe HTTP surface. No application code changes required for correct hosting; this document is the handoff contract for operators.

## Intended production document root

Set the web server **DocumentRoot** (or platform equivalent) to the **`system/public`** directory inside this repository — i.e. the folder that contains `system/public/index.php` and `system/public/.htaccess`.

**Canonical HTTP entry file:** `system/public/index.php`

**Do not** point production DocumentRoot at:

- The repository root (the parent of `system/`)
- `system/` itself
- Any sibling folder (`archive/`, `handoff/`, `workers/`, `distribution/`)

## Why this matters

If DocumentRoot is above `system/public`, the server may **serve static files** (`.sql`, `.md`, `.env`, scripts, zips, internal docs) directly from disk without going through PHP. That bypasses routing and leaks source material, credentials, and operational detail.

The repository may include a **root `index.php`** for local convenience when the vhost points at the package root. That file does **not** make non-public directories safe to expose; it only boots the app for requests routed to `index.php`. **Production must not rely on it.**

## Forbidden paths (must never be web-accessible)

These paths exist in the tree and must not be reachable as static URLs from production:

| Path (repo-relative) | Risk |
|----------------------|------|
| `system/` (except what is **only** exposed intentionally under `system/public/` when that folder is the docroot) | Bootstrap, config, modules, `.env`, credentials |
| `system/config/` | Application secrets and environment-derived config |

The repository ships **`system/config/.htaccess`** with **deny-all** for Apache. If DocumentRoot is mistakenly pointed at `system/` or the repo root, that file reduces direct HTTP access to PHP config files (Nginx operators should use `location` blocks to deny `/system/config/`).
| `system/data/` (migrations, `full_project_schema.sql`, seeds) | Schema, operational SQL |
| `system/scripts/` | CLI/smoke/migration tooling |
| `system/docs/` | Internal runbooks and audits |
| `system/storage/` | Uploads, generated files, logs (see also `system/storage/.htaccess` for Apache) |
| `archive/` | Historical blueprint and exports |
| `handoff/` | Packaging and zip verification scripts |
| `workers/` | Worker images and job code |
| `distribution/` | Build artifacts (e.g. handoff zips) |
| `logs/`, `backups/`, `secrets/` (if present at repo top) | Operational data; must not be web roots or public static paths |
| `scripts/` (repo-top) | CLI or packaging tooling; not a public URL namespace |

**Never** place `.env`, `.env.local`, private keys, database dumps, log files, or zip archives under `system/public/`.

## Reference snippets (copy/paste)

Concrete fragments live next to this document and are verified by `verify_deployment_docroot_hardening_readonly_01.php`:

- `system/docs/deployment/apache-vhost.production-snippet.conf` — `DEPLOYMENT-REFERENCE-APACHE-MARKER-01`, `DEPLOYMENT-REFERENCE-APACHE-ELITE-MARKER-01` (must show `system/public` twice in examples)
- `system/docs/deployment/nginx-server.production-snippet.conf` — `DEPLOYMENT-REFERENCE-NGINX-MARKER-01`, `DEPLOYMENT-REFERENCE-NGINX-ELITE-MARKER-01` (must include concrete `root /var/www/spa/system/public` example)

Additional verifier anchors: `system/public/index.php` (`DEPLOYMENT-DOCROOT-CANONICAL-PUBLIC-ENTRY-MARKER-01`), `system/config/.htaccess` (`DEPLOYMENT-DOCROOT-SYSTEM-CONFIG-HTACCESS-MARKER-01`).

Repository root `index.php` refuses HTTP when `APP_ENV` is `production` or `prod` (`DEPLOYMENT-DOCROOT-ROOT-INDEX-PRODUCTION-BLOCK-MARKER-01`), forcing production traffic through `system/public` only.

## Apache

### Production (recommended)

Point the vhost `DocumentRoot` at `.../path/to/repo/system/public`.

The app ships `system/public/.htaccess` with rewrite rules to `index.php` for front controller behavior.

### Defense in depth (repo root docroot — not recommended)

If the server is mistakenly configured with DocumentRoot at the **repository root**, the root `.htaccess` includes rules that return **403** for direct requests under `archive/`, `distribution/`, `handoff/`, `system/`, `workers/`, and (when those top-level directories exist) `logs/`, `backups/`, `secrets/`, and `scripts/`. This does **not** replace setting DocumentRoot to `system/public`; it only limits damage from misconfiguration. The marker `DEPLOYMENT-DOCROOT-ROOT-DENY-DEPTH-MARKER-02` documents the extended deny set for operators and CI.

**Subdirectory installs:** If the app is mounted under a URL prefix, adjust `RewriteBase` (existing root `.htaccess` may already do this for local naming). Operators must verify deny rules still apply to the mounted path (consult Apache docs for per-directory prefix behavior).

### Example: explicit deny (alternate style, Apache 2.4)

If you prefer explicit blocks instead of or in addition to rewrite rules:

```apache
<IfModule mod_authz_core.c>
    <Directory "/var/www/spa/system">
        Require all denied
    </Directory>
    <Directory "/var/www/spa/archive">
        Require all denied
    </Directory>
    <Directory "/var/www/spa/handoff">
        Require all denied
    </Directory>
    <Directory "/var/www/spa/workers">
        Require all denied
    </Directory>
    <Directory "/var/www/spa/distribution">
        Require all denied
    </Directory>
</IfModule>
```

Replace `/var/www/spa` with the absolute path to your deployment clone.

## Nginx

**Production:** set `root` to `system/public` and pass PHP to `index.php` only as needed.

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;
    root /var/www/spa/system/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    # Do not define alternate roots that map / to the repository parent.
}
```

**Defense in depth** if the server block accidentally used the repo root as `root` (illustrative — prefer fixing `root`):

```nginx
location ^~ /system/    { deny all; }
location ^~ /archive/     { deny all; }
location ^~ /handoff/     { deny all; }
location ^~ /workers/     { deny all; }
location ^~ /distribution/{ deny all; }
location ^~ /logs/        { deny all; }
location ^~ /backups/     { deny all; }
location ^~ /secrets/     { deny all; }
location ^~ /scripts/     { deny all; }
```

## Local development (Laragon / Windows)

- **Preferred:** set the site **Document root** to `...\spa-skincare-system-blueprint\system\public` (same as production). Use `system/public/router.php` with the PHP built-in server if needed:  
  `php -S localhost:8000 router.php` (run from `system/public`).
- **Acceptable for local only:** Document root at the **repository root** with the bundled root `index.php` and root `.htaccess`. Ensure sensitive paths remain blocked (root `.htaccess` deny rules). Static assets are expected under `/assets/...` relative to the vhost; that layout matches **`system/public`** as docroot. If CSS/JS 404 with root docroot, switch the vhost to `system/public`.

## Operator quick checklist (production)

1. DocumentRoot (Apache) or `root` (Nginx) resolves to the **`system/public`** directory (the folder containing `index.php` and `.htaccess` with `DEPLOYMENT-DOCROOT-PUBLIC-HTACCESS-MARKER-01`).
2. You are **not** serving the repository root, `system/`, `archive/`, `handoff/`, `workers/`, or `distribution/` as the site root.
3. Run the verifier below after deploy or packaging; exit code **0** required.

## Verification artifact

Run the read-only verifier from the **repository root**:

```bash
php system/scripts/read-only/verify_deployment_docroot_hardening_readonly_01.php
```

Exit code **0** means structural checks passed. Review any **warnings** printed (e.g. presence of root `index.php`).
