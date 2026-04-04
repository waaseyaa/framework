<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Community;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;

/**
 * Resolves the active community from the incoming request and sets
 * it on CommunityContextInterface for the duration of the request.
 *
 * Resolution order (first match wins):
 *   1. Route parameter  — e.g. /community/{community_id}/...
 *   2. Session key      — 'waaseyaa_community_id'
 *
 * When no community is resolved (CLI, admin, unauthenticated), the
 * context remains inactive and queries are unscoped.
 *
 * Priority 20 ensures this runs after SessionMiddleware (priority 30),
 * so session data is already available when community is resolved.
 */
#[AsMiddleware(pipeline: 'http', priority: 20)]
final class CommunityMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly CommunityContextInterface $context,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $communityId = $this->resolve($request);

        if ($communityId !== null) {
            $this->context->set($communityId);
        }

        try {
            return $next->handle($request);
        } finally {
            $this->context->clear();
        }
    }

    private function resolve(Request $request): ?string
    {
        // 1. Route parameter (most explicit — wins over session).
        $routeParam = $request->attributes->get('community_id');
        if (is_string($routeParam) && $routeParam !== '') {
            return $routeParam;
        }

        // 2. Session key — requires SessionMiddleware (priority 30) to have run first.
        $session = $request->attributes->get('_session');
        if (is_array($session)) {
            $sessionValue = $session['waaseyaa_community_id'] ?? null;
            if (is_string($sessionValue) && $sessionValue !== '') {
                return $sessionValue;
            }
        }

        return null;
    }
}
