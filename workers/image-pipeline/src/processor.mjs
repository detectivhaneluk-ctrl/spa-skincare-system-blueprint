/**
 * Lock-safe job claim + sharp variants + stale reclaim + retry/terminal policy (wave 14).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

export const JOB_TYPE = 'process_photo_variants_v1';
/** Canonical PHP runtime_async_jobs.job_type that triggers this pipeline (governance shell). */
export const RUNTIME_ASYNC_GOVERNANCE_JOB_TYPE = 'media.image_pipeline';
/** Canonical PHP runtime_async_jobs.queue paired with RUNTIME_ASYNC_GOVERNANCE_JOB_TYPE. */
export const RUNTIME_ASYNC_GOVERNANCE_QUEUE = 'media';
export const VARIANT_PUBLIC_PREFIX = 'media/processed';
export const RESP_TARGET_WIDTHS = [320, 640, 960, 1280];
export const THUMB_SIZE = 320;

export class TerminalJobError extends Error {
  constructor(message) {
    super(message);
    this.name = 'TerminalJobError';
  }
}

export class TransientJobError extends Error {
  constructor(message) {
    super(message);
    this.name = 'TransientJobError';
  }
}

const JPEG_QUALITY = Math.min(100, Math.max(40, Number(process.env.WORKER_JPEG_QUALITY ?? 82)));
const WEBP_QUALITY = Math.min(100, Math.max(40, Number(process.env.WORKER_WEBP_QUALITY ?? 80)));
const AVIF_QUALITY = Math.min(100, Math.max(30, Number(process.env.WORKER_AVIF_QUALITY ?? 45)));

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export function envStaleLockMinutes() {
  return Math.max(1, Number(process.env.IMAGE_JOB_STALE_LOCK_MINUTES ?? 30));
}

export function envMaxAttempts() {
  return Math.max(1, Number(process.env.IMAGE_JOB_MAX_ATTEMPTS ?? 5));
}

/**
 * Resolves the PHP `system/` tree used for quarantine + public media paths.
 * - If neither `MEDIA_SYSTEM_ROOT` nor `STORAGE_LOCAL_SYSTEM_PATH` is set: worker-relative default (unchanged).
 * - If `MEDIA_SYSTEM_ROOT` is set (including empty string): must resolve to a directory that contains `storage/media/`; no silent fallback.
 * - Else if only `STORAGE_LOCAL_SYSTEM_PATH` is set: same validation (parity with PHP `storage.local.system_root`).
 */
export function resolveSystemRoot() {
  const explicitMedia = Object.prototype.hasOwnProperty.call(process.env, 'MEDIA_SYSTEM_ROOT');
  const explicitStorage = Object.prototype.hasOwnProperty.call(process.env, 'STORAGE_LOCAL_SYSTEM_PATH');
  if (!explicitMedia && !explicitStorage) {
    return path.resolve(__dirname, '..', '..', '..', 'system');
  }

  const raw = explicitMedia ? process.env.MEDIA_SYSTEM_ROOT : process.env.STORAGE_LOCAL_SYSTEM_PATH;
  const trimmed = typeof raw === 'string' ? raw.trim() : String(raw ?? '').trim();
  if (trimmed === '') {
    throw new Error(
      'MEDIA_SYSTEM_ROOT / STORAGE_LOCAL_SYSTEM_PATH is set but empty. Unset both to use the default worker-relative `system/` path, or set to the absolute path of the application `system` directory (must contain `storage/media/`).'
    );
  }

  const resolvedRoot = path.resolve(trimmed);
  if (!fs.existsSync(resolvedRoot)) {
    throw new Error(
      `System root is "${trimmed}" (resolved: "${resolvedRoot}") but that path does not exist. Fix MEDIA_SYSTEM_ROOT / STORAGE_LOCAL_SYSTEM_PATH or create the directory.`
    );
  }
  const rootStat = fs.statSync(resolvedRoot);
  if (!rootStat.isDirectory()) {
    throw new Error(
      `System root is "${trimmed}" (resolved: "${resolvedRoot}") but is not a directory.`
    );
  }

  const mediaDir = path.join(resolvedRoot, 'storage', 'media');
  if (!fs.existsSync(mediaDir)) {
    throw new Error(
      `System root is "${trimmed}" (resolved: "${resolvedRoot}") but required directory is missing: "${mediaDir}". Expected layout: <system>/storage/media/ (same as the deployed PHP system tree).`
    );
  }
  const mediaStat = fs.statSync(mediaDir);
  if (!mediaStat.isDirectory()) {
    throw new Error(
      `System root "${resolvedRoot}" but "${mediaDir}" exists and is not a directory.`
    );
  }

  return resolvedRoot;
}

