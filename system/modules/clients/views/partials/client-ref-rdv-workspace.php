<?php
/** @var int $clientId */
/** @var array<string, mixed> $client */
/** @var array<string, mixed> $appointmentSummary */
/** @var array{status: ?string, date_mode: string, date_from: ?string, date_to: ?string, page: int, per_page: int} $resumeApptFilters */
/** @var array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} $resumeApptList */
/** @var array<string, string> $resumeApptStatusLabels */
/** @var int $resumeApptTotalPages */
/** @var array<string, string|int> $resumeApptLinkQuery */
/** @var bool $clientItineraryShowStaff */
/** @var bool $clientItineraryShowSpace */
/** @var string|null $resumeAddAppointmentUrl */
/** @var string|null $clientRefRdvBasePath Optional GET target for filters/pagination; default /clients/{id} */
/** @var bool $clientRefDedicatedAppointments Full-page appointments workspace layout */

$fmtRdvDt = static function ($raw): string {
    if ($raw === null || $raw === '') {
        return '—';
    }
    $t = strtotime((string) $raw);

    return $t ? date('Y-m-d H:i', $t) : (string) $raw;
};

$rdvDedicated = !empty($clientRefDedicatedAppointments);
$rdvAddUrl = isset($resumeAddAppointmentUrl) && $resumeAddAppointmentUrl !== '' ? $resumeAddAppointmentUrl : null;

$rdvBasePath = (isset($clientRefRdvBasePath) && is_string($clientRefRdvBasePath) && $clientRefRdvBasePath !== '')
    ? $clientRefRdvBasePath
    : '/clients/' . $clientId;
$buildRdvUrl = static function (string $base, array $linkQuery, array $extra): string {
    $q = array_merge($linkQuery, $extra);
    $qs = http_build_query($q);

    return $qs !== '' ? $base . '?' . $qs . '#client-ref-rdv' : $base . '#client-ref-rdv';
};

$items = $resumeApptList['items'] ?? [];
$total = (int) ($resumeApptList['total'] ?? 0);
$page = (int) ($resumeApptList['page'] ?? 1);
$perPage = (int) ($resumeApptList['per_page'] ?? 15);
$hasFilters = ($resumeApptFilters['status'] ?? null) !== null
    || (($resumeApptFilters['date_from'] ?? null) !== null && $resumeApptFilters['date_from'] !== '')
    || (($resumeApptFilters['date_to'] ?? null) !== null && $resumeApptFilters['date_to'] !== '');
