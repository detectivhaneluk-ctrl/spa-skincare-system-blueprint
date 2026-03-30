# POST-FOUNDATION-ZIP-TRUTH-AUDIT-AND-SAAS-STRENGTH-GAP-MAP-01

Canonical post-wave audit artifact. **ZIP contents are the external review authority** once built via `handoff/build-final-zip.ps1` (rules: `HandoffZipRules.ps1` + `verify_handoff_zip_rules_readonly.php`).

## A) Foundation waves completed (implementation stopped after these)

| Wave | Task name (reference) | What was delivered |
|------|----------------------|-------------------|
| 01 | FOUNDATION-PLATFORM-INVARIANTS-AND-FOUNDER-RISK-ENGINE-01 | Tenant constitution, founder risk policy, TOTP foundation, marketing/intake repo naming, PLT-REL-01 Tier A |
| 02 | FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02 | Session epoch / logout-all, named rate limits, `runtime_async_jobs`, S3-compatible storage path, proofs |
| 03 | FOUNDATION-DB-TRUTH-OBSERVABILITY-AND-SCALE-PROOF-03 | Audit log structured columns (migration 125), queue lag index (126), `critical_path.*` telemetry, DB truth map, provisional micro-benchmark |

## B) ZIP artifact

- **Path (repo-relative):** `distribution/spa-skincare-system-blueprint-HANDOFF-FOR-UPLOAD.zip`
- **Build:** `handoff/build-final-zip.ps1` (runs PLT-REL-01 tenant gate, then packages, then scans ZIP + PHP twin verifier).

**Excluded from ZIP (non-exhaustive):** `system/.env`, `system/.env.local`, `**/.env` except `*.env.example`, `**/*.zip`, `**/*.log`, `system/storage/logs/**`, `system/storage/backups/**`, `system/storage/sessions/**`, framework cache/views (except `.gitkeep`), `node_modules`, pasted `system/docs/*-RESULT.txt`.

## C) ROOT-CAUSE WEAKNESS MAP (summary)

Structural gaps that **still** separate this codebase from “strong large-scale SaaS” are grouped below. Evidence is **repository + wave docs + gates**; **no** multi-tenant load test evidence is claimed.

### C1 Tenant / data-plane residuals

- **OrUnscoped / repair paths** on invoice-plane helpers when org unresolved — intentional for repair but **HIGH** if mis-invoked from HTTP.
- **`appointments.client_membership_id`** FK **deferred** (orphan risk) — **MEDIUM**.
- **Nullable `clients.branch_id` / legacy rows** — tenant listing relies on guards; **MEDIUM** recurring bug class without DB NOT NULL + backfill.

### C2 Founder / platform security residuals

- **TOTP optional** until enrolled — correct model; residual is **credential theft** before enrollment — **MEDIUM** (standard MFA adoption problem).
- **Support entry / impersonation** — strong audit + step-up; any new route missing guardrails repeats **CRITICAL** class bugs → keep release gate expansion.

### C3 Verifier / release-gate gaps

- **Tier B tenant integration** optional (`--with-integration`) — **MEDIUM**: static proofs dominate; DB-backed cross-tenant tests not default.
- **No single gate** enforcing migration 125/126 applied in target env — **MEDIUM** (runtime degrades or fails depending on path).

### C4 Service-layer bypass / wrong-helper risk

- **Direct `Database` / raw SQL** outside repository choke points — **MEDIUM** recurring (new code can skip tenant scope).
- **Polymorphic `document_links`** — no FK to owner rows — **MEDIUM** wrong-id class bugs.

### C5 Session / runtime centralization

- **`SESSION_DRIVER=files`** remains valid for single-node — **MEDIUM** ops mistake under multi-node.
- **Session epoch** requires **`users.session_version` migration** — **LOW** if migration applied; **HIGH** if forgotten (silent lenient reads).

### C6 Queue / durable async

- **Contract + worker exist**; **business enqueue** for media/docs/notifications **not fully migrated** — **HIGH** operational gap (dual job systems: `media_jobs` vs `runtime_async_jobs`).
- **No default supervised worker** in repo — **MEDIUM** (ops responsibility).

### C7 Media / docs storage

- **Default `local` driver** — **HIGH** for true multi-node file serving until `s3_compatible` + CDN path proven in prod.
- **S3 provider** buffers large reads — **MEDIUM** performance ceiling documented in code.

