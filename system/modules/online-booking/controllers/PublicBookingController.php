<?php

declare(strict_types=1);

namespace Modules\OnlineBooking\Controllers;

use Core\App\ClientIp;
use Core\App\Response;
use Core\Audit\AuditService;
use Modules\OnlineBooking\Services\PublicBookingAbuseGuardService;
use Modules\OnlineBooking\Services\PublicBookingService;

/**
 * Public (no auth) booking API. Branch must be supplied; rate-limited by DB-backed abuse guard.
 * GET slots and GET consent-check use **separate** IP buckets (no cross-endpoint starvation).
 * GET slots adds a scoped bucket on branch+service+date+staff+IP; manage-token buckets use per-IP keys when token is empty.
 * Manage-token handlers return **403** when branch-effective `online_booking.enabled` is false (see {@see PublicBookingService::ERROR_MANAGE_SELF_SERVICE_DISABLED}).
 */
final class PublicBookingController
{
    /** Per-endpoint IP read limits (PB-ABUSE-GUARD-IDENTITY-DEPTH-01). */
    private const RATE_LIMIT_READ_SLOTS_IP_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_READ_SLOTS_IP_MAX_REQUESTS = 40;
    private const RATE_LIMIT_READ_CONSENT_IP_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_READ_CONSENT_IP_MAX_REQUESTS = 40;

    /** GET slots: composite calendar cell + IP (reduces date/service enumeration from one IP). */
    private const RATE_LIMIT_READ_SLOTS_SCOPE_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_READ_SLOTS_SCOPE_MAX_REQUESTS = 36;

    /** GET consent-check: per (branch_id, IP) in addition to global IP read (branch probe spam). */
    private const RATE_LIMIT_READ_CONSENT_BRANCH_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_READ_CONSENT_BRANCH_MAX_REQUESTS = 24;
    private const RATE_LIMIT_MANAGE_READ_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_MANAGE_READ_MAX_REQUESTS = 20;
    private const RATE_LIMIT_MANAGE_WRITE_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_MANAGE_WRITE_MAX_REQUESTS = 10;
    private const RATE_LIMIT_MANAGE_TOKEN_WINDOW_SECONDS = 300;
    private const RATE_LIMIT_MANAGE_TOKEN_MAX_REQUESTS = 30;
    private const RATE_LIMIT_MANAGE_MUTATION_TOKEN_WINDOW_SECONDS = 300;
    private const RATE_LIMIT_MANAGE_MUTATION_TOKEN_MAX_REQUESTS = 8;
    private const RATE_LIMIT_MANAGE_MUTATION_FINGERPRINT_WINDOW_SECONDS = 300;
    private const RATE_LIMIT_MANAGE_MUTATION_FINGERPRINT_MAX_REQUESTS = 6;

    /** POST create booking. */
    private const RATE_LIMIT_BOOK_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_BOOK_MAX_REQUESTS = 6;
    private const RATE_LIMIT_BOOK_FINGERPRINT_WINDOW_SECONDS = 300;
    private const RATE_LIMIT_BOOK_FINGERPRINT_MAX_REQUESTS = 2;

    /** Non-IP: normalized contact (email, else phone, else anonymous), distributed abuse (PB-HARDEN-ABUSE-01). */
    private const RATE_LIMIT_BOOK_CONTACT_WINDOW_SECONDS = 3600;
    private const RATE_LIMIT_BOOK_CONTACT_MAX_REQUESTS = 10;

    /** Non-IP: slot pressure branch+service+staff+start (PB-HARDEN-ABUSE-01). */
    private const RATE_LIMIT_BOOK_SLOT_WINDOW_SECONDS = 300;
    private const RATE_LIMIT_BOOK_SLOT_MAX_REQUESTS = 12;

    public function __construct(
        private PublicBookingService $bookingService,
        private PublicBookingAbuseGuardService $abuseGuard,
        private AuditService $audit
    )
    {
    }

