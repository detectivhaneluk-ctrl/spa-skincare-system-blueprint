<?php

declare(strict_types=1);

/**
 * Services module settings.
 *
 * SERVICES_TRASH_RETENTION_DAYS — days after trash before physical purge eligibility (cron).
 */
return [
    'trash_retention_days' => max(1, (int) env('SERVICES_TRASH_RETENTION_DAYS', 30)),
];
