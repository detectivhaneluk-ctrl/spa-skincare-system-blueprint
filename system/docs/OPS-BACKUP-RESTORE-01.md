# OPS-BACKUP-RESTORE-01 — Backup and Restore Minimum Truth

**Status:** DELIVERED (2026-04-01)
**Task:** MINIMUM-OPS-RESILIENCE-GATE-01
**Scope:** MySQL database (primary truth), Redis (ephemeral state), local storage files.

> **Honesty boundary:** This codebase contains **no automated backup infrastructure**.
> There is no in-repo backup agent, no scheduled backup runner, no backup verification
> script, no off-site replication. This document defines the **minimum operator runbook**
> required before a serious deployment. Operators must implement and schedule these
> procedures manually or via their hosting provider.

---

## 1. What Needs Backup

### 1.1 MySQL database (PRIMARY TRUTH — must backup)

The MySQL database contains all durable business data:

| Data | Recovery possible without backup? |
|------|-----------------------------------|
| Organizations, branches, tenants | **NO** — must restore from dump |
| Users, staff, clients | **NO** — must restore from dump |
| Appointments, invoices, payments | **NO** — must restore from dump |
| Memberships, packages, gift cards | **NO** — must restore from dump |
| Product catalog, stock ledger | **NO** — must restore from dump |
| Queue jobs (`runtime_async_jobs`) | Partial — pending jobs lost; completed jobs re-runnable |
| Execution registry (`runtime_execution_registry`) | Re-created on next script run |
| Session data (if using DB-backed sessions) | Sessions expire naturally; users re-login |

**Recovery point objective (RPO):** equal to the age of your most recent dump.
**Recovery time objective (RTO):** time to restore dump + run pending migrations.

### 1.2 Redis (EPHEMERAL — backup is optional)

| Redis data | Recovery without backup |
|------------|------------------------|
| Session keys (`{prefix}:sess:*`) | Lost → users are logged out (acceptable) |
| Distributed lock keys (`{prefix}:lock:*`) | Auto-expire within TTL; no data loss |
| Settings cache (`{prefix}:settings:*`) | Re-populated on next request |
| Rate limit counters (`{prefix}:rate:*`) | Reset → brief abuse window on restart |
| Shared cache / availability cache | Re-populated on next request |

**Recommendation:** Redis data is fully recoverable from MySQL or expires naturally.
Redis backup is optional but recommended for busy deployments to avoid the rate-limit
reset window. Use `BGSAVE` for a point-in-time RDB snapshot.

### 1.3 Local file storage (if using local storage driver)

If `storage.driver = local`, uploaded media files are stored on the filesystem
(configured path in `STORAGE_LOCAL_SYSTEM_PATH`). These files must be backed up
separately via `rsync` or equivalent. If using S3 or another object storage provider,
backups are managed by that provider.

---

## 2. MySQL Backup Procedure

### 2.1 Full logical dump (recommended minimum)

```bash
# Full dump — single consistent snapshot
mysqldump \
  --user="$DB_USER" \
  --password="$DB_PASS" \
  --host="$DB_HOST" \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --set-gtid-purged=OFF \
  "$DB_NAME" \
  > /var/backups/spa-$(date +%Y%m%d-%H%M%S).sql

# Compress to reduce storage
gzip /var/backups/spa-$(date +%Y%m%d-%H%M%S).sql
```

**Important flags:**
- `--single-transaction` — consistent InnoDB snapshot without locking tables.
- `--routines --triggers --events` — include stored procedures/triggers if any exist.
- Do **NOT** use `--lock-tables` on InnoDB; use `--single-transaction` instead.

### 2.2 Recommended schedule

```cron
# Daily full dump at 02:00 (low-traffic window)
0 2 * * * root mysqldump --user=spa_user --password=... --host=127.0.0.1 --single-transaction spa_db | gzip > /var/backups/spa-$(date +\%Y\%m\%d).sql.gz

# Keep last 30 daily backups
0 3 * * * root find /var/backups/ -name 'spa-*.sql.gz' -mtime +30 -delete
```

