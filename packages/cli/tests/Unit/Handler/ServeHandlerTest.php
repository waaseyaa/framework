<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\ServeHandler;

#[CoversClass(ServeHandler::class)]
final class ServeHandlerTest extends TestCase
{
    #[Test]
    public function it_defaults_app_env_to_development(): void
    {
        $handler = new ServeHandler('/tmp');

        $env = $handler->resolveChildEnv([]);

        self::assertSame('development', $env['APP_ENV']);
        self::assertSame('1', $env['APP_DEBUG']);
    }

    #[Test]
    public function it_respects_caller_set_app_env(): void
    {
        $handler = new ServeHandler('/tmp');

        $env = $handler->resolveChildEnv(['APP_ENV' => 'staging']);

        self::assertSame('staging', $env['APP_ENV']);
        self::assertArrayNotHasKey('APP_DEBUG', $env);
    }

    #[Test]
    public function it_passes_through_other_env_vars(): void
    {
        $handler = new ServeHandler('/tmp');

        $env = $handler->resolveChildEnv(['PATH' => '/usr/bin', 'HOME' => '/home/test']);

        self::assertSame('/usr/bin', $env['PATH']);
        self::assertSame('/home/test', $env['HOME']);
        self::assertSame('development', $env['APP_ENV']);
    }

    #[Test]
    public function it_defaults_server_workers_so_the_sse_connection_does_not_deadlock(): void
    {
        $handler = new ServeHandler('/tmp');

        $env = $handler->resolveChildEnv([]);

        // PHP's built-in server is single-worker by default; the admin SPA's
        // long-lived SSE stream would pin that sole worker. Default to >1.
        self::assertSame('4', $env['PHP_CLI_SERVER_WORKERS']);
        self::assertGreaterThan(1, (int) $env['PHP_CLI_SERVER_WORKERS']);
    }

    #[Test]
    public function it_respects_caller_set_server_workers(): void
    {
        $handler = new ServeHandler('/tmp');

        $env = $handler->resolveChildEnv(['PHP_CLI_SERVER_WORKERS' => '8']);

        self::assertSame('8', $env['PHP_CLI_SERVER_WORKERS']);
    }
}