export function rimrafOutputDir(absDir) {
  if (fs.existsSync(absDir)) {
    fs.rmSync(absDir, { recursive: true, force: true });
  }
}

/**
 * Remove both final and in-flight staging dirs for an asset job (rollback / failure cleanup).
 */
export function rimrafVariantFinalAndStaging(finalAbs, stagingAbs) {
  rimrafOutputDir(finalAbs);
  rimrafOutputDir(stagingAbs);
}

/**
 * After DB commit: move staged variant directory to the canonical public path (same parent → rename is atomic).
 * Falls back to recursive copy when rename is not possible.
 */
export function promoteVariantStagingToFinal(stagingAbs, finalAbs) {
  if (!fs.existsSync(stagingAbs)) {
    throw new Error('variant staging directory missing after DB commit');
  }
  rimrafOutputDir(finalAbs);
  try {
    fs.renameSync(stagingAbs, finalAbs);
  } catch {
    fs.cpSync(stagingAbs, finalAbs, { recursive: true, force: true });
    rimrafOutputDir(stagingAbs);
  }
}

export function variantStagingDirAbsolute(systemRoot, orgId, branchId, assetId, jobId) {
  const finalAbs = variantDirAbsolute(systemRoot, orgId, branchId, assetId);
  return path.join(path.dirname(finalAbs), `__stg_${assetId}_${jobId}`);
}

export async function detectAvifOutputSupport() {
  try {
    await sharp({
      create: {
        width: 2,
        height: 2,
        channels: 3,
        background: { r: 1, g: 2, b: 3 },
      },
    })
      .avif({ quality: AVIF_QUALITY, effort: 3 })
      .toBuffer();
    return true;
  } catch {
    return false;
  }
}

/**
 * Reset jobs stuck in processing (and matching assets) back to pending.
 * @returns {Promise<number>} rows matched (MySQL may report affected rows per table; we use changedRows heuristic)
 */
export async function reclaimStaleLocks(pool, staleMinutes, jobType = JOB_TYPE) {
  const msg = `[stale_lock_reclaimed >${staleMinutes}m]`;
  const [res] = await pool.query(
    `UPDATE media_jobs j
     INNER JOIN media_assets a ON a.id = j.media_asset_id
     SET
       j.status = 'pending',
       j.locked_at = NULL,
       j.error_message = ?,
       j.updated_at = NOW(),
       a.status = 'pending',
       a.updated_at = NOW()
     WHERE j.status = 'processing'
       AND j.locked_at IS NOT NULL
       AND j.locked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
       AND a.status = 'processing'
       AND j.job_type = ?`,
    [msg, staleMinutes, jobType]
  );
  return res.affectedRows ?? 0;
}

/**
 * Pending jobs that already exhausted attempts → terminal failed (avoid infinite pending).
 */
export async function failExhaustedPendingJobs(pool, maxAttempts, jobType = JOB_TYPE) {
  const note = `Max processing attempts (${maxAttempts}) exceeded`;
  const [res] = await pool.query(
    `UPDATE media_jobs j
     INNER JOIN media_assets a ON a.id = j.media_asset_id
     SET
       j.status = 'failed',
       j.locked_at = NULL,
       j.error_message = ?,
       j.updated_at = NOW(),
       a.status = 'failed',
       a.updated_at = NOW()
     WHERE j.status = 'pending'
       AND j.job_type = ?
       AND j.attempts >= ?`,
    [note, jobType, maxAttempts]
  );
  return res.affectedRows ?? 0;
}

/**
 * Pending/processing jobs for media assets that are only referenced by soft-deleted
 * marketing_gift_card_images rows should not block FIFO queue progression.
 */
export async function failDeletedMarketingLibraryJobs(pool, jobType = JOB_TYPE) {
  const note = 'deleted_from_marketing_library';
  const [res] = await pool.query(
    `UPDATE media_jobs j
     INNER JOIN media_assets a ON a.id = j.media_asset_id
     SET
       j.status = 'failed',
       j.locked_at = NULL,
       j.error_message = ?,
       j.updated_at = NOW(),
       a.status = CASE WHEN a.status IN ('pending','processing') THEN 'failed' ELSE a.status END,
       a.updated_at = NOW()
     WHERE j.job_type = ?
       AND j.status IN ('pending','processing')
       AND EXISTS (
         SELECT 1
         FROM marketing_gift_card_images i_del
         WHERE i_del.media_asset_id = j.media_asset_id
           AND i_del.deleted_at IS NOT NULL
       )
       AND NOT EXISTS (
         SELECT 1
         FROM marketing_gift_card_images i_active
         WHERE i_active.media_asset_id = j.media_asset_id
           AND i_active.deleted_at IS NULL
       )`,
    [note, jobType]
  );
  return res.affectedRows ?? 0;
}

