<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\AuthOidcRouteServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(AuthOidcRouteServiceProvider::class)]
final class AuthOidcRouteServiceProviderTest extends TestCase
{
    public function test_oidc_controller_resolution_failures_are_logged(): void
    {
        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: LogLevel, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $services = new class ($logger) implements KernelServicesInterface {
            public function __construct(private readonly LoggerInterface $logger) {}

            public function get(string $abstract): ?object
            {
                if ($abstract === LoggerInterface::class) {
                    return $this->logger;
                }

                throw new \RuntimeException("Deliberate resolution failure for {$abstract}.");
            }
        };

        $provider = new AuthOidcRouteServiceProvider();
        $provider->setKernelServices($services);
        $method = new \ReflectionMethod($provider, 'registerOidcRoutes');
        $method->invoke($provider, new WaaseyaaRouter());

        self::assertCount(5, $logger->records);
        foreach ($logger->records as $record) {
            self::assertSame(LogLevel::WARNING, $record['level']);
            self::assertSame('OIDC route controller could not be resolved; route registration skipped.', $record['message']);
            self::assertArrayHasKey('controller', $record['context']);
            self::assertInstanceOf(\RuntimeException::class, $record['context']['exception']);
        }
    }
}
