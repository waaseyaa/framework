<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Telescope\Middleware\TelescopeRequestMiddleware;
use Waaseyaa\Telescope\Storage\TelescopeStoreInterface;
use Waaseyaa\Telescope\TelescopeServiceProvider;

/**
 * A telemetry write failure must never turn a successful response into a
 * 500 (audit A11 / L6-telescope.md, CRITICAL finding).
 */
#[CoversClass(TelescopeRequestMiddleware::class)]
final class TelescopeRequestMiddlewareTest extends TestCase
{
    #[Test]
    public function a_store_failure_does_not_crash_the_response(): void
    {
        $store = new class implements TelescopeStoreInterface {
            public function store(string $type, array $data): void
            {
                throw new \RuntimeException('disk full');
            }

            public function query(string $type, int $limit = 50, int $offset = 0): array
            {
                return [];
            }

            public function prune(\DateTimeInterface $before): int
            {
                return 0;
            }

            public function clear(): void {}
        };

        $logger = new class implements LoggerInterface {
            /** @var list<array{level: LogLevel, message: string|\Stringable}> */
            public array $records = [];

            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::EMERGENCY, $message, $context);
            }

            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::ALERT, $message, $context);
            }

            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::CRITICAL, $message, $context);
            }

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::ERROR, $message, $context);
            }

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::WARNING, $message, $context);
            }

            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::NOTICE, $message, $context);
            }

            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::INFO, $message, $context);
            }

            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::DEBUG, $message, $context);
            }

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message];
            }
        };

        $provider = new TelescopeServiceProvider(store: $store);
        $middleware = new TelescopeRequestMiddleware($provider, $logger);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('ok', 200);
            }
        };

        $response = $middleware->process(Request::create('/foo'), $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertNotEmpty($logger->records, 'expected the store failure to be logged, not swallowed silently');
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
    }

    #[Test]
    public function it_still_records_successfully_when_the_store_does_not_throw(): void
    {
        $middleware = new TelescopeRequestMiddleware(new TelescopeServiceProvider());

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('ok', 200);
            }
        };

        $response = $middleware->process(Request::create('/foo'), $next);

        $this->assertSame(200, $response->getStatusCode());
    }
}
