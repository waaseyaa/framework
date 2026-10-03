<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;
use Waaseyaa\Foundation\ServiceProvider\ExplicitHandlerServices;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Routing\WaaseyaaRouter;

final class RouteHandlerResolverTest extends TestCase
{
    private function snapshot(string $handler = 'service:controller::show'): RouteSnapshot
    {
        return new RouteSnapshot([new RouteDefinition('fixture', '/fixture', HandlerReference::fromString($handler), sourceId: 'fixture')], []);
    }

    public function testMatchingDoesNotResolveAndResolutionDoesNotInvoke(): void
    {
        $snapshot = $this->snapshot();
        $request = Request::create('/fixture');
        $calls = 0;
        $handler = new ResolverFixtureHandler();
        $services = new ExplicitHandlerServices($request, ['controller' => static function () use (&$calls, $handler): object {
            $calls++;
            return $handler;
        }]);
        $resolver = new RouteHandlerResolver($snapshot, $services);
        $request->attributes->add(new WaaseyaaRouter(snapshot: $snapshot)->match('/fixture'));
        self::assertSame(0, $calls);
        $callable = $resolver->resolveMatched();
        self::assertSame(1, $calls);
        self::assertSame(0, $handler->invocations);
        self::assertSame('executed', $callable($request));
        self::assertSame(1, $handler->invocations);
    }

    public function testEarlyAndForgedMatchAccessRefuseWithoutConstruction(): void
    {
        $request = new Request();
        $calls = 0;
        $services = new ExplicitHandlerServices($request, ['controller' => static function () use (&$calls): object {
            $calls++;
            return new ResolverFixtureHandler();
        }]);
        $resolver = new RouteHandlerResolver($this->snapshot(), $services);
        foreach ([[], ['_route' => 'unknown', '_controller' => 'service:controller::show'], ['_route' => 'fixture', '_controller' => 'service:other::show']] as $attributes) {
            $request->attributes->replace($attributes);
            try {
                $resolver->resolveMatched();
                self::fail('No unverified match can resolve a handler.');
            } catch (HandlerResolutionException) {
                self::assertSame(0, $calls);
            }
        }
    }

    public function testBuiltinMappingNeverUsesServiceLookup(): void
    {
        $snapshot = $this->snapshot('builtin:render.page');
        $request = Request::create('/fixture');
        $request->attributes->add(new WaaseyaaRouter(snapshot: $snapshot)->match('/fixture'));
        $services = new ExplicitHandlerServices($request, []);
        self::assertSame('render.page', new RouteHandlerResolver($snapshot, $services)->resolveMatched());
    }

    public function testClassIdsRequireExplicitRegistrationAndNeverAutoloadMissingHandlers(): void
    {
        $snapshot = $this->snapshot('class:Missing\\Unregistered::show');
        $request = Request::create('/fixture');
        $request->attributes->add(new WaaseyaaRouter(snapshot: $snapshot)->match('/fixture'));
        $autoloads = [];
        $trap = static function (string $class) use (&$autoloads): void {
            if ($class === 'Missing\\Unregistered') {
                $autoloads[] = $class;
                throw new \LogicException('No class fallback.');
            }
        };
        spl_autoload_register($trap, true, true);
        try {
            new RouteHandlerResolver($snapshot, new ExplicitHandlerServices($request, []))->resolveMatched();
            self::fail('Unregistered class must refuse.');
        } catch (HandlerResolutionException $error) {
            self::assertSame([], $autoloads);
            self::assertSame('fixture', $error->routeName);
            self::assertSame('class:Missing\\Unregistered::show', $error->handlerId);
        } finally {
            spl_autoload_unregister($trap);
        }
    }

    public function testFactoryFailureIsSanitizedAndSnapshotRemainsComplete(): void
    {
        $snapshot = $this->snapshot();
        $identity = $snapshot->identity;
        $request = Request::create('/fixture');
        $request->attributes->add(new WaaseyaaRouter(snapshot: $snapshot)->match('/fixture'));
        $services = new ExplicitHandlerServices($request, ['controller' => static function (): never {
            throw new \RuntimeException('private credential value');
        }]);
        try {
            new RouteHandlerResolver($snapshot, $services)->resolveMatched();
            self::fail('Failed execution construction must refuse.');
        } catch (HandlerResolutionException $error) {
            self::assertStringNotContainsString('private credential value', (string) $error);
            self::assertNull($error->getPrevious());
            self::assertSame($identity, $snapshot->identity);
        }
    }

    public function testMissingPrivateAndMagicMethodsCannotBecomeHandlers(): void
    {
        foreach (['absent', 'privateMethod'] as $method) {
            $snapshot = $this->snapshot('service:controller::' . $method);
            $request = Request::create('/fixture');
            $request->attributes->add(new WaaseyaaRouter(snapshot: $snapshot)->match('/fixture'));
            $services = new ExplicitHandlerServices($request, ['controller' => static fn(): object => new ResolverFixtureHandler()]);
            try {
                new RouteHandlerResolver($snapshot, $services)->resolveMatched();
                self::fail('Only real public methods may execute.');
            } catch (HandlerResolutionException $error) {
                self::assertSame('invalid-method', $error->reason);
            }
        }
    }

    public function testRegisteredClassAndStaticMethodsUseTheSameLookup(): void
    {
        $snapshot = $this->snapshot('class:' . ResolverFixtureHandler::class . '::staticMethod');
        $request = Request::create('/fixture');
        $request->attributes->add(new WaaseyaaRouter(snapshot: $snapshot)->match('/fixture'));
        $services = new ExplicitHandlerServices($request, [ResolverFixtureHandler::class => static fn(): object => new ResolverFixtureHandler()]);
        self::assertSame('static', new RouteHandlerResolver($snapshot, $services)->resolveMatched()());
    }
}

final class ResolverFixtureHandler
{
    public int $invocations = 0;
    public function show(Request $request): string
    {
        $this->invocations++;
        return 'executed';
    }
    private function privateMethod(): void {}
    public function __call(string $method, array $arguments): string
    {
        return 'magic';
    }
    public static function staticMethod(): string
    {
        return 'static';
    }
}
