<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\ContentSearch\ContentSearchPage;
use Waaseyaa\Api\ContentSearch\ContentSearchRateLimiterInterface;
use Waaseyaa\Api\ContentSearch\ContentSearchReadModelInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Controller\ContentSearchController;
use Waaseyaa\Api\Http\Router\ContentSearchApiRouter;
use Waaseyaa\Foundation\Log\LoggerInterface;

#[CoversClass(ContentSearchApiRouter::class)]
final class ContentSearchApiRouterTest extends TestCase
{
    #[Test]
    public function it_supports_only_the_exact_controller_reference(): void
    {
        $router = $this->router();
        $request = new Request();
        $request->attributes->set('_controller', ContentSearchApiRouter::CONTROLLER);
        self::assertTrue($router->supports($request));

        $request->attributes->set('_controller', ContentSearchController::class . '::other');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function it_uses_the_validated_request_principal_without_a_fallback_account(): void
    {
        $principal = new AuthorizationPrincipal(9, true, ['authenticated'], [], 'claims-9');
        $provider = $this->createMock(ContentSearchReadModelInterface::class);
        $provider->expects($this->once())
            ->method('search')
            ->with($this->anything(), $this->identicalTo($principal))
            ->willReturn(new ContentSearchPage(0, 0, 1, 20, [], []));
        $limiter = $this->createStub(ContentSearchRateLimiterInterface::class);
        $limiter->method('consume')->willReturn(true);
        $request = Request::create('/api/content/search?q=public');
        $request->attributes->set('_controller', ContentSearchApiRouter::CONTROLLER);
        $request->attributes->set('_account', $principal);
        $request->attributes->set('_authorization_principal', $principal);
        $request->attributes->set(
            '_broadcast_storage',
            (new \ReflectionClass(BroadcastStorage::class))->newInstanceWithoutConstructor(),
        );

        $response = (new ContentSearchApiRouter(new ContentSearchController($provider, $limiter)))->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function missing_authorization_context_fails_closed_without_searching(): void
    {
        $provider = $this->createMock(ContentSearchReadModelInterface::class);
        $provider->expects($this->never())->method('search');
        $limiter = $this->createMock(ContentSearchRateLimiterInterface::class);
        $limiter->expects($this->never())->method('consume');

        $response = (new ContentSearchApiRouter(new ContentSearchController($provider, $limiter)))->handle(
            Request::create('/api/content/search?q=public'),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('dev', strtolower((string) $response->getContent()));
    }

    #[Test]
    public function installed_but_unavailable_services_return_a_sanitized_logged_503(): void
    {
        $principal = new AuthorizationPrincipal(0, false, [], [], 'anonymous');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Public content search wiring failed.',
                $this->callback(static fn(array $context): bool => is_string($context['correlation_id'] ?? null)
                    && strlen($context['correlation_id']) === 16
                    && ($context['exception_class'] ?? null) === \RuntimeException::class
                    && !array_key_exists('exception', $context)),
            );
        $request = Request::create('/api/content/search?q=public');
        $request->attributes->set('_controller', ContentSearchApiRouter::CONTROLLER);
        $request->attributes->set('_account', $principal);
        $request->attributes->set('_authorization_principal', $principal);
        $request->attributes->set(
            '_broadcast_storage',
            (new \ReflectionClass(BroadcastStorage::class))->newInstanceWithoutConstructor(),
        );
        $router = new ContentSearchApiRouter(
            static fn(): ContentSearchController => throw new \RuntimeException('dsn=secret absolute/path'),
            static fn(): LoggerInterface => $logger,
        );

        $response = $router->handle($request);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('503', $payload['errors'][0]['status']);
        self::assertSame(16, strlen($payload['errors'][0]['meta']['correlation_id']));
        self::assertStringNotContainsString('secret', (string) $response->getContent());
        self::assertStringNotContainsString('path', (string) $response->getContent());
    }

    private function router(): ContentSearchApiRouter
    {
        return new ContentSearchApiRouter(new ContentSearchController(
            $this->createStub(ContentSearchReadModelInterface::class),
            $this->createStub(ContentSearchRateLimiterInterface::class),
        ));
    }
}