?>
            <section class="client-ref-rdv<?= $rdvDedicated ? ' client-ref-rdv--dedicated-page' : '' ?>" id="client-ref-rdv" aria-labelledby="client-ref-appts-heading">
                <?php if ($rdvDedicated): ?>
                <div class="client-ref-appts-workspace">
                <?php endif; ?>

                <header class="client-ref-rdv__head<?= $rdvDedicated ? ' client-ref-rdv__head--page-toolbar' : '' ?>">
                    <div class="client-ref-rdv__head-text">
                        <h2 id="client-ref-appts-heading" class="client-ref-rdv__title">Appointments</h2>
                        <?php if ($rdvDedicated): ?>
                        <p class="client-ref-rdv__lede client-ref-rdv__lede--page">View and filter this client&rsquo;s appointments. Use the list below to open a booking.</p>
                        <?php else: ?>
                        <p class="client-ref-rdv__lede">Appointment history for this client, with filters and pagination.</p>
                        <?php endif; ?>
                    </div>
                    <div class="client-ref-rdv__head-actions">
                        <?php if ($rdvDedicated): ?>
                        <button type="button" class="client-ref-rdv__btn-print" onclick="window.print(); return false;">Print</button>
                        <?php endif; ?>
                        <?php if ($rdvAddUrl !== null): ?>
                        <a class="client-ref-rdv__cta" href="<?= htmlspecialchars($rdvAddUrl) ?>">Add Appointment</a>
                        <?php endif; ?>
                    </div>
                </header>

                <div class="client-ref-rdv__summary" aria-label="Appointment summary">
                    <div class="client-ref-rdv__chip"><span class="client-ref-rdv__chip-k">Total</span><span class="client-ref-rdv__chip-v"><?= (int) ($appointmentSummary['total'] ?? 0) ?></span></div>
                    <div class="client-ref-rdv__chip"><span class="client-ref-rdv__chip-k">Scheduled</span><span class="client-ref-rdv__chip-v"><?= (int) ($appointmentSummary['scheduled'] ?? 0) ?></span></div>
                    <div class="client-ref-rdv__chip"><span class="client-ref-rdv__chip-k">Confirmed</span><span class="client-ref-rdv__chip-v"><?= (int) ($appointmentSummary['confirmed'] ?? 0) ?></span></div>
                    <div class="client-ref-rdv__chip"><span class="client-ref-rdv__chip-k">Completed</span><span class="client-ref-rdv__chip-v"><?= (int) ($appointmentSummary['completed'] ?? 0) ?></span></div>
                    <div class="client-ref-rdv__chip"><span class="client-ref-rdv__chip-k">Cancelled</span><span class="client-ref-rdv__chip-v"><?= (int) ($appointmentSummary['cancelled'] ?? 0) ?></span></div>
                    <div class="client-ref-rdv__chip"><span class="client-ref-rdv__chip-k">No-shows</span><span class="client-ref-rdv__chip-v"><?= (int) ($appointmentSummary['no_show'] ?? 0) ?></span></div>
                </div>

                <div class="client-ref-rdv__filter-card">
                    <?php if ($rdvDedicated): ?>
                    <div class="client-ref-rdv__filter-card-top">
                        <h3 class="client-ref-rdv__filter-card-title">Filters</h3>
                    </div>
                    <?php endif; ?>
                    <form class="client-ref-rdv__filter-form" method="get" action="<?= htmlspecialchars($rdvBasePath) ?>" aria-label="Filter appointments">
                        <input type="hidden" name="appt_page" value="1">
                        <div class="client-ref-rdv__filter-grid">
                            <div class="client-ref-rdv__field">
                                <label for="appt_status">Status</label>
                                <select id="appt_status" name="appt_status">
                                    <option value="">All</option>
                                    <?php foreach ($resumeApptStatusLabels as $sk => $sl): ?>
                                    <option value="<?= htmlspecialchars($sk) ?>"<?= ($resumeApptFilters['status'] ?? null) === $sk ? ' selected' : '' ?>><?= htmlspecialchars($sl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="client-ref-rdv__field">
                                <label for="appt_date_mode">Filter dates by</label>
                                <select id="appt_date_mode" name="appt_date_mode">
                                    <option value="appointment"<?= ($resumeApptFilters['date_mode'] ?? '') !== 'created' ? ' selected' : '' ?>>Appointment date</option>
                                    <option value="created"<?= ($resumeApptFilters['date_mode'] ?? '') === 'created' ? ' selected' : '' ?>>Created date</option>
                                </select>
                            </div>
                            <div class="client-ref-rdv__field">
                                <label for="appt_date_from">From</label>
                                <input type="date" id="appt_date_from" name="appt_date_from" value="<?= htmlspecialchars((string) ($resumeApptFilters['date_from'] ?? '')) ?>">
                            </div>
                            <div class="client-ref-rdv__field">
                                <label for="appt_date_to">To</label>
                                <input type="date" id="appt_date_to" name="appt_date_to" value="<?= htmlspecialchars((string) ($resumeApptFilters['date_to'] ?? '')) ?>">
                            </div>
                            <div class="client-ref-rdv__field">
                                <label for="appt_per_page">Per page</label>
                                <select id="appt_per_page" name="appt_per_page">
                                    <?php foreach ([10, 15, 25, 50] as $pp): ?>
                                    <option value="<?= $pp ?>"<?= $perPage === $pp ? ' selected' : '' ?>><?= $pp ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="client-ref-rdv__filter-actions">
                            <button type="submit" class="client-ref-rdv__btn client-ref-rdv__btn--primary"><?= $rdvDedicated ? 'Search' : 'Apply' ?></button>
                            <a class="client-ref-rdv__btn client-ref-rdv__btn--ghost" href="<?= htmlspecialchars($rdvBasePath . '#client-ref-rdv') ?>">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="client-ref-rdv__table-card">
                    <?php if ($rdvDedicated && $total > 0): ?>
                    <div class="client-ref-rdv__results-bar">
                        <span class="client-ref-rdv__results-label">Results</span>
                        <span class="client-ref-rdv__results-count"><?= $total ?> appointment<?= $total === 1 ? '' : 's' ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($total === 0): ?>
                    <div class="client-ref-rdv__empty<?= $rdvDedicated ? ' client-ref-rdv__empty--dedicated' : '' ?>" role="status">
                        <p class="client-ref-rdv__empty-title"><?= $hasFilters ? 'No results' : 'No appointments yet' ?></p>
                        <p class="client-ref-rdv__empty-text"><?= $hasFilters
                            ? 'No appointments match these filters. Adjust the criteria or reset.'
                            : 'When you book this client, their appointments will appear here.' ?></p>
                        <?php if ($rdvAddUrl !== null && !$hasFilters): ?>
                        <p class="client-ref-rdv__empty-cta">
                            <a class="client-ref-rdv__cta client-ref-rdv__cta--empty" href="<?= htmlspecialchars($rdvAddUrl) ?>">Add Appointment</a>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="client-ref-rdv__table-wrap">
                        <table class="client-ref-rdv__table">
                            <thead>
                                <tr>
                                    <th scope="col">Ref.</th>
                                    <th scope="col">Start</th>
                                    <th scope="col">End</th>
                                    <th scope="col">Created</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Service</th>
                                    <?php if (!empty($clientItineraryShowStaff)): ?><th scope="col">Staff</th><?php endif; ?>
                                    <?php if (!empty($clientItineraryShowSpace)): ?><th scope="col">Space</th><?php endif; ?>
                                    <th scope="col" class="client-ref-rdv__col-action"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $a): ?>
                            <?php
                            $st = (string) ($a['status'] ?? '');
                            $stLabel = $resumeApptStatusLabels[$st] ?? $st;
                            ?>
                                <tr>
                                    <td><span class="client-ref-rdv__id">#<?= (int) $a['id'] ?></span></td>
                                    <td><?= htmlspecialchars($fmtRdvDt($a['start_at'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($fmtRdvDt($a['end_at'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($fmtRdvDt($a['created_at'] ?? '')) ?></td>
                                    <td><span class="client-ref-rdv__status"><?= htmlspecialchars($stLabel) ?></span></td>
                                    <td><?= htmlspecialchars((string) ($a['service_name'] ?? '—')) ?></td>
                                    <?php if (!empty($clientItineraryShowStaff)): ?><td><?= htmlspecialchars((string) ($a['staff_name'] ?? '—')) ?></td><?php endif; ?>
                                    <?php if (!empty($clientItineraryShowSpace)): ?><td><?= htmlspecialchars((string) ($a['room_name'] ?? '—')) ?></td><?php endif; ?>
                                    <td class="client-ref-rdv__col-action"><a class="client-ref-rdv__link" href="/appointments/<?= (int) $a['id'] ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($resumeApptTotalPages > 1): ?>
                    <nav class="client-ref-rdv__pagination" aria-label="Appointment pagination">
                        <?php if ($page > 1): ?>
                        <a class="client-ref-rdv__page-link" href="<?= htmlspecialchars($buildRdvUrl($rdvBasePath, $resumeApptLinkQuery, ['appt_page' => $page - 1])) ?>">Previous</a>
                        <?php endif; ?>
                        <span class="client-ref-rdv__page-meta">Page <?= $page ?> / <?= $resumeApptTotalPages ?> · <?= $total ?> total</span>
                        <?php if ($page < $resumeApptTotalPages): ?>
                        <a class="client-ref-rdv__page-link" href="<?= htmlspecialchars($buildRdvUrl($rdvBasePath, $resumeApptLinkQuery, ['appt_page' => $page + 1])) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                    <?php else: ?>
                    <p class="client-ref-rdv__list-meta"><?= $total ?> appointments total · <?= count($items) ?> on this page</p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($rdvDedicated): ?>
                </div>
                <?php endif; ?>
            </section>
