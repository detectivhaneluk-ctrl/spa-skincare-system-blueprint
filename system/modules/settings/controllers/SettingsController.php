<?php

declare(strict_types=1);

namespace Modules\Settings\Controllers;

use Core\App\Application;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\BranchDirectory;
use Modules\Settings\Services\AppointmentCancellationReasonService;
use Modules\Settings\Services\BranchClosureDateService;
use Modules\Settings\Services\BranchOperatingHoursService;
use Modules\Settings\Support\SettingsShellSidebar;
use Modules\Sales\Services\PaymentMethodService;

final class SettingsController
{
    /** Query/post field: branch context for branch-aware settings domains (0 = global default). */
    private const ONLINE_BOOKING_BRANCH_PARAM = 'online_booking_branch_id';
    /** Query/post field: branch scope for appointment operational settings (0 = organization default). */
    private const APPOINTMENTS_BRANCH_PARAM = 'appointments_branch_id';
    /** POST: branch scope for appointment settings save (must match UI selector). */
    private const APPOINTMENTS_CONTEXT_BRANCH_POST = 'appointments_context_branch_id';
    /** GET: branch context on Payment Settings (gift-card limits + method preview; 0 = org default). */
    private const PAYMENTS_BRANCH_PARAM = 'payments_branch_id';
    /** POST: must match Payment Settings branch selector on save. */
    private const PAYMENTS_CONTEXT_BRANCH_POST = 'payments_context_branch_id';
    /** GET: branch context on Waitlist Settings (0 = organization default). */
    private const WAITLIST_BRANCH_PARAM = 'waitlist_branch_id';
    /** POST: must match Waitlist Settings branch selector on save. */
    private const WAITLIST_CONTEXT_BRANCH_POST = 'waitlist_context_branch_id';
    /** GET: branch context on Marketing Settings (0 = organization default). */
    private const MARKETING_BRANCH_PARAM = 'marketing_branch_id';
    /** POST: must match Marketing Settings branch selector on save. */
    private const MARKETING_CONTEXT_BRANCH_POST = 'marketing_context_branch_id';
    /** Query/post field: active subsection in settings workspace. */
    private const SECTION_PARAM = 'section';
    /** Query/post field: active establishment screen state. */
    private const SCREEN_PARAM = 'screen';
    private const DEFAULT_SECTION = 'establishment';
    private const DEFAULT_ESTABLISHMENT_SCREEN = 'overview';
    /** @var list<string> */
    private const ESTABLISHMENT_ALLOWED_SCREENS = [
        'overview',
        'edit-overview',
        'edit-primary-contact',
        'edit-secondary-contact',
        'opening-hours',
        'closure-dates',
    ];
    private const OPENING_HOURS_FORM_PARAM = 'opening_hours';
    private const CLOSURE_DATES_ACTION_PARAM = 'closure_dates_action';
    private const SECONDARY_CONTACT_KEYS = [
        'secondary_contact_first_name',
        'secondary_contact_last_name',
        'secondary_contact_phone',
        'secondary_contact_email',
    ];
    /** @var list<string> */
    private const ESTABLISHMENT_WRITE_KEYS = [
        'establishment.name',
        'establishment.phone',
        'establishment.email',
        'establishment.address',
        'establishment.currency',
        'establishment.timezone',
        'establishment.language',
    ];
    /** @var list<string> */
    private const CANCELLATION_WRITE_KEYS = SettingsService::CANCELLATION_KEYS;

    /** @var list<string> */
    private const APPOINTMENT_WRITE_KEYS = SettingsService::APPOINTMENT_SETTINGS_FORM_KEYS;
    /** @var list<string> */
    private const ONLINE_BOOKING_WRITE_KEYS = SettingsService::ONLINE_BOOKING_KEYS;

    /**
     * Combined POST allowlist for section=public_channels (domain split unchanged: online_booking / intake / public_commerce patches).
     *
     * @var list<string>
     */
    private const PUBLIC_CHANNELS_WRITE_KEYS = [
        ...SettingsService::ONLINE_BOOKING_KEYS,
        ...SettingsService::INTAKE_KEYS,
        ...SettingsService::PUBLIC_COMMERCE_KEYS,
    ];
    /** @var list<string> */
    private const PAYMENT_WRITE_KEYS = [
        'payments.default_method_code',
        'payments.allow_partial_payments',
        'payments.allow_overpayments',
        'payments.receipt_notes',
        'receipt_invoice.show_establishment_name',
        'receipt_invoice.show_establishment_address',
        'receipt_invoice.show_establishment_phone',
        'receipt_invoice.show_establishment_email',
        'receipt_invoice.show_client_block',
        'receipt_invoice.show_client_phone',
        'receipt_invoice.show_client_address',
        'receipt_invoice.show_recorded_by',
        'receipt_invoice.show_item_barcode',
        'receipt_invoice.item_header_label',
        'receipt_invoice.item_sort_mode',
        'receipt_invoice.footer_bank_details',
        'receipt_invoice.footer_text',
        'receipt_invoice.receipt_message',
        'receipt_invoice.invoice_message',
    ];
    /** @var list<string> */
    private const WAITLIST_WRITE_KEYS = SettingsService::WAITLIST_KEYS;
    /** @var list<string> */
    private const MARKETING_WRITE_KEYS = [
        'marketing.default_opt_in',
        'marketing.consent_label',
    ];
    /** @var list<string> */
    private const SECURITY_WRITE_KEYS = SettingsService::SECURITY_KEYS;
    /** @var list<string> */
    private const NOTIFICATIONS_WRITE_KEYS = SettingsService::NOTIFICATIONS_KEYS;
    /** @var list<string> */
    private const HARDWARE_WRITE_KEYS = [
        'hardware.use_cash_register',
        'hardware.use_receipt_printer',
    ];
    /** @var list<string> */
    private const MEMBERSHIPS_WRITE_KEYS = SettingsService::MEMBERSHIPS_KEYS;
    /** @var list<string> */
    private const ALL_ALLOWED_WRITE_KEYS = [
        'establishment.name',
        'establishment.phone',
        'establishment.email',
        'establishment.address',
        'establishment.currency',
        'establishment.timezone',
        'establishment.language',
        'cancellation.enabled',
        'cancellation.customer_scope',
        'cancellation.min_notice_hours',
        'cancellation.fee_mode',
        'cancellation.fee_fixed_amount',
        'cancellation.fee_percent',
        'cancellation.staff_payout_mode',
        'cancellation.staff_payout_percent',
        'cancellation.no_show_same_as_cancellation',
        'cancellation.no_show_fee_mode',
        'cancellation.no_show_fee_fixed_amount',
        'cancellation.no_show_fee_percent',
        'cancellation.no_show_staff_payout_mode',
        'cancellation.no_show_staff_payout_percent',
        'cancellation.course_same_as_cancellation',
        'cancellation.course_fee_mode',
        'cancellation.course_fee_fixed_amount',
        'cancellation.course_fee_percent',
        'cancellation.reasons_enabled',
        'cancellation.reason_required',
        'cancellation.tax_enabled',
        'cancellation.policy_text',
        'cancellation.allow_privileged_override',
        'appointments.min_lead_minutes',
        'appointments.max_days_ahead',
        'appointments.allow_past_booking',
        'appointments.allow_end_after_closing',
        'appointments.check_staff_availability_in_search',
        'appointments.allow_staff_booking_on_off_days',
        'appointments.allow_room_overbooking',
        'appointments.allow_staff_concurrency',
        'appointments.no_show_alert_enabled',
        'appointments.no_show_alert_threshold',
        'appointments.calendar_service_show_start_time',
        'appointments.calendar_service_label_mode',
        'appointments.calendar_series_show_start_time',
        'appointments.calendar_series_label_mode',
        'appointments.prebook_display_enabled',
        'appointments.prebook_threshold_value',
        'appointments.prebook_threshold_unit',
        'appointments.client_itinerary_show_staff',
        'appointments.client_itinerary_show_space',
        'appointments.print_show_staff_appointment_list',
        'appointments.print_show_client_service_history',
        'appointments.print_show_package_detail',
        'appointments.print_show_client_product_purchase_history',
        'online_booking.enabled',
        'online_booking.public_api_enabled',
        'online_booking.min_lead_minutes',
        'online_booking.max_days_ahead',
        'online_booking.allow_new_clients',
        'intake.public_enabled',
        'public_commerce.enabled',
        'public_commerce.public_api_enabled',
        'public_commerce.allow_gift_cards',
        'public_commerce.allow_packages',
        'public_commerce.allow_memberships',
        'public_commerce.allow_new_clients',
        'public_commerce.gift_card_min_amount',
        'public_commerce.gift_card_max_amount',
        'payments.default_method_code',
        'payments.allow_partial_payments',
        'payments.allow_overpayments',
        'payments.receipt_notes',
        'waitlist.enabled',
        'waitlist.auto_offer_enabled',
        'waitlist.max_active_per_client',
        'waitlist.default_expiry_minutes',
        'marketing.default_opt_in',
        'marketing.consent_label',
        'security.password_expiration',
        'security.inactivity_timeout_minutes',
        'notifications.appointments_enabled',
        'notifications.sales_enabled',
        'notifications.waitlist_enabled',
        'notifications.memberships_enabled',
        'hardware.use_cash_register',
        'hardware.use_receipt_printer',
        'memberships.terms_text',
        'memberships.renewal_reminder_days',
        'memberships.grace_period_days',
        'receipt_invoice.show_establishment_name',
        'receipt_invoice.show_establishment_address',
        'receipt_invoice.show_establishment_phone',
        'receipt_invoice.show_establishment_email',
        'receipt_invoice.show_client_block',
        'receipt_invoice.show_client_phone',
        'receipt_invoice.show_client_address',
        'receipt_invoice.show_recorded_by',
        'receipt_invoice.show_item_barcode',
        'receipt_invoice.item_header_label',
        'receipt_invoice.item_sort_mode',
        'receipt_invoice.footer_bank_details',
        'receipt_invoice.footer_text',
        'receipt_invoice.receipt_message',
        'receipt_invoice.invoice_message',
    ];
    /** @var array<string, list<string>> */
    private const SECTION_ALLOWED_KEYS = [
        'establishment' => self::ESTABLISHMENT_WRITE_KEYS,
        'cancellation' => self::CANCELLATION_WRITE_KEYS,
        'appointments' => self::APPOINTMENT_WRITE_KEYS,
        'payments' => self::PAYMENT_WRITE_KEYS,
        'waitlist' => self::WAITLIST_WRITE_KEYS,
        'marketing' => self::MARKETING_WRITE_KEYS,
        'security' => self::SECURITY_WRITE_KEYS,
        'notifications' => self::NOTIFICATIONS_WRITE_KEYS,
        'hardware' => self::HARDWARE_WRITE_KEYS,
        'memberships' => self::MEMBERSHIPS_WRITE_KEYS,
        'public_channels' => self::PUBLIC_CHANNELS_WRITE_KEYS,
    ];