    /**
     * GET public slots. Query: branch_id (required), service_id (required), date (YYYY-MM-DD), staff_id (optional).
     */
    public function slots(): void
    {
        $branchId = $this->intParam('branch_id', 0);
        $serviceId = $this->intParam('service_id', 0);
        $date = trim((string) ($_GET['date'] ?? ''));
        $staffId = $this->intParam('staff_id', null);
        if ($branchId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'branch_id is required.');
        }
        if ($serviceId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'service_id is required.');
        }
        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Date must be YYYY-MM-DD.');
        }

        $rl = $this->rateLimitConsume('read_slots_ip', self::RATE_LIMIT_READ_SLOTS_IP_MAX_REQUESTS, self::RATE_LIMIT_READ_SLOTS_IP_WINDOW_SECONDS);
        if (!$rl['ok']) {
            $this->logRateLimited('slots', 'read_slots_ip', $rl['retry_after']);
            $this->jsonRateLimited((int) $rl['retry_after']);
            return;
        }
        $staffPart = $staffId !== null && $staffId > 0 ? (string) $staffId : 'any';
        $scopeRl = $this->rateLimitConsumeScoped(
            'read_slots_scope',
            'public_slots_scope|b:' . $branchId . '|s:' . $serviceId . '|d:' . $date . '|st:' . $staffPart,
            self::RATE_LIMIT_READ_SLOTS_SCOPE_MAX_REQUESTS,
            self::RATE_LIMIT_READ_SLOTS_SCOPE_WINDOW_SECONDS
        );
        if (!$scopeRl['ok']) {
            $this->logRateLimited('slots', 'read_slots_scope', $scopeRl['retry_after'], [
                'branch_id' => $branchId,
                'service_id' => $serviceId,
                'marker_prefix' => $scopeRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $scopeRl['retry_after']);
            return;
        }

        $result = $this->bookingService->getPublicSlots($branchId, $serviceId, $date, $staffId > 0 ? $staffId : null);
        if (!$result['success'] && (($result['error'] ?? '') === PublicBookingService::ERROR_ORGANIZATION_SUSPENDED)) {
            Response::jsonPublicApiError(403, 'ORGANIZATION_SUSPENDED', 'Public booking is unavailable for this branch.');
        }
        if ($result['success'] ?? false) {
            $this->json($result, 200);
            return;
        }
        $this->jsonPublicBookingFailure($result, 422);
    }

    /**
     * POST create booking. Body: branch_id, service_id, staff_id, start_time (Y-m-d H:i or H:i with date),
     * first_name, last_name, email, phone (optional). notes (optional). client_membership_id (optional, digits only).
     * Anonymous public requests must not send client_id (PB-HARDEN-NEXT); positive client_id returns a generic 422.
     * Rate-limited per IP, per composite fingerprint (IP-inclusive), per contact identity (non-IP), and per slot (non-IP); PB-HARDEN-ABUSE-01.
     */
    public function book(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');
        }
        $branchId = $this->intParam('branch_id', 0, true);
        $serviceId = $this->intParam('service_id', 0, true);
        $staffId = $this->intParam('staff_id', 0, true);
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $date = trim((string) ($_POST['date'] ?? ''));
        if ($date !== '' && $startTime !== '' && preg_match('/^\d{1,2}:\d{2}/', $startTime)) {
            $startTime = $date . ' ' . $startTime;
        }
        if ($branchId <= 0 || $serviceId <= 0 || $staffId <= 0 || $startTime === '') {
            $this->logRequestRejected('book', 'invalid_required_payload', [
                'branch_id' => $this->positiveIdOrNull($branchId),
                'service_id' => $this->positiveIdOrNull($serviceId),
                'staff_id' => $this->positiveIdOrNull($staffId),
            ]);
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', PublicBookingService::ERROR_PUBLIC_BOOKING_GENERIC);
        }
        $rawClientId = trim((string) ($_POST['client_id'] ?? ''));
        if ($rawClientId !== '' && (int) $rawClientId > 0) {
            $this->logRequestRejected('book', 'client_id_not_allowed', [
                'branch_id' => $this->positiveIdOrNull($branchId),
                'service_id' => $this->positiveIdOrNull($serviceId),
                'staff_id' => $this->positiveIdOrNull($staffId),
            ]);
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', PublicBookingService::ERROR_PUBLIC_BOOKING_GENERIC);
        }
        $clientPayload = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
        ];

        $rawClientMembership = trim((string) ($_POST['client_membership_id'] ?? ''));
        $clientMembershipId = null;
        if ($rawClientMembership !== '') {
            if (!ctype_digit($rawClientMembership)) {
                $this->logRequestRejected('book', 'invalid_client_membership_id', [
                    'branch_id' => $this->positiveIdOrNull($branchId),
                    'service_id' => $this->positiveIdOrNull($serviceId),
                    'staff_id' => $this->positiveIdOrNull($staffId),
                ]);
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', PublicBookingService::ERROR_PUBLIC_BOOKING_GENERIC);
            }
            $clientMembershipId = (int) $rawClientMembership;
            if ($clientMembershipId <= 0) {
                $this->logRequestRejected('book', 'invalid_client_membership_id', [
                    'branch_id' => $this->positiveIdOrNull($branchId),
                    'service_id' => $this->positiveIdOrNull($serviceId),
                    'staff_id' => $this->positiveIdOrNull($staffId),
                ]);
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', PublicBookingService::ERROR_PUBLIC_BOOKING_GENERIC);
            }
        }

        $rl = $this->rateLimitConsume('book', self::RATE_LIMIT_BOOK_MAX_REQUESTS, self::RATE_LIMIT_BOOK_WINDOW_SECONDS);
        if (!$rl['ok']) {
            $this->logRateLimited('book', 'book', $rl['retry_after'], [
                'branch_id' => $this->positiveIdOrNull($branchId),
                'service_id' => $this->positiveIdOrNull($serviceId),
                'staff_id' => $this->positiveIdOrNull($staffId),
            ]);
            $this->jsonRateLimited((int) $rl['retry_after']);
            return;
        }
        $contactRl = $this->rateLimitConsumeBookContact($clientPayload);
        if (!$contactRl['ok']) {
            $this->logRateLimited('book', 'book_contact', $contactRl['retry_after'], [
                'branch_id' => $this->positiveIdOrNull($branchId),
                'service_id' => $this->positiveIdOrNull($serviceId),
                'staff_id' => $this->positiveIdOrNull($staffId),
                'marker_prefix' => $contactRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $contactRl['retry_after']);
            return;
        }
        $slotRl = $this->rateLimitConsumeBookSlot($branchId, $serviceId, $staffId, $startTime);
        if (!$slotRl['ok']) {
            $this->logRateLimited('book', 'book_slot', $slotRl['retry_after'], [
                'branch_id' => $this->positiveIdOrNull($branchId),
                'service_id' => $this->positiveIdOrNull($serviceId),
                'staff_id' => $this->positiveIdOrNull($staffId),
                'normalized_start_at' => $slotRl['normalized_start_at'] ?? null,
                'marker_prefix' => $slotRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $slotRl['retry_after']);
            return;
        }
        $fingerprintRl = $this->rateLimitConsumeBookFingerprint($branchId, $serviceId, $staffId, $startTime, $clientPayload);
        if (!$fingerprintRl['ok']) {
            $this->logRateLimited('book', 'book_fingerprint', $fingerprintRl['retry_after'], [
                'branch_id' => $this->positiveIdOrNull($branchId),
                'service_id' => $this->positiveIdOrNull($serviceId),
                'staff_id' => $this->positiveIdOrNull($staffId),
                'normalized_start_at' => $fingerprintRl['normalized_start_at'] ?? null,
                'marker_prefix' => $fingerprintRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $fingerprintRl['retry_after']);
            return;
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $result = $this->bookingService->createBooking(
            $branchId,
            $serviceId,
            $staffId,
            $startTime,
            $clientPayload,
            $notes !== '' ? $notes : null,
            $clientMembershipId
        );
        if (!$result['success'] && (($result['error'] ?? '') === PublicBookingService::ERROR_ORGANIZATION_SUSPENDED)) {
            Response::jsonPublicApiError(403, 'ORGANIZATION_SUSPENDED', 'Public booking is unavailable for this branch.');
        }
        if ($result['success'] ?? false) {
            $this->json($result, 201);
            return;
        }
        $this->jsonPublicBookingFailure($result, 422);
    }

    /**
     * GET /api/public/booking/consent-check — route retained for URL stability; does not return per-client consent state (PB-HARDEN-08).
     * Query: branch_id (required). Consent is enforced on POST book via AppointmentService (unchanged).
     */
    public function consentCheck(): void
    {
        $branchId = $this->intParam('branch_id', 0);
        if ($branchId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'branch_id is required.');
        }

        $rl = $this->rateLimitConsume('read_consent_ip', self::RATE_LIMIT_READ_CONSENT_IP_MAX_REQUESTS, self::RATE_LIMIT_READ_CONSENT_IP_WINDOW_SECONDS);
        if (!$rl['ok']) {
            $this->logRateLimited('consent_check', 'read_consent_ip', $rl['retry_after'], ['branch_id' => $branchId]);
            $this->jsonRateLimited((int) $rl['retry_after']);
            return;
        }
        $branchRl = $this->rateLimitConsumeScoped(
            'read_consent_branch',
            'public_consent_branch|b:' . $branchId,
            self::RATE_LIMIT_READ_CONSENT_BRANCH_MAX_REQUESTS,
            self::RATE_LIMIT_READ_CONSENT_BRANCH_WINDOW_SECONDS
        );
        if (!$branchRl['ok']) {
            $this->logRateLimited('consent_check', 'read_consent_branch', $branchRl['retry_after'], [
                'branch_id' => $branchId,
                'marker_prefix' => $branchRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $branchRl['retry_after']);
            return;
        }

        $gate = $this->bookingService->requireBranchAnonymousPublicBookingApi($branchId, 'consent_check');
        if (!$gate['ok']) {
            if (($gate['error'] ?? '') === PublicBookingService::ERROR_ORGANIZATION_SUSPENDED) {
                Response::jsonPublicApiError(403, 'ORGANIZATION_SUSPENDED', 'Public booking is unavailable for this branch.');
            }
            Response::jsonPublicApiError(422, 'REQUEST_FAILED', (string) ($gate['error'] ?? PublicBookingService::ERROR_PUBLIC_BOOKING_GENERIC));
        }
        Response::jsonPublicApiError(410, 'CONSENT_CHECK_DISABLED', 'Public consent status lookup is disabled. Required consents are enforced when you submit a booking.');
    }

    /**
     * POST /api/public/booking/manage — JSON or form body: `token` (read-only manage lookup).
     */
    public function manageLookup(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed. Use POST with JSON body {"token":"..."} or form field token.');
        }
        $rl = $this->rateLimitConsume('manage_read', self::RATE_LIMIT_MANAGE_READ_MAX_REQUESTS, self::RATE_LIMIT_MANAGE_READ_WINDOW_SECONDS);
        if (!$rl['ok']) {
            $this->logRateLimited('manage_lookup', 'manage_read', $rl['retry_after']);
            $this->jsonRateLimited((int) $rl['retry_after']);
            return;
        }

        $body = $this->readJsonOrFormBody();
        $token = trim((string) ($body['token'] ?? ''));
        $tokenRl = $this->rateLimitConsumeManageToken('manage_token_read', $token, self::RATE_LIMIT_MANAGE_TOKEN_MAX_REQUESTS, self::RATE_LIMIT_MANAGE_TOKEN_WINDOW_SECONDS);
        if (!$tokenRl['ok']) {
            $this->logRateLimited('manage_lookup', 'manage_token_read', $tokenRl['retry_after'], [
                'marker_prefix' => $tokenRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $tokenRl['retry_after']);
            return;
        }
        try {
            $result = $this->bookingService->getManageLookupByToken($token);
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Service temporarily unavailable. Please try again later.');
        }
        $this->jsonManageFailure($result);
    }

    /**
     * POST /api/public/booking/manage/cancel — token-authenticated public self-service cancellation.
     */
    public function manageCancel(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');
        }

        $rl = $this->rateLimitConsume('manage_write', self::RATE_LIMIT_MANAGE_WRITE_MAX_REQUESTS, self::RATE_LIMIT_MANAGE_WRITE_WINDOW_SECONDS);
        if (!$rl['ok']) {
            $this->logRateLimited('manage_cancel', 'manage_write', $rl['retry_after']);
            $this->jsonRateLimited((int) $rl['retry_after']);
            return;
        }

        $body = $this->readJsonOrFormBody();
        $token = trim((string) ($body['token'] ?? ''));
        $tokenRl = $this->rateLimitConsumeManageToken('manage_token_write', $token, self::RATE_LIMIT_MANAGE_MUTATION_TOKEN_MAX_REQUESTS, self::RATE_LIMIT_MANAGE_MUTATION_TOKEN_WINDOW_SECONDS);
        if (!$tokenRl['ok']) {
            $this->logRateLimited('manage_cancel', 'manage_token_write', $tokenRl['retry_after'], [
                'marker_prefix' => $tokenRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $tokenRl['retry_after']);
            return;
        }
        $fingerprintRl = $this->rateLimitConsumeManageMutationFingerprint($token);
        if (!$fingerprintRl['ok']) {
            $this->logRateLimited('manage_cancel', 'manage_mutation_fingerprint', $fingerprintRl['retry_after'], [
                'marker_prefix' => $fingerprintRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $fingerprintRl['retry_after']);
            return;
        }
        $reason = trim((string) ($body['reason'] ?? ''));
        $reasonIdRaw = $body['cancellation_reason_id'] ?? ($body['reason_id'] ?? '');
        $reasonId = trim((string) $reasonIdRaw) !== '' ? (int) $reasonIdRaw : null;
        try {
            $result = $this->bookingService->cancelByManageToken($token, $reason !== '' ? $reason : null, $reasonId);
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Service temporarily unavailable. Please try again later.');
        }

        $this->jsonManageFailure($result);
    }

    /**
     * POST /api/public/booking/manage/reschedule — token-authenticated public self-service rescheduling.
     */
    public function manageReschedule(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');
        }

        $rl = $this->rateLimitConsume('manage_write', self::RATE_LIMIT_MANAGE_WRITE_MAX_REQUESTS, self::RATE_LIMIT_MANAGE_WRITE_WINDOW_SECONDS);
        if (!$rl['ok']) {
            $this->logRateLimited('manage_reschedule', 'manage_write', $rl['retry_after']);
            $this->jsonRateLimited((int) $rl['retry_after']);
            return;
        }

        $body = $this->readJsonOrFormBody();
        $token = trim((string) ($body['token'] ?? ''));
        $tokenRl = $this->rateLimitConsumeManageToken('manage_token_write', $token, self::RATE_LIMIT_MANAGE_MUTATION_TOKEN_MAX_REQUESTS, self::RATE_LIMIT_MANAGE_MUTATION_TOKEN_WINDOW_SECONDS);
        if (!$tokenRl['ok']) {
            $this->logRateLimited('manage_reschedule', 'manage_token_write', $tokenRl['retry_after'], [
                'marker_prefix' => $tokenRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $tokenRl['retry_after']);
            return;
        }
        $fingerprintRl = $this->rateLimitConsumeManageMutationFingerprint($token);
        if (!$fingerprintRl['ok']) {
            $this->logRateLimited('manage_reschedule', 'manage_mutation_fingerprint', $fingerprintRl['retry_after'], [
                'marker_prefix' => $fingerprintRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $fingerprintRl['retry_after']);
            return;
        }
        $startAt = trim((string) ($body['start_at'] ?? ''));
        try {
            $result = $this->bookingService->rescheduleByManageToken($token, $startAt);
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Service temporarily unavailable. Please try again later.');
        }

        $this->jsonManageFailure($result);
    }

    /**
     * POST /api/public/booking/manage/slots — JSON or form: `token`, `date` (YYYY-MM-DD).
     */
    public function manageRescheduleSlots(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Response::jsonPublicApiError(405, 'METHOD_NOT_ALLOWED', 'Method not allowed. Use POST with JSON body {"token":"...","date":"YYYY-MM-DD"}.');
        }
        $rl = $this->rateLimitConsume('manage_read', self::RATE_LIMIT_MANAGE_READ_MAX_REQUESTS, self::RATE_LIMIT_MANAGE_READ_WINDOW_SECONDS);
        if (!$rl['ok']) {
            $this->logRateLimited('manage_reschedule_slots', 'manage_read', $rl['retry_after']);
            $this->jsonRateLimited((int) $rl['retry_after']);
            return;
        }

        $body = $this->readJsonOrFormBody();
        $token = trim((string) ($body['token'] ?? ''));
        $tokenRl = $this->rateLimitConsumeManageToken('manage_token_read', $token, self::RATE_LIMIT_MANAGE_TOKEN_MAX_REQUESTS, self::RATE_LIMIT_MANAGE_TOKEN_WINDOW_SECONDS);
        if (!$tokenRl['ok']) {
            $this->logRateLimited('manage_reschedule_slots', 'manage_token_read', $tokenRl['retry_after'], [
                'marker_prefix' => $tokenRl['marker_prefix'] ?? null,
            ]);
            $this->jsonRateLimited((int) $tokenRl['retry_after']);
            return;
        }
        $date = trim((string) ($body['date'] ?? ''));
        try {
            $result = $this->bookingService->getManageRescheduleSlotsByToken($token, $date);
        } catch (\Throwable) {
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Service temporarily unavailable. Please try again later.');
        }
        $this->jsonManageFailure($result);
    }

    private function jsonRateLimited(int $retryAfter): void
    {
        Response::jsonPublicApiError(429, 'TOO_MANY_ATTEMPTS', 'Too many requests. Please try again later.', null, $retryAfter);
    }

    /**
     * @param array{success?: bool, error?: string} $result
     */
    private function jsonPublicBookingFailure(array $result, int $httpStatus = 422): void
    {
        $err = (string) ($result['error'] ?? '');
        if ($err === PublicBookingService::ERROR_ORGANIZATION_SUSPENDED) {
            Response::jsonPublicApiError(403, 'ORGANIZATION_SUSPENDED', 'Public booking is unavailable for this branch.');
        }
        $code = match ($err) {
            PublicBookingService::ERROR_ALLOW_NEW_CLIENTS_OFF => 'NEW_CLIENTS_NOT_ALLOWED',
            default => 'REQUEST_FAILED',
        };
        Response::jsonPublicApiError($httpStatus, $code, $err !== '' ? $err : PublicBookingService::ERROR_PUBLIC_BOOKING_GENERIC);
    }

    /**
     * @param array{success?: bool, error?: string, ...} $result
     */
    private function jsonManageFailure(array $result): void
    {
        if ($result['success'] ?? false) {
            $this->json($result, 200);
            return;
        }
        $err = (string) ($result['error'] ?? '');
        $http = $this->manageFailureHttpStatus($err);
        $code = match ($err) {
            PublicBookingService::ERROR_MANAGE_LOOKUP_INVALID => 'MANAGE_TOKEN_INVALID',
            PublicBookingService::ERROR_MANAGE_SELF_SERVICE_DISABLED => 'SELF_SERVICE_DISABLED',
            PublicBookingService::ERROR_MANAGE_CANCEL_UNAVAILABLE => 'MANAGE_CANCEL_UNAVAILABLE',
            PublicBookingService::ERROR_MANAGE_RESCHEDULE_UNAVAILABLE => 'MANAGE_RESCHEDULE_UNAVAILABLE',
            PublicBookingService::ERROR_MANAGE_RESCHEDULE_SLOTS_UNAVAILABLE => 'MANAGE_RESCHEDULE_SLOTS_UNAVAILABLE',
            default => 'REQUEST_FAILED',
        };
        Response::jsonPublicApiError($http, $code, $err !== '' ? $err : PublicBookingService::ERROR_PUBLIC_BOOKING_GENERIC);
    }

    /** 404 invalid token, 403 online booking self-service off, else 422 for policy/slot failures. */
    private function manageFailureHttpStatus(?string $error): int
    {
        $e = (string) ($error ?? '');
        if ($e === PublicBookingService::ERROR_MANAGE_LOOKUP_INVALID) {
            return 404;
        }
        if ($e === PublicBookingService::ERROR_MANAGE_SELF_SERVICE_DISABLED) {
            return 403;
        }

        return 422;
    }

    private function intParam(string $key, int|null $default, bool $fromPost = false): int|null
    {
        $raw = $fromPost ? ($_POST[$key] ?? $_GET[$key] ?? '') : ($_GET[$key] ?? '');
        if ($raw === '' || $raw === null) {
            return $default;
        }
        $v = (int) $raw;
        return $default === null && $v <= 0 ? null : $v;
    }

    /**
     * Sliding window per IP and named bucket (book, read_slots_ip, read_consent_ip, …).
     *
     * @return array{ok: true}|array{ok: false, retry_after: int}
     */
    private function rateLimitConsume(string $bucket, int $maxRequests, int $windowSeconds): array
    {
        $ip = ClientIp::forRequest();
        return $this->abuseGuard->consume($bucket, 'ip:' . $ip, $maxRequests, $windowSeconds);
    }

    /**
     * Scoped throttle: throttle key includes IP (no raw PII). Used for calendar-cell and consent-branch limits.
     *
     * @return array{ok: true}|array{ok: false, retry_after: int, marker_prefix?: string}
     */
    private function rateLimitConsumeScoped(string $bucket, string $throttleKeyCore, int $maxRequests, int $windowSeconds): array
    {
        $ip = ClientIp::forRequest();
        $throttleKey = $throttleKeyCore . '|ip:' . $ip;
        $result = $this->abuseGuard->consume($bucket, $throttleKey, $maxRequests, $windowSeconds);
        if (!$result['ok']) {
            $result['marker_prefix'] = substr(hash('sha256', $bucket . "\0" . $throttleKey), 0, 12);
        }

        return $result;
    }

    /**
     * Token-scoped reads/writes: per-token hash when token present; **per-IP** when empty so one actor cannot
     * exhaust a single global bucket for all empty-token probes (PB-ABUSE-GUARD-IDENTITY-DEPTH-01).
     *
     * @return array{ok: true}|array{ok: false, retry_after: int, marker_prefix: string}
     */
    private function rateLimitConsumeManageToken(string $bucket, string $token, int $maxRequests, int $windowSeconds): array
    {
        $normalizedToken = trim($token);
        $ip = ClientIp::forRequest();
        if ($normalizedToken !== '') {
            $tokenHash = hash('sha256', $normalizedToken);
            $key = hash('sha256', $bucket . '|token_hash:' . $tokenHash);
            $markerSource = $tokenHash;
        } else {
            $key = hash('sha256', $bucket . '|token_empty|ip:' . $ip);
            $markerSource = $key;
        }
        $result = $this->abuseGuard->consume($bucket, $key, $maxRequests, $windowSeconds);
        if (!$result['ok']) {
            $result['marker_prefix'] = substr($markerSource, 0, 12);
        }
        return $result;
    }

    /**
     * @return array{ok: true}|array{ok: false, retry_after: int, marker_prefix: string}
     */
    private function rateLimitConsumeManageMutationFingerprint(string $token): array
    {
        $ip = ClientIp::forRequest();
        $normalizedToken = trim($token);
        $tokenHash = $normalizedToken !== '' ? hash('sha256', $normalizedToken) : hash('sha256', 'missing');
        $fingerprint = hash('sha256', 'manage_mutation|token_hash:' . $tokenHash . '|ip:' . $ip);
        $result = $this->abuseGuard->consume(
            'manage_mutation_fingerprint',
            $fingerprint,
            self::RATE_LIMIT_MANAGE_MUTATION_FINGERPRINT_MAX_REQUESTS,
            self::RATE_LIMIT_MANAGE_MUTATION_FINGERPRINT_WINDOW_SECONDS
        );
        if (!$result['ok']) {
            $result['marker_prefix'] = substr($fingerprint, 0, 12);
        }
        return $result;
    }

    /**
     * @return array{ok: true}|array{ok: false, retry_after: int}
     */
    private function rateLimitConsumeBookContact(array $clientPayload): array
    {
        $identity = $this->normalizeClientIdentityForFingerprint($clientPayload);
        $key = hash('sha256', 'book_contact|' . $identity);
        $markerPrefix = substr($key, 0, 12);
        $result = $this->abuseGuard->consume(
            'book_contact',
            $key,
            self::RATE_LIMIT_BOOK_CONTACT_MAX_REQUESTS,
            self::RATE_LIMIT_BOOK_CONTACT_WINDOW_SECONDS
        );

        if (!$result['ok']) {
            $result['marker_prefix'] = $markerPrefix;
        }

        return $result;
    }

    /**
     * @return array{ok: true}|array{ok: false, retry_after: int}
     */
    private function rateLimitConsumeBookSlot(
        int $branchId,
        int $serviceId,
        int $staffId,
        string $startAtRaw
    ): array {
        $normalizedStartAt = $this->normalizeStartForFingerprint($startAtRaw);
        $key = hash('sha256', 'book_slot|branch:' . $branchId . '|service:' . $serviceId . '|staff:' . $staffId . '|start:' . $normalizedStartAt);
        $markerPrefix = substr($key, 0, 12);
        $result = $this->abuseGuard->consume(
            'book_slot',
            $key,
            self::RATE_LIMIT_BOOK_SLOT_MAX_REQUESTS,
            self::RATE_LIMIT_BOOK_SLOT_WINDOW_SECONDS
        );

        if (!$result['ok']) {
            $result['marker_prefix'] = $markerPrefix;
            $result['normalized_start_at'] = $normalizedStartAt !== 'invalid' ? $normalizedStartAt : null;
        }

        return $result;
    }

    /**
     * @return array{ok: true}|array{ok: false, retry_after: int}
     */
    private function rateLimitConsumeBookFingerprint(
        int $branchId,
        int $serviceId,
        int $staffId,
        string $startAtRaw,
        array $clientPayload
    ): array {
        $ip = ClientIp::forRequest();
        $normalizedStartAt = $this->normalizeStartForFingerprint($startAtRaw);
        $identity = $this->normalizeClientIdentityForFingerprint($clientPayload);
        $fingerprint = implode('|', [
            'branch:' . $branchId,
            'service:' . $serviceId,
            'staff:' . $staffId,
            'start:' . $normalizedStartAt,
            'client:' . $identity,
            'ip:' . $ip,
        ]);

        $fingerprintHash = hash('sha256', $fingerprint);
        $markerPrefix = substr($fingerprintHash, 0, 12);
        $result = $this->abuseGuard->consume(
            'book_fingerprint',
            $fingerprintHash,
            self::RATE_LIMIT_BOOK_FINGERPRINT_MAX_REQUESTS,
            self::RATE_LIMIT_BOOK_FINGERPRINT_WINDOW_SECONDS
        );

        if (!$result['ok']) {
            $result['marker_prefix'] = $markerPrefix;
            $result['normalized_start_at'] = $normalizedStartAt !== 'invalid' ? $normalizedStartAt : null;
        }

        return $result;
    }

    private function logRateLimited(string $endpoint, string $bucket, int $retryAfter, array $meta = []): void
    {
        $metadata = array_filter(array_merge($meta, [
            'endpoint' => $endpoint,
            'deny_reason' => 'rate_limited',
            'bucket' => $bucket,
            'retry_after' => $retryAfter,
        ]), static fn ($value): bool => $value !== null);

        $this->logPublicAudit('public_booking_rate_limited', $metadata);
    }

    private function logRequestRejected(string $endpoint, string $denyReason, array $meta = []): void
    {
        $metadata = array_filter(array_merge($meta, [
            'endpoint' => $endpoint,
            'deny_reason' => $denyReason,
        ]), static fn ($value): bool => $value !== null);

        $this->logPublicAudit('public_booking_request_rejected', $metadata);
    }

    private function logPublicAudit(string $action, array $metadata): void
    {
        $this->audit->log($action, 'public_booking', null, null, $metadata['branch_id'] ?? null, $metadata);
    }

    private function positiveIdOrNull(int $value): ?int
    {
        return $value > 0 ? $value : null;
    }

    private function normalizeStartForFingerprint(string $startAtRaw): string
    {
        $ts = strtotime(trim($startAtRaw));
        if ($ts === false) {
            return 'invalid';
        }
        return date('Y-m-d H:i:00', $ts);
    }

    private function normalizeClientIdentityForFingerprint(array $clientPayload): string
    {
        $email = strtolower(trim((string) ($clientPayload['email'] ?? '')));
        if ($email !== '') {
            return 'email:' . $email;
        }
        $phoneRaw = trim((string) ($clientPayload['phone'] ?? ''));
        $phone = preg_replace('/[^0-9+]/', '', $phoneRaw) ?? '';
        if ($phone !== '') {
            return 'phone:' . $phone;
        }
        return 'anonymous';
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonOrFormBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return is_array($_POST) ? $_POST : [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($_POST) ? $_POST : [];
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

}
