<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\DecisionAccountResolver;
use Waaseyaa\Api\Controller\EngagementController;
use Waaseyaa\Api\Http\JsonApiResponse;
use Waaseyaa\Engagement\EngagementException;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/** Lazy optional engagement boundary. An installed but broken service refuses per request. */
final class EngagementApiRouter implements DomainRouterInterface
{
    private const array METHODS = [
        'summary' => ['GET', 'HEAD'], 'like' => ['PUT'], 'commentKey' => ['POST'],
        'comments' => ['GET', 'HEAD'], 'createComment' => ['POST'], 'replies' => ['GET', 'HEAD'],
        'show' => ['GET', 'HEAD'], 'edit' => ['PATCH'], 'remove' => ['DELETE'],
        'moderateRemove' => ['POST'], 'summaries' => ['POST'],
    ];

    /** @var \Closure(): EngagementService */
    private readonly \Closure $service;

    /** @param EngagementService|\Closure():EngagementService $service */
    public function __construct(EngagementService|\Closure $service)
    {
        $this->service = $service instanceof EngagementService ? static fn(): EngagementService => $service : $service;
    }

    public function supports(Request $request): bool
    {
        return $this->action($request) !== null;
    }

    /** Named path arguments are accepted for the canonical controller dispatcher.
     * Matched request attributes remain the authority for their values.
     */
    public function handle(Request $request, mixed $type = null, mixed $id = null, mixed $uuid = null): Response
    {
        $response = $this->dispatch($request);
        if ($request->isMethod('HEAD')) {
            $response->setContent('');
        }
        return $response;
    }

    private function dispatch(Request $request): Response
    {
        $action = $this->action($request);
        if ($action === null) {
            return self::error(404, 'TARGET_UNAVAILABLE', 'The requested engagement is unavailable.');
        }
        if (!in_array($request->getMethod(), self::METHODS[$action], true)) {
            return self::error(405, 'METHOD_NOT_ALLOWED', 'This method is unavailable.', ['Allow' => implode(', ', self::METHODS[$action])]);
        }
        $principal = DecisionAccountResolver::resolve($request->attributes->get('_authorization_principal'), $request->attributes->get('_account'));
        $write = !in_array($action, ['summary', 'comments', 'replies', 'show', 'summaries'], true);
        if ($write && ($principal === null || !$principal->isAuthenticated())) {
            return self::error(401, 'AUTH_REQUIRED', 'Sign in to change engagement.');
        }
        if ($principal === null) {
            return self::error(503, 'TEMPORARILY_UNAVAILABLE', 'Engagement is temporarily unavailable.');
        }
        try {
            $service = ($this->service)();
            $response = new EngagementController($service)->handle($request, $principal, $action);
        } catch (EngagementException $failure) {
            $response = self::domainError($failure);
        } catch (\Throwable) {
            $response = self::error(503, 'TEMPORARILY_UNAVAILABLE', 'Engagement is temporarily unavailable. Retry with the same submission key.');
        }
        return $response;
    }

    private function action(Request $request): ?string
    {
        $controller = $request->attributes->get('_controller');
        $route = $request->attributes->get('_route_object');
        if ($controller instanceof \Closure && $route instanceof Route && $route->getOption('_engagement') === true) {
            $action = $route->getDefault('_engagement_action');
        } elseif ($controller === self::class . '::handle') {
            $action = $request->attributes->get('_engagement_action');
        } elseif (is_string($controller) && str_starts_with($controller, EngagementController::class . '::')) {
            $action = substr($controller, strlen(EngagementController::class . '::'));
        } else {
            return null;
        }
        return is_string($action) && array_key_exists($action, self::METHODS) ? $action : null;
    }

    private static function domainError(EngagementException $failure): Response
    {
        [$status, $detail] = match ($failure->errorCode) {
            'AUTH_REQUIRED' => [401, 'Sign in to change engagement.'],
            'TARGET_UNAVAILABLE' => [404, 'The requested engagement is unavailable.'],
            'ACTION_FORBIDDEN' => [403, 'This engagement action is forbidden.'],
            'VALIDATION_FAILED' => [422, 'Invalid engagement request.'],
            'REVISION_CONFLICT' => [409, 'The comment has changed. Reload before editing.'],
            'SUBMISSION_CONFLICT' => [409, 'The submission key was used for a different request.'],
            'SUBMISSION_EXPIRED' => [409, 'The submission key has expired.'],
            'CURSOR_STALE' => [409, 'Discussion permissions changed. Restart the page.'],
            'INVALID_CURSOR' => [400, 'Invalid discussion cursor.'],
            'CURSOR_EXPIRED' => [400, 'The discussion cursor has expired.'],
            'RATE_LIMITED' => [429, 'Too many engagement attempts. Retry later.'],
            default => [503, 'Engagement is temporarily unavailable. Retry with the same submission key.'],
        };
        return self::error(
            $status,
            $status === 503 ? 'TEMPORARILY_UNAVAILABLE' : $failure->errorCode,
            $detail,
            $status === 429 && $failure->retryAfter !== null ? ['Retry-After' => (string) max(1, $failure->retryAfter)] : [],
        );
    }

    /** @param array<string,string> $headers */
    private static function error(int $status, string $code, string $detail, array $headers = []): Response
    {
        return new JsonApiResponse(['jsonapi' => ['version' => '1.1'], 'errors' => [[
            'status' => (string) $status, 'code' => $code, 'title' => Response::$statusTexts[$status], 'detail' => $detail,
        ]]], $status, ['Cache-Control' => 'private, no-store', ...$headers]);
    }
}
