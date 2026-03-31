# Online DDL Migration Policy — WAVE-04

**Status:** ACTIVE  
**Applies to:** All schema changes on tables expected to reach > 1M rows or that will have high concurrent read/write traffic at scale.

---

## Why This Policy Exists

Standard `ALTER TABLE` in MySQL/InnoDB takes a metadata lock for the duration of the operation on most DDL types (adding columns, modifying indexes, changing column types). At scale with 1000+ active salons, a blocking DDL on a large table (`audit_logs`, `appointments`, `runtime_async_jobs`, `invoices`, `invoice_items`) can cause:

- All queries on the table to queue behind the metadata lock
- Connection pool exhaustion within seconds
- Application-wide timeout cascade

**Rule:** Any DDL against a table classified as "large" or "hot" MUST use an online-safe strategy. Standard `ALTER TABLE` is forbidden in production for these tables.

---

## Large / Hot Table Registry

| Table | Rows at 1000 salons (est.) | Online DDL tool required |
|---|---|---|
| `audit_logs` | 50M+ | pt-online-schema-change or gh-ost |
| `appointments` | 20M+ | pt-online-schema-change or gh-ost |
| `invoice_items` | 30M+ | pt-online-schema-change or gh-ost |
| `invoices` | 10M+ | pt-online-schema-change or gh-ost |
| `runtime_async_jobs` | 5M+ (with archival) | pt-online-schema-change or gh-ost |
| `public_booking_abuse_hits` | 10M+ | pt-online-schema-change or gh-ost |

All other tables: standard `ALTER TABLE` is acceptable if the migration can tolerate a sub-1-second lock. When in doubt, use the online DDL path.

---

## Approved Online DDL Strategies

### Strategy A — MySQL 8.0 ALGORITHM=INPLACE, LOCK=NONE (preferred for simple changes)

Many index additions and column additions support `ALGORITHM=INPLACE, LOCK=NONE` natively in MySQL 8.0. Check the [MySQL 8.0 Online DDL documentation](https://dev.mysql.com/doc/refman/8.0/en/innodb-online-ddl-operations.html) for each DDL type.

```sql
-- Safe: add index with in-place algorithm, no lock
ALTER TABLE audit_logs
    ADD INDEX idx_new_column (new_column),
    ALGORITHM=INPLACE, LOCK=NONE;
```

**Cannot be used for:** changing primary key, changing column type incompatibly, adding full-text index on large tables.

### Strategy B — Percona pt-online-schema-change (pt-osc)

For changes not supported by `ALGORITHM=INPLACE, LOCK=NONE`.

```bash
pt-online-schema-change \
  --alter "ADD COLUMN archived_at DATETIME NULL DEFAULT NULL" \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --user="${DB_USERNAME}" \
  --password="${DB_PASSWORD}" \
  --database="${DB_DATABASE}" \
  --table=audit_logs \
  --execute \
  --no-drop-old-table \
  --chunk-size=1000 \
  --max-lag=2 \
  --check-interval=1
```

### Strategy C — gh-ost (GitHub Online Schema Change)

For MySQL replication environments where pt-osc's trigger-based approach is not viable.

```bash
gh-ost \
  --user="${DB_USERNAME}" \
  --password="${DB_PASSWORD}" \
  --host="${DB_HOST}" \
  --database="${DB_DATABASE}" \
  --table=audit_logs \
  --alter="ADD COLUMN archived_at DATETIME NULL DEFAULT NULL" \
  --allow-on-master \
  --execute
```

---

## Migration File Conventions

### Standard migrations (all small/safe tables)

Use the normal migration file pattern: `NNN_description.sql` in `system/data/migrations/`.

### Large-table migrations

Create a companion runbook file alongside the SQL:

```
system/data/migrations/NNN_description.sql          ← DDL to run via online tool
system/data/migrations/NNN_description_online_ddl_runbook.md  ← mandatory for large tables
```

The SQL file for a large-table migration MUST contain a header comment:

```sql
-- ONLINE-DDL-REQUIRED: Run via pt-online-schema-change or gh-ost.
-- See NNN_description_online_ddl_runbook.md for exact command.
-- DO NOT run this file directly via system/scripts/migrate.php against a production large table.
```

The migration runner (`system/scripts/migrate.php`) will detect this header and refuse to apply the migration in a production-like environment without the `--allow-online-ddl-bypass` flag.

---

## CI Guardrail

`system/scripts/ci/guardrail_online_ddl_large_table_migrations.php` enforces that any new migration file touching a large/hot table has the `ONLINE-DDL-REQUIRED` header.

Run: `php system/scripts/ci/guardrail_online_ddl_large_table_migrations.php`

---

## Rollback

Online DDL tools (pt-osc, gh-ost) operate on a shadow copy. Rollback before cutover is safe — simply drop the shadow table. After cutover, rollback requires a reverse DDL (reverting the column/index change).

For all large-table migrations, the runbook MUST document the rollback command.

---

## Prohibited Patterns

```sql
-- PROHIBITED on large/hot tables in production:
ALTER TABLE audit_logs ADD COLUMN foo VARCHAR(255);  -- blocks with metadata lock
ALTER TABLE appointments MODIFY COLUMN notes TEXT;   -- rebuild entire table
ALTER TABLE invoices DROP INDEX idx_old;             -- may block reads
```
