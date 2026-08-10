<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Community;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        $handler = new class ($context) implements HttpHandlerInterface {
            public function __construct(private readonly CommunityContext $context) {}

            public function handle(Request $request): Response
            {
                TestCase::assertSame('configured-community', $request->attributes->get('_community_id'));
                TestCase::assertSame('configured-community', $this->context->get());

                return new Response('ok');
            }
        };

        self::assertSame('ok', new CommunityMiddleware($context)->process($request, $handler)->getContent());
        self::assertTrue($context->isActive());
        self::assertSame('configured-community', $context->get());
    }

    #[Test]
    public function explicit_route_community_overrides_and_normalizes_the_configured_community(): void
    {
        $context = new CommunityContext();
        $context->set('configured-community');
        $request = Request::create('/community/route-community/admin');
        $request->attributes->set('community_id', 'route-community');
        $handler = new class ($context) implements HttpHandlerInterface {
            public function __construct(private readonly CommunityContext $context) {}

            public function handle(Request $request): Response
            {
                TestCase::assertSame('route-community', $request->attributes->get('_community_id'));
                TestCase::assertSame('route-community', $this->context->get());

                return new Response('ok');
            }
        };

        self::assertSame('ok', new CommunityMiddleware($context)->process($request, $handler)->getContent());
        self::assertTrue($context->isActive());
        self::assertSame('configured-community', $context->get());
    }

    #[Test]
    public function session_community_overrides_configured_context_and_is_restored_after_dispatch(): void
    {
        $context = new CommunityContext();
        $context->set('configured-community');
        $request = Request::create('/admin');
        $request->attributes->set('_session', ['waaseyaa_community_id' => 'session-community']);
        $handler = new class ($context) implements HttpHandlerInterface {
            public function __construct(private readonly CommunityContext $context) {}

            public function handle(Request $request): Response
            {
                TestCase::assertSame('session-community', $request->attributes->get('_community_id'));
                TestCase::assertSame('session-community', $this->context->get());

                return new Response('ok');
            }
        };

        self::assertSame('ok', new CommunityMiddleware($context)->process($request, $handler)->getContent());
        self::assertTrue($context->isActive());
        self::assertSame('configured-community', $context->get());
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

        self::assertSame('ok', new CommunityMiddleware($context)->process($request, $handler)->getContent());
        self::assertFalse($context->isActive());
    }

    #[Test]
    public function streamed_callback_rebinds_route_community_and_restores_inactive_context(): void
    {
        $context = new CommunityContext();
        $request = Request::create('/community/route-community/export');
        $request->attributes->set('community_id', 'route-community');
        $seen = null;
        $handler = new class ($context, $seen) implements HttpHandlerInterface {
            public function __construct(
                private readonly CommunityContext $context,
                private mixed &$seen,
            ) {}

            public function handle(Request $request): Response
            {
                return new StreamedResponse(function (): void {
                    $this->seen = $this->context->get();
                });
            }
        };

        $response = new CommunityMiddleware($context)->process($request, $handler);
        self::assertFalse($context->isActive());
        ob_start();
        $response->sendContent();
        ob_end_clean();
        self::assertSame('route-community', $seen);
        self::assertFalse($context->isActive());
    }
}