    /**
     * @param list<array{id: int|string, ...}> $activeBranches rows from branches WHERE deleted_at IS NULL
     */
    private static function normalizeOnlineBookingBranchId(int $raw, array $activeBranches): int
    {
        if ($raw <= 0) {
            return 0;
        }
        foreach ($activeBranches as $row) {
            if ((int) $row['id'] === $raw) {
                return $raw;
            }
        }
        return 0;
    }

    /**
     * @param list<array{id: int|string, ...}> $activeBranches
     */
    private static function normalizeSection(?string $raw): string
    {
        $section = strtolower(trim((string) $raw));
        if ($section === '' || !array_key_exists($section, self::SECTION_ALLOWED_KEYS)) {
            return self::DEFAULT_SECTION;
        }
        return $section;
    }

    private static function normalizeEstablishmentScreen(?string $raw): string
    {
        $screen = strtolower(trim((string) $raw));
        if ($screen === '' || !in_array($screen, self::ESTABLISHMENT_ALLOWED_SCREENS, true)) {
            return self::DEFAULT_ESTABLISHMENT_SCREEN;
        }

        return $screen;
    }

    /**
     * @param list<array{id:int|string,...}> $activeBranches
     */
    private static function resolveOpeningHoursBranchId(array $activeBranches): ?int
    {
        $allowed = [];
        foreach ($activeBranches as $row) {
            $bid = (int) ($row['id'] ?? 0);
            if ($bid > 0) {
                $allowed[$bid] = true;
            }
        }

        $branchContextId = (int) (Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId() ?? 0);
        if ($branchContextId > 0 && isset($allowed[$branchContextId])) {
            return $branchContextId;
        }

        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();
        $userBranchId = (int) ($user['branch_id'] ?? 0);
        if ($userBranchId > 0 && isset($allowed[$userBranchId])) {
            return $userBranchId;
        }

        return null;
    }

    /**
     * @param list<array{id:int|string,...}> $activeBranches
     */
    private static function resolveClosureDatesBranchId(array $activeBranches): ?int
    {
        $allowed = [];
        foreach ($activeBranches as $row) {
            $bid = (int) ($row['id'] ?? 0);
            if ($bid > 0) {
                $allowed[$bid] = true;
            }
        }

        $branchContextId = (int) (Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId() ?? 0);
        if ($branchContextId > 0 && isset($allowed[$branchContextId])) {
            return $branchContextId;
        }

        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();
        $userBranchId = (int) ($user['branch_id'] ?? 0);
        if ($userBranchId > 0 && isset($allowed[$userBranchId])) {
            return $userBranchId;
        }

        return null;
    }

