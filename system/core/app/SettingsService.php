<?php

declare(strict_types=1);

namespace Core\App;

use Core\Contracts\SharedCacheInterface;
use Core\Errors\AccessDeniedException;
use Core\Organization\OrganizationContext;
use Modules\Sales\Services\PaymentMethodService;

/**
 * Settings-driven config. Supports type, group, organization_id, branch_id.
 * Types: string, int, float, bool, json.
 *
 * **Authoritative tenant-safe precedence (single resolver):** all reads go through {@see get()} and/or {@see all()}:
 * - branch override: `(organization_id = O, branch_id = B)`
 * - organization default: `(organization_id = O, branch_id = 0)`
 * - platform default: `(organization_id = 0, branch_id = 0)`
 * For `branchId` null/0 with resolved organization context, reads resolve organization default then platform default.
 * Group helpers (`getCancellationSettings`, `getAppointmentSettings`, …) pass the branch id through to `get()` / `all()` with the same rule.
 * **Validated defaults** are the final fallback when no row exists at either level (per-key defaults in each getter).
 *
 * **Writes:** `set()` resolves organization scope explicitly:
 * - `branch_id > 0` -> branch override for that branch's organization
 * - `branch_id = 0` + resolved org context -> organization default
 * - otherwise -> platform default (`organization_id = 0`, `branch_id = 0`)
 *
 * ---
 * Stored keys vs runtime enforcement (repo truth; admin UI labels may differ):
 *
 * | Group / keys | Written via | Read/enforced at runtime |
 * | --- | --- | --- |
 * | establishment.* | SettingsController, patch/set helpers | Currency: {@see getEffectiveCurrencyCode()} (invoices, public commerce, etc.). Language: {@see getEffectiveEstablishmentLanguageTag()} → HTTP `Content-Language` after {@see \Core\Middleware\BranchContextMiddleware} (branch merge; no `setlocale`). Timezone: {@see \Core\App\ApplicationTimezone} (pre-middleware bootstrap + {@see ApplicationTimezone::syncAfterBranchContextResolved()} after branch resolution). Other fields: display metadata. Legacy fallbacks: company_name, currency_code, timezone rows. |
 * | cancellation.* | idem | {@see \Modules\Appointments\Services\AppointmentService::cancel} (branch on appointment). |
 * | appointments.* | idem (branch override + org default; {@see \Modules\Settings\Controllers\SettingsController} appointment scope selector) | Booking window: {@see \Modules\Appointments\Services\AppointmentService::validateTimes} (appointment `branch_id`). Branch operating-hours window: {@see \Modules\Appointments\Services\AppointmentService} (assert within hours; `appointments.allow_end_after_closing` relaxes end-after-close only). Slot search: {@see \Modules\Appointments\Services\AvailabilityService::getAvailableSlots} — `check_staff_availability_in_search` + internal vs public audience; `allow_staff_booking_on_off_days` internal-only off-day synthetic branch window (public booking/slots strict); internal optional `room_id` occupancy filter (FOUNDATION-12). Calendar / client profile Appointment History read-side (`client_itinerary_show_*`) as documented. Independent from online_booking.*. |
 * | online_booking.* | idem | {@see \Modules\OnlineBooking\Services\PublicBookingService}: anonymous slots/book/consent-check use enabled + `public_api_enabled`; token manage (lookup/cancel/reschedule) uses `enabled` only + active branch. |
 * | intake.* | SettingsController, patch helper | {@see \Modules\Intake\Services\IntakeFormService}: anonymous token URLs require branch row not soft-deleted when `assignment.branch_id` is set, plus branch-effective {@see getIntakeSettings()} `public_enabled` (default true when unset; global `branch_id = 0` merged with branch overlay per {@see get()}). |
 * | public_commerce.* | idem | {@see \Modules\PublicCommerce\Services\PublicCommerceService} gates, catalog rules, gift card min/max; {@see \Modules\GiftCards\Services\GiftCardService} amount bounds. |
 * | payments.* | idem | **A-005:** recorded-payment **policy** (`default_method_code`, partial/overpay, org-level `receipt_notes` from Payments admin) read via {@see getPaymentSettings(null)} in {@see \Modules\Sales\Services\PaymentService} / {@see \Modules\Sales\Controllers\PaymentController}. Receipt footer text may still merge branch-effective `receipt_invoice.*` via {@see getEffectiveReceiptFooterText}. |
 * | waitlist.* | idem | {@see \Modules\Appointments\Services\WaitlistService} (enabled, auto_offer, max active, expiry). |
 * | marketing.* | idem | {@see \Modules\Clients\Services\PublicClientResolutionService}, {@see \Modules\Clients\Controllers\ClientController}, {@see \Modules\Clients\Services\ClientRegistrationService} (defaults for new clients). |
 * | security.* | idem | **A-005:** {@see \Core\Middleware\AuthMiddleware} reads {@see getSecuritySettings(null)} only (matches Settings security section; branch `security.*` rows are not enforcement inputs). |
 * | notifications.* | idem | **A-005:** {@see shouldEmitInAppNotificationForType} / {@see shouldEmitOutboundNotificationForEvent} read {@see getNotificationSettings(null)} for toggles (admin Notifications section is org-only; `$branchId` args kept for API stability). Prefix rules unchanged. |
 * | hardware.* | idem | **A-005:** cash-register requirement + receipt-print dispatch gate in {@see \Modules\Sales\Services\PaymentService} / {@see \Modules\Sales\Services\InvoiceService} use {@see getHardwareSettings(null)} / {@see isReceiptPrintingEnabled(null)} (Hardware admin is org-only). |
 * | memberships.* | idem | terms: {@see membershipTermsDocumentBlock}; grace/renewal: {@see \Modules\Memberships\Services\MembershipService}, {@see \Modules\Memberships\Services\MembershipLifecycleService}, renewal reminder batch. |
 *
 * VAT rates and payment *methods* are separate tables/controllers ({@see \Modules\Settings\Controllers\VatRatesController}, {@see \Modules\Settings\Controllers\PaymentMethodsController}), not rows in `settings`.
 *
 * **Read-scope inventory (operator vs entity vs public):** `system/docs/SETTINGS-READ-SCOPE.md` (SETTINGS-BRANCH-EFFECTIVE-CALLSITE-SEAL-01).
 *
 * **Lower-half HTML settings workspace vs runtime:** {@see \Modules\Settings\Controllers\SettingsController} loads and saves
 * `waitlist.*`, `marketing.*`, `security.*`, `notifications.*`, `hardware.*`, and `memberships.*` with branch argument `null` only
 * (organization default row, `branch_id = 0`). **A-005:** {@see \Core\Middleware\AuthMiddleware}, notification policy gates above,
 * and sales payment **policy** + hardware **cash register / receipt printer enable** paths read those org defaults for enforcement
 * (no hidden branch-only rows for those surfaces). Other domains (e.g. waitlist operations, client marketing reads) may still use
 * branch-effective merges where the UI or entity row supplies branch scope.
 */
final class SettingsService
{
    /** Establishment / general settings keys (grouped). */
    public const ESTABLISHMENT_KEYS = [
        'establishment.name',
        'establishment.phone',
        'establishment.email',
        'establishment.address',
        'establishment.currency',
        'establishment.timezone',
        'establishment.language',
        'establishment.secondary_contact_first_name',
        'establishment.secondary_contact_last_name',
        'establishment.secondary_contact_phone',
        'establishment.secondary_contact_email',
    ];

    private const ESTABLISHMENT_GROUP = 'establishment';

    /** Max lengths for establishment fields (sanitization). */
    private const MAX_LEN = [
        'establishment.name' => 255,
        'establishment.phone' => 50,
        'establishment.email' => 255,
        'establishment.address' => 500,
        'establishment.currency' => 10,
        'establishment.timezone' => 50,
        'establishment.language' => 20,
        'establishment.secondary_contact_first_name' => 100,
        'establishment.secondary_contact_last_name' => 100,
        'establishment.secondary_contact_phone' => 50,
        'establishment.secondary_contact_email' => 255,
    ];

    /** Cancellation policy keys (group: cancellation). */
    public const CANCELLATION_KEYS = [
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
    ];
    private const CANCELLATION_GROUP = 'cancellation';
    /** @var list<string> */
    private const CANCELLATION_CUSTOMER_SCOPE_VALUES = ['all', 'new_only', 'existing_only'];
    /** @var list<string> */
    private const CANCELLATION_FEE_MODE_VALUES = ['none', 'full', 'fixed', 'percent'];
    /** @var list<string> */
    private const CANCELLATION_STAFF_PAYOUT_MODE_VALUES = ['none', 'full', 'percent'];
    private const CANCELLATION_POLICY_TEXT_MAX_LEN = 5000;

    /** Appointment settings keys (group: appointments): booking window + display / alert metadata. */
    public const APPOINTMENT_KEYS = [
        'appointments.min_lead_minutes',
        'appointments.max_days_ahead',
        'appointments.allow_past_booking',
        'appointments.no_show_alert_enabled',
        'appointments.no_show_alert_threshold',
        'appointments.calendar_service_show_start_time',
        'appointments.calendar_service_label_mode',
        'appointments.calendar_series_show_start_time',
        'appointments.calendar_series_label_mode',
        'appointments.prebook_display_enabled',
        'appointments.prebook_threshold_value',
        'appointments.prebook_threshold_unit',
        'appointments.prebook_threshold_hours',
        'appointments.allow_end_after_closing',
        'appointments.check_staff_availability_in_search',
        'appointments.allow_staff_booking_on_off_days',
        'appointments.allow_room_overbooking',
        'appointments.allow_staff_concurrency',
        'appointments.client_itinerary_show_staff',
        'appointments.client_itinerary_show_space',
        'appointments.print_show_staff_appointment_list',
        'appointments.print_show_client_service_history',
        'appointments.print_show_package_detail',
        'appointments.print_show_client_product_purchase_history',
    ];

    /**
     * Settings UI / POST allowlist: same as {@see APPOINTMENT_KEYS} except legacy
     * `appointments.prebook_threshold_hours` (read/normalize + programmatic patch only; not a form field).
     *
     * @var list<string>
     */
    public const APPOINTMENT_SETTINGS_FORM_KEYS = [
        'appointments.min_lead_minutes',
        'appointments.max_days_ahead',
        'appointments.allow_past_booking',
        'appointments.no_show_alert_enabled',
        'appointments.no_show_alert_threshold',
        'appointments.calendar_service_show_start_time',
        'appointments.calendar_service_label_mode',
        'appointments.calendar_series_show_start_time',
        'appointments.calendar_series_label_mode',
        'appointments.prebook_display_enabled',
        'appointments.prebook_threshold_value',
        'appointments.prebook_threshold_unit',
        'appointments.allow_end_after_closing',
        'appointments.check_staff_availability_in_search',
        'appointments.allow_staff_booking_on_off_days',
        'appointments.allow_room_overbooking',
        'appointments.allow_staff_concurrency',
        'appointments.client_itinerary_show_staff',
        'appointments.client_itinerary_show_space',
        'appointments.print_show_staff_appointment_list',
        'appointments.print_show_client_service_history',
        'appointments.print_show_package_detail',
        'appointments.print_show_client_product_purchase_history',
    ];
    private const APPOINTMENT_GROUP = 'appointments';
    /** @var list<string> */
    private const APPOINTMENT_CALENDAR_LABEL_MODE_VALUES = [
        'client_and_service',
        'service_and_client',
        'service_only',
        'client_only',
    ];
    /** @var list<string> */
    private const APPOINTMENT_PREBOOK_THRESHOLD_UNIT_VALUES = ['hours', 'minutes'];

    /** Online booking keys (group: online_booking). */
    public const ONLINE_BOOKING_KEYS = [
        'online_booking.enabled',
        'online_booking.public_api_enabled',
        'online_booking.min_lead_minutes',
        'online_booking.max_days_ahead',
        'online_booking.allow_new_clients',
    ];
    private const ONLINE_BOOKING_GROUP = 'online_booking';

    /** Public intake token links (group: intake). Independent from online_booking.* */
    public const INTAKE_KEYS = [
        'intake.public_enabled',
    ];
    private const INTAKE_GROUP = 'intake';

    /** Public online commerce (separate from booking). All booleans default false except allow_new_clients (true when unset). */
    public const PUBLIC_COMMERCE_KEYS = [
        'public_commerce.enabled',
        'public_commerce.public_api_enabled',
        'public_commerce.allow_gift_cards',
        'public_commerce.allow_packages',
        'public_commerce.allow_memberships',
        'public_commerce.allow_new_clients',
        'public_commerce.gift_card_min_amount',
        'public_commerce.gift_card_max_amount',
    ];
    private const PUBLIC_COMMERCE_GROUP = 'public_commerce';

    /** Payment settings keys (group: payments). */
    public const PAYMENT_KEYS = [
        'payments.default_method_code',
        'payments.allow_partial_payments',
        'payments.allow_overpayments',
        'payments.receipt_notes',
    ];
    private const PAYMENT_GROUP = 'payments';

