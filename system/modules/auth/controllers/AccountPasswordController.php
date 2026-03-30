<?php

declare(strict_types=1);

namespace Modules\Auth\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Auth\SessionAuth;

final class AccountPasswordController
{
    public function show(): void
    {
        $error = flash('error');
        $success = flash('success');
        $csrf = Application::container()->get(SessionAuth::class)->csrfToken();
        require base_path('modules/auth/views/account-password.php');
    }

    public function update(): void
    {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($new !== $confirm) {
            flash('error', 'New password and confirmation do not match.');
            header('Location: /account/password');
            exit;
        }
        try {
            Application::container()->get(AuthService::class)->updatePasswordForCurrentUser($current, $new);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /account/password');
            exit;
        }
        unset($_SESSION[SessionAuth::SESSION_PASSWORD_EXPIRY_BLOCK_AUDIT]);
        flash('success', 'Password updated.');
        header('Location: /appointments/calendar/day');
        exit;
    }
}
