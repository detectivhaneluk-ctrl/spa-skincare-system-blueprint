/**
 * Media image pipeline worker: reclaim stale locks, fail exhausted jobs, claim, process, cleanup.
 */
import mysql from 'mysql2/promise';
import {
  claimNextJob,
  detectAvifOutputSupport,
  envMaxAttempts,
  envStaleLockMinutes,
  failExhaustedPendingJobs,
  failDeletedMarketingLibraryJobs,
  processClaimedJob,
  reclaimStaleLocks,
  resolveSystemRoot,
} from './processor.mjs';
import { finalizeWorkerSession, recordWorkerHeartbeat } from './executionRegistry.mjs';

function configFromEnv() {
  return {
    host: process.env.DB_HOST ?? '127.0.0.1',
    port: Number(process.env.DB_PORT ?? 3306),
    database: process.env.DB_DATABASE,
    user: process.env.DB_USERNAME,
    password: process.env.DB_PASSWORD ?? '',
  };
}

const intervalMs = Math.max(2000, Number(process.env.WORKER_POLL_MS ?? 8000));
const maxJobs = Math.max(0, Number(process.env.WORKER_MAX_JOBS ?? 0));

async function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

async function runHousekeeping(pool) {
  const staleMin = envStaleLockMinutes();
  const maxAtt = envMaxAttempts();
  const reclaimed = await reclaimStaleLocks(pool, staleMin);
  const exhausted = await failExhaustedPendingJobs(pool, maxAtt);
  const deletedLibrary = await failDeletedMarketingLibraryJobs(pool);
  console.log(
    new Date().toISOString(),
    'housekeeping',
    `stale_lock_minutes=${staleMin}`,
    `max_attempts=${maxAtt}`,
    `reclaimed_stale_rows=${reclaimed}`,
    `failed_exhausted_pending=${exhausted}`,
    `failed_deleted_marketing_library=${deletedLibrary}`
  );
  return { reclaimed, exhausted, deletedLibrary };
}

async function main() {
  const cfg = configFromEnv();
  if (!cfg.database || cfg.user === undefined || cfg.user === '') {
    console.error('Set DB_DATABASE and DB_USERNAME (and optionally DB_HOST, DB_PORT, DB_PASSWORD).');
    process.exit(1);
  }

  const systemRoot = resolveSystemRoot();
  const maxAttempts = envMaxAttempts();
  const avifEnabled = await detectAvifOutputSupport();

  console.log(new Date().toISOString(), 'worker_started', 'system_root=' + systemRoot);
  console.log(new Date().toISOString(), 'worker_avif_output', avifEnabled ? 'enabled' : 'disabled');

  const pool = mysql.createPool({
    ...cfg,
    waitForConnections: true,
    connectionLimit: 4,
  });

  const forceMediaJobRaw = process.env.IMAGE_PIPELINE_FORCE_MEDIA_JOB_ID;
  const forceMediaJobId =
    forceMediaJobRaw !== undefined &&
    forceMediaJobRaw !== null &&
    String(forceMediaJobRaw).trim() !== '' &&
    /^\d+$/.test(String(forceMediaJobRaw).trim())
      ? Number(String(forceMediaJobRaw).trim())
      : 0;

  if (process.env.WORKER_ONLY_RECLAIM === '1') {
    let reclaimOk = true;
    let reclaimErr = '';
    try {
      await recordWorkerHeartbeat(pool, workerMetaLabel());
      await runHousekeeping(pool);
    } catch (e) {
      reclaimOk = false;
      reclaimErr = (e && e.message) || String(e);
      console.error(new Date().toISOString(), 'worker_reclaim_only_failed', reclaimErr);
    } finally {
      try {
        await finalizeWorkerSession(pool, reclaimOk, reclaimErr);
      } catch (e) {
        console.error(new Date().toISOString(), 'execution_registry_finalize_failed', (e && e.message) || String(e));
      }
      await pool.end();
    }
    return;
  }

  let processedCount = 0;
  let shutdownOk = true;
  let shutdownErr = '';

  try {
    for (;;) {
      await recordWorkerHeartbeat(pool, workerMetaLabel());
      if (process.env.WORKER_SKIP_HOUSEKEEPING_LOOP !== '1') {
        await runHousekeeping(pool);
      }

      const conn = await pool.getConnection();
      let claim;
      try {
        claim = await claimNextJob(conn, {
          systemRoot,
          maxAttempts,
          ...(forceMediaJobId > 0 ? { forceMediaJobId } : {}),
        });
      } finally {
        conn.release();
      }

      if (claim.type === 'none') {
        console.log(new Date().toISOString(), 'job_claim', 'none');
        if (process.env.WORKER_ONCE === '1') {
          break;
        }
        await sleep(intervalMs);
        continue;
      }

      console.log(
        new Date().toISOString(),
        'job_claimed',
        'job_id=' + claim.jobId,
        'asset_id=' + claim.asset.id
      );

      const result = await processClaimedJob(pool, {
        avifEnabled,
        systemRoot,
        jobId: claim.jobId,
        asset: claim.asset,
      });

      if (result.ok) {
        console.log(
          new Date().toISOString(),
          'variants_written',
          'count=' + result.variantCount,
          'dir=' + result.variantDir
        );
        console.log(new Date().toISOString(), 'quarantine_removed', result.quarantine_removed ? 'yes' : 'no');
        console.log(new Date().toISOString(), 'asset_status', 'ready', 'asset_id=' + result.assetId);
        console.log(new Date().toISOString(), 'job_status', 'completed', 'job_id=' + result.jobId);
        processedCount++;
      } else if (result.reason === 'transient_requeued') {
        console.log(
          new Date().toISOString(),
          'job_requeued_transient',
          'job_id=' + claim.jobId,
          'error=' + (result.error ?? '')
        );
        processedCount++;
      } else if (result.reason === 'post_commit_promote_failed') {
        console.error(
          new Date().toISOString(),
          'job_completed_db_but_filesystem_promote_failed',
          'job_id=' + claim.jobId,
          'asset_id=' + claim.asset.id,
          'error=' + (result.error ?? '')
        );
        processedCount++;
      } else {
        console.log(
          new Date().toISOString(),
          'job_failed_terminal',
          'job_id=' + claim.jobId,
          'reason=' + result.reason,
          result.error ? 'error=' + result.error : ''
        );
        processedCount++;
      }

      if (maxJobs > 0 && processedCount >= maxJobs) {
        break;
      }

      if (process.env.WORKER_ONCE === '1') {
        break;
      }
    }
  } catch (e) {
    shutdownOk = false;
    shutdownErr = (e && e.message) || String(e);
    console.error(new Date().toISOString(), 'worker_fatal', shutdownErr);
    throw e;
  } finally {
    try {
      await finalizeWorkerSession(pool, shutdownOk, shutdownErr);
    } catch (e) {
      console.error(new Date().toISOString(), 'execution_registry_finalize_failed', (e && e.message) || String(e));
    }
    await pool.end();
  }
}

function workerMetaLabel() {
  const pid = typeof process.pid === 'number' ? String(process.pid) : '';
  return pid !== '' ? `node_pid=${pid}` : 'node';
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