    /**
     * Desktop/internal invoice & receipt presentation (group: receipt_invoice).
     * Consumed by {@see \Modules\Sales\Services\ReceiptInvoicePresentationService} and invoice show view.
     * Org/branch merge follows {@see get()} with $branchId (0 = organization default).
     */
    public const RECEIPT_INVOICE_KEYS = [
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

    /** @var list<string> Short keys (POST/controller patch) for boolean receipt_invoice fields. */
    public const RECEIPT_INVOICE_BOOL_SHORT_KEYS = [
        'show_establishment_name',
        'show_establishment_address',
        'show_establishment_phone',
        'show_establishment_email',
        'show_client_block',
        'show_client_phone',
        'show_client_address',
        'show_recorded_by',
        'show_item_barcode',
    ];
    private const RECEIPT_INVOICE_GROUP = 'receipt_invoice';
    /** @var list<string> */
    private const RECEIPT_INVOICE_SORT_MODES = ['as_entered', 'description_asc'];

    /** Waitlist settings keys (group: waitlist). */
    public const WAITLIST_KEYS = [
        'waitlist.enabled',
        'waitlist.auto_offer_enabled',
        'waitlist.max_active_per_client',
        'waitlist.default_expiry_minutes',
    ];
    private const WAITLIST_GROUP = 'waitlist';

    /** Marketing settings keys (group: marketing). */
    public const MARKETING_KEYS = [
        'marketing.default_opt_in',
        'marketing.consent_label',
        'marketing.automations_external_scheduler_acknowledged',
    ];
    private const MARKETING_GROUP = 'marketing';

    /**
     * Operator-recorded acknowledgment that an external scheduler (e.g. cron) runs
     * `system/scripts/marketing_automations_execute.php`. Not proof the job runs.
     */
    public const MARKETING_AUTOMATIONS_SCHEDULER_ACK_KEY = 'marketing.automations_external_scheduler_acknowledged';

    /** Security settings keys (group: security). Constrained values only. */
    public const SECURITY_KEYS = [
        'security.password_expiration',
        'security.inactivity_timeout_minutes',
    ];
    private const SECURITY_GROUP = 'security';
    private const PASSWORD_EXPIRATION_VALUES = ['never', '90_days'];
    private const INACTIVITY_TIMEOUT_VALUES = [15, 30, 120];

    /**
     * Internal notification toggles (group: notifications). Operational mapping:
     * - In-app: {@see shouldEmitInAppNotificationForType} (appointment_/waitlist_/membership_/payment_ prefixes).
     * - Outbound transactional email: {@see shouldEmitOutboundNotificationForEvent} uses appointments/waitlist/membership only;
     *   {@see NOTIFICATIONS_KEYS} `sales_enabled` does **not** affect outbound (no payment.* enqueue in-repo).
     */
    public const NOTIFICATIONS_KEYS = [
        'notifications.appointments_enabled',
        'notifications.sales_enabled',
        'notifications.waitlist_enabled',
        'notifications.memberships_enabled',
    ];
    private const NOTIFICATIONS_GROUP = 'notifications';

    /** Hardware / device settings (group: hardware). Minimal: cash register usage, receipt printer (foundation). */
    public const HARDWARE_KEYS = [
        'hardware.use_cash_register',
        'hardware.use_receipt_printer',
    ];
    private const HARDWARE_GROUP = 'hardware';

    /** Membership settings (group: memberships). Foundation only; no membership module yet. Used when F2/F3 exist. */
    public const MEMBERSHIPS_KEYS = [
        'memberships.terms_text',
        'memberships.renewal_reminder_days',
        'memberships.grace_period_days',
    ];
    private const MEMBERSHIPS_GROUP = 'memberships';
    private const MEMBERSHIPS_TERMS_MAX_LEN = 5000;

    /** @var array<string, mixed> */
    private array $requestSettingsGetCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $requestSettingsAllCache = [];

    /** @var array<int, ?int> */
    private array $requestBranchOrganizationIdCache = [];

    /** Cross-request TTL cache for branch → organization (empty string in store = no org). */
    private const BRANCH_ORG_SHARED_CACHE_TTL_SECONDS = 120;

    /** Packed {@see self::getPublicCommerceSettings()} for anonymous catalog hot paths (invalidated on patch). */
    private const PUBLIC_COMMERCE_PACKED_CACHE_TTL_SECONDS = 45;

    /** Packed {@see self::getPaymentSettings()} (invalidated on {@see self::setPaymentSettings()}). */
    private const PAYMENT_SETTINGS_PACKED_CACHE_TTL_SECONDS = 45;

    /** Packed {@see self::getOnlineBookingSettings()} (invalidated on online booking writes/patches). */
    private const ONLINE_BOOKING_PACKED_CACHE_TTL_SECONDS = 45;

    /** Packed {@see self::getSecuritySettings()} (invalidated on security writes/patches). */
    private const SECURITY_SETTINGS_PACKED_CACHE_TTL_SECONDS = 45;

    /** Packed {@see self::getIntakeSettings()} (invalidated on intake patch). */
    private const INTAKE_SETTINGS_PACKED_CACHE_TTL_SECONDS = 45;

    /** Packed {@see self::getHardwareSettings()} (invalidated on hardware writes/patches). */
    private const HARDWARE_SETTINGS_PACKED_CACHE_TTL_SECONDS = 45;

    /** Packed {@see self::getNotificationSettings()} (invalidated on notification writes/patches). */
    private const NOTIFICATION_SETTINGS_PACKED_CACHE_TTL_SECONDS = 45;

    public function __construct(
        private Database $db,
        private OrganizationContext $organizationContext,
        private SharedCacheInterface $sharedCache
    ) {
    }

    private function clearRequestSettingsReadCache(): void
    {
        $this->requestSettingsGetCache = [];
        $this->requestSettingsAllCache = [];
        $this->requestBranchOrganizationIdCache = [];
    }

    private function settingsGetCacheKey(string $key, mixed $default, int $bid): string
    {
        try {
            $def = serialize($default);
        } catch (\Throwable) {
            $def = '*';
        }
        if ($bid > 0) {
            return $key . "\0" . $def . "\0b" . $bid;
        }
        $o = $this->resolvedOrganizationContextId();

        return $key . "\0" . $def . "\0o" . (string) ($o ?? 0);
    }

    private function settingsAllCacheKey(?string $settingGroup, int $bid): string
    {
        $g = $settingGroup ?? '';
        if ($bid > 0) {
            return $g . "\0b" . $bid;
        }
        $o = $this->resolvedOrganizationContextId();

        return $g . "\0o" . (string) ($o ?? 0);
    }

    private function resolvedOrganizationContextId(): ?int
    {
        $orgId = $this->organizationContext->getCurrentOrganizationId();

        return ($orgId !== null && $orgId > 0) ? $orgId : null;
    }

    private function publicCommerceSettingsSharedCacheKey(int $branchId): string
    {
        $o = $this->resolvedOrganizationContextId();

        return 'settings_v1:public_commerce_packed:b' . $branchId . ':o' . (string) ($o ?? 0);
    }

    private function paymentSettingsSharedCacheKey(int $branchId): string
    {
        $o = $this->resolvedOrganizationContextId();

        return 'settings_v1:payment_settings_packed:b' . $branchId . ':o' . (string) ($o ?? 0);
    }

    private function onlineBookingSettingsSharedCacheKey(int $branchId): string
    {
        $o = $this->resolvedOrganizationContextId();

        return 'settings_v1:online_booking_packed:b' . $branchId . ':o' . (string) ($o ?? 0);
    }

    private function securitySettingsSharedCacheKey(int $branchId): string
    {
        $o = $this->resolvedOrganizationContextId();

        return 'settings_v1:security_settings_packed:b' . $branchId . ':o' . (string) ($o ?? 0);
    }

    private function intakeSettingsSharedCacheKey(int $branchId): string
    {
        $o = $this->resolvedOrganizationContextId();

        return 'settings_v1:intake_settings_packed:b' . $branchId . ':o' . (string) ($o ?? 0);
    }

    private function hardwareSettingsSharedCacheKey(int $branchId): string
    {
        $o = $this->resolvedOrganizationContextId();

        return 'settings_v1:hardware_settings_packed:b' . $branchId . ':o' . (string) ($o ?? 0);
    }

    private function notificationSettingsSharedCacheKey(int $branchId): string
    {
        $o = $this->resolvedOrganizationContextId();

        return 'settings_v1:notification_settings_packed:b' . $branchId . ':o' . (string) ($o ?? 0);
    }

    private function organizationIdForActiveBranch(int $branchId): ?int
    {
        if ($branchId <= 0) {
            return null;
        }
        if (array_key_exists($branchId, $this->requestBranchOrganizationIdCache)) {
            return $this->requestBranchOrganizationIdCache[$branchId];
        }
        $scKey = 'settings_v1:branch_org:' . $branchId;
        $hit = $this->sharedCache->get($scKey);
        if ($hit !== null) {
            $resolved = $hit === '' ? null : (int) $hit;
            $this->requestBranchOrganizationIdCache[$branchId] = $resolved;

            return $resolved;
        }
        $row = $this->db->fetchOne(
            'SELECT b.organization_id AS organization_id
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
             WHERE b.id = ? AND b.deleted_at IS NULL
             LIMIT 1',
            [$branchId]
        );
        if ($row === null || !isset($row['organization_id'])) {
            $this->requestBranchOrganizationIdCache[$branchId] = null;
            $this->sharedCache->set($scKey, '', self::BRANCH_ORG_SHARED_CACHE_TTL_SECONDS);

            return null;
        }
        $orgId = (int) $row['organization_id'];
        $resolved = $orgId > 0 ? $orgId : null;
        $this->requestBranchOrganizationIdCache[$branchId] = $resolved;
        $this->sharedCache->set($scKey, $resolved === null ? '' : (string) $resolved, self::BRANCH_ORG_SHARED_CACHE_TTL_SECONDS);

        return $resolved;
    }

    /**
     * Read one setting with branch/global merge and optional default.
     *
     * Precedence: row at `(key, branch_id = B)` when `B = $branchId > 0` and present, else row at `(key, 0)`, else `$default`.
     */
    public function get(string $key, mixed $default = null, ?int $branchId = null): mixed
    {
        $bid = ($branchId !== null && $branchId > 0) ? $branchId : 0;
        $cacheKey = $this->settingsGetCacheKey($key, $default, $bid);
        if (array_key_exists($cacheKey, $this->requestSettingsGetCache)) {
            return $this->requestSettingsGetCache[$cacheKey];
        }
        if ($bid > 0) {
            $orgId = $this->organizationIdForActiveBranch($bid);
            if ($orgId === null) {
                $this->requestSettingsGetCache[$cacheKey] = $default;

                return $default;
            }
            $row = $this->db->fetchOne(
                'SELECT `value`, type
                 FROM settings
                 WHERE `key` = ?
                   AND (
                     (organization_id = ? AND branch_id = ?)
                     OR (organization_id = ? AND branch_id = 0)
                     OR (organization_id = 0 AND branch_id = 0)
                   )
                 ORDER BY CASE
                    WHEN organization_id = ? AND branch_id = ? THEN 1
                    WHEN organization_id = ? AND branch_id = 0 THEN 2
                    ELSE 3
                 END ASC
                 LIMIT 1',
                [$key, $orgId, $bid, $orgId, $orgId, $bid, $orgId]
            );
        } else {
            $orgId = $this->resolvedOrganizationContextId();
            if ($orgId !== null) {
                $row = $this->db->fetchOne(
                    'SELECT `value`, type
                     FROM settings
                     WHERE `key` = ?
                       AND (
                         (organization_id = ? AND branch_id = 0)
                         OR (organization_id = 0 AND branch_id = 0)
                       )
                     ORDER BY CASE
                        WHEN organization_id = ? AND branch_id = 0 THEN 1
                        ELSE 2
                     END ASC
                     LIMIT 1',
                    [$key, $orgId, $orgId]
                );
            } else {
                $row = $this->db->fetchOne(
                    'SELECT `value`, type FROM settings WHERE `key` = ? AND organization_id = 0 AND branch_id = 0 LIMIT 1',
                    [$key]
                );
            }
        }
        $resolved = !$row ? $default : $this->cast($row['value'], $row['type'] ?? 'string');
        $this->requestSettingsGetCache[$cacheKey] = $resolved;

        return $resolved;
    }

    public function set(string $key, mixed $value, string $type = 'string', ?string $settingGroup = null, ?int $branchId = null): void
    {
        $this->clearRequestSettingsReadCache();
        $encoded = $this->encode($value, $type);
        $branchId = ($branchId !== null && $branchId > 0) ? $branchId : 0;
        $organizationId = 0;
        if ($branchId > 0) {
            $branchOrgId = $this->organizationIdForActiveBranch($branchId);
            if ($branchOrgId === null) {
                throw new \InvalidArgumentException('Cannot write settings for inactive or unknown branch.');
            }
            $organizationId = $branchOrgId;
            $ctxOrgId = $this->resolvedOrganizationContextId();
            if ($ctxOrgId !== null && $ctxOrgId !== $organizationId) {
                throw new AccessDeniedException('Branch override does not belong to the resolved organization context.');
            }
        } else {
            $ctxOrgId = $this->resolvedOrganizationContextId();
            if ($ctxOrgId !== null) {
                $organizationId = $ctxOrgId;
            }
        }
        $this->db->query(
            'INSERT INTO settings (`key`, `value`, type, setting_group, organization_id, branch_id, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                `value` = VALUES(`value`),
                type = VALUES(type),
                setting_group = COALESCE(VALUES(setting_group), setting_group),
                updated_at = NOW()',
            [$key, $encoded, $type, $settingGroup, $organizationId, $branchId]
        );
    }

    public function getInt(string $key, int $default = 0, ?int $branchId = null): int
    {
        return (int) $this->get($key, $default, $branchId);
    }

    public function getFloat(string $key, float $default = 0.0, ?int $branchId = null): float
    {
        return (float) $this->get($key, $default, $branchId);
    }

    public function getBool(string $key, bool $default = false, ?int $branchId = null): bool
    {
        $v = $this->get($key, $default, $branchId);
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function getJson(string $key, ?array $default = null, ?int $branchId = null): ?array
    {
        $v = $this->get($key, $default, $branchId);
        return is_array($v) ? $v : (is_string($v) ? (json_decode($v, true) ?? $default) : $default);
    }

    public function all(?string $settingGroup = null, ?int $branchId = null): array
    {
        $bid = ($branchId !== null && $branchId > 0) ? $branchId : 0;
        $allKey = $this->settingsAllCacheKey($settingGroup, $bid);
        if (array_key_exists($allKey, $this->requestSettingsAllCache)) {
            return $this->requestSettingsAllCache[$allKey];
        }
        if ($bid > 0) {
            $orgId = $this->organizationIdForActiveBranch($bid);
            if ($orgId === null) {
                return [];
            }
            $sql = 'SELECT `key`, `value`, type, setting_group, organization_id, branch_id
                    FROM settings
                    WHERE
                      (
                        (organization_id = ? AND branch_id = ?)
                        OR (organization_id = ? AND branch_id = 0)
                        OR (organization_id = 0 AND branch_id = 0)
                      )';
            $params = [$orgId, $bid, $orgId];
        } else {
            $orgId = $this->resolvedOrganizationContextId();
            if ($orgId !== null) {
                $sql = 'SELECT `key`, `value`, type, setting_group, organization_id, branch_id
                        FROM settings
                        WHERE
                          (
                            (organization_id = ? AND branch_id = 0)
                            OR (organization_id = 0 AND branch_id = 0)
                          )';
                $params = [$orgId];
            } else {
                $sql = 'SELECT `key`, `value`, type, setting_group, organization_id, branch_id
                        FROM settings
                        WHERE organization_id = 0 AND branch_id = 0';
                $params = [];
            }
        }
        if ($settingGroup !== null) {
            $sql .= ' AND setting_group = ?';
            $params[] = $settingGroup;
        }
        if ($bid > 0 && isset($orgId)) {
            $sql .= ' ORDER BY CASE
                        WHEN organization_id = ' . (int) $orgId . ' AND branch_id = ' . (int) $bid . ' THEN 1
                        WHEN organization_id = ' . (int) $orgId . ' AND branch_id = 0 THEN 2
                        ELSE 3
                      END ASC';
        } elseif (isset($orgId) && $orgId !== null) {
            $sql .= ' ORDER BY CASE
                        WHEN organization_id = ' . (int) $orgId . ' AND branch_id = 0 THEN 1
                        ELSE 2
                      END ASC';
        }
        $rows = $this->db->fetchAll($sql, $params);
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if (!isset($seen[$row['key']])) {
                $seen[$row['key']] = true;
                $out[$row['key']] = $this->cast($row['value'], $row['type'] ?? 'string');
            }
        }
        $this->requestSettingsAllCache[$allKey] = $out;

        return $out;
    }

    /**
     * Read establishment/general settings as a keyed array (key without "establishment." prefix for convenience).
     * Falls back to legacy keys (company_name -> name, currency_code -> currency) when establishment key is missing.
     */
    public function getEstablishmentSettings(?int $branchId = null): array
    {
        $all = $this->all(self::ESTABLISHMENT_GROUP, $branchId);
        $legacy = $this->all(null, $branchId);
        $out = [];
        foreach (self::ESTABLISHMENT_KEYS as $key) {
            $short = str_replace('establishment.', '', $key);
            if (isset($all[$key]) && $all[$key] !== '') {
                $out[$short] = is_string($all[$key]) ? trim($all[$key]) : $all[$key];
            } elseif ($key === 'establishment.name' && isset($legacy['company_name'])) {
                $out['name'] = trim((string) $legacy['company_name']);
            } elseif ($key === 'establishment.currency' && isset($legacy['currency_code'])) {
                $out['currency'] = trim((string) $legacy['currency_code']);
            } elseif ($key === 'establishment.timezone' && isset($legacy['timezone'])) {
                $out['timezone'] = trim((string) $legacy['timezone']);
            } else {
                $out[$short] = '';
            }
        }
        return $out;
    }

    /**
     * Canonical runtime currency code for business logic.
     * Primary: {@see self::ESTABLISHMENT_KEYS} `establishment.currency` (via {@see getEstablishmentSettings()}).
     * Backward-safe fallback: legacy `currency_code` when the establishment key is absent (same merge as getEstablishmentSettings).
     * Default when unset/empty: `USD` (matches prior `get('currency_code', 'USD', ...)` consumers).
     */
    public function getEffectiveCurrencyCode(?int $branchId = null): string
    {
        $est = $this->getEstablishmentSettings($branchId);
        $c = trim((string) ($est['currency'] ?? ''));
        if ($c === '') {
            return 'USD';
        }

        return strtoupper($c);
    }

    /**
     * Branch-effective language tag for HTTP `Content-Language` (BCP 47–style, conservative).
     * Does not call `setlocale`; invalid or empty stored values yield null (no header).
     */
    public function getEffectiveEstablishmentLanguageTag(?int $branchId = null): ?string
    {
        $est = $this->getEstablishmentSettings($branchId);
        $raw = trim((string) ($est['language'] ?? ''));

        return self::normalizeEstablishmentLanguageTag($raw);
    }

    private static function normalizeEstablishmentLanguageTag(string $raw): ?string
    {
        $raw = trim(str_replace('_', '-', $raw));
        if ($raw === '') {
            return null;
        }
        $max = self::MAX_LEN['establishment.language'] ?? 20;
        if (strlen($raw) > $max) {
            $raw = substr($raw, 0, $max);
        }
        $lower = strtolower($raw);
        if (!preg_match('/^[a-z]{1,8}(-[a-z0-9]{1,8})*$/', $lower)) {
            return null;
        }

        return $lower;
    }

    /**
     * Write establishment settings with validation. Keys: name, phone, email, address, currency, timezone, language.
     *
     * @throws \InvalidArgumentException on validation failure
     */
    public function setEstablishmentSettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $validated = $this->validateEstablishmentData($data);
        foreach (self::ESTABLISHMENT_KEYS as $key) {
            $short = str_replace('establishment.', '', $key);
            $value = $validated[$short] ?? '';
            $this->set($key, $value, 'string', self::ESTABLISHMENT_GROUP, $branchId);
        }
    }