function quarantinePath(systemRoot, orgId, branchId, basename) {
  return path.join(systemRoot, 'storage', 'media', 'quarantine', String(orgId), String(branchId), basename);
}

export function variantDirRelative(orgId, branchId, assetId) {
  return path.posix.join(VARIANT_PUBLIC_PREFIX, String(orgId), String(branchId), String(assetId));
}

export function variantDirAbsolute(systemRoot, orgId, branchId, assetId) {
  const rel = variantDirRelative(orgId, branchId, assetId);
  return path.join(systemRoot, 'public', ...rel.split('/'));
}

/**
 * @param {import('mysql2/promise').PoolConnection} conn
 * @param {{ systemRoot: string, maxAttempts: number, forceMediaJobId?: number }} opts
 */
export async function claimNextJob(conn, opts) {
  const { systemRoot, maxAttempts } = opts;
  const forceId =
    typeof opts.forceMediaJobId === 'number' && Number.isFinite(opts.forceMediaJobId) && opts.forceMediaJobId > 0
      ? Math.floor(opts.forceMediaJobId)
      : 0;
  const idClause = forceId > 0 ? ' AND j.id = ?' : '';
  await conn.beginTransaction();
  try {
    const params = forceId > 0 ? [JOB_TYPE, maxAttempts, forceId] : [JOB_TYPE, maxAttempts];
    const [rows] = await conn.query(
      `SELECT j.id AS job_id, j.media_asset_id, j.job_type, j.attempts AS attempts_before
       FROM media_jobs j
       WHERE j.status = 'pending'
         AND j.job_type = ?
         AND j.available_at <= NOW()
         AND j.attempts < ?` +
        idClause +
        `
       AND NOT (
         EXISTS (
           SELECT 1 FROM marketing_gift_card_images i_del
           WHERE i_del.media_asset_id = j.media_asset_id
             AND i_del.deleted_at IS NOT NULL
         )
         AND NOT EXISTS (
           SELECT 1 FROM marketing_gift_card_images i_active
           WHERE i_active.media_asset_id = j.media_asset_id
             AND i_active.deleted_at IS NULL
         )
       )
         AND EXISTS (
           SELECT 1 FROM media_assets a
           WHERE a.id = j.media_asset_id AND a.status = 'pending'
         )
       ORDER BY j.id ASC
       LIMIT 1
       FOR UPDATE SKIP LOCKED`,
      params
    );
    if (!rows.length) {
      await conn.commit();
      return { type: 'none' };
    }
    const { job_id: jobId, media_asset_id: assetId } = rows[0];

    const [assets0] = await conn.query(
      `SELECT organization_id, branch_id, stored_basename FROM media_assets WHERE id = ?`,
      [assetId]
    );
    const row0 = assets0[0];
    const orgId = Number(row0.organization_id);
    const branchId = Number(row0.branch_id);
    const basename = String(row0.stored_basename);
    const srcPath = quarantinePath(systemRoot, orgId, branchId, basename);

    if (!fs.existsSync(srcPath)) {
      await conn.query(
        `UPDATE media_jobs SET status = 'failed', locked_at = NULL, error_message = ?, updated_at = NOW() WHERE id = ?`,
        ['Terminal: quarantine source file missing (not counted as processing attempt).', jobId]
      );
      await conn.query(`UPDATE media_assets SET status = 'failed', updated_at = NOW() WHERE id = ?`, [assetId]);
      await conn.commit();
      return { type: 'none' };
    }

    const [u1] = await conn.query(
      `UPDATE media_jobs
       SET status = 'processing', locked_at = NOW(), attempts = attempts + 1, updated_at = NOW()
       WHERE id = ? AND status = 'pending'`,
      [jobId]
    );
    if ((u1.affectedRows ?? 0) !== 1) {
      await conn.rollback();
      return { type: 'none' };
    }
    await conn.query(
      `UPDATE media_assets SET status = 'processing', updated_at = NOW() WHERE id = ? AND status = 'pending'`,
      [assetId]
    );
    const [assets] = await conn.query(
      `SELECT id, organization_id, branch_id, stored_basename, mime_detected, width, height, status
       FROM media_assets WHERE id = ?`,
      [assetId]
    );
    await conn.commit();
    return { type: 'claimed', jobId, asset: assets[0], srcPath };
  } catch (e) {
    await conn.rollback();
    throw e;
  }
}

