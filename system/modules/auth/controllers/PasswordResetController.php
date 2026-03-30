<?php

declare(strict_types=1);

namespace Modules\Auth\Controllers;

use Core\App\Application;
use Core\Auth\SessionAuth;
use Modules\Auth\Services\PasswordResetService;

final class PasswordResetController
{
    /** Server-side only after email link exchange; not echoed in URLs post-redirect. */
    private const SESSION_PLAIN_RESET_TOKEN = '_password_reset_plain_token';

    public function showRequestForm(): void
    {
        $error = flash('error');
        $success = flash('success');
        $csrf = Application::container()->get(SessionAuth::class)->csrfToken();
        require base_path('modules/auth/views/password-reset-request.php');
    }

    public function submitRequest(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address.');
            header('Location: /password/reset');
            exit;
        }
        $normalized = strtolower($email);
        Application::container()->get(PasswordResetService::class)->initiateResetForEmail(
            $normalized,
            \Core\App\ClientIp::forRequest()
        );
        flash('success', 'If an account exists for that email, we sent password reset instructions.');
        header('Location: /password/reset');
        exit;
    }

    public function showCompleteForm(): void
    {
        $getToken = trim((string) ($_GET['token'] ?? ''));
        $svc = Application::container()->get(PasswordResetService::class);
        if ($getToken !== '') {
            if ($svc->plainResetTokenIsCurrentlyValid($getToken)) {
                $_SESSION[self::SESSION_PLAIN_RESET_TOKEN] = $getToken;
                header('Location: /password/reset/complete', true, 302);
                exit;
            }
            unset($_SESSION[self::SESSION_PLAIN_RESET_TOKEN]);
        }

        $token = trim((string) ($_SESSION[self::SESSION_PLAIN_RESET_TOKEN] ?? ''));
        $error = flash('error');
        $success = flash('success');
        $csrf = Application::container()->get(SessionAuth::class)->csrfToken();
        require base_path('modules/auth/views/password-reset-complete.php');
    }

    public function submitComplete(): void
    {
        $token = trim((string) ($_SESSION[self::SESSION_PLAIN_RESET_TOKEN] ?? ''));
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        try {
            Application::container()->get(PasswordResetService::class)->completeResetWithToken($token, $new, $confirm);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /password/reset/complete', true, 302);
            exit;
        }
        unset($_SESSION[self::SESSION_PLAIN_RESET_TOKEN]);
        flash('success', 'Your password was updated. You can sign in now.');
        header('Location: /login');
        exit;
    }
}
