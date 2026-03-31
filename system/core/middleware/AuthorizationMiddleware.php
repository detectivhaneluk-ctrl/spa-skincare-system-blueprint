<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\AuthorizationException;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;

/**
 * PLT-AUTH-02: HTTP-level resource action enforcement using the canonical AuthorizerInterface.
 *
 * Runs after TenantContextMiddleware has resolved TenantContext into RequestContextHolder.
 * Enforces a specific ResourceAction on an optional resource type.
 *
 * Usage in route definitions:
 *   AuthorizationMiddleware::forAction(ResourceAction::CLIENT_CREATE, 'client')
 *   AuthorizationMiddleware::forAction(ResourceAction::INVOICE_PAY, 'invoice')
 *
 * Stack position: after AuthMiddleware + TenantProtectedRouteMiddleware (or PlatformPrincipalMiddleware),
 * before or in place of PermissionMiddleware for new protected surfaces.
 *
 * Deny semantics:
 * - HTTP 403 JSON response for API/JSON requests.
 * - HTTP 403 error page for HTML requests.
 * - The AuthorizationException reason string is NOT exposed to the client (only logged server-side).
 *
 * Complementary to PermissionMiddleware: on existing routes, PermissionMiddleware continues to
 * provide the coarse RBAC gate. This middleware adds PolicyAuthorizer-backed enforcement that
 * correctly handles SUPPORT_ACTOR read-only policy and deny-by-default semantics.
 */
final class AuthorizationMiddleware implements MiddlewareInterface
{
    private function __construct(
        private readonly ResourceAction $action,
        private readonly string $resourceType,
    ) {
    }

    /**
     * Factory: enforce a specific ResourceAction (collection-level, no entity id at HTTP boundary).
     */
    public static function forAction(ResourceAction $action, string $resourceType): self
    {
        return new self($action, $resourceType);
    }

    public function handle(callable $next): void
    {
        $container = Application::container();

        /** @var RequestContextHolder $holder */
        $holder = $container->get(RequestContextHolder::class);
        $ctx = $holder->requireContext();

        /** @var AuthorizerInterface $authorizer */
        $authorizer = $container->get(AuthorizerInterface::class);

        try {
            $authorizer->requireAuthorized($ctx, $this->action, ResourceRef::collection($this->resourceType));
        } catch (AuthorizationException $e) {
            $this->denyForbidden($e);
            return;
        }

        $next();
    }

    private function denyForbidden(AuthorizationException $e): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'AUTHORIZATION_DENIED',
                    'message' => 'Access denied by policy.',
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
        exit;
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
