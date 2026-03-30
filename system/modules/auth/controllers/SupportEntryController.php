<?php

declare(strict_types=1);

namespace Modules\Auth\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use InvalidArgumentException;
use Modules\Organizations\Services\FounderSupportEntryService;
use Throwable;

/**
 * Tenant-reachable stop for founder support entry (session returns to platform principal).
 */
final class SupportEntryController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private FounderSupportEntryService $supportEntry,
    ) {
    }

    public function postStop(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $name = (string) config('app.csrf_token_name', 'csrf_token');
        $token = (string) ($_POST[$name] ?? '');
        if (!$this->session->validateCsrf($token)) {
            flash('error', 'Invalid security token.');
            header('Location: /dashboard');
            exit;
        }
        if (!$this->session->isSupportEntryActive()) {
            flash('error', 'No active support entry session.');
            header('Location: /platform-admin/access');
            exit;
        }
        try {
            $this->supportEntry->stopActive();
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /dashboard');
            exit;
        } catch (Throwable $e) {
            flash('error', 'Could not end support entry.');
            header('Location: /dashboard');
            exit;
        }
        flash('success', 'Support entry ended. You are signed in again as the platform principal.');
        header('Location: /platform-admin/access');
        exit;
    }
}