    /**
     * @return array<string, string>
     */
    private function validateEstablishmentData(array $data): array
    {
        $out = [];
        foreach (self::ESTABLISHMENT_KEYS as $key) {
            $short = str_replace('establishment.', '', $key);
            $value = isset($data[$short]) ? trim((string) $data[$short]) : '';
            $max = self::MAX_LEN[$key] ?? 255;
            if (strlen($value) > $max) {
                $value = substr($value, 0, $max);
            }
            if (($key === 'establishment.email' || $key === 'establishment.secondary_contact_email') && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Establishment email is invalid.');
            }
            $out[$short] = $value;
        }
        return $out;
    }

    /**
     * Read cancellation policy settings. Returns: enabled (bool), min_notice_hours (int), reason_required (bool), allow_privileged_override (bool).
     */
    public function getCancellationSettings(?int $branchId = null): array
    {
        $policy = $this->getCancellationPolicySettings($branchId);

        return [
            'enabled' => $policy['enabled'],
            'min_notice_hours' => $policy['min_notice_hours'],
            'reason_required' => $policy['reason_required'],
            'allow_privileged_override' => $policy['allow_privileged_override'],
        ];
    }

    /**
     * Expanded cancellation policy contract (tenant-global in this wave).
     *
     * @return array<string, mixed>
     */
    public function getCancellationPolicySettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $customerScope = $this->normalizeEnum(
            (string) $this->get('cancellation.customer_scope', 'all', $bid),
            self::CANCELLATION_CUSTOMER_SCOPE_VALUES,
            'all'
        );
        $feeMode = $this->normalizeEnum(
            (string) $this->get('cancellation.fee_mode', 'none', $bid),
            self::CANCELLATION_FEE_MODE_VALUES,
            'none'
        );
        $staffPayoutMode = $this->normalizeEnum(
            (string) $this->get('cancellation.staff_payout_mode', 'none', $bid),
            self::CANCELLATION_STAFF_PAYOUT_MODE_VALUES,
            'none'
        );
        $noShowFeeMode = $this->normalizeEnum(
            (string) $this->get('cancellation.no_show_fee_mode', 'none', $bid),
            self::CANCELLATION_FEE_MODE_VALUES,
            'none'
        );
        $noShowStaffPayoutMode = $this->normalizeEnum(
            (string) $this->get('cancellation.no_show_staff_payout_mode', 'none', $bid),
            self::CANCELLATION_STAFF_PAYOUT_MODE_VALUES,
            'none'
        );
        $courseFeeMode = $this->normalizeEnum(
            (string) $this->get('cancellation.course_fee_mode', 'none', $bid),
            self::CANCELLATION_FEE_MODE_VALUES,
            'none'
        );