### C8 Database integrity

- **Deferred FKs** and **nullable tenant keys** documented in `DB-TRUTH-MAP-HIGH-RISK-DOMAINS-03.md` — **MEDIUM** to **HIGH** depending on table.
- **Invoice sequence hotspot** — documented historically — **MEDIUM** contention under burst checkout.

### C9 Observability / audit / telemetry

- **Structured audit columns** need **migration 125** — **MEDIUM** until universal.
- **`critical_path.*` via `error_log` JSON** — **LOW/MEDIUM** without centralized log aggregation (Loki/Datadog/etc.).

### C10 Scale / load

- **Micro-benchmark only** (`db_hot_query_timing_proof_03.php`) — **provisional**; **no** 1000+ tenant evidence.

## D) Classification table (abbreviated)

| Weakness | Risk | Blast radius | Repeated-bug? | Current protection |
|----------|------|--------------|---------------|-------------------|
| Multi-node file sessions / local storage default | HIGH | All tenants auth + assets | Yes | Config + docs; S3 path exists |
| Dual async systems (media_jobs vs runtime_async_jobs) | HIGH | Media/notifications reliability | Yes | Partial — new table + worker only |
| OrUnscoped invoice paths misused from HTTP | CRITICAL | Cross-tenant data | Yes | Static gates + code review |
| No default integration tenant gate in CI | MEDIUM | Regression leakage | Yes | Tier B optional |
| Missing FK `client_membership_id` | MEDIUM | Billing/membership consistency | Yes | App validation only |
| Polymorphic document owner integrity | MEDIUM | Wrong attachments | Yes | App + unique constraints |
| Migration 125 not applied | MEDIUM | Audit correlation broken | Yes | AuditService fallback insert |
| Invoice / payment sequence contention | MEDIUM | Checkout latency / deadlocks | Intermittent | Per-org sequences + indexes |

## E) HIGH / CRITICAL — battle-tested directions (no clever architecture)

1. **CRITICAL (OrUnscoped):** Keep expanding **readonly release gates** for any new invoice/membership SQL; prefer **repository-only** data access for tenant paths; add **integration tests** for cross-tenant denial.
2. **HIGH (storage):** Run **`STORAGE_DRIVER=s3_compatible`** + CDN/object ACL review in staging; treat local as **dev-only** in prod policy.
3. **HIGH (async):** **Consolidate** durable work onto **one** queue contract (`runtime_async_jobs` or external broker) with **idempotent handlers** and **observable lag** (index 126 + metrics).
4. **HIGH (sessions):** **Redis** session handler + **`session_version`** operational runbook; document **invalidate CLI**.
5. **MEDIUM (DB):** Execute documented **orphan check** then add **FK**; **NOT NULL** + backfill for tenant-scoped rows where safe.
6. **MEDIUM (observability):** Ship logs to a **managed aggregator**; alert on **`critical_path.*` error rates** and **queue depth**.

## F) Best order — next 3 major backend waves

1. **Durable async unification** — enqueue real workloads, retire duplicate job tables where safe, supervised workers, DLQ dashboards.
2. **DB integrity closure** — FKs with cleanup plans, EXPLAIN baselines in CI, nullable tenant column reduction where backfilled.
3. **Production runtime profile** — Redis sessions + S3 (or equivalent) **mandatory** in prod checklist; integration tenant tests in default CI.

## G) Single next backend task (anti-drift)

**FOUNDATION-DISTRIBUTED-RUNTIME-JOB-CONSUMERS-MEDIA-NOTIFY-03** (or sequenced **FOUNDATION-DB-TRUTH-APPOINTMENTS-MEMBERSHIP-FK-AND-EXPLAIN-BASELINE-04** if async unification waits) — pick **one** as org priority: **job consumers first** if reliability > schema purity.

**Recommended single next name:** `FOUNDATION-DISTRIBUTED-RUNTIME-JOB-CONSUMERS-MEDIA-NOTIFY-03`

## H) SaaS maturity verdict

- **State:** **Hardening in progress** — foundations improved; **not** yet “strong SaaS foundation” end-to-end (storage default, async dualism, integration gates, load evidence).
- **1000+ readiness:** **Provisional** — no evidence-based claim; micro-benchmark + static gates only.
