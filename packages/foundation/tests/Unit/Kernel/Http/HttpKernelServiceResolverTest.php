<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Http\BindingAwareHttpServiceResolverInterface;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Foundation\Kernel\Http\HttpKernelServiceResolver;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Contract test for the SSR controller-method dependency resolver introduced
 * in mission #1257 follow-up cleanup (mirrors the typed-resolver pattern from
 * mission #824 WP02 surface A).
 *
 * Covers four cases per the design ratification:
 *  - provider binding hit
 *  - provider miss + kernel-services fallback hit
 *  - provider miss + fallback miss => null
 *  - provider throws => logged and returns null
 */
#[CoversClass(HttpKernelServiceResolver::class)]
final class HttpKernelServiceResolverTest extends TestCase
{
    #[Test]
    public function returns_resolved_object_when_provider_has_binding(): void
    {
        $expected = new \stdClass();
        $provider = $this->makeProvider([\stdClass::class => $expected]);
        $database = $this->makeDatabaseStub();

        $resolver = new HttpKernelServiceResolver(
            providersAccessor: static fn (): array => [$provider],
            kernelServices: $this->makeKernelServices($database),
            logger: new NullLogger(),
        );

        self::assertSame($expected, $resolver->resolve(\stdClass::class));
    }

    #[Test]
    public function falls_back_to_database_when_no_provider_binds_it(): void
    {
        $database = $this->makeDatabaseStub();

        $resolver = new HttpKernelServiceResolver(
            providersAccessor: static fn (): array => [],
            kernelServices: $this->makeKernelServices($database),
            logger: new NullLogger(),
        );

        self::assertSame($database, $resolver->resolve(DatabaseInterface::class));
    }

    #[Test]
    public function returns_null_when_no_provider_binds_and_no_fallback_matches(): void
    {
        $resolver = new HttpKernelServiceResolver(
            providersAccessor: static fn (): array => [],
            kernelServices: $this->makeKernelServices($this->makeDatabaseStub()),
            logger: new NullLogger(),
        );

        self::assertNull($resolver->resolve('Some\\Unknown\\Class'));
    }

    #[Test]
    public function logs_and_returns_null_when_provider_resolution_throws(): void
    {
        $provider = $this->makeProvider(
            bindings: [\stdClass::class => 'sentinel'],
            resolveCallback: static function (): never {
                throw new \RuntimeException('boom');
            },
        );
        $logger = new class () implements LoggerInterface {
            /** @var list<string> */
            public array $errors = [];

            public function emergency(\Stringable|string $message, array $context = []): void {}
            public function alert(\Stringable|string $message, array $context = []): void {}
            public function critical(\Stringable|string $message, array $context = []): void {}
            public function error(\Stringable|string $message, array $context = []): void
            {
                $this->errors[] = (string) $message;
            }
            public function warning(\Stringable|string $message, array $context = []): void {}
            public function notice(\Stringable|string $message, array $context = []): void {}
            public function info(\Stringable|string $message, array $context = []): void {}
            public function debug(\Stringable|string $message, array $context = []): void {}
            public function log(mixed $level, \Stringable|string $message, array $context = []): void {}
        };

        $resolver = new HttpKernelServiceResolver(
            providersAccessor: static fn (): array => [$provider],
            kernelServices: $this->makeKernelServices($this->makeDatabaseStub()),
            logger: $logger,
        );

        self::assertNull($resolver->resolve(\stdClass::class));
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('Failed to resolve stdClass', $logger->errors[0]);
        self::assertStringContainsString('boom', $logger->errors[0]);
    }

    #[Test]
    public function binding_aware_resolution_distinguishes_absent_bindings_from_factory_failures(): void
    {
        $provider = $this->makeProvider(
            bindings: [\stdClass::class => 'sentinel'],
            resolveCallback: static function (): never {
                throw new \RuntimeException('bound factory failed');
            },
        );
        $resolver = new HttpKernelServiceResolver(
            providersAccessor: static fn(): array => [$provider],
            kernelServices: $this->makeKernelServices($this->makeDatabaseStub()),
            logger: new NullLogger(),
        );

        self::assertTrue($resolver->hasBinding(\stdClass::class));
        self::assertFalse($resolver->hasBinding('Some\\Unknown\\Class'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bound factory failed');
        $resolver->resolveBound(\stdClass::class);
    }

    #[Test]
    public function returns_default_implementation_class(): void
    {
        $resolver = new HttpKernelServiceResolver(
            providersAccessor: static fn (): array => [],
            kernelServices: $this->makeKernelServices($this->makeDatabaseStub()),
            logger: new NullLogger(),
        );

        self::assertInstanceOf(HttpServiceResolverInterface::class, $resolver);
        self::assertInstanceOf(BindingAwareHttpServiceResolverInterface::class, $resolver);
    }

    private function makeKernelServices(DatabaseInterface $database): KernelServicesInterface
    {
        return new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        };
    }

    /**
     * @param array<class-string, mixed> $bindings
     */
    private function makeProvider(array $bindings, ?\Closure $resolveCallback = null): ServiceProvider
    {
        return new class ($bindings, $resolveCallback) extends ServiceProvider {
            /**
             * @param array<class-string, mixed> $bindingsMap
             */
            public function __construct(
                private readonly array $bindingsMap,
                private readonly ?\Closure $resolveCallback,
            ) {}

            public function register(): void {}

            public function getBindings(): array
            {
                return $this->bindingsMap;
            }

            public function resolve(string $abstract): object
            {
                if ($this->resolveCallback !== null) {
                    $result = ($this->resolveCallback)($abstract);
                    if (!is_object($result)) {
                        throw new \RuntimeException("resolveCallback for {$abstract} did not produce an object");
                    }
                    return $result;
                }
                $value = $this->bindingsMap[$abstract] ?? null;
                if (!is_object($value)) {
                    throw new \RuntimeException("No binding registered for {$abstract}.");
                }
                return $value;
            }
        };
    }

    private function makeDatabaseStub(): DatabaseInterface
    {
        return DBALDatabase::createSqlite(':memory:');
    }
}