/**
 * @param {Error} err
 * @returns {boolean}
 */
export function isTerminalProcessingError(err) {
  if (err instanceof TerminalJobError) {
    return true;
  }
  const m = String(err?.message ?? err).toLowerCase();
  const patterns = [
    'unsupported image',
    'unsupported format',
    'input file contains',
    'improper image header',
    'corrupt jpeg',
    'invalid png',
    'truncated',
    'vipserror',
    'bad seek',
    'magick',
    'heif',
    'svg',
  ];
  return patterns.some((p) => m.includes(p));
}

async function deleteVariantsForAsset(conn, assetId) {
  await conn.query(`DELETE FROM media_asset_variants WHERE media_asset_id = ?`, [assetId]);
}

async function terminalFail(pool, jobId, assetId, message, finalAbs, stagingAbs) {
  rimrafVariantFinalAndStaging(finalAbs, stagingAbs);
  const conn = await pool.getConnection();
  try {
    await conn.query(
      `UPDATE media_jobs SET status = 'failed', locked_at = NULL, error_message = ?, updated_at = NOW() WHERE id = ?`,
      [message.slice(0, 1900), jobId]
    );
    await conn.query(`UPDATE media_assets SET status = 'failed', updated_at = NOW() WHERE id = ?`, [assetId]);
  } finally {
    conn.release();
  }
}

async function requeueAfterTransientFailure(pool, jobId, assetId, finalAbs, stagingAbs, message) {
  rimrafVariantFinalAndStaging(finalAbs, stagingAbs);
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    await deleteVariantsForAsset(conn, assetId);
    await conn.query(
      `UPDATE media_jobs
       SET status = 'pending', locked_at = NULL, error_message = ?, available_at = NOW(), updated_at = NOW()
       WHERE id = ?`,
      [message.slice(0, 1900), jobId]
    );
    await conn.query(`UPDATE media_assets SET status = 'pending', updated_at = NOW() WHERE id = ?`, [assetId]);
    await conn.commit();
  } catch (e) {
    await conn.rollback();
    throw e;
  } finally {
    conn.release();
  }
}

/**
 * @param {import('mysql2/promise').Pool} pool
 * @param {{ avifEnabled: boolean, systemRoot: string, jobId: number, asset: object }} opts
 */
