# ProxySQL Deployment Package — WAVE-03

**Purpose:** Read-write split + connection pooling for the Ollira database tier.  
**Status:** Deploy-ready artifacts (env contract, config templates, ops runbook, health checks).  
**Wave:** WAVE-03 — Scale Readiness + Observability.

---

## What ProxySQL solves at 1000+ salons

| Problem | Without ProxySQL | With ProxySQL |
|---|---|---|
| Connection count | Each PHP-FPM worker opens its own TCP connection → MySQL `max_connections` saturates at ~250–500 workers | ProxySQL maintains a small persistent pool; all PHP workers share it → `max_connections` drops by 10–50× |
| Read/write split | All queries hit primary → primary saturates on read-heavy workloads (availability checks, client lists, settings reads) | Reads auto-routed to read replicas; writes go to primary |
| Query routing transparency | Application must know replica hostnames | Application speaks to `127.0.0.1:6033`; ProxySQL routes transparently |
| Slow query visibility | Buried in MySQL slow-query log per host | ProxySQL `stats_mysql_query_digest` gives per-query latency across all connections |

---

## Architecture diagram

```
PHP-FPM workers (n instances)
        │  PDO: 127.0.0.1:6033
        ▼
  [ ProxySQL :6033 ]
        │
  ┌─────┴────────────────┐
  │                      │
  ▼                      ▼
Primary MySQL        Read Replica(s)
  (writes)             (reads)
  :3306                 :3306
```

---

## Environment contract

All variables consumed by `deploy/proxysql/proxysql.cnf.template` and health check scripts.

```bash
# Primary (writer) MySQL host — required
MYSQL_PRIMARY_HOST=10.0.0.1
MYSQL_PRIMARY_PORT=3306

# Read replica(s) — at least one required for read/write split
MYSQL_REPLICA_HOST=10.0.0.2
MYSQL_REPLICA_PORT=3306

# Application user credentials (same across primary + replicas)
MYSQL_APP_USER=spa_app
MYSQL_APP_PASSWORD=<strong-random>
MYSQL_DATABASE=spa_production

# ProxySQL admin credentials (change from defaults!)
PROXYSQL_ADMIN_USER=proxysql_admin
PROXYSQL_ADMIN_PASSWORD=<strong-random>
PROXYSQL_ADMIN_PORT=6032

# ProxySQL MySQL-protocol listener (what the application connects to)
PROXYSQL_LISTEN_PORT=6033

# Application: point PDO to ProxySQL listener
DB_HOST=127.0.0.1
DB_PORT=6033
```

---

## ProxySQL configuration template

See `proxysql.cnf.template` in this directory.

Key routing rules:
- `SELECT ... FOR UPDATE` → writer group (hostgroup 0)
- All other `SELECT` → reader group (hostgroup 1)
- `INSERT`, `UPDATE`, `DELETE`, `CALL`, `BEGIN` → writer group (hostgroup 0)

---

## Deployment steps

1. **Provision ProxySQL** (same server as app, or dedicated proxy tier):
   ```bash
   apt install proxysql
   # or: yum install proxysql
   ```

2. **Apply configuration** (substitute env vars):
   ```bash
   envsubst < deploy/proxysql/proxysql.cnf.template > /etc/proxysql.cnf
   systemctl start proxysql
   ```

3. **Add MySQL backend hosts** (via admin interface):
   ```bash
   mysql -u $PROXYSQL_ADMIN_USER -p$PROXYSQL_ADMIN_PASSWORD -h 127.0.0.1 -P $PROXYSQL_ADMIN_PORT \
     < deploy/proxysql/proxysql_setup.sql
   ```

4. **Verify connectivity** (app → ProxySQL → MySQL):
   ```bash
   php deploy/proxysql/health_check_proxysql.php
   ```

5. **Update application .env**:
   ```bash
   DB_HOST=127.0.0.1
   DB_PORT=6033
   ```

6. **Run migration baseline check** to ensure DB_HOST change doesn't break anything:
   ```bash
   php system/scripts/run_migration_baseline_deploy_gate_01.php
   ```

---

## Health check commands

```bash
# PHP readiness check (tests writer and reader routing)
php deploy/proxysql/health_check_proxysql.php

# ProxySQL admin stats
mysql -u $PROXYSQL_ADMIN_USER -p$PROXYSQL_ADMIN_PASSWORD -h 127.0.0.1 -P 6032 \
  -e "SELECT hostgroup, srv_host, status, ConnUsed, ConnFree, Latency_ms FROM stats_mysql_connection_pool;"

# Top queries by execution time
mysql -u $PROXYSQL_ADMIN_USER -p$PROXYSQL_ADMIN_PASSWORD -h 127.0.0.1 -P 6032 \
  -e "SELECT digest_text, count_star, sum_time/1000 AS total_ms, sum_time/count_star/1000 AS avg_ms FROM stats_mysql_query_digest ORDER BY sum_time DESC LIMIT 20;"
```

---

## Failure modes and fallback

| Failure | ProxySQL behaviour | Application impact |
|---|---|---|
| ProxySQL process crash | App cannot connect to port 6033 | All requests fail with PDO connect error |
| Primary MySQL down | ProxySQL marks primary OFFLINE | All write queries fail; read queries continue on replicas |
| Replica MySQL down | ProxySQL marks replica OFFLINE | All queries routed to primary (degraded, not down) |
| Replica replication lag > threshold | Set `max_replication_lag` to divert reads to primary above lag threshold | Transparent to application |

**Rollback:** Set `DB_HOST` and `DB_PORT` back to the primary MySQL host/port and restart PHP-FPM.

---

## Read/write routing abstraction in application

The current `Database.php` uses a single PDO connection. When ProxySQL is in front, routing is transparent — the application still connects to one host/port.

For future explicit read/write routing (WAVE-03 groundwork only — do NOT implement before ProxySQL is deployed):

```php
// DO NOT enable until ProxySQL is confirmed deployed and routing rules are validated.
// Premature read/write split without ProxySQL causes silent inconsistency.
// The Database class should expose:
//   $db->forWrite()  → PDO to writer
//   $db->forRead()   → PDO to reader (or same as writer if not split)
```

This abstraction is documented here as a future migration path. The current single-connection `Database.php` is correct and safe until ProxySQL is deployed and validated.

---

## Connection pool sizing recommendations

| Environment | PHP-FPM workers | ProxySQL pool size | MySQL max_connections |
|---|---|---|---|
| 50 salons | 20–40 workers | 10–20 per host | 100 |
| 200 salons | 60–120 workers | 20–40 per host | 200 |
| 1000+ salons | 200–400+ workers | 50–100 per host | 300–500 |

Rule: `ProxySQL pool ≪ MySQL max_connections ≪ PHP-FPM workers`.
