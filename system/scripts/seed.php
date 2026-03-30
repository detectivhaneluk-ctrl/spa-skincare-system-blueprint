<?php

declare(strict_types=1);

/**
 * Run seeders. Usage: php scripts/seed.php
 */

require dirname(__DIR__) . '/bootstrap.php';

$db = app(\Core\App\Database::class);

require dirname(__DIR__) . '/data/seeders/001_seed_roles_permissions.php';
require dirname(__DIR__) . '/data/seeders/002_seed_baseline_settings.php';
require dirname(__DIR__) . '/data/seeders/003_seed_payment_methods.php';
require dirname(__DIR__) . '/data/seeders/004_seed_phase_b_settings.php';
require dirname(__DIR__) . '/data/seeders/005_seed_phase_c_payment_settings.php';
require dirname(__DIR__) . '/data/seeders/006_seed_vat_rates.php';
require dirname(__DIR__) . '/data/seeders/007_seed_phase_d_waitlist_settings.php';
require dirname(__DIR__) . '/data/seeders/008_seed_phase_f_marketing_settings.php';
require dirname(__DIR__) . '/data/seeders/009_seed_phase_g_security_settings.php';
require dirname(__DIR__) . '/data/seeders/010_seed_notification_settings.php';
require dirname(__DIR__) . '/data/seeders/011_seed_hardware_settings.php';
require dirname(__DIR__) . '/data/seeders/012_seed_sync_settings_permissions.php';
require dirname(__DIR__) . '/data/seeders/013_seed_marketing_payroll_role_permissions.php';
require dirname(__DIR__) . '/data/seeders/014_seed_control_plane_role_split_permissions.php';
require dirname(__DIR__) . '/data/seeders/015_seed_cancellation_reasons.php';
require dirname(__DIR__) . '/data/seeders/016_seed_price_modification_reasons.php';
