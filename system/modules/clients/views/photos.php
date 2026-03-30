<?php
$title = 'Client · Photos · ' . ($client['display_name'] ?? '');
$mainClass = 'client-resume-page client-ref-surface client-ref--client-tab client-ref--tab-photos';
$clientRefTitleRowSecondaryTab = true;
$csrfTn = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$clientProfilePhotoLibraryReady = !empty($clientProfilePhotoLibraryReady ?? false);
$clientProfilePhotoMediaReady = !empty($clientProfilePhotoMediaReady ?? false);
$clientProfilePhotoImages = is_array($clientProfilePhotoImages ?? null) ? $clientProfilePhotoImages : [];
$clientPhotosStatusUrl = htmlspecialchars((string) ($clientPhotosStatusUrl ?? '/clients/' . (int) $clientId . '/photos/status'), ENT_QUOTES, 'UTF-8');

if (!function_exists('client_gc_img_lib_render_status_cell')) {
    /**
     * @param array<string, mixed> $img
     */
    function client_gc_img_lib_render_status_cell(array $img): string
    {
        $libSt = (string) ($img['library_status'] ?? 'legacy');
        $runtime = is_array($img['pipeline_runtime'] ?? null) ? $img['pipeline_runtime'] : [];
        $stalledReason = (string) ($runtime['stalled_reason'] ?? '');
        $runtimeLogPath = (string) ($runtime['runtime_log_path'] ?? '');
        $stdoutLogPath = (string) ($runtime['stdout_log_path'] ?? '');
        $stderrLogPath = (string) ($runtime['stderr_log_path'] ?? '');
        $runtimeAssetId = isset($runtime['asset_id']) ? (int) $runtime['asset_id'] : 0;
        $spawnPid = isset($runtime['spawn_pid']) ? (int) $runtime['spawn_pid'] : 0;
        $operatorCmd = (string) ($runtime['operator_command'] ?? '');
        $diagnoseCmd = (string) ($runtime['diagnose_command'] ?? '');
        if ($libSt === 'legacy') {
            return '<span class="gc-img-lib__status-label gc-img-lib__status-label--legacy">Legacy file</span>';
        }
        if ($libSt === 'ready') {
            return '<span class="gc-img-lib__status-label gc-img-lib__status-label--ready" role="status">Ready</span>';
        }
        if ($libSt === 'failed') {
            return '<div class="gc-img-lib__failed" role="status" aria-live="polite" aria-atomic="true">'
                . '<span class="gc-img-lib__failed-title">Processing failed</span></div>';
        }
        if ($libSt === 'pending' || $libSt === 'processing') {
            if ($stalledReason !== '') {
                $reasonLabel = $stalledReason === 'spawned_but_boot_missing'
                    ? 'Launcher failed before drain boot'
                    : $stalledReason;

                return '<div class="gc-img-lib__failed" role="status" aria-live="polite" aria-atomic="true">'
                    . '<span class="gc-img-lib__failed-title">Pipeline stalled</span>'
                    . '<div class="gc-img-lib__failed-detail">Reason: ' . htmlspecialchars($reasonLabel, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '<div class="gc-img-lib__failed-detail">Asset ID: ' . $runtimeAssetId . '</div>'
                    . '<div class="gc-img-lib__failed-detail">PID: ' . ($spawnPid > 0 ? $spawnPid : 'n/a') . '</div>'
                    . '<div class="gc-img-lib__failed-detail">Stdout log: ' . htmlspecialchars($stdoutLogPath !== '' ? $stdoutLogPath : $runtimeLogPath, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '<div class="gc-img-lib__failed-detail">Stderr log: ' . htmlspecialchars($stderrLogPath, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '<div class="gc-img-lib__failed-detail">Rerun: ' . htmlspecialchars($operatorCmd, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '<div class="gc-img-lib__failed-detail">Diagnose: ' . htmlspecialchars($diagnoseCmd, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '</div>';
            }

            return '<div class="gc-img-lib__processing" role="status" aria-live="polite" aria-atomic="true">'
                . '<span class="gc-img-lib__spinner" aria-hidden="true"></span>'
                . '<div class="gc-img-lib__processing-text">'
                . '<span class="gc-img-lib__processing-title">Processing…</span>'
                . '<span class="gc-img-lib__processing-sub">Preparing preview</span>'
                . '<span class="gc-img-lib__processing-note">Not ready</span>'
                . '</div></div>';
        }

        return '<span class="gc-img-lib__status-label gc-img-lib__status-label--legacy">'
            . htmlspecialchars($libSt, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('client_gc_img_lib_render_preview_cell')) {
    /**
     * @param array<string, mixed> $img
     */
    function client_gc_img_lib_render_preview_cell(array $img): string
    {
        $libSt = (string) ($img['library_status'] ?? 'legacy');
        $pub = (string) ($img['public_variant_url'] ?? '');
        if ($pub !== '') {
            $a = htmlspecialchars($pub, ENT_QUOTES, 'UTF-8');

            return '<a class="gc-img-lib__preview-ready" href="' . $a . '" target="_blank" rel="noopener noreferrer">'
                . '<img src="' . $a . '" alt=""></a>';
        }
        if ($libSt === 'failed') {
            return '<span class="gc-img-lib__preview-none">—</span>';
        }
        if ($libSt === 'pending' || $libSt === 'processing') {
            return '<div class="gc-img-lib__preview-processing">'
                . '<span class="gc-img-lib__spinner gc-img-lib__spinner--sm" aria-hidden="true"></span>'
                . '<span>Preparing preview</span></div>';
        }

        return '<span class="gc-img-lib__preview-none">—</span>';
    }
}

ob_start();
?>
<div class="client-ref client-ref-surface client-ref--client-tab client-ref--tab-photos">
<?php require base_path('modules/clients/views/partials/client-ref-header-tabs.php'); ?>

    <div class="client-ref-body">
<?php require base_path('modules/clients/views/partials/client-ref-sidebar.php'); ?>

        <div class="client-ref-main client-ref-main--client-tab" role="main">
            <div class="client-ref-tab-workspace client-ref-photos-workspace">
                <header class="client-ref-tab-workspace__head">
                    <div>
                        <h2 class="client-ref-tab-workspace__title">Photos</h2>
                        <p class="client-ref-tab-workspace__lede">Client photos use the same canonical media pipeline as Gift Card Image Library (JPEG, PNG, WebP, AVIF). Previews appear only after processing completes.</p>
                    </div>
                </header>

                <?php if (!empty($flash) && is_array($flash)): $type = (string) array_key_first($flash); ?>
                <div class="flash flash-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars((string) ($flash[$type] ?? '')) ?></div>
                <?php endif; ?>

                <?php if ($clientPhotosError !== null): ?>
                <p class="client-ref-tab-workspace__muted client-ref-documents-workspace__load-error" role="alert"><?= htmlspecialchars($clientPhotosError) ?></p>
                <?php endif; ?>

                <?php if (!$clientProfilePhotoLibraryReady): ?>
                <p class="hint" role="status">Photo library is unavailable until migration 118 (client_profile_images) is applied.</p>
                <?php elseif (!$clientProfilePhotoMediaReady): ?>
                <p class="hint" role="status">Uploads require the media pipeline (migration 103) and media_asset_id on client_profile_images.</p>
                <?php elseif ($canUploadClientPhotos ?? false): ?>
                <form id="client-profile-img-upload-form" class="client-ref-documents-workspace__upload client-ref-photos-workspace__upload" method="post" action="/clients/<?= (int) $clientId ?>/photos/upload" enctype="multipart/form-data">
                    <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <label class="client-ref-documents-workspace__upload-label" for="client-profile-photo-title">Title (optional)</label>
                    <input id="client-profile-photo-title" type="text" name="title" maxlength="160" placeholder="e.g. Before treatment" style="max-width:420px;">
                    <label class="client-ref-documents-workspace__upload-label" for="client-profile-photo-file">Image file</label>
                    <input id="client-profile-photo-file" type="file" name="image" required accept=".jpg,.jpeg,.png,.webp,.avif,image/jpeg,image/png,image/webp,image/avif">
                    <button type="submit" class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--primary">Upload</button>
                </form>
                <div id="client-profile-img-upload-feedback" class="hint" style="margin-top:8px;display:none;" role="alert" aria-live="polite"></div>
                <?php endif; ?>

                <?php if ($clientProfilePhotoLibraryReady && $clientPhotosError === null): ?>
                <?php
                $hasPendingLib = false;
                foreach ($clientProfilePhotoImages as $imgRow) {
                    $st = (string) ($imgRow['library_status'] ?? '');
                    if ($st === 'pending' || $st === 'processing') {
                        $hasPendingLib = true;
                        break;
                    }
                }
                $csrfName = (string) config('app.csrf_token_name', 'csrf_token');
                ?>
                <?php if ($clientProfilePhotoImages === []): ?>
                <div class="client-ref-photos-workspace__stage" role="status" style="margin-bottom:1rem;">
                    <div class="client-ref-photos-workspace__placeholder" aria-hidden="true">
                        <span class="client-ref-photos-workspace__placeholder-icon"></span>
                    </div>
                    <p class="client-ref-photos-workspace__empty-title">No photos in this client library yet</p>
                    <p class="client-ref-photos-workspace__empty-text"><?= !empty($canUploadClientPhotos) ? 'Upload an image above (requires documents.edit).' : 'You can view this library but uploads require documents.edit.' ?></p>
                </div>
                <?php endif; ?>
                <div class="client-ref-tab-workspace__panel" style="margin-top:0;">
                    <h3 class="client-ref-tab-workspace__title" style="font-size:1rem;margin:0 0 0.5rem;">Active photos</h3>
                    <?php if ($clientProfilePhotoImages === []): ?>
                    <p class="hint" style="margin-top:0;">No rows yet — the table will populate after upload.</p>
                    <?php endif; ?>
                    <div class="client-ref-tab-workspace__table-wrap">
                        <table class="index-table gc-img-lib__table"
                               data-gc-img-lib-poll-url="<?= $clientPhotosStatusUrl ?>"
                               data-gc-img-lib-poll="<?= $hasPendingLib ? '1' : '0' ?>"
                               data-csrf-name="<?= htmlspecialchars($csrfName, ENT_QUOTES, 'UTF-8') ?>">
                            <thead>
                            <tr>
                                <th scope="col">Title</th>
                                <th scope="col">Status</th>
                                <th scope="col">Preview</th>
                                <th scope="col">Filename</th>
                                <th scope="col">MIME</th>
                                <th scope="col">Size (bytes)</th>
                                <th scope="col" class="client-ref-tab-workspace__col-action">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($clientProfilePhotoImages as $img): ?>
                            <?php $rowId = (int) ($img['id'] ?? 0); ?>
                            <?php $libSt = (string) ($img['library_status'] ?? 'legacy'); ?>
                            <tr data-gc-image-id="<?= $rowId ?>" data-library-status="<?= htmlspecialchars($libSt, ENT_QUOTES, 'UTF-8') ?>">
                                <td><?= htmlspecialchars((string) (($img['title'] ?? '') !== '' ? $img['title'] : '—')) ?></td>
                                <td class="gc-img-lib__status-cell"><?= client_gc_img_lib_render_status_cell($img) ?></td>
                                <td class="gc-img-lib__preview-cell"><?= client_gc_img_lib_render_preview_cell($img) ?></td>
                                <td class="gc-img-lib__meta-filename"><?= htmlspecialchars((string) ($img['display_filename'] ?? $img['filename'] ?? '')) ?></td>
                                <td class="gc-img-lib__meta-mime"><?= htmlspecialchars((string) ($img['display_mime'] ?? $img['mime_type'] ?? '')) ?></td>
                                <td class="gc-img-lib__meta-size"><?= (int) ($img['display_size_bytes'] ?? $img['size_bytes'] ?? 0) ?></td>
                                <td class="client-ref-tab-workspace__col-action">
                                    <?php if (!empty($canUploadClientPhotos) && $rowId > 0): ?>
                                    <form method="post" action="/clients/<?= (int) $clientId ?>/photos/<?= $rowId ?>/delete" onsubmit="return confirm('Remove this photo from the client library?');">
                                        <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                                        <button type="submit" class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--secondary">Delete</button>
                                    </form>
                                    <?php else: ?>
                                    <span class="client-ref-tab-workspace__muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <script>
                (function () {
                    var table = document.querySelector('table.gc-img-lib__table[data-gc-img-lib-poll-url]');
                    if (!table) return;
                    var pollUrl = table.getAttribute('data-gc-img-lib-poll-url');
                    if (!pollUrl) return;

                    function escAttr(s) {
                        return String(s)
                            .replace(/&/g, '&amp;')
                            .replace(/"/g, '&quot;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;');
                    }
                    function escText(s) {
                        return String(s)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;');
                    }
                    function safePublicUrl(u) {
                        if (typeof u !== 'string' || u === '') return null;
                        if (u.charAt(0) !== '/') return null;
                        return u;
                    }
                    function statusHtml(libSt, row, workerHint) {
                        var q = row && row.queue ? row.queue : null;
                        var runtime = row && row.pipeline_runtime ? row.pipeline_runtime : null;
                        var stalledReason = runtime && runtime.stalled_reason ? String(runtime.stalled_reason) : '';
                        var reason = workerHint && workerHint.probable_block_reason ? String(workerHint.probable_block_reason) : '';
                        if (libSt === 'legacy') {
                            return '<span class="gc-img-lib__status-label gc-img-lib__status-label--legacy">Legacy file</span>';
                        }
                        if (libSt === 'ready') {
                            return '<span class="gc-img-lib__status-label gc-img-lib__status-label--ready" role="status">Ready</span>';
                        }
                        if (libSt === 'failed') {
                            var err = q && q.error_message ? String(q.error_message) : '';
                            var detail = err ? '<div class="gc-img-lib__failed-detail">' + escText(err) + '</div>' : '';
                            return '<div class="gc-img-lib__failed" role="status" aria-live="polite" aria-atomic="true">' +
                                '<span class="gc-img-lib__failed-title">Processing failed</span>' + detail + '</div>';
                        }
                        if (libSt === 'pending' || libSt === 'processing') {
                            if (stalledReason !== '') {
                                var runtimeAssetId = runtime && runtime.asset_id != null ? String(runtime.asset_id) : '';
                                var runtimeLogPath = runtime && runtime.runtime_log_path ? String(runtime.runtime_log_path) : '';
                                var stdoutLogPath = runtime && runtime.stdout_log_path ? String(runtime.stdout_log_path) : runtimeLogPath;
                                var stderrLogPath = runtime && runtime.stderr_log_path ? String(runtime.stderr_log_path) : '';
                                var spawnPid = runtime && runtime.spawn_pid != null ? String(runtime.spawn_pid) : 'n/a';
                                var runtimeCmd = runtime && runtime.operator_command ? String(runtime.operator_command) : '';
                                var diagnoseCmd = runtime && runtime.diagnose_command ? String(runtime.diagnose_command) : '';
                                var reasonLabel = stalledReason === 'spawned_but_boot_missing'
                                    ? 'Launcher failed before drain boot'
                                    : stalledReason;
                                return '<div class="gc-img-lib__failed" role="status" aria-live="polite" aria-atomic="true">' +
                                    '<span class="gc-img-lib__failed-title">Pipeline stalled</span>' +
                                    '<div class="gc-img-lib__failed-detail">Reason: ' + escText(reasonLabel) + '</div>' +
                                    '<div class="gc-img-lib__failed-detail">Asset ID: ' + escText(runtimeAssetId) + '</div>' +
                                    '<div class="gc-img-lib__failed-detail">PID: ' + escText(spawnPid) + '</div>' +
                                    '<div class="gc-img-lib__failed-detail">Stdout log: ' + escText(stdoutLogPath) + '</div>' +
                                    '<div class="gc-img-lib__failed-detail">Stderr log: ' + escText(stderrLogPath) + '</div>' +
                                    '<div class="gc-img-lib__failed-detail">Rerun: ' + escText(runtimeCmd) + '</div>' +
                                    '<div class="gc-img-lib__failed-detail">Diagnose: ' + escText(diagnoseCmd) + '</div>' +
                                    '</div>';
                            }
                            var title = 'Processing…';
                            var sub = 'Waiting for pipeline';
                            var showSpinner = true;
                            if (reason === 'drain_failed' || reason === 'drain_exhausted' || reason === 'spawn_failed') {
                                title = 'Pipeline stalled';
                                var blockDetail = workerHint && workerHint.block_detail ? String(workerHint.block_detail) : '';
                                var op = workerHint && workerHint.operator_command ? String(workerHint.operator_command) : '';
                                sub = blockDetail !== '' ? blockDetail : 'Auto-drain could not start';
                                if (op !== '') sub += ' | Run: ' + op;
                                showSpinner = false;
                            } else if (reason === 'worker_not_running') {
                                title = 'Worker not running';
                                var cmd = workerHint && workerHint.operator_command ? String(workerHint.operator_command) : 'php scripts/dev-only/run_media_image_worker_loop.php';
                                sub = 'Start worker: ' + cmd;
                                showSpinner = false;
                            } else if (reason === 'healthy_backlog' && q && q.pending_jobs_ahead > 0) {
                                title = 'Queued';
                                sub = 'Behind ' + q.pending_jobs_ahead + ' older job(s) in the media queue';
                                showSpinner = true;
                                if (workerHint && workerHint.large_fifo_backlog) {
                                    sub += ' (large backlog)';
                                }
                                var staleNonBlocking = workerHint && workerHint.stale_processing_rows_ahead_non_blocking ? Number(workerHint.stale_processing_rows_ahead_non_blocking) : 0;
                                if (staleNonBlocking > 0) {
                                    sub += '; stale processing rows exist but are non-blocking';
                                }
                            } else if (reason === 'stale_present_non_blocking') {
                                title = 'Queued';
                                sub = 'Stale processing rows detected (non-blocking); worker can still claim pending jobs';
                                showSpinner = true;
                            } else if (workerHint && workerHint.large_fifo_backlog) {
                                title = 'Queued';
                                sub = 'Large backlog ahead in the media queue';
                                showSpinner = true;
                            } else if (reason === 'processing') {
                                title = 'Processing';
                                sub = 'Worker is running the image pipeline';
                                showSpinner = true;
                            }
                            var spin = showSpinner ? '<span class="gc-img-lib__spinner" aria-hidden="true"></span>' : '';
                            return '<div class="gc-img-lib__processing gc-img-lib__processing--honest" role="status" aria-live="polite" aria-atomic="true">' +
                                spin +
                                '<div class="gc-img-lib__processing-text">' +
                                '<span class="gc-img-lib__processing-title">' + escText(title) + '</span>' +
                                '<span class="gc-img-lib__processing-sub">' + escText(sub) + '</span>' +
                                '<span class="gc-img-lib__processing-note">Not ready</span>' +
                                '</div></div>';
                        }
                        return '<span class="gc-img-lib__status-label gc-img-lib__status-label--legacy">' + escText(libSt) + '</span>';
                    }
                    function previewHtml(libSt, pub, workerHint) {
                        var url = safePublicUrl(pub);
                        if (url) {
                            var a = escAttr(url);
                            return '<a class="gc-img-lib__preview-ready" href="' + a + '" target="_blank" rel="noopener noreferrer">' +
                                '<img src="' + a + '" alt=""></a>';
                        }
                        if (libSt === 'failed') {
                            return '<span class="gc-img-lib__preview-none">—</span>';
                        }
                        if (libSt === 'pending' || libSt === 'processing') {
                            var runtime = workerHint && workerHint._row_runtime ? workerHint._row_runtime : null;
                            var stalledReason = runtime && runtime.stalled_reason ? String(runtime.stalled_reason) : '';
                            if (stalledReason !== '') {
                                return '<div class="gc-img-lib__preview-wait"><span class="gc-img-lib__preview-wait-msg">Stalled - no preview</span></div>';
                            }
                            var reason = workerHint && workerHint.probable_block_reason ? String(workerHint.probable_block_reason) : '';
                            if (reason === 'drain_failed' || reason === 'drain_exhausted' || reason === 'spawn_failed' || reason === 'worker_not_running') {
                                return '<div class="gc-img-lib__preview-wait"><span class="gc-img-lib__preview-wait-msg">No preview until the worker runs</span></div>';
                            }
                            return '<div class="gc-img-lib__preview-processing">' +
                                '<span class="gc-img-lib__spinner gc-img-lib__spinner--sm" aria-hidden="true"></span>' +
                                '<span>Preparing preview</span></div>';
                        }
                        return '<span class="gc-img-lib__preview-none">—</span>';
                    }
                    function anyPending() {
                        var rows = table.querySelectorAll('tbody tr[data-gc-image-id]');
                        for (var i = 0; i < rows.length; i++) {
                            var st = rows[i].getAttribute('data-library-status') || '';
                            if (st === 'pending' || st === 'processing') return true;
                        }
                        return false;
                    }
                    var lastWorkerHint = null;
                    function applyRow(tr, row, workerHint) {
                        if (!row || !tr) return;
                        var st = row.library_status || '';
                        tr.setAttribute('data-library-status', st);
                        var sc = tr.querySelector('.gc-img-lib__status-cell');
                        var pc = tr.querySelector('.gc-img-lib__preview-cell');
                        var fn = tr.querySelector('.gc-img-lib__meta-filename');
                        var mm = tr.querySelector('.gc-img-lib__meta-mime');
                        var sz = tr.querySelector('.gc-img-lib__meta-size');
                        var wh = workerHint || lastWorkerHint;
                        var whForRow = wh ? Object.assign({}, wh, { _row_runtime: row.pipeline_runtime || null }) : { _row_runtime: row.pipeline_runtime || null };
                        if (sc) sc.innerHTML = statusHtml(st, row, whForRow);
                        if (pc) pc.innerHTML = previewHtml(st, row.public_variant_url, whForRow);
                        if (fn) fn.textContent = row.display_filename != null ? String(row.display_filename) : '';
                        if (mm) mm.textContent = row.display_mime != null ? String(row.display_mime) : '';
                        if (sz) sz.textContent = String(row.display_size_bytes != null ? row.display_size_bytes : 0);
                    }
                    function nextDelayMs() {
                        return 3000 + Math.floor(Math.random() * 2000);
                    }
                    var timer = null;
                    function schedule() {
                        if (timer) clearTimeout(timer);
                        if (!anyPending()) {
                            timer = null;
                            table.setAttribute('data-gc-img-lib-poll', '0');
                            return;
                        }
                        timer = setTimeout(tick, nextDelayMs());
                    }
                    function pendingPollImageIds() {
                        var ids = [];
                        var trs = table.querySelectorAll('tbody tr[data-gc-image-id]');
                        for (var i = 0; i < trs.length; i++) {
                            var tr = trs[i];
                            var idAttr = tr.getAttribute('data-gc-image-id') || '';
                            if (idAttr.indexOf('uploading-temp') === 0) continue;
                            var idNum = parseInt(idAttr, 10);
                            if (!idNum) continue;
                            var st = tr.getAttribute('data-library-status') || '';
                            if (st === 'pending' || st === 'processing') {
                                ids.push(idNum);
                            }
                        }
                        if (ids.length > 50) {
                            ids = ids.slice(0, 50);
                        }
                        return ids;
                    }
                    function tick() {
                        timer = null;
                        if (!anyPending()) {
                            table.setAttribute('data-gc-img-lib-poll', '0');
                            return;
                        }
                        var pollIds = pendingPollImageIds();
                        var sep = pollUrl.indexOf('?') >= 0 ? '&' : '?';
                        var fetchUrl = pollIds.length
                            ? pollUrl + sep + 'ids=' + encodeURIComponent(pollIds.join(','))
                            : pollUrl;
                        fetch(fetchUrl, {
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' }
                        })
                            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                            .then(function (payload) {
                                var list = (payload && payload.images) ? payload.images : [];
                                lastWorkerHint = payload && payload.worker_hint ? payload.worker_hint : null;
                                var removed = (payload && payload.removed_image_ids) ? payload.removed_image_ids : [];
                                for (var ri = 0; ri < removed.length; ri++) {
                                    var rid = parseInt(String(removed[ri]), 10);
                                    if (!rid) continue;
                                    var delTr = table.querySelector('tbody tr[data-gc-image-id="' + rid + '"]');
                                    if (delTr && delTr.parentNode) {
                                        delTr.parentNode.removeChild(delTr);
                                    }
                                }
                                var map = {};
                                for (var j = 0; j < list.length; j++) {
                                    map[list[j].id] = list[j];
                                }
                                var trs = table.querySelectorAll('tbody tr[data-gc-image-id]');
                                for (var k = 0; k < trs.length; k++) {
                                    var tr = trs[k];
                                    var id = parseInt(tr.getAttribute('data-gc-image-id'), 10);
                                    if (!map[id]) continue;
                                    applyRow(tr, map[id], lastWorkerHint);
                                }
                                schedule();
                            })
                            .catch(function () {
                                if (anyPending()) {
                                    timer = setTimeout(tick, nextDelayMs() + 2000);
                                }
                            });
                    }
                    if (table.getAttribute('data-gc-img-lib-poll') === '1') {
                        schedule();
                    }
                    document.addEventListener('gc-img-lib-start-poll', function () {
                        table.setAttribute('data-gc-img-lib-poll', '1');
                        schedule();
                    });
                })();
                </script>
                <script>
                (function () {
                    var form = document.getElementById('client-profile-img-upload-form');
                    if (!form || !window.fetch || !window.FormData) return;
                    var feedback = document.getElementById('client-profile-img-upload-feedback');
                    var submitBtn = form.querySelector('button[type="submit"]');
                    var fileInput = form.querySelector('input[name="image"]');
                    var titleInput = form.querySelector('input[name="title"]');
                    var table = document.querySelector('table.gc-img-lib__table[data-gc-img-lib-poll-url]');
                    function setFeedback(msg, isError) {
                        if (!feedback) return;
                        var text = msg ? String(msg) : '';
                        if (!text || !isError) {
                            feedback.textContent = '';
                            feedback.style.display = 'none';
                            return;
                        }
                        feedback.textContent = text;
                        feedback.style.color = '#a12727';
                        feedback.style.display = 'block';
                    }
                    function escText(s) {
                        return String(s)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;');
                    }
                    function escAttr(s) {
                        return String(s)
                            .replace(/&/g, '&amp;')
                            .replace(/"/g, '&quot;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;');
                    }
                    function insertLoadingRow(file, title) {
                        if (!table) return null;
                        var tbody = table.querySelector('tbody');
                        if (!tbody) return null;
                        var tr = document.createElement('tr');
                        tr.setAttribute('data-gc-image-id', 'uploading-temp-' + String(Date.now()));
                        tr.setAttribute('data-library-status', 'pending');
                        tr.innerHTML =
                            '<td>' + escText(title || '') + '</td>' +
                            '<td class="gc-img-lib__status-cell"><div class="gc-img-lib__processing" role="status" aria-live="polite" aria-atomic="true">' +
                            '<span class="gc-img-lib__spinner" aria-hidden="true"></span>' +
                            '<div class="gc-img-lib__processing-text">' +
                            '<span class="gc-img-lib__processing-title">Uploading...</span>' +
                            '<span class="gc-img-lib__processing-sub">Queueing media pipeline</span>' +
                            '<span class="gc-img-lib__processing-note">Not ready</span>' +
                            '</div></div></td>' +
                            '<td class="gc-img-lib__preview-cell"><div class="gc-img-lib__preview-processing">' +
                            '<span class="gc-img-lib__spinner gc-img-lib__spinner--sm" aria-hidden="true"></span>' +
                            '<span>Preparing preview</span></div></td>' +
                            '<td class="gc-img-lib__meta-filename">' + escText(file && file.name ? file.name : '') + '</td>' +
                            '<td class="gc-img-lib__meta-mime">' + escText(file && file.type ? file.type : '') + '</td>' +
                            '<td class="gc-img-lib__meta-size">' + escText(file && file.size != null ? String(file.size) : '0') + '</td>' +
                            '<td><button type="button" class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--secondary" disabled>Delete</button></td>';
                        tbody.insertBefore(tr, tbody.firstChild);
                        table.setAttribute('data-gc-img-lib-poll', '1');
                        document.dispatchEvent(new Event('gc-img-lib-start-poll'));
                        return tr;
                    }
                    function markRowAccepted(tr, imageId, clientId) {
                        if (!tr) return;
                        var idNum = imageId ? parseInt(String(imageId), 10) : 0;
                        if (idNum > 0) {
                            tr.setAttribute('data-gc-image-id', String(idNum));
                        }
                        var status = tr.querySelector('.gc-img-lib__status-cell');
                        if (status) {
                            status.innerHTML = '<div class="gc-img-lib__processing" role="status" aria-live="polite" aria-atomic="true">' +
                                '<span class="gc-img-lib__spinner" aria-hidden="true"></span>' +
                                '<div class="gc-img-lib__processing-text">' +
                                '<span class="gc-img-lib__processing-title">Uploaded</span>' +
                                '<span class="gc-img-lib__processing-sub">Processing in pipeline</span>' +
                                '<span class="gc-img-lib__processing-note">Not ready</span>' +
                                '</div></div>';
                        }
                        var actionsTd = tr.querySelector('td:last-child');
                        if (actionsTd && idNum > 0 && table) {
                            var csrfField = table.getAttribute('data-csrf-name') || 'csrf_token';
                            var csrfEl = null;
                            var hidInputs = form.querySelectorAll('input[type="hidden"]');
                            for (var hi = 0; hi < hidInputs.length; hi++) {
                                if (hidInputs[hi].name === csrfField) {
                                    csrfEl = hidInputs[hi];
                                    break;
                                }
                            }
                            if (!csrfEl && hidInputs.length) {
                                csrfEl = hidInputs[0];
                            }
                            var token = csrfEl && csrfEl.value ? String(csrfEl.value) : '';
                            actionsTd.innerHTML =
                                '<form method="post" action="/clients/' + String(clientId) + '/photos/' + String(idNum) + '/delete" onsubmit="return confirm(\'Remove this photo from the client library?\');">' +
                                '<input type="hidden" name="' + escAttr(csrfField) + '" value="' + escAttr(token) + '">' +
                                '<button type="submit" class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--secondary">Delete</button></form>';
                        }
                    }
                    function removeRow(tr) {
                        if (tr && tr.parentNode) tr.parentNode.removeChild(tr);
                    }
                    var clientId = <?= (int) $clientId ?>;
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        if (submitBtn) submitBtn.disabled = true;
                        setFeedback('', false);
                        var file = fileInput && fileInput.files && fileInput.files.length ? fileInput.files[0] : null;
                        var title = titleInput && titleInput.value ? titleInput.value : '';
                        var loadingRow = insertLoadingRow(file, title);
                        fetch(form.getAttribute('action') || '', {
                            method: 'POST',
                            body: new FormData(form),
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                            .then(function (r) {
                                return r.json().catch(function () { return {}; }).then(function (data) {
                                    if (!r.ok || data.ok === false) {
                                        var msg = data && data.error && data.error.message ? String(data.error.message) : (data && data.message ? String(data.message) : 'Upload failed.');
                                        throw new Error(msg);
                                    }
                                    return data;
                                });
                            })
                            .then(function (payload) {
                                var imageId = payload && payload.image_id ? String(payload.image_id) : '';
                                setFeedback('', false);
                                markRowAccepted(loadingRow, imageId, clientId);
                                document.dispatchEvent(new Event('gc-img-lib-start-poll'));
                                if (fileInput) fileInput.value = '';
                                if (titleInput) titleInput.value = '';
                            })
                            .catch(function (err) {
                                removeRow(loadingRow);
                                setFeedback(err && err.message ? String(err.message) : 'Upload failed.', true);
                            })
                            .finally(function () {
                                if (submitBtn) submitBtn.disabled = false;
                            });
                    });
                })();
                </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require base_path('modules/clients/views/partials/client-ref-shell-styles.php'); ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
