# Phase 1 Foundation — Setup

## Requirements

- PHP 8.3+
- MySQL 8
- Web server (Apache with mod_rewrite, or nginx)

## Setup

1. **Copy environment file**
   ```bash
   cp .env.example .env
   ```

2. **Configure `.env`** — set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

3. **Create database**
   ```sql
   CREATE DATABASE spa_skincare CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. **Run migrations**
   ```bash
   php scripts/migrate.php
   ```

5. **Seed roles and permissions**
   ```bash
   php scripts/seed.php
   ```

6. **Create first user**
   ```bash
   php scripts/create_user.php admin@example.com yourpassword
   ```

7. **Configure document root** — point to `system/public/`. Ensure `.htaccess` is allowed.

## Routes

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | / | ✓ | Redirect to /settings |
| GET | /login | guest | Login form |
| POST | /login | guest | Login attempt |
| POST | /logout | ✓ | Logout |
| GET | /settings | ✓, settings.view | Settings list |
| POST | /settings | ✓, settings.edit | Save settings |

## Running with built-in server

```bash
cd system/public
php -S localhost:8000 router.php
```

Then visit http://localhost:8000