        return [
            'enabled' => $this->getBool('cancellation.enabled', true, $bid),
            'customer_scope' => $customerScope,
            'min_notice_hours' => max(0, $this->getInt('cancellation.min_notice_hours', 0, $bid)),
            'fee_mode' => $feeMode,
            'fee_fixed_amount' => max(0.0, round((float) $this->get('cancellation.fee_fixed_amount', 0.0, $bid), 2)),
            'fee_percent' => $this->clampPercent((float) $this->get('cancellation.fee_percent', 0.0, $bid)),
            'staff_payout_mode' => $staffPayoutMode,
            'staff_payout_percent' => $this->clampPercent((float) $this->get('cancellation.staff_payout_percent', 0.0, $bid)),
            'no_show_same_as_cancellation' => $this->getBool('cancellation.no_show_same_as_cancellation', true, $bid),
            'no_show_fee_mode' => $noShowFeeMode,
            'no_show_fee_fixed_amount' => max(0.0, round((float) $this->get('cancellation.no_show_fee_fixed_amount', 0.0, $bid), 2)),
            'no_show_fee_percent' => $this->clampPercent((float) $this->get('cancellation.no_show_fee_percent', 0.0, $bid)),
            'no_show_staff_payout_mode' => $noShowStaffPayoutMode,
            'no_show_staff_payout_percent' => $this->clampPercent((float) $this->get('cancellation.no_show_staff_payout_percent', 0.0, $bid)),
            'course_same_as_cancellation' => $this->getBool('cancellation.course_same_as_cancellation', true, $bid),
            'course_fee_mode' => $courseFeeMode,
            'course_fee_fixed_amount' => max(0.0, round((float) $this->get('cancellation.course_fee_fixed_amount', 0.0, $bid), 2)),
            'course_fee_percent' => $this->clampPercent((float) $this->get('cancellation.course_fee_percent', 0.0, $bid)),
            'reasons_enabled' => $this->getBool('cancellation.reasons_enabled', false, $bid),
            'reason_required' => $this->getBool('cancellation.reason_required', false, $bid),
            'tax_enabled' => $this->getBool('cancellation.tax_enabled', false, $bid),
            'policy_text' => $this->normalizePolicyText((string) $this->get('cancellation.policy_text', '', $bid)),
            'allow_privileged_override' => $this->getBool('cancellation.allow_privileged_override', true, $bid),
        ];
    }

    /**
     * Canonical runtime cancellation enforcement flags.
     *
     * `reason_effectively_required_for_cancellation` applies to cancellation only in this wave.
     * No-show reason requirements are not enforced by this flag.
     *
     * @return array{
     *   cancellation_allowed: bool,
     *   min_notice_hours: int,
     *   inside_notice_window_blocks_cancellation: bool,
     *   reason_effectively_required_for_cancellation: bool,
     *   allow_privileged_override: bool
     * }
     */
    public function getCancellationRuntimeEnforcement(?int $branchId = null): array
    {
        $policy = $this->getCancellationPolicySettings($branchId);

        return [
            'cancellation_allowed' => (bool) ($policy['enabled'] ?? true),
            'min_notice_hours' => max(0, (int) ($policy['min_notice_hours'] ?? 0)),
            'inside_notice_window_blocks_cancellation' => max(0, (int) ($policy['min_notice_hours'] ?? 0)) > 0,
            'reason_effectively_required_for_cancellation' => (bool) (($policy['reasons_enabled'] ?? false) && ($policy['reason_required'] ?? false)),
            'allow_privileged_override' => (bool) ($policy['allow_privileged_override'] ?? true),
        ];
    }

    /**
     * Write cancellation settings. Keys: enabled, min_notice_hours, reason_required, allow_privileged_override.
     */
    public function setCancellationSettings(array $data, ?int $branchId = null): void
    {
        $this->patchCancellationSettings($data, $branchId ?? 0);
    }

    /**
     * Read appointment settings (branch-effective when {@see $branchId} is a positive branch id).
     *
     * @return array{
     *   min_lead_minutes:int,
     *   max_days_ahead:int,
     *   allow_past_booking:bool,
     *   no_show_alert_enabled:bool,
     *   no_show_alert_threshold:int,
     *   calendar_service_show_start_time:bool,
     *   calendar_service_label_mode:string,
     *   calendar_series_show_start_time:bool,
     *   calendar_series_label_mode:string,
     *   prebook_display_enabled:bool,
     *   prebook_threshold_value:int,
     *   prebook_threshold_unit:string,
     *   allow_end_after_closing:bool,
     *   check_staff_availability_in_search:bool,
     *   allow_staff_booking_on_off_days:bool,
     *   allow_room_overbooking:bool,
     *   allow_staff_concurrency:bool,
     *   client_itinerary_show_staff:bool,
     *   client_itinerary_show_space:bool,
     *   print_show_staff_appointment_list:bool,
     *   print_show_client_service_history:bool,
     *   print_show_package_detail:bool,
     *   print_show_client_product_purchase_history:bool
     * }
     */
    public function getAppointmentSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $labelMode = $this->normalizeEnum(
            (string) $this->get('appointments.calendar_service_label_mode', 'client_and_service', $bid),
            self::APPOINTMENT_CALENDAR_LABEL_MODE_VALUES,
            'client_and_service'
        );
        $seriesLabelMode = $this->normalizeEnum(
            (string) $this->get('appointments.calendar_series_label_mode', 'client_and_service', $bid),
            self::APPOINTMENT_CALENDAR_LABEL_MODE_VALUES,
            'client_and_service'
        );
        $prebook = $this->resolveAppointmentPrebookThreshold($bid);

        return [
            'min_lead_minutes' => max(0, $this->getInt('appointments.min_lead_minutes', 0, $bid)),
            'max_days_ahead' => max(1, $this->getInt('appointments.max_days_ahead', 180, $bid)),
            'allow_past_booking' => $this->getBool('appointments.allow_past_booking', false, $bid),
            'no_show_alert_enabled' => $this->getBool('appointments.no_show_alert_enabled', false, $bid),
            'no_show_alert_threshold' => max(1, min(99, $this->getInt('appointments.no_show_alert_threshold', 1, $bid))),
            'calendar_service_show_start_time' => $this->getBool('appointments.calendar_service_show_start_time', true, $bid),
            'calendar_service_label_mode' => $labelMode,
            'calendar_series_show_start_time' => $this->getBool('appointments.calendar_series_show_start_time', true, $bid),
            'calendar_series_label_mode' => $seriesLabelMode,
            'prebook_display_enabled' => $this->getBool('appointments.prebook_display_enabled', false, $bid),
            'prebook_threshold_value' => $prebook['value'],
            'prebook_threshold_unit' => $prebook['unit'],
            'allow_end_after_closing' => $this->getBool('appointments.allow_end_after_closing', false, $bid),
            'check_staff_availability_in_search' => $this->getBool('appointments.check_staff_availability_in_search', true, $bid),
            'allow_staff_booking_on_off_days' => $this->getBool('appointments.allow_staff_booking_on_off_days', false, $bid),
            'allow_room_overbooking' => $this->getBool('appointments.allow_room_overbooking', false, $bid),
            'allow_staff_concurrency' => $this->getBool('appointments.allow_staff_concurrency', false, $bid),
            'client_itinerary_show_staff' => $this->getBool('appointments.client_itinerary_show_staff', true, $bid),
            'client_itinerary_show_space' => $this->getBool('appointments.client_itinerary_show_space', false, $bid),
            'print_show_staff_appointment_list' => $this->getBool('appointments.print_show_staff_appointment_list', true, $bid),
            'print_show_client_service_history' => $this->getBool('appointments.print_show_client_service_history', true, $bid),
            'print_show_package_detail' => $this->getBool('appointments.print_show_package_detail', true, $bid),
            'print_show_client_product_purchase_history' => $this->getBool('appointments.print_show_client_product_purchase_history', false, $bid),
        ];
    }

    /**
     * Room-only policy for **internal** availability/booking paths: returns **true** when `appointments.allow_room_overbooking`
     * is false/absent (default) — enforce {@see AppointmentRepository::hasRoomConflict}. Returns **false** when the setting
     * is **true** — bypass **only** that room overlap check. Public booking / `forPublicBookingAvailabilityChannel` callers
     * must **not** use this bypass (always enforce room when `room_id` is in play).
     */
    public function shouldEnforceAppointmentRoomExclusivity(?int $branchId): bool
    {
        return empty($this->getAppointmentSettings($branchId)['allow_room_overbooking']);
    }

    /**
     * Staff-only: buffered overlapping-appointment check inside {@see AvailabilityService::isStaffWindowAvailable}.
     * When **true**, overlap SQL runs. When **false** (internal + `appointments.allow_staff_concurrency`), it is skipped.
     * **Public** booking / `forPublicBookingChannel` always returns **true** — concurrency bypass does **not** apply online.
     */
    public function shouldEnforceBufferedStaffAppointmentOverlap(?int $branchId, bool $forPublicBookingChannel): bool
    {
        if ($forPublicBookingChannel) {
            return true;
        }

        return empty($this->getAppointmentSettings($branchId)['allow_staff_concurrency']);
    }

    /**
     * Canonical pre-book threshold with legacy fallback: if `appointments.prebook_threshold_value` has no stored row,
     * use `appointments.prebook_threshold_hours` (wave-01) as value with unit `hours` (clamped 1–168 for legacy reads).
     *
     * @return array{value:int, unit:string}
     */
    private function resolveAppointmentPrebookThreshold(int $bid): array
    {
        $valueRaw = $this->get('appointments.prebook_threshold_value', null, $bid);
        if ($valueRaw === null) {
            $legacyHours = max(1, min(168, $this->getInt('appointments.prebook_threshold_hours', 2, $bid)));

            return ['value' => $legacyHours, 'unit' => 'hours'];
        }
        $value = max(1, min(9999, (int) $valueRaw));
        $unit = $this->normalizeEnum(
            (string) $this->get('appointments.prebook_threshold_unit', 'hours', $bid),
            self::APPOINTMENT_PREBOOK_THRESHOLD_UNIT_VALUES,
            'hours'
        );

        return ['value' => $value, 'unit' => $unit];
    }

    /**
     * Write appointment settings (full replace of known keys present in {@see $data}).
     */
    public function setAppointmentSettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $this->set('appointments.min_lead_minutes', max(0, (int) ($data['min_lead_minutes'] ?? 0)), 'int', self::APPOINTMENT_GROUP, $branchId);
        $this->set('appointments.max_days_ahead', max(1, (int) ($data['max_days_ahead'] ?? 180)), 'int', self::APPOINTMENT_GROUP, $branchId);
        $this->set('appointments.allow_past_booking', !empty($data['allow_past_booking']), 'bool', self::APPOINTMENT_GROUP, $branchId);
        $this->set('appointments.no_show_alert_enabled', !empty($data['no_show_alert_enabled']), 'bool', self::APPOINTMENT_GROUP, $branchId);
        $this->set(
            'appointments.no_show_alert_threshold',
            max(1, min(99, (int) ($data['no_show_alert_threshold'] ?? 1))),
            'int',
            self::APPOINTMENT_GROUP,
            $branchId
        );
        $this->set(
            'appointments.calendar_service_show_start_time',
            array_key_exists('calendar_service_show_start_time', $data) ? !empty($data['calendar_service_show_start_time']) : true,
            'bool',
            self::APPOINTMENT_GROUP,
            $branchId
        );
        $mode = $this->normalizeEnum(
            (string) ($data['calendar_service_label_mode'] ?? 'client_and_service'),
            self::APPOINTMENT_CALENDAR_LABEL_MODE_VALUES,
            'client_and_service'
        );
        $this->set('appointments.calendar_service_label_mode', $mode, 'string', self::APPOINTMENT_GROUP, $branchId);
        $this->set(
            'appointments.calendar_series_show_start_time',
            array_key_exists('calendar_series_show_start_time', $data) ? !empty($data['calendar_series_show_start_time']) : true,
            'bool',
            self::APPOINTMENT_GROUP,
            $branchId
        );
        $seriesMode = $this->normalizeEnum(
            (string) ($data['calendar_series_label_mode'] ?? 'client_and_service'),
            self::APPOINTMENT_CALENDAR_LABEL_MODE_VALUES,
            'client_and_service'
        );
        $this->set('appointments.calendar_series_label_mode', $seriesMode, 'string', self::APPOINTMENT_GROUP, $branchId);
        $this->set('appointments.prebook_display_enabled', !empty($data['prebook_display_enabled']), 'bool', self::APPOINTMENT_GROUP, $branchId);
        $pbVal = max(1, min(9999, (int) ($data['prebook_threshold_value'] ?? 2)));
        $pbUnit = $this->normalizeEnum(
            (string) ($data['prebook_threshold_unit'] ?? 'hours'),
            self::APPOINTMENT_PREBOOK_THRESHOLD_UNIT_VALUES,
            'hours'
        );
        $this->set('appointments.prebook_threshold_value', $pbVal, 'int', self::APPOINTMENT_GROUP, $branchId);
        $this->set('appointments.prebook_threshold_unit', $pbUnit, 'string', self::APPOINTMENT_GROUP, $branchId);
        $this->set('appointments.allow_end_after_closing', !empty($data['allow_end_after_closing']), 'bool', self::APPOINTMENT_GROUP, $branchId);
        $this->set(
            'appointments.check_staff_availability_in_search',
            array_key_exists('check_staff_availability_in_search', $data) ? !empty($data['check_staff_availability_in_search']) : true,
            'bool',
            self::APPOINTMENT_GROUP,
            $branchId
        );
        $this->set('appointments.allow_staff_booking_on_off_days', !empty($data['allow_staff_booking_on_off_days']), 'bool', self::APPOINTMENT_GROUP, $branchId);
        $this->set('appointments.allow_room_overbooking', !empty($data['allow_room_overbooking']), 'bool', self::APPOINTMENT_GROUP, $branchId);
        $this->set('appointments.allow_staff_concurrency', !empty($data['allow_staff_concurrency']), 'bool', self::APPOINTMENT_GROUP, $branchId);
        $this->set(
            'appointments.client_itinerary_show_staff',
            array_key_exists('client_itinerary_show_staff', $data) ? !empty($data['client_itinerary_show_staff']) : true,
            'bool',
            self::APPOINTMENT_GROUP,
            $branchId
        );
        $this->set(
            'appointments.client_itinerary_show_space',
            array_key_exists('client_itinerary_show_space', $data) ? !empty($data['client_itinerary_show_space']) : false,
            'bool',
            self::APPOINTMENT_GROUP,
            $branchId
        );
        $this->set(
            'appointments.print_show_staff_appointment_list',
            array_key_exists('print_show_staff_appointment_list', $data) ? !empty($data['print_show_staff_appointment_list']) : true,
            'bool',
            self::APPOINTMENT_GROUP,
            $branchId
        );
        $this->set(
            'appointments.print_show_client_service_history',
            array_key_exists('print_show_client_service_history', $data) ? !empty($data['print_show_client_service_history']) : true,
            'bool',
            self::APPOINTMENT_GROUP,
            $branchId
        );
        $this->set(
            'appointments.print_show_package_detail',
            array_key_exists('print_show_package_detail', $data) ? !empty($data['print_show_package_detail']) : true,
            'bool',
            self::APPOINTMENT_GROUP,
            $branchId
        );
        $this->set(
            'appointments.print_show_client_product_purchase_history',
            array_key_exists('print_show_client_product_purchase_history', $data) ? !empty($data['print_show_client_product_purchase_history']) : false,
            'bool',
            self::APPOINTMENT_GROUP,
            $branchId
        );
    }

    /**
     * Read online booking settings. Returns: enabled (bool), public_api_enabled (bool, default true),
     * min_lead_minutes (int), max_days_ahead (int), allow_new_clients (bool).
     */
    public function getOnlineBookingSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $scKey = $this->onlineBookingSettingsSharedCacheKey($bid);
        $packed = $this->sharedCache->get($scKey);
        if ($packed !== null && $packed !== '') {
            try {
                $decoded = json_decode($packed, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }
        $out = [
            'enabled' => $this->getBool('online_booking.enabled', false, $bid),
            'public_api_enabled' => $this->getBool('online_booking.public_api_enabled', true, $bid),
            'min_lead_minutes' => max(0, $this->getInt('online_booking.min_lead_minutes', 120, $bid)),
            'max_days_ahead' => max(1, $this->getInt('online_booking.max_days_ahead', 60, $bid)),
            'allow_new_clients' => $this->getBool('online_booking.allow_new_clients', true, $bid),
        ];
        try {
            $this->sharedCache->set($scKey, json_encode($out, JSON_THROW_ON_ERROR), self::ONLINE_BOOKING_PACKED_CACHE_TTL_SECONDS);
        } catch (\JsonException) {
        }

        return $out;
    }

    /**
     * Write online booking settings. Keys: enabled, public_api_enabled, min_lead_minutes, max_days_ahead, allow_new_clients.
     */
    public function setOnlineBookingSettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $this->set('online_booking.enabled', !empty($data['enabled']), 'bool', self::ONLINE_BOOKING_GROUP, $branchId);
        $publicApi = array_key_exists('public_api_enabled', $data) ? !empty($data['public_api_enabled']) : true;
        $this->set('online_booking.public_api_enabled', $publicApi, 'bool', self::ONLINE_BOOKING_GROUP, $branchId);
        $this->set('online_booking.min_lead_minutes', max(0, (int) ($data['min_lead_minutes'] ?? 120)), 'int', self::ONLINE_BOOKING_GROUP, $branchId);
        $this->set('online_booking.max_days_ahead', max(1, (int) ($data['max_days_ahead'] ?? 60)), 'int', self::ONLINE_BOOKING_GROUP, $branchId);
        $this->set('online_booking.allow_new_clients', !empty($data['allow_new_clients']), 'bool', self::ONLINE_BOOKING_GROUP, $branchId);
        $this->sharedCache->delete($this->onlineBookingSettingsSharedCacheKey($branchId));
    }

    /**
     * Public intake token-link gate. Defaults to enabled when no row exists (backward compatible).
     * Merge: branch-specific row at `branch_id = B` overrides global `0` when {@see get()} is called with `B > 0`.
     * When the assignment has no branch_id, only global (`0`) rows apply — pass `null` here to read global-effective only.
     *
     * @return array{public_enabled: bool}
     */
    public function getIntakeSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $scKey = $this->intakeSettingsSharedCacheKey($bid);
        $packed = $this->sharedCache->get($scKey);
        if ($packed !== null && $packed !== '') {
            try {
                $decoded = json_decode($packed, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }
        $out = [
            'public_enabled' => $this->getBool('intake.public_enabled', true, $bid),
        ];
        try {
            $this->sharedCache->set($scKey, json_encode($out, JSON_THROW_ON_ERROR), self::INTAKE_SETTINGS_PACKED_CACHE_TTL_SECONDS);
        } catch (\JsonException) {
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $patch Keys: public_enabled
     * @return list<string>
     */
    public function patchIntakeSettings(array $patch, ?int $branchId = null): array
    {
        $patch = self::onlyPatchKeys($patch, ['public_enabled']);
        $branchId = $branchId ?? 0;
        $current = $this->getIntakeSettings($branchId > 0 ? $branchId : null);
        $changed = [];
        if (array_key_exists('public_enabled', $patch)) {
            $v = (bool) $patch['public_enabled'];
            if ($v !== $current['public_enabled']) {
                $this->set('intake.public_enabled', $v, 'bool', self::INTAKE_GROUP, $branchId);
                $changed[] = 'intake.public_enabled';
            }
        }

        if ($changed !== []) {
            $this->sharedCache->delete($this->intakeSettingsSharedCacheKey($branchId));
        }

        return $changed;
    }

    /**
     * Branch-effective public commerce gates (independent from online booking).
     *
     * @return array{
     *   enabled: bool,
     *   public_api_enabled: bool,
     *   allow_gift_cards: bool,
     *   allow_packages: bool,
     *   allow_memberships: bool,
     *   allow_new_clients: bool,
     *   gift_card_min_amount: float,
     *   gift_card_max_amount: float
     * }
     */
    public function getPublicCommerceSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $scKey = $this->publicCommerceSettingsSharedCacheKey($bid);
        $packed = $this->sharedCache->get($scKey);
        if ($packed !== null && $packed !== '') {
            try {
                $decoded = json_decode($packed, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }
        $min = round((float) $this->get('public_commerce.gift_card_min_amount', 25.0, $bid), 2);
        $max = round((float) $this->get('public_commerce.gift_card_max_amount', 500.0, $bid), 2);
        if ($max < $min) {
            $max = $min;
        }

        $out = [
            'enabled' => $this->getBool('public_commerce.enabled', false, $bid),
            'public_api_enabled' => $this->getBool('public_commerce.public_api_enabled', false, $bid),
            'allow_gift_cards' => $this->getBool('public_commerce.allow_gift_cards', false, $bid),
            'allow_packages' => $this->getBool('public_commerce.allow_packages', false, $bid),
            'allow_memberships' => $this->getBool('public_commerce.allow_memberships', false, $bid),
            'allow_new_clients' => $this->getBool('public_commerce.allow_new_clients', true, $bid),
            'gift_card_min_amount' => max(0.01, $min),
            'gift_card_max_amount' => max(0.01, $max),
        ];
        try {
            $this->sharedCache->set($scKey, json_encode($out, JSON_THROW_ON_ERROR), self::PUBLIC_COMMERCE_PACKED_CACHE_TTL_SECONDS);
        } catch (\JsonException) {
        }

        return $out;
    }

    /**
     * @return list<string> changed setting keys
     */
    public function patchPublicCommerceSettings(array $patch, ?int $branchId = null): array
    {
        $patch = self::onlyPatchKeys($patch, [
            'enabled',
            'public_api_enabled',
            'allow_gift_cards',
            'allow_packages',
            'allow_memberships',
            'allow_new_clients',
            'gift_card_min_amount',
            'gift_card_max_amount',
        ]);
        $branchId = $branchId ?? 0;
        $current = $this->getPublicCommerceSettings($branchId);
        $changed = [];
        if (array_key_exists('enabled', $patch)) {
            $v = (bool) $patch['enabled'];
            if ($v !== $current['enabled']) {
                $this->set('public_commerce.enabled', $v, 'bool', self::PUBLIC_COMMERCE_GROUP, $branchId);
                $changed[] = 'public_commerce.enabled';
            }
        }
        if (array_key_exists('public_api_enabled', $patch)) {
            $v = (bool) $patch['public_api_enabled'];
            if ($v !== $current['public_api_enabled']) {
                $this->set('public_commerce.public_api_enabled', $v, 'bool', self::PUBLIC_COMMERCE_GROUP, $branchId);
                $changed[] = 'public_commerce.public_api_enabled';
            }
        }
        if (array_key_exists('allow_gift_cards', $patch)) {
            $v = (bool) $patch['allow_gift_cards'];
            if ($v !== $current['allow_gift_cards']) {
                $this->set('public_commerce.allow_gift_cards', $v, 'bool', self::PUBLIC_COMMERCE_GROUP, $branchId);
                $changed[] = 'public_commerce.allow_gift_cards';
            }
        }
        if (array_key_exists('allow_packages', $patch)) {
            $v = (bool) $patch['allow_packages'];
            if ($v !== $current['allow_packages']) {
                $this->set('public_commerce.allow_packages', $v, 'bool', self::PUBLIC_COMMERCE_GROUP, $branchId);
                $changed[] = 'public_commerce.allow_packages';
            }
        }
        if (array_key_exists('allow_memberships', $patch)) {
            $v = (bool) $patch['allow_memberships'];
            if ($v !== $current['allow_memberships']) {
                $this->set('public_commerce.allow_memberships', $v, 'bool', self::PUBLIC_COMMERCE_GROUP, $branchId);
                $changed[] = 'public_commerce.allow_memberships';
            }
        }
        if (array_key_exists('allow_new_clients', $patch)) {
            $v = (bool) $patch['allow_new_clients'];
            if ($v !== $current['allow_new_clients']) {
                $this->set('public_commerce.allow_new_clients', $v, 'bool', self::PUBLIC_COMMERCE_GROUP, $branchId);
                $changed[] = 'public_commerce.allow_new_clients';
            }
        }
        if (array_key_exists('gift_card_min_amount', $patch)) {
            $v = max(0.01, round((float) $patch['gift_card_min_amount'], 2));
            if (abs($v - $current['gift_card_min_amount']) > 0.001) {
                $this->set('public_commerce.gift_card_min_amount', $v, 'float', self::PUBLIC_COMMERCE_GROUP, $branchId);
                $changed[] = 'public_commerce.gift_card_min_amount';
            }
        }
        if (array_key_exists('gift_card_max_amount', $patch)) {
            $v = max(0.01, round((float) $patch['gift_card_max_amount'], 2));
            if (abs($v - $current['gift_card_max_amount']) > 0.001) {
                $this->set('public_commerce.gift_card_max_amount', $v, 'float', self::PUBLIC_COMMERCE_GROUP, $branchId);
                $changed[] = 'public_commerce.gift_card_max_amount';
            }
        }

        if ($changed !== []) {
            $this->sharedCache->delete($this->publicCommerceSettingsSharedCacheKey($branchId));
        }

        return $changed;
    }

    /**
     * Read payment settings. Returns: default_method_code (string), allow_partial_payments (bool), allow_overpayments (bool), receipt_notes (string).
     */
    public function getPaymentSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $scKey = $this->paymentSettingsSharedCacheKey($bid);
        $packed = $this->sharedCache->get($scKey);
        if ($packed !== null && $packed !== '') {
            try {
                $decoded = json_decode($packed, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }
        $code = trim((string) $this->get('payments.default_method_code', 'cash', $bid));
        $out = [
            'default_method_code' => $code !== '' ? $code : 'cash',
            'allow_partial_payments' => $this->getBool('payments.allow_partial_payments', true, $bid),
            'allow_overpayments' => $this->getBool('payments.allow_overpayments', false, $bid),
            'receipt_notes' => trim((string) $this->get('payments.receipt_notes', '', $bid)),
        ];
        try {
            $this->sharedCache->set($scKey, json_encode($out, JSON_THROW_ON_ERROR), self::PAYMENT_SETTINGS_PACKED_CACHE_TTL_SECONDS);
        } catch (\JsonException) {
        }

        return $out;
    }

    /**
     * Write payment settings. Keys: default_method_code, allow_partial_payments, allow_overpayments, receipt_notes.
     */
    public function setPaymentSettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $code = isset($data['default_method_code']) ? trim((string) $data['default_method_code']) : 'cash';
        $this->set('payments.default_method_code', $code !== '' ? $code : 'cash', 'string', self::PAYMENT_GROUP, $branchId);
        $this->set('payments.allow_partial_payments', !empty($data['allow_partial_payments']), 'bool', self::PAYMENT_GROUP, $branchId);
        $this->set('payments.allow_overpayments', !empty($data['allow_overpayments']), 'bool', self::PAYMENT_GROUP, $branchId);
        $notes = isset($data['receipt_notes']) ? trim((string) $data['receipt_notes']) : '';
        $this->set('payments.receipt_notes', strlen($notes) > 500 ? substr($notes, 0, 500) : $notes, 'string', self::PAYMENT_GROUP, $branchId);
        $this->sharedCache->delete($this->paymentSettingsSharedCacheKey($branchId));
    }

    /**
     * Read receipt/invoice presentation settings (desktop operator invoice view + print styling).
     *
     * @return array{
     *   show_establishment_name: bool,
     *   show_establishment_address: bool,
     *   show_establishment_phone: bool,
     *   show_establishment_email: bool,
     *   show_client_block: bool,
     *   show_client_phone: bool,
     *   show_client_address: bool,
     *   show_recorded_by: bool,
     *   show_item_barcode: bool,
     *   item_header_label: string,
     *   item_sort_mode: string,
     *   footer_bank_details: string,
     *   footer_text: string,
     *   receipt_message: string,
     *   invoice_message: string
     * }
     */
    public function getReceiptInvoiceSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $missing = '__settings_missing__';
        $readBool = function (string $newKey, bool $default, ?string $legacyKey = null) use ($bid, $missing): bool {
            $v = $this->get($newKey, $missing, $bid);
            if ($v === $missing) {
                return $legacyKey !== null ? $this->getBool($legacyKey, $default, $bid) : $default;
            }
            return (bool) $v;
        };
        $readString = function (string $newKey, string $default, ?string $legacyKey = null, int $maxLen = 0) use ($bid, $missing): string {
            $v = $this->get($newKey, $missing, $bid);
            if ($v === $missing) {
                $v = $legacyKey !== null ? $this->get($legacyKey, $default, $bid) : $default;
            }
            $t = trim((string) $v);
            if ($t === '') {
                $t = $default;
            }
            if ($maxLen > 0 && strlen($t) > $maxLen) {
                $t = substr($t, 0, $maxLen);
            }
            return $t;
        };

        $sort = strtolower(trim((string) $this->get('receipt_invoice.item_sort_mode', 'as_entered', $bid)));
        if (!in_array($sort, self::RECEIPT_INVOICE_SORT_MODES, true)) {
            $sort = 'as_entered';
        }
        $label = $readString(
            'receipt_invoice.item_header_label',
            'Description',
            'receipt_invoice.item_table_header_label',
            40
        );

        return [
            'show_establishment_name' => $readBool('receipt_invoice.show_establishment_name', true, 'receipt_invoice.header_show_establishment_name'),
            'show_establishment_address' => $readBool('receipt_invoice.show_establishment_address', true, 'receipt_invoice.header_show_establishment_address'),
            'show_establishment_phone' => $readBool('receipt_invoice.show_establishment_phone', true, 'receipt_invoice.header_show_phone'),
            'show_establishment_email' => $readBool('receipt_invoice.show_establishment_email', true, 'receipt_invoice.header_show_email'),
            'show_client_block' => $readBool('receipt_invoice.show_client_block', true, null),
            'show_client_phone' => $readBool('receipt_invoice.show_client_phone', false, 'receipt_invoice.client_show_phone'),
            'show_client_address' => $readBool('receipt_invoice.show_client_address', false, 'receipt_invoice.client_show_address'),
            'show_recorded_by' => $readBool('receipt_invoice.show_recorded_by', false, null),
            'show_item_barcode' => $readBool('receipt_invoice.show_item_barcode', false, null),
            'item_header_label' => $label,
            'item_sort_mode' => $sort,
            'footer_bank_details' => $readString('receipt_invoice.footer_bank_details', '', 'receipt_invoice.footer_bank_text', 500),
            'footer_text' => $readString('receipt_invoice.footer_text', '', 'receipt_invoice.footer_custom_text', 500),
            'receipt_message' => trim((string) $this->get('receipt_invoice.receipt_message', '', $bid)),
            'invoice_message' => trim((string) $this->get('receipt_invoice.invoice_message', '', $bid)),
        ];
    }

    /**
     * Footer line for receipts/audits: prefers {@see receipt_invoice.receipt_message}, else {@see payments.receipt_notes}.
     */
    public function getEffectiveReceiptFooterText(?int $branchId = null): string
    {
        $ri = $this->getReceiptInvoiceSettings($branchId);
        $m = trim((string) ($ri['receipt_message'] ?? ''));
        if ($m !== '') {
            return strlen($m) > 1000 ? substr($m, 0, 1000) : $m;
        }

        return trim((string) ($this->getPaymentSettings($branchId)['receipt_notes'] ?? ''));
    }

    /**
     * @param array<string, mixed> $patch Short keys (no receipt_invoice. prefix)
     * @return list<string> Full setting keys changed
     */
    public function patchReceiptInvoiceSettings(array $patch, ?int $branchId = null): array
    {
        $branchId = $branchId ?? 0;
        $current = $this->getReceiptInvoiceSettings($branchId > 0 ? $branchId : null);
        $changed = [];

        foreach (self::RECEIPT_INVOICE_BOOL_SHORT_KEYS as $short) {
            if (!array_key_exists($short, $patch)) {
                continue;
            }
            $fullKey = 'receipt_invoice.' . $short;
            $v = (bool) $patch[$short];
            if ($v !== ($current[$short] ?? false)) {
                $this->set($fullKey, $v, 'bool', self::RECEIPT_INVOICE_GROUP, $branchId);
                $changed[] = $fullKey;
            }
        }

        if (array_key_exists('footer_bank_details', $patch)) {
            $t = trim((string) $patch['footer_bank_details']);
            if (strlen($t) > 500) {
                $t = substr($t, 0, 500);
            }
            if ($t !== ($current['footer_bank_details'] ?? '')) {
                $this->set('receipt_invoice.footer_bank_details', $t, 'string', self::RECEIPT_INVOICE_GROUP, $branchId);
                $changed[] = 'receipt_invoice.footer_bank_details';
            }
        }
        if (array_key_exists('footer_text', $patch)) {
            $t = trim((string) $patch['footer_text']);
            if (strlen($t) > 500) {
                $t = substr($t, 0, 500);
            }
            if ($t !== ($current['footer_text'] ?? '')) {
                $this->set('receipt_invoice.footer_text', $t, 'string', self::RECEIPT_INVOICE_GROUP, $branchId);
                $changed[] = 'receipt_invoice.footer_text';
            }
        }
        if (array_key_exists('item_header_label', $patch)) {
            $t = trim((string) $patch['item_header_label']);
            if ($t === '') {
                $t = 'Description';
            }
            if (strlen($t) > 40) {
                $t = substr($t, 0, 40);
            }
            if ($t !== ($current['item_header_label'] ?? 'Description')) {
                $this->set('receipt_invoice.item_header_label', $t, 'string', self::RECEIPT_INVOICE_GROUP, $branchId);
                $changed[] = 'receipt_invoice.item_header_label';
            }
        }
        if (array_key_exists('item_sort_mode', $patch)) {
            $t = strtolower(trim((string) $patch['item_sort_mode']));
            if (!in_array($t, self::RECEIPT_INVOICE_SORT_MODES, true)) {
                $t = 'as_entered';
            }
            if ($t !== $current['item_sort_mode']) {
                $this->set('receipt_invoice.item_sort_mode', $t, 'string', self::RECEIPT_INVOICE_GROUP, $branchId);
                $changed[] = 'receipt_invoice.item_sort_mode';
            }
        }
        if (array_key_exists('receipt_message', $patch)) {
            $t = trim((string) $patch['receipt_message']);
            if (strlen($t) > 1000) {
                $t = substr($t, 0, 1000);
            }
            if ($t !== $current['receipt_message']) {
                $this->set('receipt_invoice.receipt_message', $t, 'string', self::RECEIPT_INVOICE_GROUP, $branchId);
                $changed[] = 'receipt_invoice.receipt_message';
            }
            $legacy = strlen($t) > 500 ? substr($t, 0, 500) : $t;
            $pay = $this->getPaymentSettings($branchId > 0 ? $branchId : null);
            if ($legacy !== $pay['receipt_notes']) {
                $this->set('payments.receipt_notes', $legacy, 'string', self::PAYMENT_GROUP, $branchId);
                $changed[] = 'payments.receipt_notes';
            }
        }
        if (array_key_exists('invoice_message', $patch)) {
            $t = trim((string) $patch['invoice_message']);
            if (strlen($t) > 1000) {
                $t = substr($t, 0, 1000);
            }
            if ($t !== $current['invoice_message']) {
                $this->set('receipt_invoice.invoice_message', $t, 'string', self::RECEIPT_INVOICE_GROUP, $branchId);
                $changed[] = 'receipt_invoice.invoice_message';
            }
        }

        return $changed;
    }

    /**
     * Read waitlist settings. Returns: enabled (bool), auto_offer_enabled (bool), max_active_per_client (int), default_expiry_minutes (int).
     */
    public function getWaitlistSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        return [
            'enabled' => $this->getBool('waitlist.enabled', true, $bid),
            'auto_offer_enabled' => $this->getBool('waitlist.auto_offer_enabled', false, $bid),
            'max_active_per_client' => max(1, $this->getInt('waitlist.max_active_per_client', 3, $bid)),
            'default_expiry_minutes' => max(0, $this->getInt('waitlist.default_expiry_minutes', 30, $bid)),
        ];
    }

    /**
     * Write waitlist settings. Keys: enabled, auto_offer_enabled, max_active_per_client, default_expiry_minutes.
     */
    public function setWaitlistSettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $this->set('waitlist.enabled', !empty($data['enabled']), 'bool', self::WAITLIST_GROUP, $branchId);
        $this->set('waitlist.auto_offer_enabled', !empty($data['auto_offer_enabled']), 'bool', self::WAITLIST_GROUP, $branchId);
        $this->set('waitlist.max_active_per_client', max(1, (int) ($data['max_active_per_client'] ?? 3)), 'int', self::WAITLIST_GROUP, $branchId);
        $this->set('waitlist.default_expiry_minutes', max(0, (int) ($data['default_expiry_minutes'] ?? 30)), 'int', self::WAITLIST_GROUP, $branchId);
    }

    /**
     * Read marketing settings. Returns: default_opt_in (bool), consent_label (string).
     */
    public function getMarketingSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $label = trim((string) $this->get('marketing.consent_label', 'Marketing communications', $bid));
        return [
            'default_opt_in' => $this->getBool('marketing.default_opt_in', false, $bid),
            'consent_label' => $label !== '' ? $label : 'Marketing communications',
        ];
    }

    /**
     * Write marketing settings. Keys: default_opt_in, consent_label.
     */
    public function setMarketingSettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $this->set('marketing.default_opt_in', !empty($data['default_opt_in']), 'bool', self::MARKETING_GROUP, $branchId);
        $label = isset($data['consent_label']) ? trim((string) $data['consent_label']) : 'Marketing communications';
        $this->set('marketing.consent_label', strlen($label) > 255 ? substr($label, 0, 255) : $label, 'string', self::MARKETING_GROUP, $branchId);
    }

    /**
     * Whether operators have recorded that an external scheduler should run marketing automation execution for this branch.
     * Default false — does not verify cron is configured or succeeding.
     */
    public function getMarketingAutomationsSchedulerAcknowledged(?int $branchId = null): bool
    {
        $bid = $branchId ?? 0;

        return $this->getBool(self::MARKETING_AUTOMATIONS_SCHEDULER_ACK_KEY, false, $bid > 0 ? $bid : null);
    }

    public function setMarketingAutomationsSchedulerAcknowledged(bool $acknowledged, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $this->set(
            self::MARKETING_AUTOMATIONS_SCHEDULER_ACK_KEY,
            $acknowledged,
            'bool',
            self::MARKETING_GROUP,
            $branchId
        );
    }

    /**
     * Read security settings. Returns: password_expiration (string: never|90_days), inactivity_timeout_minutes (int: 15|30|120).
     */
    public function getSecuritySettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $scKey = $this->securitySettingsSharedCacheKey($bid);
        $packed = $this->sharedCache->get($scKey);
        if ($packed !== null && $packed !== '') {
            try {
                $decoded = json_decode($packed, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }
        $pwd = trim((string) $this->get('security.password_expiration', 'never', $bid));
        $inact = (int) $this->get('security.inactivity_timeout_minutes', 30, $bid);
        $out = [
            'password_expiration' => in_array($pwd, self::PASSWORD_EXPIRATION_VALUES, true) ? $pwd : 'never',
            'inactivity_timeout_minutes' => in_array($inact, self::INACTIVITY_TIMEOUT_VALUES, true) ? $inact : 30,
        ];
        try {
            $this->sharedCache->set($scKey, json_encode($out, JSON_THROW_ON_ERROR), self::SECURITY_SETTINGS_PACKED_CACHE_TTL_SECONDS);
        } catch (\JsonException) {
        }

        return $out;
    }

    /**
     * Write security settings. Keys: password_expiration (never|90_days), inactivity_timeout_minutes (15|30|120).
     *
     * @throws \InvalidArgumentException if value not in allowed set
     */
    public function setSecuritySettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $pwd = isset($data['password_expiration']) ? trim((string) $data['password_expiration']) : 'never';
        if (!in_array($pwd, self::PASSWORD_EXPIRATION_VALUES, true)) {
            throw new \InvalidArgumentException('security.password_expiration must be one of: ' . implode(', ', self::PASSWORD_EXPIRATION_VALUES));
        }
        $inact = isset($data['inactivity_timeout_minutes']) ? (int) $data['inactivity_timeout_minutes'] : 30;
        if (!in_array($inact, self::INACTIVITY_TIMEOUT_VALUES, true)) {
            throw new \InvalidArgumentException('security.inactivity_timeout_minutes must be one of: ' . implode(', ', self::INACTIVITY_TIMEOUT_VALUES));
        }
        $this->set('security.password_expiration', $pwd, 'string', self::SECURITY_GROUP, $branchId);
        $this->set('security.inactivity_timeout_minutes', $inact, 'int', self::SECURITY_GROUP, $branchId);
        $this->sharedCache->delete($this->securitySettingsSharedCacheKey($branchId));
    }

    /**
     * Read internal notification settings. Returns: appointments_enabled, sales_enabled, waitlist_enabled, memberships_enabled (all bool, default true).
     */
    public function getNotificationSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $scKey = $this->notificationSettingsSharedCacheKey($bid);
        $packed = $this->sharedCache->get($scKey);
        if ($packed !== null && $packed !== '') {
            try {
                $decoded = json_decode($packed, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }
        $out = [
            'appointments_enabled' => (bool) $this->cast($this->get('notifications.appointments_enabled', '1', $bid), 'bool'),
            'sales_enabled' => (bool) $this->cast($this->get('notifications.sales_enabled', '1', $bid), 'bool'),
            'waitlist_enabled' => (bool) $this->cast($this->get('notifications.waitlist_enabled', '1', $bid), 'bool'),
            'memberships_enabled' => (bool) $this->cast($this->get('notifications.memberships_enabled', '1', $bid), 'bool'),
        ];
        try {
            $this->sharedCache->set($scKey, json_encode($out, JSON_THROW_ON_ERROR), self::NOTIFICATION_SETTINGS_PACKED_CACHE_TTL_SECONDS);
        } catch (\JsonException) {
        }

        return $out;
    }

    /**
     * Write internal notification settings. Keys: appointments_enabled, sales_enabled, waitlist_enabled, memberships_enabled (all bool).
     */
    public function setNotificationSettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $this->set('notifications.appointments_enabled', isset($data['appointments_enabled']) && (bool) $data['appointments_enabled'], 'bool', self::NOTIFICATIONS_GROUP, $branchId);
        $this->set('notifications.sales_enabled', isset($data['sales_enabled']) && (bool) $data['sales_enabled'], 'bool', self::NOTIFICATIONS_GROUP, $branchId);
        $this->set('notifications.waitlist_enabled', isset($data['waitlist_enabled']) && (bool) $data['waitlist_enabled'], 'bool', self::NOTIFICATIONS_GROUP, $branchId);
        $this->set('notifications.memberships_enabled', isset($data['memberships_enabled']) && (bool) $data['memberships_enabled'], 'bool', self::NOTIFICATIONS_GROUP, $branchId);
        $this->sharedCache->delete($this->notificationSettingsSharedCacheKey($branchId));
    }

    /**
     * In-app notification policy by `type` prefix. Toggles are read from {@see getNotificationSettings(null)} (A-005 org-default admin truth).
     *
     * @param int|null $branchId Unused for policy; retained for backward-compatible call sites.
     *
     * Unknown prefixes return true (backward compatible for future or manual types).
     */
    public function shouldEmitInAppNotificationForType(string $type, ?int $branchId = null): bool
    {
        $type = trim($type);
        if ($type === '') {
            return true;
        }
        // A-005: Notifications admin UI reads/writes org default (`branch_id` 0) only; `$branchId` is ignored for policy (no hidden per-branch toggles).
        $s = $this->getNotificationSettings(null);
        if (str_starts_with($type, 'appointment_')) {
            return $s['appointments_enabled'];
        }
        if (str_starts_with($type, 'waitlist_')) {
            return $s['waitlist_enabled'];
        }
        if (str_starts_with($type, 'membership_')) {
            return $s['memberships_enabled'];
        }
        if (str_starts_with($type, 'payment_')) {
            return $s['sales_enabled'];
        }

        return true;
    }

    /**
     * Gate for outbound transactional email queue. Toggle reads use {@see getNotificationSettings(null)} (A-005; same as in-app policy).
     *
     * @param int|null $branchId Unused for policy; retained for backward-compatible call sites.
     *
     * `appointment.*` → appointments_enabled; `waitlist.*` → waitlist_enabled; `membership.*` → memberships_enabled.
     * Other event prefixes return true (e.g. future channels). **sales_enabled is intentionally not consulted** — there are no
     * outbound enqueue paths for payment/sales events in this repo; do not assume parity with the in-app `payment_` gate.
     * SMS is not enqueued by in-repo services; dispatch skips SMS as unsupported.
     * Marketing sends use {@see \Modules\Notifications\Services\OutboundMarketingEnqueueService::EVENT_KEY} ({@code marketing.*}) and are not toggled here.
     */
    public function shouldEmitOutboundNotificationForEvent(string $eventKey, ?int $branchId = null): bool
    {
        $eventKey = trim($eventKey);
        if ($eventKey === '') {
            return true;
        }
        // A-005: Same org-default notification toggles as {@see shouldEmitInAppNotificationForType}; `$branchId` ignored for policy (API stability only).
        $s = $this->getNotificationSettings(null);
        if (str_starts_with($eventKey, 'appointment.')) {
            return $s['appointments_enabled'];
        }
        if (str_starts_with($eventKey, 'waitlist.')) {
            return $s['waitlist_enabled'];
        }
        if (str_starts_with($eventKey, 'membership.')) {
            return $s['memberships_enabled'];
        }

        return true;
    }

    /**
     * Branch-effective hardware receipt printer flag. Future receipt dispatch must no-op when false (no driver in-repo).
     */
    public function isReceiptPrintingEnabled(?int $branchId = null): bool
    {
        return $this->getHardwareSettings($branchId)['use_receipt_printer'];
    }

    /**
     * Read hardware settings. Returns: use_cash_register (bool, default true), use_receipt_printer (bool, default false).
     */
    public function getHardwareSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $scKey = $this->hardwareSettingsSharedCacheKey($bid);
        $packed = $this->sharedCache->get($scKey);
        if ($packed !== null && $packed !== '') {
            try {
                $decoded = json_decode($packed, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }
        $out = [
            'use_cash_register' => (bool) $this->cast($this->get('hardware.use_cash_register', '1', $bid), 'bool'),
            'use_receipt_printer' => (bool) $this->cast($this->get('hardware.use_receipt_printer', '0', $bid), 'bool'),
        ];
        try {
            $this->sharedCache->set($scKey, json_encode($out, JSON_THROW_ON_ERROR), self::HARDWARE_SETTINGS_PACKED_CACHE_TTL_SECONDS);
        } catch (\JsonException) {
        }

        return $out;
    }

    /**
     * Write hardware settings. Keys: use_cash_register, use_receipt_printer (all bool).
     */
    public function setHardwareSettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $this->set('hardware.use_cash_register', isset($data['use_cash_register']) && (bool) $data['use_cash_register'], 'bool', self::HARDWARE_GROUP, $branchId);
        $this->set('hardware.use_receipt_printer', isset($data['use_receipt_printer']) && (bool) $data['use_receipt_printer'], 'bool', self::HARDWARE_GROUP, $branchId);
        $this->sharedCache->delete($this->hardwareSettingsSharedCacheKey($branchId));
    }

    /**
     * Read membership settings (foundation). Returns: terms_text (string), renewal_reminder_days (int), grace_period_days (int).
     * {@see membershipTermsDocumentBlock} embeds branch-effective terms on membership invoices and client_membership rows.
     */
    public function getMembershipSettings(?int $branchId = null): array
    {
        $bid = $branchId ?? 0;
        $terms = trim((string) $this->get('memberships.terms_text', '', $bid));
        return [
            'terms_text' => $terms,
            'renewal_reminder_days' => max(0, $this->getInt('memberships.renewal_reminder_days', 7, $bid)),
            'grace_period_days' => max(0, $this->getInt('memberships.grace_period_days', 0, $bid)),
        ];
    }

    /**
     * Branch-effective membership terms for embedding on invoices / client_membership notes (same storage cap as {@see setMembershipSettings}).
     *
     * @return string|null Full block including header; null when no terms are configured
     */
    public function membershipTermsDocumentBlock(?int $branchId = null): ?string
    {
        $t = trim((string) ($this->getMembershipSettings($branchId)['terms_text'] ?? ''));
        if ($t === '') {
            return null;
        }
        if (strlen($t) > self::MEMBERSHIPS_TERMS_MAX_LEN) {
            $t = substr($t, 0, self::MEMBERSHIPS_TERMS_MAX_LEN);
        }

        return '--- Membership terms (branch-effective) ---' . "\n" . $t;
    }

    /**
     * Write membership settings. Keys: terms_text, renewal_reminder_days, grace_period_days.
     */
    public function setMembershipSettings(array $data, ?int $branchId = null): void
    {
        $branchId = $branchId ?? 0;
        $terms = isset($data['terms_text']) ? trim((string) $data['terms_text']) : '';
        if (strlen($terms) > self::MEMBERSHIPS_TERMS_MAX_LEN) {
            $terms = substr($terms, 0, self::MEMBERSHIPS_TERMS_MAX_LEN);
        }
        $this->set('memberships.terms_text', $terms, 'string', self::MEMBERSHIPS_GROUP, $branchId);
        $this->set('memberships.renewal_reminder_days', max(0, (int) ($data['renewal_reminder_days'] ?? 7)), 'int', self::MEMBERSHIPS_GROUP, $branchId);
        $this->set('memberships.grace_period_days', max(0, (int) ($data['grace_period_days'] ?? 0)), 'int', self::MEMBERSHIPS_GROUP, $branchId);
    }

    /**
     * Patch establishment fields only for keys present in $patch (short keys: name, phone, …).
     *
     * @param array<string, mixed> $patch
     * @return list<string> Full keys (e.g. establishment.name) that were persisted because values changed
     *
     * @throws \InvalidArgumentException on validation failure for a submitted field
     */
    public function patchEstablishmentSettings(array $patch, ?int $branchId = null): array
    {
        $branchId = $branchId ?? 0;
        $current = $this->getEstablishmentSettings($branchId);
        $changed = [];
        foreach ($patch as $short => $raw) {
            $value = trim((string) $raw);
            $maxKey = 'establishment.' . $short;
            if (!in_array($maxKey, self::ESTABLISHMENT_KEYS, true)) {
                continue;
            }
            $max = self::MAX_LEN[$maxKey] ?? 255;
            if (strlen($value) > $max) {
                $value = substr($value, 0, $max);
            }
            if ($maxKey === 'establishment.email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Establishment email is invalid.');
            }
            if ($maxKey === 'establishment.secondary_contact_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Secondary contact email is invalid.');
            }
            $cur = trim((string) ($current[$short] ?? ''));
            if ($value !== $cur) {
                $this->set($maxKey, $value, 'string', self::ESTABLISHMENT_GROUP, $branchId);
                $changed[] = $maxKey;
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $patch Keys: enabled, min_notice_hours, reason_required, allow_privileged_override (only present keys updated)
     * @return list<string>
     */
    public function patchCancellationSettings(array $patch, ?int $branchId = null): array
    {
        $patch = self::onlyPatchKeys($patch, [
            'enabled',
            'customer_scope',
            'min_notice_hours',
            'fee_mode',
            'fee_fixed_amount',
            'fee_percent',
            'staff_payout_mode',
            'staff_payout_percent',
            'no_show_same_as_cancellation',
            'no_show_fee_mode',
            'no_show_fee_fixed_amount',
            'no_show_fee_percent',
            'no_show_staff_payout_mode',
            'no_show_staff_payout_percent',
            'course_same_as_cancellation',
            'course_fee_mode',
            'course_fee_fixed_amount',
            'course_fee_percent',
            'reasons_enabled',
            'reason_required',
            'tax_enabled',
            'policy_text',
            'allow_privileged_override',
        ]);
        $branchId = $branchId ?? 0;
        $current = $this->getCancellationPolicySettings($branchId);
        $changed = [];
        if (array_key_exists('enabled', $patch)) {
            $v = (bool) $patch['enabled'];
            if ($v !== $current['enabled']) {
                $this->set('cancellation.enabled', $v, 'bool', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.enabled';
            }
        }
        if (array_key_exists('min_notice_hours', $patch)) {
            $v = max(0, (int) $patch['min_notice_hours']);
            if ($v !== $current['min_notice_hours']) {
                $this->set('cancellation.min_notice_hours', $v, 'int', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.min_notice_hours';
            }
        }
        if (array_key_exists('reason_required', $patch)) {
            $v = (bool) $patch['reason_required'];
            if ($v !== $current['reason_required']) {
                $this->set('cancellation.reason_required', $v, 'bool', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.reason_required';
            }
        }
        if (array_key_exists('allow_privileged_override', $patch)) {
            $v = (bool) $patch['allow_privileged_override'];
            if ($v !== $current['allow_privileged_override']) {
                $this->set('cancellation.allow_privileged_override', $v, 'bool', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.allow_privileged_override';
            }
        }
        if (array_key_exists('customer_scope', $patch)) {
            $v = $this->normalizeEnum((string) $patch['customer_scope'], self::CANCELLATION_CUSTOMER_SCOPE_VALUES, 'all');
            if ($v !== $current['customer_scope']) {
                $this->set('cancellation.customer_scope', $v, 'string', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.customer_scope';
            }
        }
        if (array_key_exists('fee_mode', $patch)) {
            $v = $this->normalizeEnum((string) $patch['fee_mode'], self::CANCELLATION_FEE_MODE_VALUES, 'none');
            if ($v !== $current['fee_mode']) {
                $this->set('cancellation.fee_mode', $v, 'string', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.fee_mode';
            }
        }
        if (array_key_exists('fee_fixed_amount', $patch)) {
            $v = max(0.0, round((float) $patch['fee_fixed_amount'], 2));
            if (abs($v - (float) $current['fee_fixed_amount']) > 0.001) {
                $this->set('cancellation.fee_fixed_amount', $v, 'float', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.fee_fixed_amount';
            }
        }
        if (array_key_exists('fee_percent', $patch)) {
            $v = $this->clampPercent((float) $patch['fee_percent']);
            if (abs($v - (float) $current['fee_percent']) > 0.001) {
                $this->set('cancellation.fee_percent', $v, 'float', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.fee_percent';
            }
        }
        if (array_key_exists('staff_payout_mode', $patch)) {
            $v = $this->normalizeEnum((string) $patch['staff_payout_mode'], self::CANCELLATION_STAFF_PAYOUT_MODE_VALUES, 'none');
            if ($v !== $current['staff_payout_mode']) {
                $this->set('cancellation.staff_payout_mode', $v, 'string', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.staff_payout_mode';
            }
        }
        if (array_key_exists('staff_payout_percent', $patch)) {
            $v = $this->clampPercent((float) $patch['staff_payout_percent']);
            if (abs($v - (float) $current['staff_payout_percent']) > 0.001) {
                $this->set('cancellation.staff_payout_percent', $v, 'float', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.staff_payout_percent';
            }
        }
        if (array_key_exists('no_show_same_as_cancellation', $patch)) {
            $v = (bool) $patch['no_show_same_as_cancellation'];
            if ($v !== $current['no_show_same_as_cancellation']) {
                $this->set('cancellation.no_show_same_as_cancellation', $v, 'bool', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.no_show_same_as_cancellation';
            }
        }
        if (array_key_exists('no_show_fee_mode', $patch)) {
            $v = $this->normalizeEnum((string) $patch['no_show_fee_mode'], self::CANCELLATION_FEE_MODE_VALUES, 'none');
            if ($v !== $current['no_show_fee_mode']) {
                $this->set('cancellation.no_show_fee_mode', $v, 'string', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.no_show_fee_mode';
            }
        }
        if (array_key_exists('no_show_fee_fixed_amount', $patch)) {
            $v = max(0.0, round((float) $patch['no_show_fee_fixed_amount'], 2));
            if (abs($v - (float) $current['no_show_fee_fixed_amount']) > 0.001) {
                $this->set('cancellation.no_show_fee_fixed_amount', $v, 'float', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.no_show_fee_fixed_amount';
            }
        }
        if (array_key_exists('no_show_fee_percent', $patch)) {
            $v = $this->clampPercent((float) $patch['no_show_fee_percent']);
            if (abs($v - (float) $current['no_show_fee_percent']) > 0.001) {
                $this->set('cancellation.no_show_fee_percent', $v, 'float', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.no_show_fee_percent';
            }
        }
        if (array_key_exists('no_show_staff_payout_mode', $patch)) {
            $v = $this->normalizeEnum((string) $patch['no_show_staff_payout_mode'], self::CANCELLATION_STAFF_PAYOUT_MODE_VALUES, 'none');
            if ($v !== $current['no_show_staff_payout_mode']) {
                $this->set('cancellation.no_show_staff_payout_mode', $v, 'string', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.no_show_staff_payout_mode';
            }
        }
        if (array_key_exists('no_show_staff_payout_percent', $patch)) {
            $v = $this->clampPercent((float) $patch['no_show_staff_payout_percent']);
            if (abs($v - (float) $current['no_show_staff_payout_percent']) > 0.001) {
                $this->set('cancellation.no_show_staff_payout_percent', $v, 'float', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.no_show_staff_payout_percent';
            }
        }
        if (array_key_exists('course_same_as_cancellation', $patch)) {
            $v = (bool) $patch['course_same_as_cancellation'];
            if ($v !== $current['course_same_as_cancellation']) {
                $this->set('cancellation.course_same_as_cancellation', $v, 'bool', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.course_same_as_cancellation';
            }
        }
        if (array_key_exists('course_fee_mode', $patch)) {
            $v = $this->normalizeEnum((string) $patch['course_fee_mode'], self::CANCELLATION_FEE_MODE_VALUES, 'none');
            if ($v !== $current['course_fee_mode']) {
                $this->set('cancellation.course_fee_mode', $v, 'string', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.course_fee_mode';
            }
        }
        if (array_key_exists('course_fee_fixed_amount', $patch)) {
            $v = max(0.0, round((float) $patch['course_fee_fixed_amount'], 2));
            if (abs($v - (float) $current['course_fee_fixed_amount']) > 0.001) {
                $this->set('cancellation.course_fee_fixed_amount', $v, 'float', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.course_fee_fixed_amount';
            }
        }
        if (array_key_exists('course_fee_percent', $patch)) {
            $v = $this->clampPercent((float) $patch['course_fee_percent']);
            if (abs($v - (float) $current['course_fee_percent']) > 0.001) {
                $this->set('cancellation.course_fee_percent', $v, 'float', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.course_fee_percent';
            }
        }
        if (array_key_exists('reasons_enabled', $patch)) {
            $v = (bool) $patch['reasons_enabled'];
            if ($v !== $current['reasons_enabled']) {
                $this->set('cancellation.reasons_enabled', $v, 'bool', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.reasons_enabled';
            }
        }
        if (array_key_exists('tax_enabled', $patch)) {
            $v = (bool) $patch['tax_enabled'];
            if ($v !== $current['tax_enabled']) {
                $this->set('cancellation.tax_enabled', $v, 'bool', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.tax_enabled';
            }
        }
        if (array_key_exists('policy_text', $patch)) {
            $v = $this->normalizePolicyText((string) $patch['policy_text']);
            if ($v !== $current['policy_text']) {
                $this->set('cancellation.policy_text', $v, 'string', self::CANCELLATION_GROUP, $branchId);
                $changed[] = 'cancellation.policy_text';
            }
        }

        return $changed;
    }

    private function normalizeEnum(string $value, array $allowed, string $default): string
    {
        $v = trim(strtolower($value));
        if (!in_array($v, $allowed, true)) {
            return $default;
        }

        return $v;
    }

    private function clampPercent(float $value): float
    {
        if ($value < 0) {
            $value = 0;
        } elseif ($value > 100) {
            $value = 100;
        }

        return round($value, 2);
    }

    private function normalizePolicyText(string $value): string
    {
        $v = trim($this->sanitizePolicyTextHtml($value));
        if (strlen($v) > self::CANCELLATION_POLICY_TEXT_MAX_LEN) {
            $v = substr($v, 0, self::CANCELLATION_POLICY_TEXT_MAX_LEN);
        }

        return $v;
    }

    private function sanitizePolicyTextHtml(string $value): string
    {
        $html = trim($value);
        if ($html === '') {
            return '';
        }

        // Drop dangerous containers completely.
        $html = preg_replace('/<(script|style|iframe|object|embed|svg|math)[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        // Remove event handler attributes and inline style attributes.
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("|\').*?\1/is', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/is', '', $html) ?? '';
        $html = preg_replace('/\s+style\s*=\s*("|\').*?\1/is', '', $html) ?? '';
        $html = preg_replace('/\s+style\s*=\s*[^\s>]+/is', '', $html) ?? '';
        // Allow only conservative policy-text tags.
        $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li>');
        // Normalize legacy bold/italic tags.
        $html = str_ireplace(['<b>', '</b>', '<i>', '</i>'], ['<strong>', '</strong>', '<em>', '</em>'], $html);
        // Remove any attributes that survived on allowed tags.
        $html = preg_replace('/<([a-z0-9]+)(?:\s+[^>]*)>/i', '<$1>', $html) ?? '';

        return $html;
    }

    /**
     * Allowlisted appointment settings patch. Unknown keys are ignored by callers; only documented keys apply.
     *
     * @param array<string, mixed> $patch Keys: min_lead_minutes, max_days_ahead, allow_past_booking, allow_end_after_closing,
     *        check_staff_availability_in_search, allow_staff_booking_on_off_days, allow_room_overbooking, allow_staff_concurrency, no_show_alert_enabled, no_show_alert_threshold, calendar_service_show_start_time, calendar_service_label_mode,
     *        calendar_series_show_start_time, calendar_series_label_mode, prebook_display_enabled, prebook_threshold_value, prebook_threshold_unit, prebook_threshold_hours (legacy: maps to value + hours unit when value not in patch),
     *        client_itinerary_show_staff, client_itinerary_show_space,
     *        print_show_staff_appointment_list, print_show_client_service_history, print_show_package_detail, print_show_client_product_purchase_history
     * @return list<string>
     */
    public function patchAppointmentSettings(array $patch, ?int $branchId = null): array
    {
        $patch = self::onlyPatchKeys($patch, [
            'min_lead_minutes',
            'max_days_ahead',
            'allow_past_booking',
            'allow_end_after_closing',
            'check_staff_availability_in_search',
            'allow_staff_booking_on_off_days',
            'allow_room_overbooking',
            'allow_staff_concurrency',
            'no_show_alert_enabled',
            'no_show_alert_threshold',
            'calendar_service_show_start_time',
            'calendar_service_label_mode',
            'calendar_series_show_start_time',
            'calendar_series_label_mode',
            'prebook_display_enabled',
            'prebook_threshold_value',
            'prebook_threshold_unit',
            'prebook_threshold_hours',
            'client_itinerary_show_staff',
            'client_itinerary_show_space',
            'print_show_staff_appointment_list',
            'print_show_client_service_history',
            'print_show_package_detail',
            'print_show_client_product_purchase_history',
        ]);
        $branchId = $branchId ?? 0;
        $readBranch = $branchId > 0 ? $branchId : null;
        $current = $this->getAppointmentSettings($readBranch);
        $changed = [];
        if (array_key_exists('min_lead_minutes', $patch)) {
            $v = max(0, (int) $patch['min_lead_minutes']);
            if ($v !== $current['min_lead_minutes']) {
                $this->set('appointments.min_lead_minutes', $v, 'int', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.min_lead_minutes';
            }
        }
        if (array_key_exists('max_days_ahead', $patch)) {
            $v = max(1, (int) $patch['max_days_ahead']);
            if ($v !== $current['max_days_ahead']) {
                $this->set('appointments.max_days_ahead', $v, 'int', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.max_days_ahead';
            }
        }
        if (array_key_exists('allow_past_booking', $patch)) {
            $v = (bool) $patch['allow_past_booking'];
            if ($v !== $current['allow_past_booking']) {
                $this->set('appointments.allow_past_booking', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.allow_past_booking';
            }
        }
        if (array_key_exists('allow_end_after_closing', $patch)) {
            $v = (bool) $patch['allow_end_after_closing'];
            if ($v !== $current['allow_end_after_closing']) {
                $this->set('appointments.allow_end_after_closing', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.allow_end_after_closing';
            }
        }
        if (array_key_exists('check_staff_availability_in_search', $patch)) {
            $v = (bool) $patch['check_staff_availability_in_search'];
            if ($v !== $current['check_staff_availability_in_search']) {
                $this->set('appointments.check_staff_availability_in_search', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.check_staff_availability_in_search';
            }
        }
        if (array_key_exists('allow_staff_booking_on_off_days', $patch)) {
            $v = (bool) $patch['allow_staff_booking_on_off_days'];
            if ($v !== $current['allow_staff_booking_on_off_days']) {
                $this->set('appointments.allow_staff_booking_on_off_days', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.allow_staff_booking_on_off_days';
            }
        }
        if (array_key_exists('allow_room_overbooking', $patch)) {
            $v = (bool) $patch['allow_room_overbooking'];
            if ($v !== $current['allow_room_overbooking']) {
                $this->set('appointments.allow_room_overbooking', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.allow_room_overbooking';
            }
        }
        if (array_key_exists('allow_staff_concurrency', $patch)) {
            $v = (bool) $patch['allow_staff_concurrency'];
            if ($v !== $current['allow_staff_concurrency']) {
                $this->set('appointments.allow_staff_concurrency', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.allow_staff_concurrency';
            }
        }
        if (array_key_exists('no_show_alert_enabled', $patch)) {
            $v = (bool) $patch['no_show_alert_enabled'];
            if ($v !== $current['no_show_alert_enabled']) {
                $this->set('appointments.no_show_alert_enabled', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.no_show_alert_enabled';
            }
        }
        if (array_key_exists('no_show_alert_threshold', $patch)) {
            $v = max(1, min(99, (int) $patch['no_show_alert_threshold']));
            if ($v !== $current['no_show_alert_threshold']) {
                $this->set('appointments.no_show_alert_threshold', $v, 'int', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.no_show_alert_threshold';
            }
        }
        if (array_key_exists('calendar_service_show_start_time', $patch)) {
            $v = (bool) $patch['calendar_service_show_start_time'];
            if ($v !== $current['calendar_service_show_start_time']) {
                $this->set('appointments.calendar_service_show_start_time', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.calendar_service_show_start_time';
            }
        }
        if (array_key_exists('calendar_service_label_mode', $patch)) {
            $v = $this->normalizeEnum((string) $patch['calendar_service_label_mode'], self::APPOINTMENT_CALENDAR_LABEL_MODE_VALUES, 'client_and_service');
            if ($v !== $current['calendar_service_label_mode']) {
                $this->set('appointments.calendar_service_label_mode', $v, 'string', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.calendar_service_label_mode';
            }
        }
        if (array_key_exists('calendar_series_show_start_time', $patch)) {
            $v = (bool) $patch['calendar_series_show_start_time'];
            if ($v !== $current['calendar_series_show_start_time']) {
                $this->set('appointments.calendar_series_show_start_time', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.calendar_series_show_start_time';
            }
        }
        if (array_key_exists('calendar_series_label_mode', $patch)) {
            $v = $this->normalizeEnum((string) $patch['calendar_series_label_mode'], self::APPOINTMENT_CALENDAR_LABEL_MODE_VALUES, 'client_and_service');
            if ($v !== $current['calendar_series_label_mode']) {
                $this->set('appointments.calendar_series_label_mode', $v, 'string', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.calendar_series_label_mode';
            }
        }
        if (array_key_exists('prebook_display_enabled', $patch)) {
            $v = (bool) $patch['prebook_display_enabled'];
            if ($v !== $current['prebook_display_enabled']) {
                $this->set('appointments.prebook_display_enabled', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.prebook_display_enabled';
            }
        }
        $touchPrebook = array_key_exists('prebook_threshold_value', $patch)
            || array_key_exists('prebook_threshold_unit', $patch)
            || array_key_exists('prebook_threshold_hours', $patch);
        if ($touchPrebook) {
            $nextVal = (int) $current['prebook_threshold_value'];
            $nextUnit = (string) $current['prebook_threshold_unit'];
            if (array_key_exists('prebook_threshold_hours', $patch) && !array_key_exists('prebook_threshold_value', $patch)) {
                $nextVal = max(1, min(168, (int) $patch['prebook_threshold_hours']));
                $nextUnit = 'hours';
            }
            if (array_key_exists('prebook_threshold_value', $patch)) {
                $nextVal = max(1, min(9999, (int) $patch['prebook_threshold_value']));
            }
            if (array_key_exists('prebook_threshold_unit', $patch)) {
                $nextUnit = $this->normalizeEnum(
                    (string) $patch['prebook_threshold_unit'],
                    self::APPOINTMENT_PREBOOK_THRESHOLD_UNIT_VALUES,
                    'hours'
                );
            }
            if ($nextVal !== (int) $current['prebook_threshold_value']) {
                $this->set('appointments.prebook_threshold_value', $nextVal, 'int', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.prebook_threshold_value';
            }
            if ($nextUnit !== (string) $current['prebook_threshold_unit']) {
                $this->set('appointments.prebook_threshold_unit', $nextUnit, 'string', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.prebook_threshold_unit';
            }
        }
        if (array_key_exists('client_itinerary_show_staff', $patch)) {
            $v = (bool) $patch['client_itinerary_show_staff'];
            if ($v !== $current['client_itinerary_show_staff']) {
                $this->set('appointments.client_itinerary_show_staff', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.client_itinerary_show_staff';
            }
        }
        if (array_key_exists('client_itinerary_show_space', $patch)) {
            $v = (bool) $patch['client_itinerary_show_space'];
            if ($v !== $current['client_itinerary_show_space']) {
                $this->set('appointments.client_itinerary_show_space', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.client_itinerary_show_space';
            }
        }
        if (array_key_exists('print_show_staff_appointment_list', $patch)) {
            $v = (bool) $patch['print_show_staff_appointment_list'];
            if ($v !== $current['print_show_staff_appointment_list']) {
                $this->set('appointments.print_show_staff_appointment_list', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.print_show_staff_appointment_list';
            }
        }
        if (array_key_exists('print_show_client_service_history', $patch)) {
            $v = (bool) $patch['print_show_client_service_history'];
            if ($v !== $current['print_show_client_service_history']) {
                $this->set('appointments.print_show_client_service_history', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.print_show_client_service_history';
            }
        }
        if (array_key_exists('print_show_package_detail', $patch)) {
            $v = (bool) $patch['print_show_package_detail'];
            if ($v !== $current['print_show_package_detail']) {
                $this->set('appointments.print_show_package_detail', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.print_show_package_detail';
            }
        }
        if (array_key_exists('print_show_client_product_purchase_history', $patch)) {
            $v = (bool) $patch['print_show_client_product_purchase_history'];
            if ($v !== $current['print_show_client_product_purchase_history']) {
                $this->set('appointments.print_show_client_product_purchase_history', $v, 'bool', self::APPOINTMENT_GROUP, $branchId);
                $changed[] = 'appointments.print_show_client_product_purchase_history';
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $patch Keys: enabled, public_api_enabled, min_lead_minutes, max_days_ahead, allow_new_clients
     * @return list<string>
     */
    public function patchOnlineBookingSettings(array $patch, ?int $branchId = null): array
    {
        $patch = self::onlyPatchKeys($patch, ['enabled', 'public_api_enabled', 'min_lead_minutes', 'max_days_ahead', 'allow_new_clients']);
        $branchId = $branchId ?? 0;
        $current = $this->getOnlineBookingSettings($branchId);
        $changed = [];
        if (array_key_exists('enabled', $patch)) {
            $v = (bool) $patch['enabled'];
            if ($v !== $current['enabled']) {
                $this->set('online_booking.enabled', $v, 'bool', self::ONLINE_BOOKING_GROUP, $branchId);
                $changed[] = 'online_booking.enabled';
            }
        }
        if (array_key_exists('public_api_enabled', $patch)) {
            $v = (bool) $patch['public_api_enabled'];
            if ($v !== $current['public_api_enabled']) {
                $this->set('online_booking.public_api_enabled', $v, 'bool', self::ONLINE_BOOKING_GROUP, $branchId);
                $changed[] = 'online_booking.public_api_enabled';
            }
        }
        if (array_key_exists('min_lead_minutes', $patch)) {
            $v = max(0, (int) $patch['min_lead_minutes']);
            if ($v !== $current['min_lead_minutes']) {
                $this->set('online_booking.min_lead_minutes', $v, 'int', self::ONLINE_BOOKING_GROUP, $branchId);
                $changed[] = 'online_booking.min_lead_minutes';
            }
        }
        if (array_key_exists('max_days_ahead', $patch)) {
            $v = max(1, (int) $patch['max_days_ahead']);
            if ($v !== $current['max_days_ahead']) {
                $this->set('online_booking.max_days_ahead', $v, 'int', self::ONLINE_BOOKING_GROUP, $branchId);
                $changed[] = 'online_booking.max_days_ahead';
            }
        }
        if (array_key_exists('allow_new_clients', $patch)) {
            $v = (bool) $patch['allow_new_clients'];
            if ($v !== $current['allow_new_clients']) {
                $this->set('online_booking.allow_new_clients', $v, 'bool', self::ONLINE_BOOKING_GROUP, $branchId);
                $changed[] = 'online_booking.allow_new_clients';
            }
        }

        if ($changed !== []) {
            $this->sharedCache->delete($this->onlineBookingSettingsSharedCacheKey($branchId));
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $patch Keys: default_method_code, allow_partial_payments, allow_overpayments, receipt_notes
     * @param int|null $defaultMethodCatalogBranchId When {@see PaymentMethodService} is registered, validates default code against
     *        active recorded methods for this branch (or global-only when {@code null} or non-positive).
     * @return list<string>
     */
    public function patchPaymentSettings(array $patch, ?int $branchId = null, ?int $defaultMethodCatalogBranchId = null): array
    {
        $branchId = $branchId ?? 0;
        $current = $this->getPaymentSettings($branchId);
        $changed = [];
        if (array_key_exists('default_method_code', $patch)) {
            $code = trim((string) $patch['default_method_code']);
            if ($code === '') {
                $code = 'cash';
            }
            $container = Application::container();
            if (!$container->has(PaymentMethodService::class)) {
                throw new \InvalidArgumentException(
                    'PaymentMethodService must be registered to validate payments.default_method_code (M-004). Load modules/bootstrap.php.'
                );
            }
            /** @var PaymentMethodService $paymentMethods */
            $paymentMethods = $container->get(PaymentMethodService::class);
            $catalogBranch = ($defaultMethodCatalogBranchId !== null && $defaultMethodCatalogBranchId > 0)
                ? $defaultMethodCatalogBranchId
                : null;
            if (!$paymentMethods->isAllowedForRecordedInvoicePayment($code, $catalogBranch)) {
                throw new \InvalidArgumentException(
                    'Default payment method code must be an active recorded method for the selected scope (not gift_card).'
                );
            }
            if ($code !== $current['default_method_code']) {
                $this->set('payments.default_method_code', $code, 'string', self::PAYMENT_GROUP, $branchId);
                $changed[] = 'payments.default_method_code';
            }
        }
        if (array_key_exists('allow_partial_payments', $patch)) {
            $v = (bool) $patch['allow_partial_payments'];
            if ($v !== $current['allow_partial_payments']) {
                $this->set('payments.allow_partial_payments', $v, 'bool', self::PAYMENT_GROUP, $branchId);
                $changed[] = 'payments.allow_partial_payments';
            }
        }
        if (array_key_exists('allow_overpayments', $patch)) {
            $v = (bool) $patch['allow_overpayments'];
            if ($v !== $current['allow_overpayments']) {
                $this->set('payments.allow_overpayments', $v, 'bool', self::PAYMENT_GROUP, $branchId);
                $changed[] = 'payments.allow_overpayments';
            }
        }
        if (array_key_exists('receipt_notes', $patch)) {
            $notes = trim((string) $patch['receipt_notes']);
            if (strlen($notes) > 500) {
                $notes = substr($notes, 0, 500);
            }
            if ($notes !== $current['receipt_notes']) {
                $this->set('payments.receipt_notes', $notes, 'string', self::PAYMENT_GROUP, $branchId);
                $changed[] = 'payments.receipt_notes';
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $patch Keys: enabled, auto_offer_enabled, max_active_per_client, default_expiry_minutes
     * @return list<string>
     */
    public function patchWaitlistSettings(array $patch, ?int $branchId = null): array
    {
        $patch = self::onlyPatchKeys($patch, ['enabled', 'auto_offer_enabled', 'max_active_per_client', 'default_expiry_minutes']);
        $branchId = $branchId ?? 0;
        $current = $this->getWaitlistSettings($branchId);
        $changed = [];
        if (array_key_exists('enabled', $patch)) {
            $v = (bool) $patch['enabled'];
            if ($v !== $current['enabled']) {
                $this->set('waitlist.enabled', $v, 'bool', self::WAITLIST_GROUP, $branchId);
                $changed[] = 'waitlist.enabled';
            }
        }
        if (array_key_exists('auto_offer_enabled', $patch)) {
            $v = (bool) $patch['auto_offer_enabled'];
            if ($v !== $current['auto_offer_enabled']) {
                $this->set('waitlist.auto_offer_enabled', $v, 'bool', self::WAITLIST_GROUP, $branchId);
                $changed[] = 'waitlist.auto_offer_enabled';
            }
        }
        if (array_key_exists('max_active_per_client', $patch)) {
            $v = max(1, (int) $patch['max_active_per_client']);
            if ($v !== $current['max_active_per_client']) {
                $this->set('waitlist.max_active_per_client', $v, 'int', self::WAITLIST_GROUP, $branchId);
                $changed[] = 'waitlist.max_active_per_client';
            }
        }
        if (array_key_exists('default_expiry_minutes', $patch)) {
            $v = max(0, (int) $patch['default_expiry_minutes']);
            if ($v !== $current['default_expiry_minutes']) {
                $this->set('waitlist.default_expiry_minutes', $v, 'int', self::WAITLIST_GROUP, $branchId);
                $changed[] = 'waitlist.default_expiry_minutes';
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $patch Keys: default_opt_in, consent_label
     * @return list<string>
     */
    public function patchMarketingSettings(array $patch, ?int $branchId = null): array
    {
        $branchId = $branchId ?? 0;
        $current = $this->getMarketingSettings($branchId);
        $changed = [];
        if (array_key_exists('default_opt_in', $patch)) {
            $v = (bool) $patch['default_opt_in'];
            if ($v !== $current['default_opt_in']) {
                $this->set('marketing.default_opt_in', $v, 'bool', self::MARKETING_GROUP, $branchId);
                $changed[] = 'marketing.default_opt_in';
            }
        }
        if (array_key_exists('consent_label', $patch)) {
            $label = trim((string) $patch['consent_label']);
            if (strlen($label) > 255) {
                $label = substr($label, 0, 255);
            }
            if ($label !== $current['consent_label']) {
                $this->set('marketing.consent_label', $label, 'string', self::MARKETING_GROUP, $branchId);
                $changed[] = 'marketing.consent_label';
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $patch Keys: password_expiration, inactivity_timeout_minutes
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function patchSecuritySettings(array $patch, ?int $branchId = null): array
    {
        $patch = self::onlyPatchKeys($patch, ['password_expiration', 'inactivity_timeout_minutes']);
        $branchId = $branchId ?? 0;
        $current = $this->getSecuritySettings($branchId);
        $changed = [];
        if (array_key_exists('password_expiration', $patch)) {
            $pwd = trim((string) $patch['password_expiration']);
            if (!in_array($pwd, self::PASSWORD_EXPIRATION_VALUES, true)) {
                throw new \InvalidArgumentException('security.password_expiration must be one of: ' . implode(', ', self::PASSWORD_EXPIRATION_VALUES));
            }
            if ($pwd !== $current['password_expiration']) {
                $this->set('security.password_expiration', $pwd, 'string', self::SECURITY_GROUP, $branchId);
                $changed[] = 'security.password_expiration';
            }
        }
        if (array_key_exists('inactivity_timeout_minutes', $patch)) {
            $inact = (int) $patch['inactivity_timeout_minutes'];
            if (!in_array($inact, self::INACTIVITY_TIMEOUT_VALUES, true)) {
                throw new \InvalidArgumentException('security.inactivity_timeout_minutes must be one of: ' . implode(', ', self::INACTIVITY_TIMEOUT_VALUES));
            }
            if ($inact !== $current['inactivity_timeout_minutes']) {
                $this->set('security.inactivity_timeout_minutes', $inact, 'int', self::SECURITY_GROUP, $branchId);
                $changed[] = 'security.inactivity_timeout_minutes';
            }
        }

        if ($changed !== []) {
            $this->sharedCache->delete($this->securitySettingsSharedCacheKey($branchId));
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $patch Keys: appointments_enabled, sales_enabled, waitlist_enabled, memberships_enabled
     * @return list<string>
     */
    public function patchNotificationSettings(array $patch, ?int $branchId = null): array
    {
        $patch = self::onlyPatchKeys($patch, ['appointments_enabled', 'sales_enabled', 'waitlist_enabled', 'memberships_enabled']);
        $branchId = $branchId ?? 0;
        $current = $this->getNotificationSettings($branchId);
        $changed = [];
        if (array_key_exists('appointments_enabled', $patch)) {
            $v = (bool) $patch['appointments_enabled'];
            if ($v !== $current['appointments_enabled']) {
                $this->set('notifications.appointments_enabled', $v, 'bool', self::NOTIFICATIONS_GROUP, $branchId);
                $changed[] = 'notifications.appointments_enabled';
            }
        }
        if (array_key_exists('sales_enabled', $patch)) {
            $v = (bool) $patch['sales_enabled'];
            if ($v !== $current['sales_enabled']) {
                $this->set('notifications.sales_enabled', $v, 'bool', self::NOTIFICATIONS_GROUP, $branchId);
                $changed[] = 'notifications.sales_enabled';
            }
        }
        if (array_key_exists('waitlist_enabled', $patch)) {
            $v = (bool) $patch['waitlist_enabled'];
            if ($v !== $current['waitlist_enabled']) {
                $this->set('notifications.waitlist_enabled', $v, 'bool', self::NOTIFICATIONS_GROUP, $branchId);
                $changed[] = 'notifications.waitlist_enabled';
            }
        }
        if (array_key_exists('memberships_enabled', $patch)) {
            $v = (bool) $patch['memberships_enabled'];
            if ($v !== $current['memberships_enabled']) {
                $this->set('notifications.memberships_enabled', $v, 'bool', self::NOTIFICATIONS_GROUP, $branchId);
                $changed[] = 'notifications.memberships_enabled';
            }
        }

        if ($changed !== []) {
            $this->sharedCache->delete($this->notificationSettingsSharedCacheKey($branchId));
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $patch Keys: use_cash_register, use_receipt_printer
     * @return list<string>
     */
    public function patchHardwareSettings(array $patch, ?int $branchId = null): array
    {
        $branchId = $branchId ?? 0;
        $current = $this->getHardwareSettings($branchId);
        $changed = [];
        if (array_key_exists('use_cash_register', $patch)) {
            $v = (bool) $patch['use_cash_register'];
            if ($v !== $current['use_cash_register']) {
                $this->set('hardware.use_cash_register', $v, 'bool', self::HARDWARE_GROUP, $branchId);
                $changed[] = 'hardware.use_cash_register';
            }
        }
        if (array_key_exists('use_receipt_printer', $patch)) {
            $v = (bool) $patch['use_receipt_printer'];
            if ($v !== $current['use_receipt_printer']) {
                $this->set('hardware.use_receipt_printer', $v, 'bool', self::HARDWARE_GROUP, $branchId);
                $changed[] = 'hardware.use_receipt_printer';
            }
        }

        if ($changed !== []) {
            $this->sharedCache->delete($this->hardwareSettingsSharedCacheKey($branchId));
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $patch Keys: terms_text, renewal_reminder_days, grace_period_days
     * @return list<string>
     */
    public function patchMembershipSettings(array $patch, ?int $branchId = null): array
    {
        $patch = self::onlyPatchKeys($patch, ['terms_text', 'renewal_reminder_days', 'grace_period_days']);
        $branchId = $branchId ?? 0;
        $current = $this->getMembershipSettings($branchId);
        $changed = [];
        if (array_key_exists('terms_text', $patch)) {
            $terms = trim((string) $patch['terms_text']);
            if (strlen($terms) > self::MEMBERSHIPS_TERMS_MAX_LEN) {
                $terms = substr($terms, 0, self::MEMBERSHIPS_TERMS_MAX_LEN);
            }
            if ($terms !== $current['terms_text']) {
                $this->set('memberships.terms_text', $terms, 'string', self::MEMBERSHIPS_GROUP, $branchId);
                $changed[] = 'memberships.terms_text';
            }
        }
        if (array_key_exists('renewal_reminder_days', $patch)) {
            $v = max(0, (int) $patch['renewal_reminder_days']);
            if ($v !== $current['renewal_reminder_days']) {
                $this->set('memberships.renewal_reminder_days', $v, 'int', self::MEMBERSHIPS_GROUP, $branchId);
                $changed[] = 'memberships.renewal_reminder_days';
            }
        }
        if (array_key_exists('grace_period_days', $patch)) {
            $v = max(0, (int) $patch['grace_period_days']);
            if ($v !== $current['grace_period_days']) {
                $this->set('memberships.grace_period_days', $v, 'int', self::MEMBERSHIPS_GROUP, $branchId);
                $changed[] = 'memberships.grace_period_days';
            }
        }

        return $changed;
    }

    /**
     * Drop unsupported keys so callers cannot rely on undocumented patch side effects.
     *
     * @param array<string, mixed> $patch
     * @param list<string> $allowedKeys
     * @return array<string, mixed>
     */
    private static function onlyPatchKeys(array $patch, array $allowedKeys): array
    {
        return array_intersect_key($patch, array_flip($allowedKeys));
    }

    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => is_string($value) ? (json_decode($value, true) ?? $value) : $value,
            default => is_string($value) && ($d = json_decode($value, true)) !== null ? $d : $value,
        };
    }

    private function encode(mixed $value, string $type): string
    {
        return match ($type) {
            'bool', 'boolean' => $value ? '1' : '0',
            'json' => is_string($value) ? $value : json_encode($value),
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    private const PLATFORM_SETTINGS_GROUP = 'platform';

    /** Deployment-wide emergency flags (organization_id = 0, branch_id = 0). Enforced in public booking/commerce services. */
    public const PLATFORM_FOUNDER_KILL_ONLINE_BOOKING = 'platform.founder_kill_online_booking';
    public const PLATFORM_FOUNDER_KILL_ANONYMOUS_PUBLIC_APIS = 'platform.founder_kill_anonymous_public_apis';
    public const PLATFORM_FOUNDER_KILL_PUBLIC_COMMERCE = 'platform.founder_kill_public_commerce';

    public function isPlatformFounderKillOnlineBooking(): bool
    {
        return $this->readPlatformDefaultBool(self::PLATFORM_FOUNDER_KILL_ONLINE_BOOKING, false);
    }

    public function isPlatformFounderKillAnonymousPublicApis(): bool
    {
        return $this->readPlatformDefaultBool(self::PLATFORM_FOUNDER_KILL_ANONYMOUS_PUBLIC_APIS, false);
    }

    public function isPlatformFounderKillPublicCommerce(): bool
    {
        return $this->readPlatformDefaultBool(self::PLATFORM_FOUNDER_KILL_PUBLIC_COMMERCE, false);
    }

    /**
     * @return array{kill_online_booking: bool, kill_anonymous_public_apis: bool, kill_public_commerce: bool}
     */
    public function getPlatformFounderPublicSurfaceKillSwitches(): array
    {
        return [
            'kill_online_booking' => $this->isPlatformFounderKillOnlineBooking(),
            'kill_anonymous_public_apis' => $this->isPlatformFounderKillAnonymousPublicApis(),
            'kill_public_commerce' => $this->isPlatformFounderKillPublicCommerce(),
        ];
    }

    /**
     * @param array{kill_online_booking: bool, kill_anonymous_public_apis: bool, kill_public_commerce: bool} $state
     */
    public function setPlatformFounderPublicSurfaceKillSwitches(array $state): void
    {
        $this->clearRequestSettingsReadCache();
        $map = [
            self::PLATFORM_FOUNDER_KILL_ONLINE_BOOKING => (bool) ($state['kill_online_booking'] ?? false),
            self::PLATFORM_FOUNDER_KILL_ANONYMOUS_PUBLIC_APIS => (bool) ($state['kill_anonymous_public_apis'] ?? false),
            self::PLATFORM_FOUNDER_KILL_PUBLIC_COMMERCE => (bool) ($state['kill_public_commerce'] ?? false),
        ];
        foreach ($map as $key => $blocked) {
            $encoded = $this->encode($blocked, 'bool');
            $this->db->query(
                'INSERT INTO settings (`key`, `value`, type, setting_group, organization_id, branch_id, updated_at)
                 VALUES (?, ?, ?, ?, 0, 0, NOW())
                 ON DUPLICATE KEY UPDATE
                    `value` = VALUES(`value`),
                    type = VALUES(type),
                    setting_group = COALESCE(VALUES(setting_group), setting_group),
                    updated_at = NOW()',
                [$key, $encoded, 'bool', self::PLATFORM_SETTINGS_GROUP]
            );
        }
    }

    private function readPlatformDefaultBool(string $key, bool $default): bool
    {
        $row = $this->db->fetchOne(
            'SELECT `value`, type FROM settings WHERE `key` = ? AND organization_id = 0 AND branch_id = 0 LIMIT 1',
            [$key]
        );
        if ($row === null) {
            return $default;
        }

        return (bool) $this->cast($row['value'], $row['type'] ?? 'bool');
    }
}
