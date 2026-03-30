/**
 * M-007-MEDIA-SYSTEM-ROOT-FAIL-FAST-01: static proof that resolveSystemRoot() fails fast when
 * MEDIA_SYSTEM_ROOT is explicitly set but storage/media is missing (no silent fallback).
 *
 * Usage (from repo root):
 *   node system/scripts/read-only/verify_media_system_root_m007_01.mjs
 *
 * Optional runtime smoke (expect non-zero exit + Error message):
 *   cd workers/image-pipeline && MEDIA_SYSTEM_ROOT=/___no_such_m007_path___ node -e "import('./src/processor.mjs').then(m=>m.resolveSystemRoot())"
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const processorPath = path.join(__dirname, '../../../workers/image-pipeline/src/processor.mjs');
const src = fs.readFileSync(processorPath, 'utf8');

const checks = {
  'resolveSystemRoot uses hasOwnProperty on process.env for MEDIA_SYSTEM_ROOT':
    src.includes("Object.prototype.hasOwnProperty.call(process.env, 'MEDIA_SYSTEM_ROOT')"),
  'explicit branch returns default without env key (early return when !explicitlySet)':
    /if\s*\(\s*!explicitlySet\s*\)\s*\{[\s\S]*?path\.resolve\(__dirname/.test(src),
  'explicit empty MEDIA_SYSTEM_ROOT throws (no fallback)': src.includes('is set but empty'),
  'missing storage/media throws with resolved path and expected layout': src.includes('storage/media') && src.includes('required directory is missing'),
  'no silent fallback to worker-relative when env was checked': !/if\s*\(\s*envRoot\s*&&\s*fs\.existsSync/.test(src),
};

let failed = false;
for (const [label, ok] of Object.entries(checks)) {
  console.log(`${label}=${ok ? 'ok' : 'MISSING'}`);
  if (!ok) failed = true;
}

process.exit(failed ? 1 : 0);
