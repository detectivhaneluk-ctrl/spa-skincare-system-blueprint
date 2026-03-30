# Scale proof and bottleneck map (FOUNDATION-DB-TRUTH-OBSERVABILITY-AND-SCALE-PROOF-03)

## Honest scope

This wave does **not** claim “1000+ salons ready.” It adds:

1. A **repeatable micro-benchmark script** (`system/scripts/dev-only/db_hot_query_timing_proof_03.php`) that measures a few OR-shaped queries when a DB is available.
2. A **bottleneck map** from code/schema review — hypotheses to validate with `EXPLAIN ANALYZE` and production slow-query logs.

## Bottleneck map (provisional)

| Area | Hypothesized hotspot | Why | Evidence to collect |
|------|---------------------|-----|---------------------|
| Appointments | `idx_appointments_branch_staff_range`, `branch_deleted_start` | Calendar loads filter branch + time range + soft-delete | `EXPLAIN` on branch week view query; rows examined |
| Clients | `idx_clients_branch_deleted_name` | Directory search by branch | `EXPLAIN` list + search |
| Payments | `idx_payments_invoice`, `idx_payments_invoice_created` | Settlement and invoice detail | `EXPLAIN` payment list by invoice |
| Invoices | Per-org sequence row (`invoice_number_sequences`) | Contention on single row per org+key | `SHOW ENGINE INNODB STATUS` / queue depth during burst checkout |
| Queue | `idx_runtime_async_jobs_queue_pick`, **new** `idx_runtime_async_jobs_queue_status_updated` | Claim vs lag monitoring | Worker cycle time + `SELECT ... ORDER BY updated_at` |
| Sessions | Redis single endpoint | Large tenant count → memory + connections | Redis `INFO`, connection count |
| Rate limit | Redis ZSET keys or `public_booking_abuse_hits` | DB fallback under load | p99 `tryConsume` latency |

## Load / stress plan (narrow)

1. **DB script:** Run `php system/scripts/dev-only/db_hot_query_timing_proof_03.php` on staging — captures p50/p95/p99 ms for fixed SQL templates (see script output).
2. **Application:** Future wave: k6 or Locust against **login + appointment create + payment_recorded** APIs with realistic payloads (out of scope for this backend-only wave).
3. **Acceptance:** Compare before/after index migrations on staging; do not extrapolate to 1000+ tenants without horizontal scale tests.

## Claim statement

**1000+ readiness remains provisional** until horizontal load tests, read replicas (if used), and queue worker concurrency are measured with tenant-isolated workloads.
