<?php

declare(strict_types=1);

namespace Modules\Memberships\Controllers;

use Core\App\Application;
use Core\Audit\AuditService;
use Modules\Memberships\Services\MembershipLifecycleService;

/**
 * HTTP entry for client_membership lifecycle — delegates only to {@see MembershipLifecycleService}.
 */
final class MembershipLifecycleController
{
    /** Matches `client_memberships.lifecycle_reason` column (migration 069). */
    private const REASON_MAX_LEN = 500;

    private const REDIRECT_LIST = '/memberships/client-memberships';

    public function __construct(
        private MembershipLifecycleService $lifecycle,
        private AuditService $audit
    ) {
    }

    public function pause(int $id): void
    {
        $this->run($id, 'pause', function (?string $reason) use ($id): void {
            $this->lifecycle->pauseNow($id, $reason);
        });
    }

    public function resume(int $id): void
    {
        $this->run($id, 'resume', function (?string $reason) use ($id): void {
            $this->lifecycle->resumeNow($id, $reason);
        });
    }

    public function scheduleCancelAtPeriodEnd(int $id): void
    {
        $this->run($id, 'schedule_cancel_at_period_end', function (?string $reason) use ($id): void {
            $this->lifecycle->scheduleCancellationAtPeriodEnd($id, $reason);
        });
    }

    public function revokeScheduledCancel(int $id): void
    {
        $this->run($id, 'revoke_scheduled_cancel', function (?string $reason) use ($id): void {
            $this->lifecycle->revokeScheduledCancellation($id, $reason);
        });
    }

    /**
     * @param callable(?string): void $invoke
     */
    private function run(int $clientMembershipId, string $action, callable $invoke): void
    {
        if ($clientMembershipId <= 0) {
            $this->denyValidation($action, $clientMembershipId, 'Invalid membership id.');
            return;
        }

        $reason = $this->optionalReason();

        try {
            $invoke($reason);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Client membership not found.') {
                $this->audit->log('membership_lifecycle_http_denied', 'client_membership', $clientMembershipId, $this->actorId(), null, [
                    'action' => $action,
                    'denial_reason' => 'not_found',
                ]);
                $this->respond($action, 'Membership not found.', 404);
                return;
            }
            $this->audit->log('membership_lifecycle_http_error', 'client_membership', $clientMembershipId, $this->actorId(), null, [
                'action' => $action,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->respond($action, 'Could not update membership.', 500);
            return;
        } catch (\DomainException $e) {
            $msg = $e->getMessage();
            $denialReason = $this->isBranchMismatchMessage($msg) ? 'branch_mismatch' : 'invalid_transition';
            $this->audit->log('membership_lifecycle_http_denied', 'client_membership', $clientMembershipId, $this->actorId(), null, [
                'action' => $action,
                'denial_reason' => $denialReason,
                'message' => $msg,
            ]);
            $this->respond($action, $msg, 422);
            return;
        } catch (\Throwable $e) {
            $this->audit->log('membership_lifecycle_http_error', 'client_membership', $clientMembershipId, $this->actorId(), null, [
                'action' => $action,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->respond($action, 'Could not update membership.', 500);
            return;
        }

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'action' => $action,
                'client_membership_id' => $clientMembershipId,
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $messages = [
            'pause' => 'Membership paused.',
            'resume' => 'Membership resumed.',
            'schedule_cancel_at_period_end' => 'Cancellation scheduled at period end.',
            'revoke_scheduled_cancel' => 'Scheduled cancellation revoked.',
        ];
        flash('success', $messages[$action] ?? 'Membership updated.');
        header('Location: ' . self::REDIRECT_LIST);
        exit;
    }

    private function denyValidation(string $action, int $clientMembershipId, string $message): void
    {
        $this->audit->log('membership_lifecycle_http_denied', 'client_membership', $clientMembershipId > 0 ? $clientMembershipId : null, $this->actorId(), null, [
            'action' => $action,
            'denial_reason' => 'validation',
            'message' => $message,
        ]);
        $this->respond($action, $message, 422);
    }

    private function optionalReason(): ?string
    {
        $a = trim((string) ($_POST['reason'] ?? ''));
        if ($a !== '') {
            return $this->truncateReason($a);
        }
        $b = trim((string) ($_POST['lifecycle_reason'] ?? ''));
        if ($b !== '') {
            return $this->truncateReason($b);
        }

        return null;
    }

    private function truncateReason(string $s): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, self::REASON_MAX_LEN, 'UTF-8');
        }

        return strlen($s) <= self::REASON_MAX_LEN ? $s : substr($s, 0, self::REASON_MAX_LEN);
    }

    private function isBranchMismatchMessage(string $msg): bool
    {
        return str_contains($msg, 'belongs to another branch');
    }

    private function respond(string $action, string $message, int $httpCode): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($httpCode);
            $code = match (true) {
                $httpCode === 404 => 'MEMBERSHIP_LIFECYCLE_NOT_FOUND',
                $httpCode === 422 => 'MEMBERSHIP_LIFECYCLE_INVALID',
                default => 'MEMBERSHIP_LIFECYCLE_ERROR',
            };
            echo json_encode([
                'success' => false,
                'action' => $action,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        flash('error', $message);
        header('Location: ' . self::REDIRECT_LIST);
        exit;
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    private function actorId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }
}
