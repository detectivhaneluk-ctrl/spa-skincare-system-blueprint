<?php

declare(strict_types=1);

namespace Modules\Intake\Controllers;

use Core\App\ClientIp;
use Modules\Intake\Services\IntakeFormService;
use Modules\OnlineBooking\Services\PublicBookingAbuseGuardService;

/**
 * Anonymous (no auth middleware) intake completion: every entry requires a valid assignment token in query or POST body.
 * Rate limits reuse {@see PublicBookingAbuseGuardService} (DB or Redis per {@see \Core\Contracts\SlidingWindowRateLimiterInterface}).
 */
final class IntakePublicController
{
    /** Bound after first valid `?token=` hit; cleared after submit or invalid form load. */
    private const SESSION_FORM_TOKEN = '_public_intake_assignment_token';
    /** One-time thanks page; cleared when thanks is shown. */
    private const SESSION_THANKS_TOKEN = '_public_intake_thanks_token';

    /** Align with public commerce catalog/status read tier. */
    private const RL_READ_WINDOW_SECONDS = 60;
    private const RL_READ_MAX_REQUESTS = 40;
    /** Align with public commerce purchase/finalize write tier. */
    private const RL_SUBMIT_WINDOW_SECONDS = 60;
    private const RL_SUBMIT_MAX_REQUESTS = 20;

    /** Same user-facing text as JSON public commerce/booking 429 responses. */
    private const RATE_LIMIT_MESSAGE = 'Too many requests. Please try again later.';

    public function __construct(
        private IntakeFormService $intake,
        private PublicBookingAbuseGuardService $abuseGuard
    ) {
    }

    public function showForm(): void
    {
        $this->enforceAbuseLimit('public_intake_show_ip', self::RL_READ_MAX_REQUESTS, self::RL_READ_WINDOW_SECONDS);
        $qToken = trim((string) ($_GET['token'] ?? ''));
        if ($qToken !== '') {
            if (!$this->intake->loadPublicForm($qToken)) {
                unset($_SESSION[self::SESSION_FORM_TOKEN], $_SESSION[self::SESSION_THANKS_TOKEN]);
                $title = 'Intake form';
                $hideNav = true;
                $error = IntakeFormService::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE;
                require base_path('modules/intake/views/public/error.php');
                return;
            }
            $_SESSION[self::SESSION_FORM_TOKEN] = $qToken;
            header('Location: /public/intake', true, 302);
            exit;
        }

        $token = trim((string) ($_SESSION[self::SESSION_FORM_TOKEN] ?? ''));
        if ($token === '') {
            $title = 'Intake form';
            $hideNav = true;
            $error = IntakeFormService::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE;
            require base_path('modules/intake/views/public/error.php');
            return;
        }
        $loaded = $this->intake->loadPublicForm($token);
        if (!$loaded) {
            unset($_SESSION[self::SESSION_FORM_TOKEN], $_SESSION[self::SESSION_THANKS_TOKEN]);
            $title = 'Intake form';
            $hideNav = true;
            $error = IntakeFormService::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE;
            require base_path('modules/intake/views/public/error.php');
            return;
        }
        $title = 'Intake: ' . (string) ($loaded['assignment']['template_name'] ?? 'Form');
        $hideNav = true;
        $assignment = $loaded['assignment'];
        $fields = $loaded['fields'];
        $tokenValue = $token;
        $errors = [];
        $old = [];
        require base_path('modules/intake/views/public/form.php');
    }

    public function submit(): void
    {
        $this->enforceAbuseLimit('public_intake_submit_ip', self::RL_SUBMIT_MAX_REQUESTS, self::RL_SUBMIT_WINDOW_SECONDS);
        $sessionTok = trim((string) ($_SESSION[self::SESSION_FORM_TOKEN] ?? ''));
        $postTok = trim((string) ($_POST['token'] ?? ''));
        if ($sessionTok === '' || $postTok === '' || !hash_equals($sessionTok, $postTok)) {
            $title = 'Intake form';
            $hideNav = true;
            $error = IntakeFormService::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE;
            require base_path('modules/intake/views/public/error.php');
            return;
        }
        $token = $sessionTok;
        $result = $this->intake->submitPublic($token, $_POST);
        if ($result['ok'] ?? false) {
            unset($_SESSION[self::SESSION_FORM_TOKEN]);
            $_SESSION[self::SESSION_THANKS_TOKEN] = $token;
            header('Location: /public/intake/thanks', true, 302);
            exit;
        }
        $loaded = $this->intake->loadPublicForm($token);
        if (!$loaded) {
            $title = 'Intake form';
            $hideNav = true;
            $error = IntakeFormService::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE;
            require base_path('modules/intake/views/public/error.php');
            return;
        }
        $title = 'Intake: ' . (string) ($loaded['assignment']['template_name'] ?? 'Form');
        $hideNav = true;
        $assignment = $loaded['assignment'];
        $fields = $loaded['fields'];
        $tokenValue = $token;
        $errors = $result['errors'] ?? [];
        $old = $_POST;
        require base_path('modules/intake/views/public/form.php');
    }

    public function thanks(): void
    {
        $this->enforceAbuseLimit('public_intake_thanks_ip', self::RL_READ_MAX_REQUESTS, self::RL_READ_WINDOW_SECONDS);
        $token = trim((string) ($_SESSION[self::SESSION_THANKS_TOKEN] ?? ''));
        if ($token === '' || !$this->intake->publicThanksAllowed($token)) {
            $title = 'Intake form';
            $hideNav = true;
            $error = IntakeFormService::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE;
            require base_path('modules/intake/views/public/error.php');
            return;
        }
        unset($_SESSION[self::SESSION_THANKS_TOKEN]);
        $title = 'Thank you';
        $hideNav = true;
        require base_path('modules/intake/views/public/thanks.php');
    }

    private function enforceAbuseLimit(string $bucket, int $maxRequests, int $windowSeconds): void
    {
        $rl = $this->abuseGuard->consume($bucket, ClientIp::forRequest(), $maxRequests, $windowSeconds);
        if ($rl['ok']) {
            return;
        }
        header('Retry-After: ' . (string) $rl['retry_after']);
        http_response_code(429);
        $title = 'Intake form';
        $hideNav = true;
        $error = self::RATE_LIMIT_MESSAGE;
        require base_path('modules/intake/views/public/error.php');
        exit;
    }
}