    /**
     * @param list<array{id:int|string,...}> $activeBranches
     */
    private static function resolveSecondaryContactBranchId(array $activeBranches): ?int
    {
        $allowed = [];
        foreach ($activeBranches as $row) {
            $bid = (int) ($row['id'] ?? 0);
            if ($bid > 0) {
                $allowed[$bid] = true;
            }
        }

        $branchContextId = (int) (Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId() ?? 0);
        if ($branchContextId > 0 && isset($allowed[$branchContextId])) {
            return $branchContextId;
        }

        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();
        $userBranchId = (int) ($user['branch_id'] ?? 0);
        if ($userBranchId > 0 && isset($allowed[$userBranchId])) {
            return $userBranchId;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function scopedPostForSection(array $post, string $section): array
    {
        $allowed = self::SECTION_ALLOWED_KEYS[$section] ?? [];
        if ($allowed === []) {
            return [];
        }
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $post)) {
                $out[$key] = $post[$key];
            }
        }
        return $out;
    }

    /**
     * @param list<array{id: int|string, ...}> $activeBranches
     */
    private static function settingsRedirectLocation(
        int $onlineBookingBranchId,
        array $activeBranches,
        string $activeSection,
        ?string $activeScreen = null,
        int $appointmentsBranchId = 0,
        int $paymentsBranchId = 0,
        int $waitlistBranchId = 0,
        int $marketingBranchId = 0
    ): string {
        $normalizedSection = self::normalizeSection($activeSection);
        $bid = self::normalizeOnlineBookingBranchId($onlineBookingBranchId, $activeBranches);
        $appBid = self::normalizeOnlineBookingBranchId($appointmentsBranchId, $activeBranches);
        $payBid = self::normalizeOnlineBookingBranchId($paymentsBranchId, $activeBranches);
        $waitlistBid = self::normalizeOnlineBookingBranchId($waitlistBranchId, $activeBranches);
        $marketingBid = self::normalizeOnlineBookingBranchId($marketingBranchId, $activeBranches);
        $query = ['section' => $normalizedSection];
        if ($normalizedSection === 'establishment') {
            $query[self::SCREEN_PARAM] = self::normalizeEstablishmentScreen($activeScreen);
        }
        if ($bid > 0) {
            $query[self::ONLINE_BOOKING_BRANCH_PARAM] = (string) $bid;
        }
        if ($normalizedSection === 'appointments' && $appBid > 0) {
            $query[self::APPOINTMENTS_BRANCH_PARAM] = (string) $appBid;
        }
        if ($normalizedSection === 'payments' && $payBid > 0) {
            $query[self::PAYMENTS_BRANCH_PARAM] = (string) $payBid;
        }
        if ($normalizedSection === 'waitlist' && $waitlistBid > 0) {
            $query[self::WAITLIST_BRANCH_PARAM] = (string) $waitlistBid;
        }
        if ($normalizedSection === 'marketing' && $marketingBid > 0) {
            $query[self::MARKETING_BRANCH_PARAM] = (string) $marketingBid;
        }

        return '/settings?' . http_build_query($query);
    }

    /**
     * Branch id for appointment settings UI read/write: {@see APPOINTMENTS_BRANCH_PARAM} when present, else opening-hours-style context, else 0 (org default).
     *
     * @param list<array{id:int|string,...}> $activeBranches
     */
    private static function resolveAppointmentsSettingsBranchId(array $activeBranches): int
    {
        if (array_key_exists(self::APPOINTMENTS_BRANCH_PARAM, $_GET)) {
            return self::normalizeOnlineBookingBranchId((int) $_GET[self::APPOINTMENTS_BRANCH_PARAM], $activeBranches);
        }
        $resolved = self::resolveOpeningHoursBranchId($activeBranches);

        return self::normalizeOnlineBookingBranchId((int) ($resolved ?? 0), $activeBranches);
    }

    public function index(): void
    {
        $settingsService = Application::container()->get(SettingsService::class);
        $openingHoursService = Application::container()->get(BranchOperatingHoursService::class);
        $closureDatesService = Application::container()->get(BranchClosureDateService::class);
        $cancellationReasonService = Application::container()->get(AppointmentCancellationReasonService::class);
        $branches = Application::container()->get(BranchDirectory::class)->getActiveBranchesForSelection();
        $onlineBookingBranchId = self::normalizeOnlineBookingBranchId(
            isset($_GET[self::ONLINE_BOOKING_BRANCH_PARAM]) ? (int) $_GET[self::ONLINE_BOOKING_BRANCH_PARAM] : 0,
            $branches
        );
        $appointmentsBranchId = self::resolveAppointmentsSettingsBranchId($branches);
        $paymentsBranchId = self::normalizeOnlineBookingBranchId(
            isset($_GET[self::PAYMENTS_BRANCH_PARAM]) ? (int) $_GET[self::PAYMENTS_BRANCH_PARAM] : 0,
            $branches
        );
        $waitlistBranchId = self::normalizeOnlineBookingBranchId(
            isset($_GET[self::WAITLIST_BRANCH_PARAM]) ? (int) $_GET[self::WAITLIST_BRANCH_PARAM] : 0,
            $branches
        );
        $marketingBranchId = self::normalizeOnlineBookingBranchId(
            isset($_GET[self::MARKETING_BRANCH_PARAM]) ? (int) $_GET[self::MARKETING_BRANCH_PARAM] : 0,
            $branches
        );
        $activeSettingsSection = self::normalizeSection(isset($_GET[self::SECTION_PARAM]) ? (string) $_GET[self::SECTION_PARAM] : self::DEFAULT_SECTION);
        $activeEstablishmentScreen = self::normalizeEstablishmentScreen(isset($_GET[self::SCREEN_PARAM]) ? (string) $_GET[self::SCREEN_PARAM] : self::DEFAULT_ESTABLISHMENT_SCREEN);
        $openingHoursStorageReady = $openingHoursService->isStorageReady();
        $openingHoursBranchId = self::resolveOpeningHoursBranchId($branches);
        $closureDatesStorageReady = $closureDatesService->isStorageReady();
        $closureDatesBranchId = self::resolveClosureDatesBranchId($branches);
        $secondaryContactBranchId = self::resolveSecondaryContactBranchId($branches);
        $openingHoursBranchName = null;
        $closureDatesBranchName = null;
        $secondaryContactBranchName = null;
        foreach ($branches as $branchRow) {
            if ((int) ($branchRow['id'] ?? 0) === (int) ($openingHoursBranchId ?? 0)) {
                $openingHoursBranchName = (string) ($branchRow['name'] ?? '');
            }
            if ((int) ($branchRow['id'] ?? 0) === (int) ($closureDatesBranchId ?? 0)) {
                $closureDatesBranchName = (string) ($branchRow['name'] ?? '');
            }
            if ((int) ($branchRow['id'] ?? 0) === (int) ($secondaryContactBranchId ?? 0)) {
                $secondaryContactBranchName = (string) ($branchRow['name'] ?? '');
            }
        }
        $publicSettingsBranch = $onlineBookingBranchId > 0 ? $onlineBookingBranchId : null;
        if ($activeSettingsSection === 'payments') {
            $publicSettingsBranch = $paymentsBranchId > 0 ? $paymentsBranchId : null;
        }
        $establishment = $settingsService->getEstablishmentSettings(null);
        $cancellation = $settingsService->getCancellationPolicySettings(null);
        $cancellationReasonStorageReady = $cancellationReasonService->isStorageReady();
        $cancellationReasons = $cancellationReasonStorageReady ? $cancellationReasonService->listForCurrentOrganization(false) : [];
        $appointmentReadBranch = $appointmentsBranchId > 0 ? $appointmentsBranchId : null;
        $appointment = $settingsService->getAppointmentSettings($appointmentReadBranch);
        $onlineBooking = $settingsService->getOnlineBookingSettings($publicSettingsBranch);
        $intake = $settingsService->getIntakeSettings($publicSettingsBranch);
        $publicCommerce = $settingsService->getPublicCommerceSettings($publicSettingsBranch);
        // A-005: recording defaults (partial/overpay/default method/receipt_notes) are org-wide; matches PaymentService/PaymentController reads.
        $payment = $settingsService->getPaymentSettings(null);
        $waitlistReadBranch = $waitlistBranchId > 0 ? $waitlistBranchId : null;
        $marketingReadBranch = $marketingBranchId > 0 ? $marketingBranchId : null;
        $waitlist = $settingsService->getWaitlistSettings($waitlistReadBranch);
        $marketing = $settingsService->getMarketingSettings($marketingReadBranch);
        $security = $settingsService->getSecuritySettings(null);
        $notification = $settingsService->getNotificationSettings(null);
        $hardware = $settingsService->getHardwareSettings(null);
        $membership = $settingsService->getMembershipSettings(null);
        $paymentMethodsEffective = [];
        $paymentEdit = '';
        $receiptInvoice = [];
        $receiptInvoiceFooterPreview = '';
        if ($activeSettingsSection === 'payments') {
            $paymentMethodService = Application::container()->get(PaymentMethodService::class);
            $methodCtx = $paymentsBranchId > 0 ? $paymentsBranchId : null;
            $paymentMethodsEffective = $paymentMethodService->listForPaymentForm($methodCtx);
            $paymentEdit = strtolower(trim((string) ($_GET['payment_edit'] ?? '')));
            $receiptReadBranch = $paymentsBranchId > 0 ? $paymentsBranchId : null;
            $receiptInvoice = $settingsService->getReceiptInvoiceSettings($receiptReadBranch);
            $receiptInvoiceFooterPreview = $settingsService->getEffectiveReceiptFooterText($receiptReadBranch);
        }
        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();
        extract(SettingsShellSidebar::permissionFlagsForUser($user), EXTR_SKIP);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        $openingHoursForm = [];
        $openingHoursDayLabels = $openingHoursService->dayLabels();
        $openingHoursSummary = '';
        $closureDatesRows = [];
        $secondaryContact = [
            'secondary_contact_first_name' => '',
            'secondary_contact_last_name' => '',
            'secondary_contact_phone' => '',
            'secondary_contact_email' => '',
        ];
        if ($openingHoursStorageReady && $openingHoursBranchId !== null) {
            $openingHoursForm = $openingHoursService->getWeeklyMapForBranch($openingHoursBranchId);
            if ($flash !== null && is_array($flash) && is_array($flash['opening_hours_old'] ?? null)) {
                $openingHoursForm = $openingHoursService->mergeSubmittedMap($openingHoursForm, $flash['opening_hours_old']);
            }
            $openingHoursSummary = $openingHoursService->formatSummary($openingHoursForm);
        }
        if ($closureDatesStorageReady && $closureDatesBranchId !== null) {
            $closureDatesRows = $closureDatesService->listForBranch($closureDatesBranchId);
        }
        if ($secondaryContactBranchId !== null) {
            $branchEstablishment = $settingsService->getEstablishmentSettings($secondaryContactBranchId);
            foreach (self::SECONDARY_CONTACT_KEYS as $key) {
                $secondaryContact[$key] = trim((string) ($branchEstablishment[$key] ?? ''));
            }
            if ($flash !== null && is_array($flash) && is_array($flash['secondary_contact_old'] ?? null)) {
                foreach (self::SECONDARY_CONTACT_KEYS as $key) {
                    if (array_key_exists($key, $flash['secondary_contact_old'])) {
                        $secondaryContact[$key] = trim((string) $flash['secondary_contact_old'][$key]);
                    }
                }
            }
        }
        require base_path('modules/settings/views/index.php');
    }

    /**
     * Read-only operator notice for VAT distribution: the transactional endpoint returns JSON only.
     * Does not implement report UI; see Lane 01 Wave 2 honesty scope.
     */
    public function vatDistributionGuide(): void
    {
        $branches = Application::container()->get(BranchDirectory::class)->getActiveBranchesForSelection();
        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();
        extract(SettingsShellSidebar::permissionFlagsForUser($user), EXTR_SKIP);
        $flash = flash();
        $onlineBookingBranchId = 0;
        $appointmentsBranchId = 0;
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-30 days'));
        $sampleJsonUrl = '/reports/vat-distribution?' . http_build_query([
            'date_from' => $from,
            'date_to' => $to,
        ]);
        require base_path('modules/settings/views/vat-distribution-guide.php');
    }

    /** Truthy when scalar is an explicit "on" submission (checkbox + hidden 0/1 contract). */
    private static function boolFromScalar(mixed $v): bool
    {
        $s = (string) $v;

        return $s !== '' && $s !== '0';
    }

    /**
     * @param list<string> $changedKeys
     * @param list<string> $unknownRawKeys Keys from raw `settings[]` POST not in ALL_ALLOWED_WRITE_KEYS
     */
    private static function auditSettingsUpdated(
        AuditService $audit,
        string $domain,
        array $changedKeys,
        ?int $onlineBookingBranchColumn,
        array $unknownRawKeys
    ): void {
        if ($changedKeys === []) {
            return;
        }
        // Branch scope for branch-effective domains (public channels, appointments operational subset); 0 = organization default row.
        $meta = [
            'domain' => $domain,
            'changed_keys' => $changedKeys,
            'branch_scope' => $onlineBookingBranchColumn ?? 0,
        ];
        if ($unknownRawKeys !== []) {
            $meta['unknown_raw_keys'] = $unknownRawKeys;
            $meta['ignored_keys'] = $unknownRawKeys;
        }
        $audit->log('settings_updated', 'settings', null, null, $onlineBookingBranchColumn, $meta);
    }

    /**
     * Unknown / unmapped keys from the raw `settings[]` POST array (client truth), not the section-scoped payload.
     * Keys dropped by scopedPostForSection may still appear here when they are not globally allowlisted.
     *
     * @param array<string, mixed> $rawSettingsPost
     * @return list<string>
     */
    private static function collectUnknownRawKeysFromSettingsPost(array $rawSettingsPost): array
    {
        $unknown = [];
        foreach ($rawSettingsPost as $key => $_value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!in_array($key, self::ALL_ALLOWED_WRITE_KEYS, true)) {
                $unknown[] = $key;
            }
        }
        sort($unknown);

        return $unknown;
    }

    /**
     * Sorted string keys from raw `settings[]` POST (client truth).
     *
     * @param array<string, mixed> $rawSettingsPost
     * @return list<string>
     */
    private static function collectPostedSettingsKeyNames(array $rawSettingsPost): array
    {
        $keys = [];
        foreach ($rawSettingsPost as $key => $_) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Keys accepted into the section-scoped payload (subset of posted keys that match this screen’s allowlist).
     *
     * @param array<string, mixed> $scopedPost
     * @return list<string>
     */
    private static function collectScopedSettingsKeyNames(array $scopedPost): array
    {
        $keys = [];
        foreach ($scopedPost as $key => $_) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Keys present under `settings` in POST but not allowed for the active section (never persisted).
     *
     * @param array<string, mixed> $rawPost
     * @return list<string>
     */
    private static function collectStrippedRawSettingsKeys(array $rawPost, string $section): array
    {
        $allowed = self::SECTION_ALLOWED_KEYS[$section] ?? [];
        if ($allowed === []) {
            return [];
        }
        $allowedFlip = array_flip($allowed);
        $stripped = [];
        foreach ($rawPost as $key => $_) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!isset($allowedFlip[$key])) {
                $stripped[] = $key;
            }
        }
        sort($stripped);

        return $stripped;
    }

    /**
     * Map scoped POST keys `receipt_invoice.*` to short keys for {@see SettingsService::patchReceiptInvoiceSettings}.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function receiptInvoicePatchFromPost(array $post): array
    {
        $prefix = 'receipt_invoice.';
        $out = [];
        foreach (SettingsService::RECEIPT_INVOICE_BOOL_SHORT_KEYS as $short) {
            $full = $prefix . $short;
            if (array_key_exists($full, $post)) {
                $out[$short] = self::boolFromScalar($post[$full]);
            }
        }
        foreach (['footer_bank_details', 'footer_text', 'item_header_label', 'item_sort_mode', 'receipt_message', 'invoice_message'] as $short) {
            $full = $prefix . $short;
            if (array_key_exists($full, $post)) {
                $out[$short] = $post[$full];
            }
        }

        return $out;
    }

    public function store(): void
    {
        $settingsService = Application::container()->get(SettingsService::class);
        $openingHoursService = Application::container()->get(BranchOperatingHoursService::class);
        $closureDatesService = Application::container()->get(BranchClosureDateService::class);
        $cancellationReasonService = Application::container()->get(AppointmentCancellationReasonService::class);
        $audit = Application::container()->get(AuditService::class);
        $activeBranches = Application::container()->get(BranchDirectory::class)->getActiveBranchesForSelection();
        $onlineBookingContextBranchId = self::normalizeOnlineBookingBranchId(
            isset($_POST['online_booking_context_branch_id']) ? (int) $_POST['online_booking_context_branch_id'] : 0,
            $activeBranches
        );
        $appointmentsContextBranchId = self::normalizeOnlineBookingBranchId(
            isset($_POST[self::APPOINTMENTS_CONTEXT_BRANCH_POST]) ? (int) $_POST[self::APPOINTMENTS_CONTEXT_BRANCH_POST] : 0,
            $activeBranches
        );
        $paymentsContextBranchId = self::normalizeOnlineBookingBranchId(
            isset($_POST[self::PAYMENTS_CONTEXT_BRANCH_POST]) ? (int) $_POST[self::PAYMENTS_CONTEXT_BRANCH_POST] : 0,
            $activeBranches
        );
        $waitlistContextBranchId = self::normalizeOnlineBookingBranchId(
            isset($_POST[self::WAITLIST_CONTEXT_BRANCH_POST]) ? (int) $_POST[self::WAITLIST_CONTEXT_BRANCH_POST] : 0,
            $activeBranches
        );
        $marketingContextBranchId = self::normalizeOnlineBookingBranchId(
            isset($_POST[self::MARKETING_CONTEXT_BRANCH_POST]) ? (int) $_POST[self::MARKETING_CONTEXT_BRANCH_POST] : 0,
            $activeBranches
        );
        $activeSection = self::normalizeSection(isset($_POST[self::SECTION_PARAM]) ? (string) $_POST[self::SECTION_PARAM] : self::DEFAULT_SECTION);
        $activeScreen = self::normalizeEstablishmentScreen(isset($_POST[self::SCREEN_PARAM]) ? (string) $_POST[self::SCREEN_PARAM] : self::DEFAULT_ESTABLISHMENT_SCREEN);
        $publicSettingsSaveBranch = $onlineBookingContextBranchId > 0 ? $onlineBookingContextBranchId : null;
        $redirectBase = self::settingsRedirectLocation(
            $onlineBookingContextBranchId,
            $activeBranches,
            $activeSection,
            $activeScreen,
            $appointmentsContextBranchId,
            $paymentsContextBranchId,
            $waitlistContextBranchId,
            $marketingContextBranchId
        );

        if ($activeSection === 'establishment' && $activeScreen === 'opening-hours') {
            if (!$openingHoursService->isStorageReady()) {
                flash('error', 'Opening Hours is not available yet because the required database migration has not been applied.');
                header('Location: ' . $redirectBase);
                exit;
            }
            $openingHoursBranchId = self::resolveOpeningHoursBranchId($activeBranches);
            if ($openingHoursBranchId === null) {
                flash('error', 'Opening Hours cannot be saved because no active branch context is available.');
                header('Location: ' . $redirectBase);
                exit;
            }
            $rawHours = is_array($_POST[self::OPENING_HOURS_FORM_PARAM] ?? null)
                ? $_POST[self::OPENING_HOURS_FORM_PARAM]
                : [];
            try {
                $normalized = $openingHoursService->saveWeeklyMapForBranch($openingHoursBranchId, $rawHours);
                $audit->log('branch_operating_hours_updated', 'branch', $openingHoursBranchId, null, $openingHoursBranchId, [
                    'updated_days' => array_keys($normalized),
                ]);
                flash('success', 'Opening hours saved.');
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
                flash('opening_hours_old', $rawHours);
            }
            header('Location: ' . $redirectBase);
            exit;
        }

        if ($activeSection === 'establishment' && $activeScreen === 'closure-dates') {
            if (!$closureDatesService->isStorageReady()) {
                flash('error', 'Closure Dates is not available yet because the required database migration has not been applied.');
                header('Location: ' . $redirectBase);
                exit;
            }
            $closureDatesBranchId = self::resolveClosureDatesBranchId($activeBranches);
            if ($closureDatesBranchId === null) {
                flash('error', 'Closure Dates cannot be saved because no active branch context is available.');
                header('Location: ' . $redirectBase);
                exit;
            }
            $action = trim((string) ($_POST[self::CLOSURE_DATES_ACTION_PARAM] ?? ''));
            try {
                if ($action === 'create') {
                    $raw = [
                        'closure_date' => (string) ($_POST['closure_date'] ?? ''),
                        'title' => (string) ($_POST['title'] ?? ''),
                        'notes' => (string) ($_POST['notes'] ?? ''),
                    ];
                    $closureDatesService->createForBranch($closureDatesBranchId, $raw);
                    flash('success', 'Closure date added.');
                } elseif ($action === 'update') {
                    $id = (int) ($_POST['closure_id'] ?? 0);
                    $raw = [
                        'closure_date' => (string) ($_POST['closure_date'] ?? ''),
                        'title' => (string) ($_POST['title'] ?? ''),
                        'notes' => (string) ($_POST['notes'] ?? ''),
                    ];
                    $closureDatesService->updateForBranch($closureDatesBranchId, $id, $raw);
                    flash('success', 'Closure date updated.');
                } elseif ($action === 'delete') {
                    $id = (int) ($_POST['closure_id'] ?? 0);
                    $closureDatesService->deleteForBranch($closureDatesBranchId, $id);
                    flash('success', 'Closure date deleted.');
                } else {
                    flash('error', 'Unsupported closure date action.');
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
                if ($action === 'create' || $action === 'update') {
                    flash('closure_dates_old', [
                        'action' => $action,
                        'closure_id' => (int) ($_POST['closure_id'] ?? 0),
                        'closure_date' => (string) ($_POST['closure_date'] ?? ''),
                        'title' => (string) ($_POST['title'] ?? ''),
                        'notes' => (string) ($_POST['notes'] ?? ''),
                    ]);
                }
            }
            header('Location: ' . $redirectBase);
            exit;
        }

        if ($activeSection === 'establishment' && $activeScreen === 'edit-secondary-contact') {
            $secondaryBranchId = self::resolveSecondaryContactBranchId($activeBranches);
            if ($secondaryBranchId === null) {
                flash('error', 'Secondary Contact cannot be saved because no active branch context is available.');
                header('Location: ' . $redirectBase);
                exit;
            }

            $patch = [];
            foreach (self::SECONDARY_CONTACT_KEYS as $short) {
                $patch[$short] = (string) ($_POST[$short] ?? '');
            }

            try {
                $changed = $settingsService->patchEstablishmentSettings($patch, $secondaryBranchId);
                if ($changed !== []) {
                    self::auditSettingsUpdated($audit, 'establishment_secondary_contact', $changed, $secondaryBranchId, []);
                }
                flash('success', 'Secondary contact saved.');
            } catch (\InvalidArgumentException $e) {
                flash('error', $e->getMessage());
                flash('secondary_contact_old', $patch);
            }
            header('Location: ' . $redirectBase);
            exit;
        }

        if ($activeSection === 'cancellation') {
            $reasonAction = trim((string) ($_POST['cancellation_reasons_action'] ?? ''));
            if ($reasonAction !== '') {
                $editRedirect = '/settings?' . http_build_query([
                    'section' => 'cancellation',
                    'cancellation_reasons_mode' => 'edit',
                ]);
                try {
                    if ($reasonAction === 'editor_save' || $reasonAction === 'editor_add') {
                        $rows = is_array($_POST['reason_rows'] ?? null) ? $_POST['reason_rows'] : [];
                        $required = self::boolFromScalar($_POST['reason_required'] ?? '0');
                        $existingReasons = $cancellationReasonService->listForCurrentOrganization(false);
                        $existingById = [];
                        foreach ($existingReasons as $r) {
                            $rid = (int) ($r['id'] ?? 0);
                            if ($rid > 0) {
                                $existingById[$rid] = $r;
                            }
                        }

                        $activeNames = [];
                        foreach ($rows as $idRaw => $row) {
                            $id = (int) $idRaw;
                            if ($id <= 0 || !isset($existingById[$id])) {
                                continue;
                            }
                            $remove = self::boolFromScalar($row['remove'] ?? '0');
                            if ($remove) {
                                continue;
                            }
                            $name = trim((string) ($row['name'] ?? ''));
                            if ($name === '') {
                                throw new \InvalidArgumentException('Reason names cannot be empty.');
                            }
                            $key = strtolower($name);
                            if (isset($activeNames[$key])) {
                                throw new \InvalidArgumentException('Duplicate active reason names are not allowed.');
                            }
                            $activeNames[$key] = true;
                        }
                        $newName = trim((string) ($_POST['new_reason_name'] ?? ''));
                        if ($reasonAction === 'editor_add') {
                            if ($newName === '') {
                                throw new \InvalidArgumentException('Enter a reason name to add.');
                            }
                            $newKey = strtolower($newName);
                            if (isset($activeNames[$newKey])) {
                                throw new \InvalidArgumentException('Duplicate active reason names are not allowed.');
                            }
                            $base = strtolower(preg_replace('/[^a-z0-9]+/', '_', $newName) ?? '');
                            $base = trim($base, '_');
                            if ($base === '') {
                                $base = 'reason';
                            }
                            $code = $base;
                            $suffix = 2;
                            $existingCodes = [];
                            foreach ($existingReasons as $r) {
                                $existingCodes[strtolower((string) ($r['code'] ?? ''))] = true;
                            }
                            while (isset($existingCodes[$code])) {
                                $code = $base . '_' . $suffix;
                                $suffix++;
                            }
                            $cancellationReasonService->create([
                                'code' => $code,
                                'name' => $newName,
                                'applies_to' => 'both',
                                'sort_order' => count($existingReasons) + 10,
                                'is_active' => true,
                            ]);
                            $settingsService->patchCancellationSettings(['reason_required' => $required], null);
                            flash('success', 'Cancellation reason added.');
                            header('Location: ' . $editRedirect);
                            exit;
                        } else {
                            foreach ($rows as $idRaw => $row) {
                                $id = (int) $idRaw;
                                if ($id <= 0 || !isset($existingById[$id])) {
                                    continue;
                                }
                                $remove = self::boolFromScalar($row['remove'] ?? '0');
                                if ($remove) {
                                    $cancellationReasonService->delete($id);
                                    continue;
                                }
                                $existing = $existingById[$id];
                                $name = trim((string) ($row['name'] ?? ''));
                                $cancellationReasonService->update($id, [
                                    'code' => (string) ($existing['code'] ?? ''),
                                    'name' => $name,
                                    'applies_to' => (string) ($existing['applies_to'] ?? 'both'),
                                    'sort_order' => (int) ($existing['sort_order'] ?? 0),
                                    'is_active' => true,
                                ]);
                            }
                            $settingsService->patchCancellationSettings(['reason_required' => $required], null);
                            flash('success', 'Cancellation reasons saved.');
                            header('Location: ' . $redirectBase);
                            exit;
                        }
                    } else {
                        flash('error', 'Unsupported cancellation reason action.');
                    }
                } catch (\Throwable $e) {
                    flash('error', $e->getMessage());
                    flash('cancellation_reasons_old', [
                        'rows' => is_array($_POST['reason_rows'] ?? null) ? $_POST['reason_rows'] : [],
                        'new_reason_name' => (string) ($_POST['new_reason_name'] ?? ''),
                        'reason_required' => self::boolFromScalar($_POST['reason_required'] ?? '0'),
                    ]);
                    header('Location: ' . $editRedirect);
                    exit;
                }
                header('Location: ' . $redirectBase);
                exit;
            }
        }

        $rawPost = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
        $post = self::scopedPostForSection($rawPost, $activeSection);
        $strippedKeys = self::collectStrippedRawSettingsKeys($rawPost, $activeSection);
        $unknownRawKeys = self::collectUnknownRawKeysFromSettingsPost($rawPost);
        $postedSettingsKeys = self::collectPostedSettingsKeyNames($rawPost);
        $scopedSettingsKeys = self::collectScopedSettingsKeyNames($post);

        $establishmentPatch = [];
        foreach (self::ESTABLISHMENT_WRITE_KEYS as $key) {
            if (array_key_exists($key, $post)) {
                $establishmentPatch[str_replace('establishment.', '', $key)] = $post[$key];
            }
        }
        if ($establishmentPatch !== []) {
            try {
                $changed = $settingsService->patchEstablishmentSettings($establishmentPatch, null);
                self::auditSettingsUpdated($audit, 'establishment', $changed, null, $unknownRawKeys);
            } catch (\InvalidArgumentException $e) {
                flash('error', $e->getMessage());
                header('Location: ' . $redirectBase);
                exit;
            }
        }

        if ($activeSection === 'cancellation') {
            $cancellationPatch = [];
            if (array_key_exists('cancellation.enabled', $post)) {
                $cancellationPatch['enabled'] = self::boolFromScalar($post['cancellation.enabled']);
            }
            if (array_key_exists('cancellation.customer_scope', $post)) {
                $cancellationPatch['customer_scope'] = (string) $post['cancellation.customer_scope'];
            }
            if (array_key_exists('cancellation.min_notice_hours', $post)) {
                $cancellationPatch['min_notice_hours'] = (int) $post['cancellation.min_notice_hours'];
            }
            if (array_key_exists('cancellation.fee_mode', $post)) {
                $cancellationPatch['fee_mode'] = (string) $post['cancellation.fee_mode'];
            }
            if (array_key_exists('cancellation.fee_fixed_amount', $post)) {
                $cancellationPatch['fee_fixed_amount'] = (float) $post['cancellation.fee_fixed_amount'];
            }
            if (array_key_exists('cancellation.fee_percent', $post)) {
                $cancellationPatch['fee_percent'] = (float) $post['cancellation.fee_percent'];
            }
            if (array_key_exists('cancellation.staff_payout_mode', $post)) {
                $cancellationPatch['staff_payout_mode'] = (string) $post['cancellation.staff_payout_mode'];
            }
            if (array_key_exists('cancellation.staff_payout_percent', $post)) {
                $cancellationPatch['staff_payout_percent'] = (float) $post['cancellation.staff_payout_percent'];
            }
            if (array_key_exists('cancellation.no_show_same_as_cancellation', $post)) {
                $cancellationPatch['no_show_same_as_cancellation'] = self::boolFromScalar($post['cancellation.no_show_same_as_cancellation']);
            }
            if (array_key_exists('cancellation.no_show_fee_mode', $post)) {
                $cancellationPatch['no_show_fee_mode'] = (string) $post['cancellation.no_show_fee_mode'];
            }
            if (array_key_exists('cancellation.no_show_fee_fixed_amount', $post)) {
                $cancellationPatch['no_show_fee_fixed_amount'] = (float) $post['cancellation.no_show_fee_fixed_amount'];
            }
            if (array_key_exists('cancellation.no_show_fee_percent', $post)) {
                $cancellationPatch['no_show_fee_percent'] = (float) $post['cancellation.no_show_fee_percent'];
            }
            if (array_key_exists('cancellation.no_show_staff_payout_mode', $post)) {
                $cancellationPatch['no_show_staff_payout_mode'] = (string) $post['cancellation.no_show_staff_payout_mode'];
            }
            if (array_key_exists('cancellation.no_show_staff_payout_percent', $post)) {
                $cancellationPatch['no_show_staff_payout_percent'] = (float) $post['cancellation.no_show_staff_payout_percent'];
            }
            if (array_key_exists('cancellation.course_same_as_cancellation', $post)) {
                $cancellationPatch['course_same_as_cancellation'] = self::boolFromScalar($post['cancellation.course_same_as_cancellation']);
            }
            if (array_key_exists('cancellation.course_fee_mode', $post)) {
                $cancellationPatch['course_fee_mode'] = (string) $post['cancellation.course_fee_mode'];
            }
            if (array_key_exists('cancellation.course_fee_fixed_amount', $post)) {
                $cancellationPatch['course_fee_fixed_amount'] = (float) $post['cancellation.course_fee_fixed_amount'];
            }
            if (array_key_exists('cancellation.course_fee_percent', $post)) {
                $cancellationPatch['course_fee_percent'] = (float) $post['cancellation.course_fee_percent'];
            }
            if (array_key_exists('cancellation.reasons_enabled', $post)) {
                $cancellationPatch['reasons_enabled'] = self::boolFromScalar($post['cancellation.reasons_enabled']);
            }
            if (array_key_exists('cancellation.reason_required', $post)) {
                $cancellationPatch['reason_required'] = self::boolFromScalar($post['cancellation.reason_required']);
            }
            if (array_key_exists('cancellation.tax_enabled', $post)) {
                $cancellationPatch['tax_enabled'] = self::boolFromScalar($post['cancellation.tax_enabled']);
            }
            if (array_key_exists('cancellation.policy_text', $post)) {
                $cancellationPatch['policy_text'] = (string) $post['cancellation.policy_text'];
            }
            if (array_key_exists('cancellation.allow_privileged_override', $post)) {
                $cancellationPatch['allow_privileged_override'] = self::boolFromScalar($post['cancellation.allow_privileged_override']);
            }
            if ($cancellationPatch !== []) {
                $changed = $settingsService->patchCancellationSettings($cancellationPatch, null);
                self::auditSettingsUpdated($audit, 'cancellation', $changed, null, $unknownRawKeys);
            }
        }

        if ($activeSection === 'appointments') {
            $appointmentPatch = [];
            if (array_key_exists('appointments.min_lead_minutes', $post)) {
                $appointmentPatch['min_lead_minutes'] = (int) $post['appointments.min_lead_minutes'];
            }
            if (array_key_exists('appointments.max_days_ahead', $post)) {
                $appointmentPatch['max_days_ahead'] = (int) $post['appointments.max_days_ahead'];
            }
            if (array_key_exists('appointments.allow_past_booking', $post)) {
                $appointmentPatch['allow_past_booking'] = self::boolFromScalar($post['appointments.allow_past_booking']);
            }
            if (array_key_exists('appointments.allow_end_after_closing', $post)) {
                $appointmentPatch['allow_end_after_closing'] = self::boolFromScalar($post['appointments.allow_end_after_closing']);
            }
            if (array_key_exists('appointments.check_staff_availability_in_search', $post)) {
                $appointmentPatch['check_staff_availability_in_search'] = self::boolFromScalar($post['appointments.check_staff_availability_in_search']);
            }
            if (array_key_exists('appointments.allow_staff_booking_on_off_days', $post)) {
                $appointmentPatch['allow_staff_booking_on_off_days'] = self::boolFromScalar($post['appointments.allow_staff_booking_on_off_days']);
            }
            if (array_key_exists('appointments.allow_room_overbooking', $post)) {
                $appointmentPatch['allow_room_overbooking'] = self::boolFromScalar($post['appointments.allow_room_overbooking']);
            }
            if (array_key_exists('appointments.allow_staff_concurrency', $post)) {
                $appointmentPatch['allow_staff_concurrency'] = self::boolFromScalar($post['appointments.allow_staff_concurrency']);
            }
            if (array_key_exists('appointments.no_show_alert_enabled', $post)) {
                $appointmentPatch['no_show_alert_enabled'] = self::boolFromScalar($post['appointments.no_show_alert_enabled']);
            }
            if (array_key_exists('appointments.no_show_alert_threshold', $post)) {
                $appointmentPatch['no_show_alert_threshold'] = max(1, min(99, (int) $post['appointments.no_show_alert_threshold']));
            }
            if (array_key_exists('appointments.calendar_service_show_start_time', $post)) {
                $appointmentPatch['calendar_service_show_start_time'] = self::boolFromScalar($post['appointments.calendar_service_show_start_time']);
            }
            if (array_key_exists('appointments.calendar_service_label_mode', $post)) {
                $rawMode = strtolower(trim((string) $post['appointments.calendar_service_label_mode']));
                $allowedModes = ['client_and_service', 'service_and_client', 'service_only', 'client_only'];
                $appointmentPatch['calendar_service_label_mode'] = in_array($rawMode, $allowedModes, true)
                    ? $rawMode
                    : 'client_and_service';
            }
            if (array_key_exists('appointments.calendar_series_show_start_time', $post)) {
                $appointmentPatch['calendar_series_show_start_time'] = self::boolFromScalar($post['appointments.calendar_series_show_start_time']);
            }
            if (array_key_exists('appointments.calendar_series_label_mode', $post)) {
                $rawSeriesMode = strtolower(trim((string) $post['appointments.calendar_series_label_mode']));
                $allowedModes = ['client_and_service', 'service_and_client', 'service_only', 'client_only'];
                $appointmentPatch['calendar_series_label_mode'] = in_array($rawSeriesMode, $allowedModes, true)
                    ? $rawSeriesMode
                    : 'client_and_service';
            }
            if (array_key_exists('appointments.prebook_display_enabled', $post)) {
                $appointmentPatch['prebook_display_enabled'] = self::boolFromScalar($post['appointments.prebook_display_enabled']);
            }
            if (array_key_exists('appointments.prebook_threshold_value', $post)) {
                $appointmentPatch['prebook_threshold_value'] = max(1, min(9999, (int) $post['appointments.prebook_threshold_value']));
            }
            if (array_key_exists('appointments.prebook_threshold_unit', $post)) {
                $rawUnit = strtolower(trim((string) $post['appointments.prebook_threshold_unit']));
                $appointmentPatch['prebook_threshold_unit'] = in_array($rawUnit, ['hours', 'minutes'], true)
                    ? $rawUnit
                    : 'hours';
            }
            if (array_key_exists('appointments.client_itinerary_show_staff', $post)) {
                $appointmentPatch['client_itinerary_show_staff'] = self::boolFromScalar($post['appointments.client_itinerary_show_staff']);
            }
            if (array_key_exists('appointments.client_itinerary_show_space', $post)) {
                $appointmentPatch['client_itinerary_show_space'] = self::boolFromScalar($post['appointments.client_itinerary_show_space']);
            }
            if (array_key_exists('appointments.print_show_staff_appointment_list', $post)) {
                $appointmentPatch['print_show_staff_appointment_list'] = self::boolFromScalar($post['appointments.print_show_staff_appointment_list']);
            }
            if (array_key_exists('appointments.print_show_client_service_history', $post)) {
                $appointmentPatch['print_show_client_service_history'] = self::boolFromScalar($post['appointments.print_show_client_service_history']);
            }
            if (array_key_exists('appointments.print_show_package_detail', $post)) {
                $appointmentPatch['print_show_package_detail'] = self::boolFromScalar($post['appointments.print_show_package_detail']);
            }
            if (array_key_exists('appointments.print_show_client_product_purchase_history', $post)) {
                $appointmentPatch['print_show_client_product_purchase_history'] = self::boolFromScalar($post['appointments.print_show_client_product_purchase_history']);
            }
            if ($appointmentPatch !== []) {
                $appointmentSaveBranchId = $appointmentsContextBranchId;
                $changed = $settingsService->patchAppointmentSettings($appointmentPatch, $appointmentSaveBranchId);
                $auditBranch = $appointmentSaveBranchId > 0 ? $appointmentSaveBranchId : null;
                self::auditSettingsUpdated($audit, 'appointments', $changed, $auditBranch, $unknownRawKeys);
            }
        }

        if ($activeSection === 'public_channels') {
            $onlineBookingPatch = [];
            if (array_key_exists('online_booking.enabled', $post)) {
                $onlineBookingPatch['enabled'] = self::boolFromScalar($post['online_booking.enabled']);
            }
            if (array_key_exists('online_booking.public_api_enabled', $post)) {
                $onlineBookingPatch['public_api_enabled'] = self::boolFromScalar($post['online_booking.public_api_enabled']);
            }
            if (array_key_exists('online_booking.min_lead_minutes', $post)) {
                $onlineBookingPatch['min_lead_minutes'] = (int) $post['online_booking.min_lead_minutes'];
            }
            if (array_key_exists('online_booking.max_days_ahead', $post)) {
                $onlineBookingPatch['max_days_ahead'] = (int) $post['online_booking.max_days_ahead'];
            }
            if (array_key_exists('online_booking.allow_new_clients', $post)) {
                $onlineBookingPatch['allow_new_clients'] = self::boolFromScalar($post['online_booking.allow_new_clients']);
            }
            if ($onlineBookingPatch !== []) {
                $changed = $settingsService->patchOnlineBookingSettings($onlineBookingPatch, $publicSettingsSaveBranch);
                self::auditSettingsUpdated($audit, 'online_booking', $changed, $publicSettingsSaveBranch, $unknownRawKeys);
            }

            $intakePatch = [];
            if (array_key_exists('intake.public_enabled', $post)) {
                $intakePatch['public_enabled'] = self::boolFromScalar($post['intake.public_enabled']);
            }
            if ($intakePatch !== []) {
                $changed = $settingsService->patchIntakeSettings($intakePatch, $publicSettingsSaveBranch);
                self::auditSettingsUpdated($audit, 'intake', $changed, $publicSettingsSaveBranch, $unknownRawKeys);
            }

            $publicCommercePatch = [];
            if (array_key_exists('public_commerce.enabled', $post)) {
                $publicCommercePatch['enabled'] = self::boolFromScalar($post['public_commerce.enabled']);
            }
            if (array_key_exists('public_commerce.public_api_enabled', $post)) {
                $publicCommercePatch['public_api_enabled'] = self::boolFromScalar($post['public_commerce.public_api_enabled']);
            }
            if (array_key_exists('public_commerce.allow_gift_cards', $post)) {
                $publicCommercePatch['allow_gift_cards'] = self::boolFromScalar($post['public_commerce.allow_gift_cards']);
            }
            if (array_key_exists('public_commerce.allow_packages', $post)) {
                $publicCommercePatch['allow_packages'] = self::boolFromScalar($post['public_commerce.allow_packages']);
            }
            if (array_key_exists('public_commerce.allow_memberships', $post)) {
                $publicCommercePatch['allow_memberships'] = self::boolFromScalar($post['public_commerce.allow_memberships']);
            }
            if (array_key_exists('public_commerce.allow_new_clients', $post)) {
                $publicCommercePatch['allow_new_clients'] = self::boolFromScalar($post['public_commerce.allow_new_clients']);
            }
            if (array_key_exists('public_commerce.gift_card_min_amount', $post)) {
                $publicCommercePatch['gift_card_min_amount'] = (float) $post['public_commerce.gift_card_min_amount'];
            }
            if (array_key_exists('public_commerce.gift_card_max_amount', $post)) {
                $publicCommercePatch['gift_card_max_amount'] = (float) $post['public_commerce.gift_card_max_amount'];
            }
            if ($publicCommercePatch !== []) {
                $changed = $settingsService->patchPublicCommerceSettings($publicCommercePatch, $publicSettingsSaveBranch);
                self::auditSettingsUpdated($audit, 'public_commerce', $changed, $publicSettingsSaveBranch, $unknownRawKeys);
            }
        }

        if ($activeSection === 'payments') {
            $paymentPatch = [];
            if (array_key_exists('payments.default_method_code', $post)) {
                $paymentPatch['default_method_code'] = $post['payments.default_method_code'];
            }
            if (array_key_exists('payments.allow_partial_payments', $post)) {
                $paymentPatch['allow_partial_payments'] = self::boolFromScalar($post['payments.allow_partial_payments']);
            }
            if (array_key_exists('payments.allow_overpayments', $post)) {
                $paymentPatch['allow_overpayments'] = self::boolFromScalar($post['payments.allow_overpayments']);
            }
            if (array_key_exists('payments.receipt_notes', $post)) {
                $paymentPatch['receipt_notes'] = $post['payments.receipt_notes'];
            }
            if ($paymentPatch !== []) {
                try {
                    $changed = $settingsService->patchPaymentSettings($paymentPatch, null, $paymentsContextBranchId);
                } catch (\InvalidArgumentException $e) {
                    flash('error', $e->getMessage());
                    header('Location: ' . $redirectBase);
                    exit;
                }
                self::auditSettingsUpdated($audit, 'payments', $changed, null, $unknownRawKeys);
            }

            $receiptPatch = self::receiptInvoicePatchFromPost($post);
            if ($receiptPatch !== []) {
                $receiptSaveBranch = $paymentsContextBranchId > 0 ? $paymentsContextBranchId : null;
                $changed = $settingsService->patchReceiptInvoiceSettings($receiptPatch, $receiptSaveBranch);
                if ($changed !== []) {
                    self::auditSettingsUpdated($audit, 'receipt_invoice', $changed, $receiptSaveBranch, $unknownRawKeys);
                }
            }
        }

        if ($activeSection === 'waitlist') {
            $waitlistPatch = [];
            if (array_key_exists('waitlist.enabled', $post)) {
                $waitlistPatch['enabled'] = self::boolFromScalar($post['waitlist.enabled']);
            }
            if (array_key_exists('waitlist.auto_offer_enabled', $post)) {
                $waitlistPatch['auto_offer_enabled'] = self::boolFromScalar($post['waitlist.auto_offer_enabled']);
            }
            if (array_key_exists('waitlist.max_active_per_client', $post)) {
                $waitlistPatch['max_active_per_client'] = (int) $post['waitlist.max_active_per_client'];
            }
            if (array_key_exists('waitlist.default_expiry_minutes', $post)) {
                $waitlistPatch['default_expiry_minutes'] = (int) $post['waitlist.default_expiry_minutes'];
            }
            if ($waitlistPatch !== []) {
                $waitlistSaveBranch = $waitlistContextBranchId > 0 ? $waitlistContextBranchId : null;
                $changed = $settingsService->patchWaitlistSettings($waitlistPatch, $waitlistSaveBranch);
                self::auditSettingsUpdated($audit, 'waitlist', $changed, $waitlistSaveBranch, $unknownRawKeys);
            }
        }

        if ($activeSection === 'marketing') {
            $marketingPatch = [];
            if (array_key_exists('marketing.default_opt_in', $post)) {
                $marketingPatch['default_opt_in'] = self::boolFromScalar($post['marketing.default_opt_in']);
            }
            if (array_key_exists('marketing.consent_label', $post)) {
                $marketingPatch['consent_label'] = $post['marketing.consent_label'];
            }
            if ($marketingPatch !== []) {
                $marketingSaveBranch = $marketingContextBranchId > 0 ? $marketingContextBranchId : null;
                $changed = $settingsService->patchMarketingSettings($marketingPatch, $marketingSaveBranch);
                self::auditSettingsUpdated($audit, 'marketing', $changed, $marketingSaveBranch, $unknownRawKeys);
            }
        }

        if ($activeSection === 'security') {
            $securityPatch = [];
            if (array_key_exists('security.password_expiration', $post)) {
                $securityPatch['password_expiration'] = $post['security.password_expiration'];
            }
            if (array_key_exists('security.inactivity_timeout_minutes', $post)) {
                $securityPatch['inactivity_timeout_minutes'] = (int) $post['security.inactivity_timeout_minutes'];
            }
            if ($securityPatch !== []) {
                try {
                    $changed = $settingsService->patchSecuritySettings($securityPatch, null);
                    self::auditSettingsUpdated($audit, 'security', $changed, null, $unknownRawKeys);
                } catch (\InvalidArgumentException $e) {
                    flash('error', $e->getMessage());
                    header('Location: ' . $redirectBase);
                    exit;
                }
            }
        }

        if ($activeSection === 'notifications') {
            $notificationsPatch = [];
            $nPrefix = 'notifications.';
            foreach (self::NOTIFICATIONS_WRITE_KEYS as $fullKey) {
                if (!str_starts_with($fullKey, $nPrefix)) {
                    continue;
                }
                if (array_key_exists($fullKey, $post)) {
                    $short = substr($fullKey, strlen($nPrefix));
                    $notificationsPatch[$short] = self::boolFromScalar($post[$fullKey]);
                }
            }
            if ($notificationsPatch !== []) {
                $changed = $settingsService->patchNotificationSettings($notificationsPatch, null);
                self::auditSettingsUpdated($audit, 'notifications', $changed, null, $unknownRawKeys);
            }
        }

        $hardwarePatch = [];
        if (array_key_exists('hardware.use_cash_register', $post)) {
            $hardwarePatch['use_cash_register'] = self::boolFromScalar($post['hardware.use_cash_register']);
        }
        if (array_key_exists('hardware.use_receipt_printer', $post)) {
            $hardwarePatch['use_receipt_printer'] = self::boolFromScalar($post['hardware.use_receipt_printer']);
        }
        if ($hardwarePatch !== []) {
            $changed = $settingsService->patchHardwareSettings($hardwarePatch, null);
            self::auditSettingsUpdated($audit, 'hardware', $changed, null, $unknownRawKeys);
        }

        if ($activeSection === 'memberships') {
            $membershipsPatch = [];
            if (array_key_exists('memberships.terms_text', $post)) {
                $membershipsPatch['terms_text'] = $post['memberships.terms_text'];
            }
            if (array_key_exists('memberships.renewal_reminder_days', $post)) {
                $membershipsPatch['renewal_reminder_days'] = (int) $post['memberships.renewal_reminder_days'];
            }
            if (array_key_exists('memberships.grace_period_days', $post)) {
                $membershipsPatch['grace_period_days'] = (int) $post['memberships.grace_period_days'];
            }
            if ($membershipsPatch !== []) {
                $changed = $settingsService->patchMembershipSettings($membershipsPatch, null);
                self::auditSettingsUpdated($audit, 'memberships', $changed, null, $unknownRawKeys);
            }
        }
        if ($strippedKeys !== []) {
            $audit->log('settings_stripped_keys_ignored', 'settings', null, null, null, [
                'section' => $activeSection,
                'stripped_keys' => $strippedKeys,
                'count' => count($strippedKeys),
                'posted_settings_keys' => $postedSettingsKeys,
                'scoped_settings_keys' => $scopedSettingsKeys,
            ]);
        }
        if ($unknownRawKeys !== []) {
            $audit->log('settings_unknown_keys_ignored', 'settings', null, null, null, [
                'unknown_raw_keys' => $unknownRawKeys,
                'ignored_keys' => $unknownRawKeys,
                'count' => count($unknownRawKeys),
                'posted_settings_keys' => $postedSettingsKeys,
                'scoped_settings_keys' => $scopedSettingsKeys,
            ]);
        }
        if ($unknownRawKeys !== [] || $strippedKeys !== []) {
            $parts = [];
            if ($unknownRawKeys !== []) {
                $parts[] = 'Some settings keys were not recognized and were ignored.';
            }
            if ($strippedKeys !== []) {
                $parts[] = 'Some form fields are not valid for this settings page and were ignored.';
            }
            flash('error', implode(' ', $parts));
        } else {
            flash('success', 'Settings saved.');
        }
        header('Location: ' . $redirectBase);
        exit;
    }
}
