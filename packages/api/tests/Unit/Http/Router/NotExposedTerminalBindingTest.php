<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\NotExposedController;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(NotExposedController::class)]
#[CoversClass(ApiServiceProvider::class)]
final class NotExposedTerminalBindingTest extends TestCase
{
    public function testOpaqueRefusalAcceptsMatchedPathWithoutResolvingServices(): void
    {
        $bus = new class implements KernelServicesInterface {
            public int $reads = 0;
            public bool $poison = false;
            public function get(string $abstract): ?object
            {
                $this->reads++;
                if ($this->poison) {
                    throw new \LogicException('Opaque refusal must not resolve services.');
                }
                return null;
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $bus->reads = 0;
        $bus->poison = true;
        $expected = new NotExposedController()->__invoke();
        foreach ([null, 'private/path', ['malformed']] as $path) {
            $request = Request::create('/api/hidden/private/path');
            if ($path !== null) {
                $request->attributes->set('path', $path);
            }
            $definition = new RouteDefinition('fixture.hidden', '/api/hidden/{path}', HandlerReference::fromString('class:' . NotExposedController::class . '::__invoke'), sourceId: 'fixture.api');
            $request->attributes->add(['_route' => $definition->name, '_controller' => $definition->handler->id]);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            self::assertTrue($services->has(NotExposedController::class));
            $callable = new RouteHandlerResolver($definition, $services)->resolveMatched();
            $request->attributes->set('_controller', $callable);
            $response = new ControllerDispatcher([])->dispatch($request);
            self::assertSame(404, $response->getStatusCode());
            self::assertSame($expected['body'], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame($expected, $callable($request, path: 'conflicting'));
        }
        self::assertSame(0, $bus->reads);
        self::assertNotSame($provider->resolve(NotExposedController::class), $provider->resolve(NotExposedController::class));
    }
}
