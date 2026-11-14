<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\Controller\EngagementController;
use Waaseyaa\Api\Http\Router\EngagementApiRouter;
use Waaseyaa\Engagement\EngagementService;

#[CoversClass(EngagementApiRouter::class)]
final class EngagementApiRouterTest extends TestCase
{
    #[Test]
    public function lazyResolverFailuresAreSanitizedForHeadAndDoNotLeakSecretText(): void
    {
        $principal = new AuthorizationPrincipal(41, true, [], [], 'router-test');
        $router = new EngagementApiRouter(static function (): EngagementService {
            throw new \RuntimeException('private key material must not escape');
        });
        $request = $this->summaryRequest('HEAD', $principal);
        $response = $router->handle($request);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertStringNotContainsString('private key material', (string) $response->getContent());
        self::assertSame(['no-store', 'private'], array_map('trim', explode(',', (string) $response->headers->get('Cache-Control'))));

        $wrongObject = new EngagementApiRouter(static fn (): object => new \stdClass());
        $response = $wrongObject->handle($this->summaryRequest('GET', $principal));
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('stdClass', (string) $response->getContent());
    }

    #[Test]
    public function controllerBindingRequiresAnExactActionAndDoesNotMatchSubstrings(): void
    {
        $router = new EngagementApiRouter(static fn (): EngagementService => throw new \LogicException('unused'));
        $request = Request::create('/');
        $request->attributes->set('_controller', EngagementController::class . '::summary');
        self::assertTrue($router->supports($request));

        $request->attributes->set('_controller', EngagementController::class . '::summarySuffix');
        self::assertFalse($router->supports($request));
        $request->attributes->set('_controller', 'Prefix' . EngagementController::class . '::summary');
        self::assertFalse($router->supports($request));
    }

    private function summaryRequest(string $method, object $principal): Request
    {
        $request = Request::create('/engagement/v1/targets/article/1/summary', $method);
        $request->attributes->set('_controller', EngagementApiRouter::class . '::handle');
        $request->attributes->set('_engagement_action', 'summary');
        $request->attributes->set('type', 'article');
        $request->attributes->set('id', '1');
        $request->attributes->set('_authorization_principal', $principal);
        $request->attributes->set('_account', $principal);
        return $request;
    }
}
