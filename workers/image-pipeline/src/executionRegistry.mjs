/**
 * DB execution ledger for the image worker (FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01).
 * Mirrors {@see \Core\Runtime\Jobs\RuntimeExecutionRegistry} worker paths — keep semantics aligned.
 */
export const IMAGE_PIPELINE_EXECUTION_KEY = 'worker:image_pipeline';

/**
 * @param {import('mysql2/promise').Pool} pool
 * @param {string | null} meta
 */
export async function recordWorkerHeartbeat(pool, meta = null) {
  const key = IMAGE_PIPELINE_EXECUTION_KEY;
  const [res] = await pool.query(
    `UPDATE runtime_execution_registry SET
        last_heartbeat_at = NOW(3),
        active_heartbeat_at = NOW(3),
        active_started_at = COALESCE(active_started_at, NOW(3)),
        active_meta = COALESCE(?, active_meta),
        updated_at = NOW(3)
     WHERE execution_key = ?`,
    [meta, key]
  );
  const n = res.affectedRows ?? 0;
  if (n === 0) {
    await pool.query(
      `INSERT INTO runtime_execution_registry (execution_key, last_started_at, last_heartbeat_at, active_heartbeat_at, active_started_at, active_meta, updated_at)
       VALUES (?, NOW(3), NOW(3), NOW(3), NOW(3), ?, NOW(3))`,
      [key, meta]
    );
  }
}

/**
 * @param {import('mysql2/promise').Pool} pool
 */
export async function finalizeWorkerSession(pool, success, errorMessage = '') {
  const key = IMAGE_PIPELINE_EXECUTION_KEY;
  if (success) {
    await pool.query(
      `UPDATE runtime_execution_registry SET
          last_finished_at = NOW(3),
          last_success_at = NOW(3),
          active_started_at = NULL,
          active_heartbeat_at = NULL,
          last_error_summary = NULL,
          updated_at = NOW(3)
       WHERE execution_key = ?`,
      [key]
    );
    return;
  }
  const msg = String(errorMessage ?? '').slice(0, 2000);
  await pool.query(
    `UPDATE runtime_execution_registry SET
        last_finished_at = NOW(3),
        last_failure_at = NOW(3),
        last_error_summary = ?,
        active_started_at = NULL,
        active_heartbeat_at = NULL,
        updated_at = NOW(3)
     WHERE execution_key = ?`,
    [msg, key]
  );
}
