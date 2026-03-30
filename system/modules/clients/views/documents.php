<?php
$title = 'Client · Documents · ' . ($client['display_name'] ?? '');
$mainClass = 'client-resume-page client-ref-surface client-ref--client-tab client-ref--tab-documents';
$clientRefTitleRowSecondaryTab = true;
$csrfTn = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
ob_start();
?>
<div class="client-ref client-ref-surface client-ref--client-tab client-ref--tab-documents">
<?php require base_path('modules/clients/views/partials/client-ref-header-tabs.php'); ?>

    <div class="client-ref-body">
<?php require base_path('modules/clients/views/partials/client-ref-sidebar.php'); ?>

        <div class="client-ref-main client-ref-main--client-tab" role="main">
            <div class="client-ref-tab-workspace client-ref-documents-workspace">
                <header class="client-ref-tab-workspace__head">
                    <div>
                        <h2 class="client-ref-tab-workspace__title">Document storage</h2>
                        <p class="client-ref-tab-workspace__lede">Files linked to this client in the documents module, plus consent and waiver records for the current branch.</p>
                    </div>
                </header>

                <?php if ($clientDocumentsError !== null): ?>
                <p class="client-ref-tab-workspace__muted client-ref-documents-workspace__load-error" role="alert"><?= htmlspecialchars($clientDocumentsError) ?></p>
                <?php endif; ?>

                <?php if ($canUploadClientDocuments): ?>
                <form class="client-ref-documents-workspace__upload" method="post" action="/clients/<?= (int) $clientId ?>/documents/upload" enctype="multipart/form-data">
                    <input type="hidden" name="<?= $csrfTn ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <label class="client-ref-documents-workspace__upload-label" for="client-doc-upload">Upload file (PDF or image, max 10MB)</label>
                    <input id="client-doc-upload" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" required>
                    <button type="submit" class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--primary">Upload</button>
                </form>
                <?php endif; ?>

                <h3 class="client-ref-tab-workspace__subhead">Files</h3>
                <?php if ($clientDocumentsError === null && empty($clientOwnedFileRows)): ?>
                <div class="client-ref-documents-workspace__empty client-ref-documents-workspace__empty--inline" role="status">
                    <p class="client-ref-documents-workspace__empty-text">No files are linked to this client yet.</p>
                </div>
                <?php elseif ($clientDocumentsError === null): ?>
                <div class="client-ref-tab-workspace__panel client-ref-documents-workspace__panel">
                    <div class="client-ref-tab-workspace__table-wrap">
                        <table class="client-ref-tab-workspace__table client-ref-documents-workspace__table">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="client-ref-tab-workspace__col-action">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($clientOwnedFileRows as $row): ?>
                                <?php
                                $docId = (int) ($row['document_id'] ?? 0);
                                $mime = (string) ($row['mime_type'] ?? '');
                                $ext = (string) ($row['extension'] ?? '');
                                $typeLabel = $mime !== '' ? $mime : ($ext !== '' ? $ext : '—');
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($row['original_name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($typeLabel) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['document_status'] ?? ($row['status'] ?? ''))) ?></td>
                                    <td class="client-ref-tab-workspace__col-action">
                                        <?php if ($docId > 0): ?>
                                        <a class="client-ref-tab-workspace__link" href="/documents/files/<?= $docId ?>/download">Download</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <h3 class="client-ref-tab-workspace__subhead client-ref-documents-workspace__subhead--secondary">Consents &amp; waivers</h3>
                <p class="client-ref-documents-workspace__api-hint">Integrations: <code><?= htmlspecialchars($documentsApiConsentsUrl) ?></code></p>

                <?php if ($clientConsentsError !== null): ?>
                <p class="client-ref-tab-workspace__muted client-ref-documents-workspace__load-error" role="alert"><?= htmlspecialchars($clientConsentsError) ?></p>
                <?php endif; ?>

                <?php if ($clientConsentsError === null && empty($clientConsentRows)): ?>
                <div class="client-ref-documents-workspace__empty client-ref-documents-workspace__empty--inline" role="status">
                    <p class="client-ref-documents-workspace__empty-text">No consent records for this client in the current branch.</p>
                </div>
                <?php elseif ($clientConsentsError === null): ?>
                <div class="client-ref-tab-workspace__panel client-ref-documents-workspace__panel">
                    <div class="client-ref-tab-workspace__table-wrap">
                        <table class="client-ref-tab-workspace__table client-ref-documents-workspace__table">
                            <thead>
                                <tr>
                                    <th scope="col">Type</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Signed</th>
                                    <th scope="col">Expires</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($clientConsentRows as $row): ?>
                                <tr>
                                    <td>
                                        <span class="client-ref-documents-workspace__type"><?= htmlspecialchars((string) ($row['definition_name'] ?? '')) ?></span>
                                        <?php if (($row['definition_code'] ?? '') !== ''): ?>
                                        <span class="client-ref-documents-workspace__type-code"><code><?= htmlspecialchars((string) $row['definition_code']) ?></code></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($row['status'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['signed_at'] ?? '—')) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['expires_at'] ?? '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require base_path('modules/clients/views/partials/client-ref-shell-styles.php'); ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
