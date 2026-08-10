<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Community;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Community\CommunityContext;
use Waaseyaa\Foundation\Community\CommunityMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;

final class CommunityMiddlewareTest extends TestCase
{
    #[Test]
    public function configured_community_is_normalized_for_downstream_principal_construction(): void
    {
        $context = new CommunityContext();
        $context->set('configured-community');
        $request = Request::create('/admin/anokii/identity');
        $handler = new class($context) implements HttpHandlerInterface {
            public function __construct(private readonly CommunityContext $context) {}

            public function handle(Request $request): Response
            {
                TestCase::assertSame('configured-community', $request->attributes->get('_community_id'));
                TestCase::assertSame('configured-community', $this->context->get());

                return new Response('ok');
            }
        };

        self::assertSame('ok', (new CommunityMiddleware($context))->process($request, $handler)->getContent());
        self::assertFalse($context->isActive());
    }

    #[Test]
    public function explicit_route_community_overrides_and_normalizes_the_configured_community(): void
    {
        $context = new CommunityContext();
        $context->set('configured-community');
        $request = Request::create('/community/route-community/admin');
        $request->attributes->set('community_id', 'route-community');
        $handler = new class($context) implements HttpHandlerInterface {
            public function __construct(private readonly CommunityContext $context) {}

            public function handle(Request $request): Response
            {
                TestCase::assertSame('route-community', $request->attributes->get('_community_id'));
                TestCase::assertSame('route-community', $this->context->get());

                return new Response('ok');
            }
        };

        self::assertSame('ok', (new CommunityMiddleware($context))->process($request, $handler)->getContent());
    }

    #[Test]
    public function inactive_context_without_selector_remains_unscoped(): void
    {
        $context = new CommunityContext();
        $request = Request::create('/admin');
        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                TestCase::assertFalse($request->attributes->has('_community_id'));

                return new Response('ok');
            }
        };

        self::assertSame('ok', (new CommunityMiddleware($context))->process($request, $handler)->getContent());
        self::assertFalse($context->isActive());
    }
}
