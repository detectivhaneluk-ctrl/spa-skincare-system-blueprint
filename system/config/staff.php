<?php

declare(strict_types=1);

/**
 * Staff module settings.
 *
 * STAFF_TRASH_RETENTION_DAYS — days after trash before physical purge eligibility (cron).
 */
return [
    'trash_retention_days' => max(1, (int) env('STAFF_TRASH_RETENTION_DAYS', 30)),
];