For higher RPO requirements, schedule every 6 hours. For critical production, use
point-in-time recovery (binlog replication or managed DB with continuous backup).

### 2.3 Off-site copy (mandatory for production)

Local-only backups are not disaster recovery. Copy each dump to an off-site location:

```bash
# Example: copy to S3 after creation
aws s3 cp /var/backups/spa-$(date +%Y%m%d).sql.gz s3://your-backup-bucket/spa/daily/

# Or: rsync to a remote backup host
rsync -az /var/backups/spa-*.sql.gz backup-user@backup-host:/backups/spa/
```

---

## 3. Redis Backup (Optional)

```bash
# Trigger a background save (non-blocking)
redis-cli -u "$REDIS_URL" BGSAVE

# Wait for BGSAVE to complete
redis-cli -u "$REDIS_URL" LASTSAVE  # compare timestamp before and after

# Copy the RDB file
cp /var/lib/redis/dump.rdb /var/backups/redis-$(date +%Y%m%d-%H%M%S).rdb
```

For production Redis, prefer using a managed Redis provider (ElastiCache, Upstash,
Redis Cloud) which handles persistence and snapshots automatically.

---

## 4. Local Storage Files Backup

If using `storage.driver = local`:

```bash
STORAGE_PATH="${STORAGE_LOCAL_SYSTEM_PATH:-/path/to/repo/storage/app}"

# rsync to off-site
rsync -az "$STORAGE_PATH/" backup-user@backup-host:/backups/spa-storage/

# Or archive
tar -czf /var/backups/spa-storage-$(date +%Y%m%d).tar.gz "$STORAGE_PATH"
```

---

## 5. Restore Procedure

### 5.1 MySQL restore from dump

```bash
# Step 1: drop and recreate the database (CAUTION: data loss)
mysql -u root -p -e "DROP DATABASE IF EXISTS spa_db; CREATE DATABASE spa_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Step 2: restore from dump
zcat /var/backups/spa-20260401.sql.gz | mysql -u spa_user -p spa_db
# (or without gzip: mysql -u spa_user -p spa_db < /var/backups/spa-20260401.sql)

# Step 3: apply any pending migrations since the dump timestamp
php system/scripts/migrate.php

# Step 4: verify baseline
php system/scripts/run_migration_baseline_deploy_gate_01.php
```

### 5.2 Redis restore (if RDB backup exists)

```bash
# Stop Redis
systemctl stop redis

# Replace dump.rdb
cp /var/backups/redis-20260401.rdb /var/lib/redis/dump.rdb
chown redis:redis /var/lib/redis/dump.rdb

# Start Redis
systemctl start redis
```

If no Redis backup is available, simply restart Redis with an empty state.
All caches will repopulate from MySQL on the next request. Users will be
logged out and must re-authenticate.

### 5.3 Storage files restore

```bash
rsync -az backup-user@backup-host:/backups/spa-storage/ "$STORAGE_PATH/"
# or
tar -xzf /var/backups/spa-storage-20260401.tar.gz -C /
```

---

## 6. Verification After Restore

```bash
# 1. Application bootstrap
php -r "require 'system/bootstrap.php'; echo 'bootstrap OK\n';"

# 2. Migration baseline
php system/scripts/run_migration_baseline_deploy_gate_01.php

# 3. Backend health
php system/scripts/read-only/report_backend_health_critical_readonly_01.php

# 4. Canonical release law (Tier A static proof set)
php system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php
```

All must exit 0 before declaring restore complete.

---

## 7. What Is NOT Covered

| Item | Status |
|------|--------|
| Automated in-repo backup agent | **NOT IMPLEMENTED** — operator responsibility |
| Backup integrity verification (checksum restore drill) | **NOT IMPLEMENTED** — recommended quarterly |
| Point-in-time recovery (binlog/PITR) | **NOT IMPLEMENTED** — use managed DB for PITR |
| Multi-region DR failover | **NOT IMPLEMENTED** — deferred |
| Encrypted backup at rest | **NOT IMPLEMENTED** — recommended for GDPR/compliance |

These are intentionally deferred. This document closes the minimum honest
ops-resilience gate: operators know what to back up, how to do it, and how to restore.
