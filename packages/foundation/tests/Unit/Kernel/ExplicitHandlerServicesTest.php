<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\ServiceProvider\ExplicitHandlerServices;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class ExplicitHandlerServicesTest extends TestCase
{
    public function testNumericStringServiceIdsRetainTheirIdentity(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('1', static fn(): object => new \stdClass());
            }
        };
        $provider->register();
        $kernel = new KernelHandlerContainer([$provider], ['0' => static fn(ContainerInterface $services): object => new \stdClass()]);
        $services = $kernel->explicitServices(new Request());
        self::assertInstanceOf(\stdClass::class, $services->get('0'));
        self::assertInstanceOf(\stdClass::class, $services->get('1'));
    }

    public function testMetadataLookupNeverConstructsOrAutoloadsAndMissingGetRefuses(): void
    {
        $calls = 0;
        $kernel = new KernelHandlerContainer([], ['known' => static function (ContainerInterface $services) use (&$calls): object {
            $calls++;
            return new \stdClass();
        }]);
        $autoloads = [];
        $trap = static function (string $class) use (&$autoloads): void {
            if ($class === 'Missing\\UnregisteredHandler') {
                $autoloads[] = $class;
                throw new \LogicException('No handler autoload.');
            }
        };
        spl_autoload_register($trap, true, true);
        try {
            $services = $kernel->explicitServices(new Request());
            self::assertTrue($services->has('known'));
            self::assertFalse($services->has('Missing\\UnregisteredHandler'));
            try {
                $services->get('Missing\\UnregisteredHandler');
                self::fail('Unregistered handler must refuse.');
            } catch (NotFoundExceptionInterface) {
                self::assertSame(0, $calls);
                self::assertSame([], $autoloads);
            }
        } finally {
            spl_autoload_unregister($trap);
        }
    }

    public function testProviderSingletonAndFactoryLifetimesSurviveLegacyCacheWarming(): void
    {
        $provider = new ExplicitBindingFixtureProvider();
        $provider->register();
        $kernel = new KernelHandlerContainer([$provider], []);
        $legacyFactory = $kernel->get('factory');
        $services = $kernel->explicitServices(new Request());
        self::assertNotSame($legacyFactory, $services->get('factory'));
        self::assertNotSame($services->get('factory'), $services->get('factory'));
        self::assertSame($services->get('shared'), $services->get('shared'));
    }

    public function testSelectedProviderFailureNeverFallsThrough(): void
    {
        $first = new ExplicitBindingFixtureProvider(fail: true);
        $second = new ExplicitBindingFixtureProvider();
        $first->register();
        $second->register();
        $services = new KernelHandlerContainer([$first, $second], [])->explicitServices(new Request());
        try {
            $services->get('factory');
            self::fail('Selected factory failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('No binding registered for private dependency.', $error->getMessage());
            self::assertSame(0, $second->calls);
        }
    }

    public function testKernelBindingsRemainSharedAndTakePrecedence(): void
    {
        $provider = new ExplicitBindingFixtureProvider();
        $provider->register();
        $sentinel = new \stdClass();
        $kernel = new KernelHandlerContainer([$provider], ['factory' => static fn(ContainerInterface $services): object => $sentinel]);
        $services = $kernel->explicitServices(new Request());
        self::assertSame($sentinel, $services->get('factory'));
        self::assertSame($sentinel, $kernel->get('factory'));
        self::assertSame($sentinel, $kernel->explicitServices(new Request())->get('factory'));
        self::assertSame(0, $provider->calls);
    }

    public function testKernelFactoryReceivesTheExplicitCurrentRequestContext(): void
    {
        $request = Request::create('/current', 'POST');
        $kernel = new KernelHandlerContainer([], ['context' => static function (ContainerInterface $services): object {
            self::assertInstanceOf(ExplicitHandlerServices::class, $services);
            return $services->request();
        }]);
        self::assertSame($request, $kernel->explicitServices($request)->get('context'));
    }

    public function testCircularKernelBindingRefusesAndDoesNotPoisonOtherServices(): void
    {
        $kernel = new KernelHandlerContainer([], [
            'cycle' => static fn(ContainerInterface $services): object => $services->get('cycle'),
            'healthy' => static fn(ContainerInterface $services): object => new \stdClass(),
        ]);
        $services = $kernel->explicitServices(new Request());
        try {
            $services->get('cycle');
            self::fail('Circular binding must refuse.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('Circular', $error->getMessage());
        }
        self::assertInstanceOf(\stdClass::class, $services->get('healthy'));
    }

    public function testExplicitRequestOwnedFactoriesKeepTheirOwnScope(): void
    {
        $factory = static function (ExplicitHandlerServices $services): object {
            $request = $services->request();
            if (!$request->attributes->has('scoped.handler')) {
                $request->attributes->set('scoped.handler', new \stdClass());
            }
            return $request->attributes->get('scoped.handler');
        };
        $first = new ExplicitHandlerServices(new Request(), ['handler' => $factory]);
        $second = new ExplicitHandlerServices(new Request(), ['handler' => $factory]);
        self::assertSame($first->get('handler'), $first->get('handler'));
        self::assertNotSame($first->get('handler'), $second->get('handler'));
    }
}

final class ExplicitBindingFixtureProvider extends ServiceProvider
{
    public int $calls = 0;
    public function __construct(private readonly bool $fail = false) {}
    public function register(): void
    {
        $this->bind('factory', function (): object {
            $this->calls++;
            if ($this->fail) {
                throw new \RuntimeException('No binding registered for private dependency.');
            }
            return new \stdClass();
        });
        $this->singleton('shared', static fn(): object => new \stdClass());
    }
}