export async function processClaimedJob(pool, { avifEnabled, systemRoot, jobId, asset }) {
  const assetId = Number(asset.id);
  const orgId = Number(asset.organization_id);
  const branchId = Number(asset.branch_id);
  const basename = String(asset.stored_basename);

  const srcPath = quarantinePath(systemRoot, orgId, branchId, basename);
  const finalAbs = variantDirAbsolute(systemRoot, orgId, branchId, assetId);
  const stagingAbs = variantStagingDirAbsolute(systemRoot, orgId, branchId, assetId, jobId);
  const relBase = variantDirRelative(orgId, branchId, assetId);

  if (!fs.existsSync(srcPath)) {
    await terminalFail(pool, jobId, assetId, 'Terminal: quarantine file disappeared after claim.', finalAbs, stagingAbs);
    return { ok: false, reason: 'missing_file' };
  }

  rimrafVariantFinalAndStaging(finalAbs, stagingAbs);
  fs.mkdirSync(stagingAbs, { recursive: true });

  const variantsToInsert = [];

  const proofTransientId = process.env.WORKER_PROOF_TRANSIENT_JOB_ID;

  try {
    const formats = [
      { format: 'webp', ext: 'webp', encode: (buf, out) => sharp(buf).webp({ quality: WEBP_QUALITY }).toFile(out) },
      { format: 'jpg', ext: 'jpg', encode: (buf, out) => sharp(buf).jpeg({ quality: JPEG_QUALITY, mozjpeg: true }).toFile(out) },
    ];
    if (avifEnabled) {
      formats.push({
        format: 'avif',
        ext: 'avif',
        encode: (buf, out) => sharp(buf).avif({ quality: AVIF_QUALITY, effort: 4 }).toFile(out),
      });
    }

    let proofTransientThrown = false;

    for (const targetW of RESP_TARGET_WIDTHS) {
      let resized;
      try {
        resized = await sharp(srcPath, { failOn: 'error' })
          .rotate()
          .resize({
            width: targetW,
            withoutEnlargement: true,
          })
          .toBuffer();
      } catch (e) {
        if (isTerminalProcessingError(e)) {
          throw new TerminalJobError(`Unreadable or corrupt image (${e.message})`);
        }
        throw e;
      }

      const info = await sharp(resized).metadata();
      const w = info.width ?? targetW;
      const h = info.height ?? 0;

      for (const { format, ext, encode } of formats) {
        const fname = `r${targetW}w.${ext}`;
        const abs = path.join(stagingAbs, fname);
        await encode(resized, abs);
        const st = fs.statSync(abs);
        const relPath = path.posix.join(relBase, fname);
        variantsToInsert.push({
          media_asset_id: assetId,
          format,
          width: w,
          height: h,
          bytes: st.size,
          relative_path: relPath,
          is_primary: targetW === 960 && format === 'webp' ? 1 : 0,
          variant_kind: 'responsive',
        });

        if (!proofTransientThrown && proofTransientId === String(jobId)) {
          proofTransientThrown = true;
          throw new TransientJobError('proof: simulated transient failure after first variant file');
        }
      }
    }

    const thumbBuf = await sharp(srcPath, { failOn: 'error' })
      .rotate()
      .resize(THUMB_SIZE, THUMB_SIZE, { fit: 'cover', position: 'centre' })
      .toBuffer();
    const tinfo = await sharp(thumbBuf).metadata();
    const tw = tinfo.width ?? THUMB_SIZE;
    const th = tinfo.height ?? THUMB_SIZE;

    for (const { format, ext, encode } of formats) {
      const fname = `thumb${THUMB_SIZE}.${ext}`;
      const abs = path.join(stagingAbs, fname);
      await encode(thumbBuf, abs);
      const st = fs.statSync(abs);
      const relPath = path.posix.join(relBase, fname);
      variantsToInsert.push({
        media_asset_id: assetId,
        format,
        width: tw,
        height: th,
        bytes: st.size,
        relative_path: relPath,
        is_primary: 0,
        variant_kind: 'thumb',
      });
    }

    const conn = await pool.getConnection();
    try {
      await conn.beginTransaction();
      await deleteVariantsForAsset(conn, assetId);
      for (const v of variantsToInsert) {
        await conn.query(
          `INSERT INTO media_asset_variants
            (media_asset_id, format, width, height, bytes, relative_path, is_primary, variant_kind)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
          [v.media_asset_id, v.format, v.width, v.height, v.bytes, v.relative_path, v.is_primary, v.variant_kind]
        );
      }
      await conn.query(`UPDATE media_assets SET status = 'ready', updated_at = NOW() WHERE id = ?`, [assetId]);
      await conn.query(
        `UPDATE media_jobs SET status = 'completed', locked_at = NULL, error_message = NULL, updated_at = NOW() WHERE id = ?`,
        [jobId]
      );
      await conn.commit();
    } catch (e) {
      await conn.rollback();
      rimrafOutputDir(stagingAbs);
      throw e;
    } finally {
      conn.release();
    }
  } catch (err) {
    const msg = (err && err.message) || String(err);
    if (err instanceof TransientJobError || (!isTerminalProcessingError(err) && !(err instanceof TerminalJobError))) {
      await requeueAfterTransientFailure(pool, jobId, assetId, finalAbs, stagingAbs, `Transient: ${msg}`);
      return { ok: false, reason: 'transient_requeued', error: msg };
    }
    const terminalMsg =
      err instanceof TerminalJobError || isTerminalProcessingError(err) ? msg : `Terminal: ${msg}`;
    await terminalFail(pool, jobId, assetId, terminalMsg.slice(0, 1900), finalAbs, stagingAbs);
    return { ok: false, reason: 'terminal_failed', error: msg };
  }

  try {
    promoteVariantStagingToFinal(stagingAbs, finalAbs);
  } catch (promoteErr) {
    const pmsg = (promoteErr && promoteErr.message) || String(promoteErr);
    console.error(new Date().toISOString(), 'post_commit_variant_promote_failed', 'job_id=' + jobId, pmsg);
    return {
      ok: false,
      reason: 'post_commit_promote_failed',
      error: pmsg,
      jobId,
      assetId,
      dbCommitted: true,
    };
  }

  try {
    fs.unlinkSync(srcPath);
  } catch (unlinkErr) {
    console.error(new Date().toISOString(), 'quarantine_unlink_warn', String(unlinkErr.message || unlinkErr));
  }

  return {
    ok: true,
    jobId,
    assetId,
    variantCount: variantsToInsert.length,
    variantDir: finalAbs,
    relBase,
    quarantine_removed: true,
  };
}
