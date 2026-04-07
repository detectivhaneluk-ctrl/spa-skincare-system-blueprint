<?php
$title = 'Admin';
ob_start();
$establishment = $establishment ?? [];
$cancellation = $cancellation ?? [];
$appointment = $appointment ?? [];
$onlineBooking = $onlineBooking ?? [];
$intake = $intake ?? ['public_enabled' => true];
$payment = $payment ?? [];
$waitlist = $waitlist ?? [];
$marketing = $marketing ?? [];
$security = $security ?? ['password_expiration' => 'never', 'inactivity_timeout_minutes' => 30];
$notification = $notification ?? ['appointments_enabled' => true, 'sales_enabled' => true, 'waitlist_enabled' => true, 'memberships_enabled' => true];
$hardware = $hardware ?? ['use_cash_register' => true, 'use_receipt_printer' => false];
$membership = $membership ?? ['terms_text' => '', 'renewal_reminder_days' => 7, 'grace_period_days' => 0];
$closureDatesStorageReady = !empty($closureDatesStorageReady);
$closureDatesBranchId = isset($closureDatesBranchId) && $closureDatesBranchId !== null ? (int) $closureDatesBranchId : null;
$closureDatesBranchName = (string) ($closureDatesBranchName ?? '');
$closureDatesRows = is_array($closureDatesRows ?? null) ? $closureDatesRows : [];
$secondaryContactBranchId = isset($secondaryContactBranchId) && $secondaryContactBranchId !== null ? (int) $secondaryContactBranchId : null;
$secondaryContactBranchName = (string) ($secondaryContactBranchName ?? '');
$secondaryContact = is_array($secondaryContact ?? null) ? $secondaryContact : [
    'secondary_contact_first_name' => '',
    'secondary_contact_last_name' => '',
    'secondary_contact_phone' => '',
    'secondary_contact_email' => '',
];
$branches = $branches ?? [];
$onlineBookingBranchId = (int) ($onlineBookingBranchId ?? 0);
$appointmentsBranchId = (int) ($appointmentsBranchId ?? 0);
$appointmentsBranchName = '';
if ($appointmentsBranchId > 0) {
    foreach ($branches as $branchRow) {
        if ((int) ($branchRow['id'] ?? 0) === $appointmentsBranchId) {
            $appointmentsBranchName = (string) ($branchRow['name'] ?? '');
            break;
        }
    }
}
$canManageMembershipsLink = !empty($canManageMembershipsLink);
$paymentsBranchId = (int) ($paymentsBranchId ?? 0);
$waitlistBranchId = (int) ($waitlistBranchId ?? 0);
$marketingBranchId = (int) ($marketingBranchId ?? 0);
$paymentMethodsEffective = isset($paymentMethodsEffective) && is_array($paymentMethodsEffective) ? $paymentMethodsEffective : [];
$paymentEdit = (string) ($paymentEdit ?? '');
$selectedBranchName = '';
$waitlistBranchName = '';
$marketingBranchName = '';
if ($onlineBookingBranchId > 0) {
    foreach ($branches as $branchRow) {
        if ((int) ($branchRow['id'] ?? 0) === $onlineBookingBranchId) {
            $selectedBranchName = (string) ($branchRow['name'] ?? '');
            break;
        }
    }
}
if ($waitlistBranchId > 0) {
    foreach ($branches as $branchRow) {
        if ((int) ($branchRow['id'] ?? 0) === $waitlistBranchId) {
            $waitlistBranchName = (string) ($branchRow['name'] ?? '');
            break;
        }
    }
}
if ($marketingBranchId > 0) {
    foreach ($branches as $branchRow) {
        if ((int) ($branchRow['id'] ?? 0) === $marketingBranchId) {
            $marketingBranchName = (string) ($branchRow['name'] ?? '');
            break;
        }
    }
}
$activeSection = (string) ($activeSettingsSection ?? 'establishment');
$flashKind = is_array($flash ?? null) ? (string) (($flash['type'] ?? $flash['kind'] ?? '') ?: '') : '';
$flashMessage = is_array($flash ?? null) ? (string) (($flash['message'] ?? '') ?: '') : '';
$cancellationMode = strtolower(trim((string) ($_GET['cancellation_mode'] ?? 'read')));
$cancellationPolicyEditOpen = $cancellationMode === 'edit';
if ($activeSection === 'cancellation' && !$cancellationPolicyEditOpen && $flashKind === 'error') {
    $cancellationPolicyEditOpen = true;
}
$cancellationPolicyTextMode = strtolower(trim((string) ($_GET['cancellation_policy_text_mode'] ?? 'read')));
$cancellationPolicyTextEditOpen = $cancellationPolicyTextMode === 'edit';
$cancellationReasonsMode = strtolower(trim((string) ($_GET['cancellation_reasons_mode'] ?? 'read')));
$cancellationReasonsEditOpen = $cancellationReasonsMode === 'edit';
$cancellationReasonsOld = is_array($flash['cancellation_reasons_old'] ?? null) ? $flash['cancellation_reasons_old'] : ['rows' => [], 'new_reason_name' => '', 'reason_required' => !empty($cancellation['reason_required'])];
if ($activeSection === 'cancellation' && !$cancellationReasonsEditOpen && $flashKind === 'error') {
    $cancellationReasonsEditOpen = true;
}
$yn = static fn (bool $v): string => $v ? 'Yes' : 'No';
if (in_array($activeSection, ['establishment', 'cancellation', 'payments'], true)) {
    require base_path('modules/settings/views/establishment/_styles.php');
}
?>
<?php if ($activeSection === 'establishment'): ?>
            <?php
            $activeEstablishmentScreen = (string) ($activeEstablishmentScreen ?? 'overview');
            $allowedEstablishmentScreens = [
                'overview',
                'edit-overview',
                'edit-primary-contact',
                'edit-secondary-contact',
                'opening-hours',
                'closure-dates',
            ];
            if (!in_array($activeEstablishmentScreen, $allowedEstablishmentScreens, true)) {
                $activeEstablishmentScreen = 'overview';
            }
            $establishmentUrl = static function (string $screen): string {
                return '/settings?' . http_build_query([
                    'section' => 'establishment',
                    'screen' => $screen,
                ]);
            };
            $establishmentScreenFile = base_path('modules/settings/views/establishment/screens/' . $activeEstablishmentScreen . '.php');
            if (!is_file($establishmentScreenFile)) {
                $establishmentScreenFile = base_path('modules/settings/views/establishment/screens/overview.php');
            }
            require $establishmentScreenFile;
            ?>
            <?php elseif ($activeSection === 'cancellation'): ?>
            <section class="settings-establishment">
                <header class="settings-establishment__hero">
                    <h2 class="settings-establishment__title">Cancellation Policy</h2>
                    <p class="settings-establishment__lead">Review current cancellation policy values, then open edit mode when you want to make changes.</p>
                </header>
                <?php if (!$cancellationPolicyEditOpen): ?>
                <div class="settings-establishment-grid">
                    <section class="settings-establishment-card settings-establishment-card--full">
                        <h3 class="settings-establishment-card__title">At a glance</h3>
                        <div class="settings-establishment-summary">
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Allow cancellations</span><span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($cancellation['enabled']))) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Apply to</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['customer_scope'] ?? 'all')) ?> (saved for future operational enforcement)</span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Minimum notice required (hours)</span><span class="settings-establishment-summary__value"><?= (int) ($cancellation['min_notice_hours'] ?? 0) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Cancellation fee</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['fee_mode'] ?? 'none')) ?> (configuration only in this phase)</span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Cancellation fixed amount</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['fee_fixed_amount'] ?? 0)) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Cancellation percent</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['fee_percent'] ?? 0)) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Staff contribution from cancellation fee</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['staff_payout_mode'] ?? 'none')) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Staff payout percent</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['staff_payout_percent'] ?? 0)) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">No-show same as cancellation</span><span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($cancellation['no_show_same_as_cancellation']))) ?> (configuration only in this phase)</span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">No-show fee</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['no_show_fee_mode'] ?? 'none')) ?> (configuration only in this phase)</span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">No-show fixed amount</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['no_show_fee_fixed_amount'] ?? 0)) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">No-show percent</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['no_show_fee_percent'] ?? 0)) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Staff contribution from no-show fee</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['no_show_staff_payout_mode'] ?? 'none')) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">No-show staff payout percent</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['no_show_staff_payout_percent'] ?? 0)) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Course same as cancellation</span><span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($cancellation['course_same_as_cancellation']))) ?> (configuration only in this phase)</span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Course fee</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['course_fee_mode'] ?? 'none')) ?> (configuration only in this phase)</span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Course fixed amount</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['course_fee_fixed_amount'] ?? 0)) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Course fee percent</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($cancellation['course_fee_percent'] ?? 0)) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Cancellation reasons active</span><span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($cancellation['reasons_enabled']))) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Reason required</span><span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($cancellation['reason_required']))) ?></span></div>
                            <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Tax enabled</span><span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($cancellation['tax_enabled']))) ?> (configuration only in this phase)</span></div>
                        </div>
                        <div class="settings-establishment-actions">
                            <a class="settings-establishment-btn" href="/settings?<?= htmlspecialchars(http_build_query(['section' => 'cancellation', 'cancellation_mode' => 'edit'])) ?>">Edit Cancellation Policy</a>
                        </div>
                    </section>
                    <section class="settings-establishment-card">
                        <h3 class="settings-establishment-card__title">Policy Text</h3>
                        <?php if (!$cancellationPolicyTextEditOpen): ?>
                            <div class="settings-policy-rich-content"><?= (string) ($cancellation['policy_text'] ?? '') ?></div>
                            <div class="settings-establishment-actions">
                                <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?<?= htmlspecialchars(http_build_query(['section' => 'cancellation', 'cancellation_policy_text_mode' => 'edit'])) ?>">Edit Policy Text</a>
                            </div>
                        <?php else: ?>
                            <form method="post" action="/settings" class="settings-form" id="cancellation-policy-text-form">
                                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="section" value="cancellation">
                                <input type="hidden" name="settings[cancellation.policy_text]" id="cancellation-policy-text-input" value="">
                                <div class="settings-policy-editor-toolbar" role="toolbar" aria-label="Policy text editor toolbar">
                                    <button type="button" data-editor-command="bold">Bold</button>
                                    <button type="button" data-editor-command="italic">Italic</button>
                                    <button type="button" data-editor-command="underline">Underline</button>
                                    <button type="button" data-editor-command="insertUnorderedList">Bullet List</button>
                                    <button type="button" data-editor-command="insertOrderedList">Numbered List</button>
                                </div>
                                <div id="cancellation-policy-text-editor" class="settings-policy-editor-surface" contenteditable="true"><?= (string) ($cancellation['policy_text'] ?? '') ?></div>
                                <div class="settings-establishment-actions">
                                    <button type="submit" class="settings-establishment-btn">Save Policy Text</button>
                                    <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?<?= htmlspecialchars(http_build_query(['section' => 'cancellation'])) ?>">Cancel</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </section>
                    <section class="settings-establishment-card">
                        <h3 class="settings-establishment-card__title">Cancellation Reasons</h3>
                        <p class="settings-establishment-card__help">Required for cancellation: <?= !empty($cancellation['reason_required']) ? 'Yes' : 'No' ?>.</p>
                        <?php if (empty($cancellationReasonStorageReady)): ?>
                            <p class="settings-establishment-card__help">Cancellation reason storage is not available yet. Apply migration 094.</p>
                        <?php elseif ($cancellationReasonsEditOpen): ?>
                            <form method="post" action="/settings" class="settings-form">
                                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="section" value="cancellation">
                                <div class="settings-establishment-summary">
                                    <div class="settings-establishment-summary__row">
                                        <span class="settings-establishment-summary__key">Reason required</span>
                                        <span class="settings-establishment-summary__value"><input type="hidden" name="reason_required" value="0"><label><input type="checkbox" name="reason_required" value="1" <?= !empty($cancellationReasonsOld['reason_required']) ? 'checked' : '' ?>> Required for cancellation</label></span>
                                    </div>
                                </div>
                                <div class="settings-grid">
                                    <?php
                                    $rowsOld = is_array($cancellationReasonsOld['rows'] ?? null) ? $cancellationReasonsOld['rows'] : [];
                                    foreach (($cancellationReasons ?? []) as $reason):
                                        $rid = (int) ($reason['id'] ?? 0);
                                        if ($rid <= 0 || empty($reason['is_active'])) { continue; }
                                        $nameValue = trim((string) ($rowsOld[(string) $rid]['name'] ?? $reason['name'] ?? ''));
                                    ?>
                                        <div class="setting-row">
                                            <label><?= htmlspecialchars((string) ($reason['code'] ?? 'reason')) ?>
                                                <input type="text" name="reason_rows[<?= $rid ?>][name]" value="<?= htmlspecialchars($nameValue) ?>">
                                            </label>
                                            <label><input type="hidden" name="reason_rows[<?= $rid ?>][remove]" value="0"><input type="checkbox" name="reason_rows[<?= $rid ?>][remove]" value="1" <?= !empty($rowsOld[(string) $rid]['remove']) ? 'checked' : '' ?>> Remove</label>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="setting-row">
                                        <label for="new_reason_name">Add reason</label>
                                        <input id="new_reason_name" type="text" name="new_reason_name" value="<?= htmlspecialchars((string) ($cancellationReasonsOld['new_reason_name'] ?? '')) ?>" placeholder="Reason name">
                                        <button type="submit" name="cancellation_reasons_action" value="editor_add">Add</button>
                                    </div>
                                </div>
                                <div class="settings-establishment-actions">
                                    <button type="submit" name="cancellation_reasons_action" value="editor_save" class="settings-establishment-btn">Save Cancellation Reasons</button>
                                    <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?<?= htmlspecialchars(http_build_query(['section' => 'cancellation'])) ?>">Cancel</a>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="settings-establishment-summary">
                                <?php foreach (($cancellationReasons ?? []) as $reason): ?>
                                    <?php if (empty($reason['is_active'])) { continue; } ?>
                                    <div class="settings-establishment-summary__row">
                                        <span class="settings-establishment-summary__key"><?= htmlspecialchars((string) ($reason['name'] ?? '')) ?></span>
                                        <span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($reason['code'] ?? '')) ?> (<?= htmlspecialchars((string) ($reason['applies_to'] ?? 'cancellation')) ?>)</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="settings-establishment-actions">
                                <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?<?= htmlspecialchars(http_build_query(['section' => 'cancellation', 'cancellation_reasons_mode' => 'edit'])) ?>">Edit Cancellation Reasons</a>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
                <?php else: ?>
                <div class="settings-establishment-grid">
                    <section class="settings-establishment-card settings-establishment-card--full">
            <form method="post" action="/settings" class="settings-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="cancellation">
                    <h3 class="settings-establishment-card__title">Edit Cancellation Policy</h3>
                    <p class="settings-card__lead">Tenant-global cancellation policy. Fee fields are configuration only in this phase (no automatic charging).</p>
                    <h3>Rules</h3>
                    <div class="settings-grid">
                        <div class="setting-row"><input type="hidden" name="settings[cancellation.enabled]" value="0"><label><input type="checkbox" name="settings[cancellation.enabled]" value="1" <?= !empty($cancellation['enabled']) ? 'checked' : '' ?>> Allow cancellations</label></div>
                        <div class="setting-row"><label for="cancellation-customer_scope">Customer scope</label><select id="cancellation-customer_scope" name="settings[cancellation.customer_scope]"><option value="all" <?= ($cancellation['customer_scope'] ?? 'all') === 'all' ? 'selected' : '' ?>>All customers</option><option value="new_only" <?= ($cancellation['customer_scope'] ?? '') === 'new_only' ? 'selected' : '' ?>>New customers only</option><option value="existing_only" <?= ($cancellation['customer_scope'] ?? '') === 'existing_only' ? 'selected' : '' ?>>Existing customers only</option></select><p class="setting-help">Saved for future operational enforcement.</p></div>
                        <div class="setting-row"><label for="cancellation-min_notice_hours">Minimum notice required (hours)</label><input type="number" id="cancellation-min_notice_hours" name="settings[cancellation.min_notice_hours]" min="0" value="<?= (int) ($cancellation['min_notice_hours'] ?? 0) ?>"></div>
                        <div class="setting-row"><label for="cancellation-fee_mode">Cancellation fee</label><select id="cancellation-fee_mode" name="settings[cancellation.fee_mode]"><option value="none" <?= ($cancellation['fee_mode'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option><option value="full" <?= ($cancellation['fee_mode'] ?? '') === 'full' ? 'selected' : '' ?>>Full</option><option value="fixed" <?= ($cancellation['fee_mode'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option><option value="percent" <?= ($cancellation['fee_mode'] ?? '') === 'percent' ? 'selected' : '' ?>>Percent</option></select></div>
                        <div class="setting-row"><label for="cancellation-fee_fixed_amount">Cancellation fixed amount</label><input type="number" id="cancellation-fee_fixed_amount" name="settings[cancellation.fee_fixed_amount]" min="0" step="0.01" value="<?= htmlspecialchars((string) ($cancellation['fee_fixed_amount'] ?? 0)) ?>"></div>
                        <div class="setting-row"><label for="cancellation-fee_percent">Cancellation percent</label><input type="number" id="cancellation-fee_percent" name="settings[cancellation.fee_percent]" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($cancellation['fee_percent'] ?? 0)) ?>"></div>
                        <div class="setting-row"><label for="cancellation-staff_payout_mode">Staff contribution from cancellation fee</label><select id="cancellation-staff_payout_mode" name="settings[cancellation.staff_payout_mode]"><option value="none" <?= ($cancellation['staff_payout_mode'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option><option value="full" <?= ($cancellation['staff_payout_mode'] ?? '') === 'full' ? 'selected' : '' ?>>Full</option><option value="percent" <?= ($cancellation['staff_payout_mode'] ?? '') === 'percent' ? 'selected' : '' ?>>Percent</option></select></div>
                        <div class="setting-row"><label for="cancellation-staff_payout_percent">Staff payout percent</label><input type="number" id="cancellation-staff_payout_percent" name="settings[cancellation.staff_payout_percent]" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($cancellation['staff_payout_percent'] ?? 0)) ?>"></div>
                        <div class="setting-row"><input type="hidden" name="settings[cancellation.no_show_same_as_cancellation]" value="0"><label><input type="checkbox" name="settings[cancellation.no_show_same_as_cancellation]" value="1" <?= !empty($cancellation['no_show_same_as_cancellation']) ? 'checked' : '' ?>> No-show fee same as cancellation (display config)</label></div>
                        <div class="setting-row"><label for="cancellation-no_show_fee_mode">No-show fee</label><select id="cancellation-no_show_fee_mode" name="settings[cancellation.no_show_fee_mode]"><option value="none" <?= ($cancellation['no_show_fee_mode'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option><option value="full" <?= ($cancellation['no_show_fee_mode'] ?? '') === 'full' ? 'selected' : '' ?>>Full</option><option value="fixed" <?= ($cancellation['no_show_fee_mode'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option><option value="percent" <?= ($cancellation['no_show_fee_mode'] ?? '') === 'percent' ? 'selected' : '' ?>>Percent</option></select></div>
                        <div class="setting-row"><label for="cancellation-no_show_fee_fixed_amount">No-show fixed amount</label><input type="number" id="cancellation-no_show_fee_fixed_amount" name="settings[cancellation.no_show_fee_fixed_amount]" min="0" step="0.01" value="<?= htmlspecialchars((string) ($cancellation['no_show_fee_fixed_amount'] ?? 0)) ?>"></div>
                        <div class="setting-row"><label for="cancellation-no_show_fee_percent">No-show percent</label><input type="number" id="cancellation-no_show_fee_percent" name="settings[cancellation.no_show_fee_percent]" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($cancellation['no_show_fee_percent'] ?? 0)) ?>"></div>
                        <div class="setting-row"><label for="cancellation-no_show_staff_payout_mode">Staff contribution from no-show fee</label><select id="cancellation-no_show_staff_payout_mode" name="settings[cancellation.no_show_staff_payout_mode]"><option value="none" <?= ($cancellation['no_show_staff_payout_mode'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option><option value="full" <?= ($cancellation['no_show_staff_payout_mode'] ?? '') === 'full' ? 'selected' : '' ?>>Full</option><option value="percent" <?= ($cancellation['no_show_staff_payout_mode'] ?? '') === 'percent' ? 'selected' : '' ?>>Percent</option></select></div>
                        <div class="setting-row"><label for="cancellation-no_show_staff_payout_percent">No-show staff payout percent</label><input type="number" id="cancellation-no_show_staff_payout_percent" name="settings[cancellation.no_show_staff_payout_percent]" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($cancellation['no_show_staff_payout_percent'] ?? 0)) ?>"></div>
                        <div class="setting-row"><input type="hidden" name="settings[cancellation.course_same_as_cancellation]" value="0"><label><input type="checkbox" name="settings[cancellation.course_same_as_cancellation]" value="1" <?= !empty($cancellation['course_same_as_cancellation']) ? 'checked' : '' ?>> Course cancellation fee same as cancellation (display config)</label></div>
                        <div class="setting-row"><label for="cancellation-course_fee_mode">Course cancellation fee</label><select id="cancellation-course_fee_mode" name="settings[cancellation.course_fee_mode]"><option value="none" <?= ($cancellation['course_fee_mode'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option><option value="full" <?= ($cancellation['course_fee_mode'] ?? '') === 'full' ? 'selected' : '' ?>>Full</option><option value="fixed" <?= ($cancellation['course_fee_mode'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option><option value="percent" <?= ($cancellation['course_fee_mode'] ?? '') === 'percent' ? 'selected' : '' ?>>Percent</option></select></div>
                        <div class="setting-row"><label for="cancellation-course_fee_fixed_amount">Course fixed amount</label><input type="number" id="cancellation-course_fee_fixed_amount" name="settings[cancellation.course_fee_fixed_amount]" min="0" step="0.01" value="<?= htmlspecialchars((string) ($cancellation['course_fee_fixed_amount'] ?? 0)) ?>"></div>
                        <div class="setting-row"><label for="cancellation-course_fee_percent">Course percent</label><input type="number" id="cancellation-course_fee_percent" name="settings[cancellation.course_fee_percent]" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($cancellation['course_fee_percent'] ?? 0)) ?>"></div>
                        <div class="setting-row"><input type="hidden" name="settings[cancellation.reasons_enabled]" value="0"><label><input type="checkbox" name="settings[cancellation.reasons_enabled]" value="1" <?= !empty($cancellation['reasons_enabled']) ? 'checked' : '' ?>> Activate cancellation reasons</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[cancellation.reason_required]" value="0"><label><input type="checkbox" name="settings[cancellation.reason_required]" value="1" <?= !empty($cancellation['reason_required']) ? 'checked' : '' ?>> Require reason for cancellation when reasons are active</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[cancellation.tax_enabled]" value="0"><label><input type="checkbox" name="settings[cancellation.tax_enabled]" value="1" <?= !empty($cancellation['tax_enabled']) ? 'checked' : '' ?>> Enable cancellation tax</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[cancellation.allow_privileged_override]" value="0"><label><input type="checkbox" name="settings[cancellation.allow_privileged_override]" value="1" <?= !empty($cancellation['allow_privileged_override']) ? 'checked' : '' ?>> Allow privileged override</label></div>
                    </div>
                    <h3>Policy Text</h3>
                    <div class="settings-grid">
                        <div class="setting-row"><label for="cancellation-policy_text">Policy text (English)</label><textarea id="cancellation-policy_text" name="settings[cancellation.policy_text]" rows="6"><?= htmlspecialchars((string) ($cancellation['policy_text'] ?? '')) ?></textarea></div>
                    </div>
                    <div class="settings-establishment-actions">
                        <button type="submit" class="settings-establishment-btn">Save Cancellation Policy</button>
                        <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?<?= htmlspecialchars(http_build_query(['section' => 'cancellation'])) ?>">Cancel</a>
                    </div>
            </form>
                    </section>
                    <section class="settings-establishment-card">
                        <h3 class="settings-establishment-card__title">Cancellation Reasons</h3>
                        <p class="settings-establishment-card__help">Manage reasons from read mode using the Edit Cancellation Reasons action.</p>
                    </section>
                </div>
                <?php endif; ?>
            </section>
            <?php elseif ($activeSection === 'appointments'): ?>
            <section class="settings-card settings-card--appt-scope">
                <h2>Booking rules — scope</h2>
                <p class="settings-card__lead">Pick org default or a branch. Branch rows override organization default; this page only stores policy values.</p>
                <form method="get" action="/settings" class="settings-branch-form">
                    <input type="hidden" name="section" value="appointments">
                    <label for="appointments_branch_id">Scope</label>
                    <select id="appointments_branch_id" name="appointments_branch_id">
                        <option value="0" <?= $appointmentsBranchId === 0 ? 'selected' : '' ?>>Organization default (all branches)</option>
                        <?php foreach ($branches as $b): $abid = (int) ($b['id'] ?? 0); ?>
                        <option value="<?= $abid ?>" <?= $appointmentsBranchId === $abid ? 'selected' : '' ?>><?= htmlspecialchars((string) ($b['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>
                <p class="settings-card__hint"><?= $appointmentsBranchId > 0 ? 'Saving to ' . htmlspecialchars($appointmentsBranchName !== '' ? $appointmentsBranchName : ('branch #' . (string) $appointmentsBranchId)) . '.' : 'Saving to organization default. Read-side branch behavior in calendar/client surfaces is unchanged.' ?></p>
            </section>
            <form method="post" action="/settings" class="settings-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="appointments">
                <input type="hidden" name="appointments_context_branch_id" value="<?= (int) $appointmentsBranchId ?>">
                <section class="settings-card">
                    <h2>Booking rules</h2>
                    <p class="settings-card__lead">Defaults for internal booking behavior and calendar readout at the selected scope. Scheduling work stays in Calendar.</p>

                    <h3>Operational rules</h3>
                    <p class="settings-card__help">These rules affect internal booking behavior and operational checks.</p>

                    <div class="settings-appt-section">
                        <h3>Scheduling</h3>
                        <p class="settings-card__help">Booking window, slot search, room conflicts, and internal staff overlap.</p>
                        <div class="settings-grid">
                            <div class="setting-row"><label for="appointments-min_lead_minutes">Min lead (minutes)</label><input type="number" id="appointments-min_lead_minutes" name="settings[appointments.min_lead_minutes]" min="0" value="<?= (int) ($appointment['min_lead_minutes'] ?? 0) ?>"></div>
                            <div class="setting-row"><label for="appointments-max_days_ahead">Max days ahead</label><input type="number" id="appointments-max_days_ahead" name="settings[appointments.max_days_ahead]" min="1" value="<?= (int) ($appointment['max_days_ahead'] ?? 180) ?>"></div>
                            <div class="setting-row"><input type="hidden" name="settings[appointments.allow_past_booking]" value="0"><label><input type="checkbox" name="settings[appointments.allow_past_booking]" value="1" <?= !empty($appointment['allow_past_booking']) ? 'checked' : '' ?>> Allow past-date booking</label></div>
                            <div class="setting-row"><input type="hidden" name="settings[appointments.allow_end_after_closing]" value="0"><label><input type="checkbox" name="settings[appointments.allow_end_after_closing]" value="1" <?= !empty($appointment['allow_end_after_closing']) ? 'checked' : '' ?>> Allow end time after closing</label></div>
                            <div class="setting-row"><input type="hidden" name="settings[appointments.check_staff_availability_in_search]" value="0"><label><input type="checkbox" name="settings[appointments.check_staff_availability_in_search]" value="1" <?= (($appointment['check_staff_availability_in_search'] ?? true) ? 'checked' : '') ?>> Enforce staff schedule in slot search</label></div>
                            <div class="setting-row">
                                <input type="hidden" name="settings[appointments.allow_room_overbooking]" value="0">
                                <label><input type="checkbox" name="settings[appointments.allow_room_overbooking]" value="1" <?= !empty($appointment['allow_room_overbooking']) ? 'checked' : '' ?>> Allow room overbooking</label>
                                <p class="setting-help">Rooms only. Off (default): internal booking blocks overlapping room use. Not other resource types.</p>
                            </div>
                            <div class="setting-row"><input type="hidden" name="settings[appointments.allow_staff_concurrency]" value="0"><label><input type="checkbox" name="settings[appointments.allow_staff_concurrency]" value="1" <?= !empty($appointment['allow_staff_concurrency']) ? 'checked' : '' ?>> Allow overlapping appointments (same staff)</label></div>
                        </div>
                        <p class="settings-card__help settings-grid-span">Internal only for overlap toggle. Public booking overlap rules remain unchanged.</p>
                    </div>

                    <div class="settings-appt-section">
                        <h3>Alerts</h3>
                        <p class="settings-card__help">Operational no-show warning threshold for staff workflows.</p>
                        <div class="settings-grid">
                            <div class="setting-row"><input type="hidden" name="settings[appointments.no_show_alert_enabled]" value="0"><label><input type="checkbox" name="settings[appointments.no_show_alert_enabled]" value="1" <?= !empty($appointment['no_show_alert_enabled']) ? 'checked' : '' ?>> No-show client alert</label></div>
                            <div class="setting-row"><label for="appointments-no_show_alert_threshold">Alert from this no-show count</label><input type="number" id="appointments-no_show_alert_threshold" name="settings[appointments.no_show_alert_threshold]" min="1" max="99" value="<?= (int) ($appointment['no_show_alert_threshold'] ?? 1) ?>"></div>
                        </div>
                    </div>

                    <div class="settings-appt-section">
                        <h3>Status</h3>
                        <p class="settings-card__help">No global check-in toggle here. Check-in is per appointment and does not change status logic.</p>
                    </div>

                    <div class="settings-appt-section">
                        <h3>Staff</h3>
                        <div class="settings-grid">
                            <div class="setting-row"><input type="hidden" name="settings[appointments.allow_staff_booking_on_off_days]" value="0"><label><input type="checkbox" name="settings[appointments.allow_staff_booking_on_off_days]" value="1" <?= !empty($appointment['allow_staff_booking_on_off_days']) ? 'checked' : '' ?>> Book on staff off days (internal)</label></div>
                        </div>
                        <p class="settings-card__help">Internal booking and search only. Public booking still respects off days.</p>
                        <p class="settings-card__help settings-card__help--muted">Staff locked to a room/space: not available yet.</p>
                    </div>

                    <h3>Display and print readout</h3>
                    <p class="settings-card__help">These settings change labels and visibility in client-facing/readout surfaces, not booking eligibility.</p>

                    <div class="settings-appt-section">
                        <h3>Display</h3>
                        <p class="settings-card__help">Client itinerary visibility and calendar pre-book label rules.</p>
                        <div class="settings-grid">
                            <div class="setting-row"><input type="hidden" name="settings[appointments.client_itinerary_show_staff]" value="0"><label><input type="checkbox" name="settings[appointments.client_itinerary_show_staff]" value="1" <?= (($appointment['client_itinerary_show_staff'] ?? true) ? 'checked' : '') ?>> Itinerary: show staff</label></div>
                            <div class="setting-row"><input type="hidden" name="settings[appointments.client_itinerary_show_space]" value="0"><label><input type="checkbox" name="settings[appointments.client_itinerary_show_space]" value="1" <?= !empty($appointment['client_itinerary_show_space']) ? 'checked' : '' ?>> Itinerary: show space</label></div>
                            <div class="setting-row"><input type="hidden" name="settings[appointments.prebook_display_enabled]" value="0"><label><input type="checkbox" name="settings[appointments.prebook_display_enabled]" value="1" <?= !empty($appointment['prebook_display_enabled']) ? 'checked' : '' ?>> Show pre-booked on calendar</label></div>
                            <div class="setting-row setting-row--prebook">
                                <span class="setting-row__inline-label">Pre-booked if created within</span>
                                <input type="number" id="appointments-prebook_threshold_value" name="settings[appointments.prebook_threshold_value]" min="1" max="9999" value="<?= (int) ($appointment['prebook_threshold_value'] ?? 2) ?>" aria-label="Pre-book threshold amount">
                                <select id="appointments-prebook_threshold_unit" name="settings[appointments.prebook_threshold_unit]" aria-label="Hours or minutes">
                                    <option value="hours" <?= (($appointment['prebook_threshold_unit'] ?? 'hours') === 'hours') ? 'selected' : '' ?>>Hours</option>
                                    <option value="minutes" <?= (($appointment['prebook_threshold_unit'] ?? '') === 'minutes') ? 'selected' : '' ?>>Minutes</option>
                                </select>
                                <span class="setting-row__inline-suffix">of start.</span>
                            </div>
                        </div>
                    </div>

                    <div class="settings-appt-section">
                        <h3>Service appointments</h3>
                        <p class="settings-card__help">Day calendar labels for one-off appointments (not series).</p>
                        <div class="settings-grid">
                            <div class="setting-row"><input type="hidden" name="settings[appointments.calendar_service_show_start_time]" value="0"><label><input type="checkbox" name="settings[appointments.calendar_service_show_start_time]" value="1" <?= !empty($appointment['calendar_service_show_start_time']) ? 'checked' : '' ?>> Show start time</label></div>
                            <div class="setting-row"><label for="appointments-calendar_service_label_mode">Label mode</label><select id="appointments-calendar_service_label_mode" name="settings[appointments.calendar_service_label_mode]"><option value="client_and_service" <?= (($appointment['calendar_service_label_mode'] ?? 'client_and_service') === 'client_and_service') ? 'selected' : '' ?>>Client name and service</option><option value="service_and_client" <?= (($appointment['calendar_service_label_mode'] ?? '') === 'service_and_client') ? 'selected' : '' ?>>Service and client name</option><option value="service_only" <?= (($appointment['calendar_service_label_mode'] ?? '') === 'service_only') ? 'selected' : '' ?>>Service only</option><option value="client_only" <?= (($appointment['calendar_service_label_mode'] ?? '') === 'client_only') ? 'selected' : '' ?>>Client name only</option></select></div>
                        </div>
                    </div>

                    <div class="settings-appt-section">
                        <h3>Series-linked appointments</h3>
                        <p class="settings-card__help">Rows with <code>series_id</code>. Separate label/show-time rules from one-offs above.</p>
                        <div class="settings-grid">
                            <div class="setting-row"><input type="hidden" name="settings[appointments.calendar_series_show_start_time]" value="0"><label><input type="checkbox" name="settings[appointments.calendar_series_show_start_time]" value="1" <?= !empty($appointment['calendar_series_show_start_time']) ? 'checked' : '' ?>> Show start time</label></div>
                            <div class="setting-row"><label for="appointments-calendar_series_label_mode">Label mode</label><select id="appointments-calendar_series_label_mode" name="settings[appointments.calendar_series_label_mode]"><option value="client_and_service" <?= (($appointment['calendar_series_label_mode'] ?? 'client_and_service') === 'client_and_service') ? 'selected' : '' ?>>Client name and service</option><option value="service_and_client" <?= (($appointment['calendar_series_label_mode'] ?? '') === 'service_and_client') ? 'selected' : '' ?>>Service and client name</option><option value="service_only" <?= (($appointment['calendar_series_label_mode'] ?? '') === 'service_only') ? 'selected' : '' ?>>Service only</option><option value="client_only" <?= (($appointment['calendar_series_label_mode'] ?? '') === 'client_only') ? 'selected' : '' ?>>Client name only</option></select></div>
                        </div>
                    </div>

                    <div class="settings-appt-section">
                        <h3>Appointment print summary</h3>
                        <p class="settings-card__help">Print view only. Toggles hide optional sections in the summary output.</p>
                        <div class="settings-grid">
                            <div class="setting-row"><input type="hidden" name="settings[appointments.print_show_staff_appointment_list]" value="0"><label><input type="checkbox" name="settings[appointments.print_show_staff_appointment_list]" value="1" <?= (($appointment['print_show_staff_appointment_list'] ?? true) ? 'checked' : '') ?>> Staff day schedule</label></div>
                            <div class="setting-row"><input type="hidden" name="settings[appointments.print_show_client_service_history]" value="0"><label><input type="checkbox" name="settings[appointments.print_show_client_service_history]" value="1" <?= (($appointment['print_show_client_service_history'] ?? true) ? 'checked' : '') ?>> Recent client appointments</label></div>
                            <div class="setting-row"><input type="hidden" name="settings[appointments.print_show_client_product_purchase_history]" value="0"><label><input type="checkbox" name="settings[appointments.print_show_client_product_purchase_history]" value="1" <?= (($appointment['print_show_client_product_purchase_history'] ?? false) ? 'checked' : '') ?>> Product lines (invoices)</label></div>
                            <div class="setting-row"><input type="hidden" name="settings[appointments.print_show_package_detail]" value="0"><label><input type="checkbox" name="settings[appointments.print_show_package_detail]" value="1" <?= (($appointment['print_show_package_detail'] ?? true) ? 'checked' : '') ?>> Packages (visit + recent)</label></div>
                        </div>
                    </div>
                </section>
                <div class="settings-savebar"><button type="submit">Save booking rules</button></div>
            </form>
            <?php elseif ($activeSection === 'payments'): ?>
            <?php
            require base_path('modules/settings/views/partials/payment-settings.php');
            ?>
            <?php elseif ($activeSection === 'waitlist'): ?>
            <section class="settings-card settings-card--appt-scope">
                <h2>Waitlist rules — scope</h2>
                <p class="settings-card__lead">Pick org default or a branch. Branch values override organization default; queue operations stay in Calendar.</p>
                <form method="get" action="/settings" class="settings-branch-form">
                    <input type="hidden" name="section" value="waitlist">
                    <label for="waitlist_branch_id">Scope</label>
                    <select id="waitlist_branch_id" name="waitlist_branch_id">
                        <option value="0" <?= $waitlistBranchId === 0 ? 'selected' : '' ?>>Organization default</option>
                        <?php foreach ($branches as $b): $wbid = (int) ($b['id'] ?? 0); ?>
                        <option value="<?= $wbid ?>" <?= $waitlistBranchId === $wbid ? 'selected' : '' ?>><?= htmlspecialchars((string) ($b['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>
                <p class="settings-card__hint"><?= $waitlistBranchId > 0 ? 'Active context: Branch override: ' . htmlspecialchars($waitlistBranchName !== '' ? $waitlistBranchName : ('branch #' . (string) $waitlistBranchId)) . '.' : 'Active context: Organization default.' ?></p>
            </section>
            <form method="post" action="/settings" class="settings-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="waitlist">
                <input type="hidden" name="waitlist_context_branch_id" value="<?= (int) $waitlistBranchId ?>">
                <section class="settings-card">
                    <h2>Waitlist rules</h2>
                    <div class="settings-grid">
                        <div class="setting-row"><input type="hidden" name="settings[waitlist.enabled]" value="0"><label><input type="checkbox" name="settings[waitlist.enabled]" value="1" <?= !empty($waitlist['enabled']) ? 'checked' : '' ?>> Waitlist enabled</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[waitlist.auto_offer_enabled]" value="0"><label><input type="checkbox" name="settings[waitlist.auto_offer_enabled]" value="1" <?= !empty($waitlist['auto_offer_enabled']) ? 'checked' : '' ?>> Auto-offer enabled</label></div>
                        <div class="setting-row"><label for="waitlist-max_active_per_client">Max active entries per client</label><input type="number" id="waitlist-max_active_per_client" name="settings[waitlist.max_active_per_client]" min="1" value="<?= (int) ($waitlist['max_active_per_client'] ?? 3) ?>"></div>
                        <div class="setting-row"><label for="waitlist-default_expiry_minutes">Default expiry (minutes)</label><input type="number" id="waitlist-default_expiry_minutes" name="settings[waitlist.default_expiry_minutes]" min="0" value="<?= (int) ($waitlist['default_expiry_minutes'] ?? 30) ?>"></div>
                    </div>
                </section>
                <div class="settings-savebar"><button type="submit">Save waitlist rules</button></div>
            </form>
            <?php elseif ($activeSection === 'marketing'): ?>
            <section class="settings-card settings-card--appt-scope">
                <h2>Marketing defaults — scope</h2>
                <p class="settings-card__lead">Pick org default or a branch. Branch values override organization default; campaigns stay in Marketing.</p>
                <form method="get" action="/settings" class="settings-branch-form">
                    <input type="hidden" name="section" value="marketing">
                    <label for="marketing_branch_id">Scope</label>
                    <select id="marketing_branch_id" name="marketing_branch_id">
                        <option value="0" <?= $marketingBranchId === 0 ? 'selected' : '' ?>>Organization default</option>
                        <?php foreach ($branches as $b): $mbid = (int) ($b['id'] ?? 0); ?>
                        <option value="<?= $mbid ?>" <?= $marketingBranchId === $mbid ? 'selected' : '' ?>><?= htmlspecialchars((string) ($b['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>
                <p class="settings-card__hint"><?= $marketingBranchId > 0 ? 'Active context: Branch override: ' . htmlspecialchars($marketingBranchName !== '' ? $marketingBranchName : ('branch #' . (string) $marketingBranchId)) . '.' : 'Active context: Organization default.' ?></p>
            </section>
            <form method="post" action="/settings" class="settings-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="marketing">
                <input type="hidden" name="marketing_context_branch_id" value="<?= (int) $marketingBranchId ?>">
                <section class="settings-card">
                    <h2>Marketing defaults</h2>
                    <div class="settings-grid">
                        <div class="setting-row"><input type="hidden" name="settings[marketing.default_opt_in]" value="0"><label><input type="checkbox" name="settings[marketing.default_opt_in]" value="1" <?= !empty($marketing['default_opt_in']) ? 'checked' : '' ?>> Default opt-in for new clients</label></div>
                        <div class="setting-row"><label for="marketing-consent_label">Consent label (client form)</label><input type="text" id="marketing-consent_label" name="settings[marketing.consent_label]" value="<?= htmlspecialchars($marketing['consent_label'] ?? 'Marketing communications') ?>" placeholder="Marketing communications"></div>
                    </div>
                </section>
                <div class="settings-savebar"><button type="submit">Save marketing defaults</button></div>
            </form>
            <?php elseif ($activeSection === 'security'): ?>
            <form method="post" action="/settings" class="settings-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="security">
                <section class="settings-card">
                    <h2>Access &amp; security</h2>
                    <p class="settings-card__help">Organization defaults for staff session and password policy. Runtime still merges branch where the app applies it.</p>
                    <div class="settings-grid">
                        <div class="setting-row"><label for="security-password_expiration">Password expiration</label><select id="security-password_expiration" name="settings[security.password_expiration]"><option value="never" <?= ($security['password_expiration'] ?? 'never') === 'never' ? 'selected' : '' ?>>Never</option><option value="90_days" <?= ($security['password_expiration'] ?? '') === '90_days' ? 'selected' : '' ?>>90 days</option></select></div>
                        <div class="setting-row"><label for="security-inactivity_timeout_minutes">Inactivity timeout (minutes)</label><select id="security-inactivity_timeout_minutes" name="settings[security.inactivity_timeout_minutes]"><option value="15" <?= (int) ($security['inactivity_timeout_minutes'] ?? 30) === 15 ? 'selected' : '' ?>>15</option><option value="30" <?= (int) ($security['inactivity_timeout_minutes'] ?? 30) === 30 ? 'selected' : '' ?>>30</option><option value="120" <?= (int) ($security['inactivity_timeout_minutes'] ?? 30) === 120 ? 'selected' : '' ?>>120</option></select></div>
                    </div>
                </section>
                <div class="settings-savebar"><button type="submit">Save access &amp; security</button></div>
            </form>
            <?php elseif ($activeSection === 'notifications'): ?>
            <form method="post" action="/settings" class="settings-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="notifications">
                <section class="settings-card">
                    <h2>Notifications &amp; automations</h2>
                    <p class="settings-card__help">Organization defaults for which notification families are on. In-app and outbound gates use branch-merged values when the creating code passes a branch.</p>
                    <p class="settings-card__help">In-app: toggles filter staff notifications by type prefix (including <code>payment_</code> when Sales is on). Outbound email: only appointment / waitlist / membership families consult these flags; no payment transactional email queue uses Sales here. Marketing email is separate.</p>
                    <div class="settings-grid">
                        <div class="setting-row"><input type="hidden" name="settings[notifications.appointments_enabled]" value="0"><label><input type="checkbox" name="settings[notifications.appointments_enabled]" value="1" <?= !empty($notification['appointments_enabled']) ? 'checked' : '' ?>> Appointments notifications</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[notifications.sales_enabled]" value="0"><label><input type="checkbox" name="settings[notifications.sales_enabled]" value="1" <?= !empty($notification['sales_enabled']) ? 'checked' : '' ?>> Sales notifications</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[notifications.waitlist_enabled]" value="0"><label><input type="checkbox" name="settings[notifications.waitlist_enabled]" value="1" <?= !empty($notification['waitlist_enabled']) ? 'checked' : '' ?>> Waitlist notifications</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[notifications.memberships_enabled]" value="0"><label><input type="checkbox" name="settings[notifications.memberships_enabled]" value="1" <?= !empty($notification['memberships_enabled']) ? 'checked' : '' ?>> Membership notifications</label></div>
                    </div>
                </section>
                <div class="settings-savebar"><button type="submit">Save notification defaults</button></div>
            </form>
            <?php elseif ($activeSection === 'hardware'): ?>
            <form method="post" action="/settings" class="settings-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="hardware">
                <section class="settings-card">
                    <h2>Devices &amp; integrations</h2>
                    <p class="settings-card__help">Organization defaults for register and receipt hardware. Checkout still runs in Sales; these flags are read at payment/invoice time.</p>
                    <div class="settings-grid">
                        <div class="setting-row"><input type="hidden" name="settings[hardware.use_cash_register]" value="0"><label><input type="checkbox" name="settings[hardware.use_cash_register]" value="1" <?= !empty($hardware['use_cash_register']) ? 'checked' : '' ?>> Use cash register</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[hardware.use_receipt_printer]" value="0"><label><input type="checkbox" name="settings[hardware.use_receipt_printer]" value="1" <?= !empty($hardware['use_receipt_printer']) ? 'checked' : '' ?>> Use receipt printer dispatch</label></div>
                    </div>
                </section>
                <div class="settings-savebar"><button type="submit">Save device defaults</button></div>
            </form>
            <?php elseif ($activeSection === 'memberships'): ?>
            <form method="post" action="/settings" class="settings-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="memberships">
                <section class="settings-card">
                    <h2>Membership defaults</h2>
                    <p class="settings-card__help">Policy text and timing defaults only. Plan definitions live in Catalog; enrolled clients live in Clients.</p>
                    <div class="settings-grid">
                        <div class="setting-row"><label for="memberships-terms_text">Terms and conditions (membership signup)</label><textarea id="memberships-terms_text" name="settings[memberships.terms_text]" rows="4" maxlength="5000"><?= htmlspecialchars($membership['terms_text'] ?? '') ?></textarea></div>
                        <div class="setting-row"><label for="memberships-renewal_reminder_days">Renewal reminder (days before expiry)</label><input type="number" id="memberships-renewal_reminder_days" name="settings[memberships.renewal_reminder_days]" min="0" value="<?= (int) ($membership['renewal_reminder_days'] ?? 7) ?>"></div>
                        <div class="setting-row"><label for="memberships-grace_period_days">Grace period (days after expiry)</label><input type="number" id="memberships-grace_period_days" name="settings[memberships.grace_period_days]" min="0" value="<?= (int) ($membership['grace_period_days'] ?? 0) ?>"></div>
                    </div>
                </section>
                <div class="settings-savebar"><button type="submit">Save membership defaults</button></div>
            </form>
            <?php else: ?>
            <section class="settings-card">
                <h2>Online channels</h2>
                <p class="settings-card__lead">Public visibility and access policy. The branch selector applies to all three areas — choose a branch to view and save overrides, or stay on organisation default.</p>
                <form method="get" action="/settings" class="settings-branch-form">
                    <input type="hidden" name="section" value="public_channels">
                    <label for="online_booking_branch_id">Branch context</label>
                    <select id="online_booking_branch_id" name="online_booking_branch_id">
                        <option value="0" <?= $onlineBookingBranchId === 0 ? 'selected' : '' ?>>Organisation default (all branches)</option>
                        <?php foreach ($branches as $b): $bid = (int) ($b['id'] ?? 0); ?>
                        <option value="<?= $bid ?>" <?= $onlineBookingBranchId === $bid ? 'selected' : '' ?>><?= htmlspecialchars((string) ($b['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>
                <p class="settings-card__hint">
                    <?= $onlineBookingBranchId > 0
                        ? 'Showing settings for: <strong>' . htmlspecialchars($selectedBranchName !== '' ? $selectedBranchName : ('#' . (string) $onlineBookingBranchId)) . '</strong>. Changes save to this branch row.'
                        : 'Showing organisation default. Changes apply to all branches unless overridden at branch level.' ?>
                </p>
            </section>
            <form method="post" action="/settings" class="settings-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="public_channels">
                <input type="hidden" name="online_booking_context_branch_id" value="<?= $onlineBookingBranchId ?>">

                <section class="settings-card settings-online-channel-card">
                    <h3 class="settings-online-channel-card__title">Online Booking</h3>
                    <p class="settings-card__lead">Controls whether clients can book appointments through your public booking page. Rules here apply to the branch context selected above.</p>
                    <div class="settings-grid">
                        <div class="setting-row"><input type="hidden" name="settings[online_booking.enabled]" value="0"><label><input type="checkbox" name="settings[online_booking.enabled]" value="1" <?= !empty($onlineBooking['enabled']) ? 'checked' : '' ?>> Online booking enabled</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[online_booking.public_api_enabled]" value="0"><label><input type="checkbox" name="settings[online_booking.public_api_enabled]" value="1" <?= !empty($onlineBooking['public_api_enabled']) ? 'checked' : '' ?>> Allow anonymous booking via public API</label></div>
                        <div class="setting-row"><label for="online_booking-min_lead_minutes">Minimum booking lead time (minutes)</label><input type="number" id="online_booking-min_lead_minutes" name="settings[online_booking.min_lead_minutes]" min="0" value="<?= (int) ($onlineBooking['min_lead_minutes'] ?? 120) ?>"></div>
                        <div class="setting-row"><label for="online_booking-max_days_ahead">Maximum days ahead clients can book</label><input type="number" id="online_booking-max_days_ahead" name="settings[online_booking.max_days_ahead]" min="1" value="<?= (int) ($onlineBooking['max_days_ahead'] ?? 60) ?>"></div>
                        <div class="setting-row"><input type="hidden" name="settings[online_booking.allow_new_clients]" value="0"><label><input type="checkbox" name="settings[online_booking.allow_new_clients]" value="1" <?= !empty($onlineBooking['allow_new_clients']) ? 'checked' : '' ?>> Allow new clients to book online</label></div>
                    </div>
                </section>

                <section class="settings-card settings-online-channel-card">
                    <h3 class="settings-online-channel-card__title">Public Intake</h3>
                    <p class="settings-card__lead">Controls whether clients can submit intake forms through a public token URL. Uses the branch context selected above.</p>
                    <div class="settings-grid">
                        <div class="setting-row"><input type="hidden" name="settings[intake.public_enabled]" value="0"><label><input type="checkbox" name="settings[intake.public_enabled]" value="1" <?= !empty($intake['public_enabled']) ? 'checked' : '' ?>> Public intake form URLs enabled</label></div>
                    </div>
                </section>

                <section class="settings-card settings-online-channel-card">
                    <h3 class="settings-online-channel-card__title">Public Commerce</h3>
                    <p class="settings-card__lead">Policy for whether anonymous online purchases are allowed (gift cards, packages, memberships). Staff fulfillment and ledgers stay in Sales. Uses the branch context selected above.</p>
                    <div class="settings-grid">
                        <div class="setting-row"><input type="hidden" name="settings[public_commerce.enabled]" value="0"><label><input type="checkbox" name="settings[public_commerce.enabled]" value="1" <?= !empty($publicCommerce['enabled']) ? 'checked' : '' ?>> Public purchases enabled</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[public_commerce.public_api_enabled]" value="0"><label><input type="checkbox" name="settings[public_commerce.public_api_enabled]" value="1" <?= !empty($publicCommerce['public_api_enabled']) ? 'checked' : '' ?>> Allow anonymous purchases via public API</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[public_commerce.allow_gift_cards]" value="0"><label><input type="checkbox" name="settings[public_commerce.allow_gift_cards]" value="1" <?= !empty($publicCommerce['allow_gift_cards']) ? 'checked' : '' ?>> Allow gift card purchases</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[public_commerce.allow_packages]" value="0"><label><input type="checkbox" name="settings[public_commerce.allow_packages]" value="1" <?= !empty($publicCommerce['allow_packages']) ? 'checked' : '' ?>> Allow package purchases</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[public_commerce.allow_memberships]" value="0"><label><input type="checkbox" name="settings[public_commerce.allow_memberships]" value="1" <?= !empty($publicCommerce['allow_memberships']) ? 'checked' : '' ?>> Allow membership purchases</label></div>
                        <div class="setting-row"><input type="hidden" name="settings[public_commerce.allow_new_clients]" value="0"><label><input type="checkbox" name="settings[public_commerce.allow_new_clients]" value="1" <?= !empty($publicCommerce['allow_new_clients']) ? 'checked' : '' ?>> Allow new clients to purchase</label></div>
                        <div class="setting-row"><label for="public_commerce-gift_card_min_amount">Gift card minimum amount</label><input type="number" id="public_commerce-gift_card_min_amount" name="settings[public_commerce.gift_card_min_amount]" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($publicCommerce['gift_card_min_amount'] ?? 25)) ?>"></div>
                        <div class="setting-row"><label for="public_commerce-gift_card_max_amount">Gift card maximum amount</label><input type="number" id="public_commerce-gift_card_max_amount" name="settings[public_commerce.gift_card_max_amount]" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($publicCommerce['gift_card_max_amount'] ?? 500)) ?>"></div>
                    </div>
                </section>

                <div class="settings-savebar"><button type="submit">Save online channel defaults</button></div>
            </form>
<?php endif; ?>
<?php if ($activeSection === 'cancellation'): ?>
<style>
    .settings-policy-rich-content {
        min-height: 5rem;
        padding: 0.85rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.65rem;
        background: #fff;
        line-height: 1.5;
        color: #111827;
    }
    .settings-policy-rich-content p { margin: 0 0 0.65rem; }
    .settings-policy-rich-content p:last-child { margin-bottom: 0; }
    .settings-policy-rich-content ul,
    .settings-policy-rich-content ol { margin: 0.4rem 0 0.7rem 1.2rem; }
    .settings-policy-editor-toolbar {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
        margin-bottom: 0.55rem;
    }
    .settings-policy-editor-toolbar button {
        border: 1px solid #d1d5db;
        background: #f9fafb;
        color: #111827;
        border-radius: 0.55rem;
        padding: 0.35rem 0.65rem;
        font-size: 0.85rem;
        cursor: pointer;
    }
    .settings-policy-editor-surface {
        min-height: 12rem;
        border: 1px solid #d1d5db;
        border-radius: 0.65rem;
        background: #fff;
        padding: 0.8rem;
        line-height: 1.5;
        margin-bottom: 0.8rem;
    }
</style>
<?php if ($cancellationPolicyTextEditOpen): ?>
<script>
    (function () {
        var editor = document.getElementById('cancellation-policy-text-editor');
        var form = document.getElementById('cancellation-policy-text-form');
        var input = document.getElementById('cancellation-policy-text-input');
        if (!editor || !form || !input) {
            return;
        }
        var toolbar = form.querySelector('.settings-policy-editor-toolbar');
        if (toolbar) {
            toolbar.addEventListener('click', function (event) {
                var button = event.target.closest('button[data-editor-command]');
                if (!button) {
                    return;
                }
                event.preventDefault();
                var command = button.getAttribute('data-editor-command');
                if (command) {
                    document.execCommand(command, false, null);
                    editor.focus();
                }
            });
        }
        form.addEventListener('submit', function () {
            input.value = editor.innerHTML;
        });
    }());
</script>
<?php endif; ?>
<?php endif; ?>
<?php
$settingsWorkspaceContent = (string) ob_get_clean();
$settingsPageTitle = 'Admin';
$settingsPageSubtitle = 'Organization policies, defaults, and controls. Use the sidebar to move between sections; operational workspaces stay in the main navigation.';
$settingsFlash = $flash ?? null;
ob_start();
require base_path('modules/settings/views/partials/shell.php');
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
