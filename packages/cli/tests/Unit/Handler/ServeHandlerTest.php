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

    #[Test]
    public function frankenphp_command_is_php_server_for_the_public_docroot(): void
    {
        $handler = new ServeHandler('/srv/app');

        $cmd = $handler->frankenphpCommand('frankenphp', '0.0.0.0', '8080');

        self::assertSame(
            ['frankenphp', 'php-server', '--listen', '0.0.0.0:8080', '--root', '/srv/app/public'],
            $cmd,
        );
    }

    #[Test]
    public function frankenphp_binary_defaults_to_path_lookup_and_honors_override(): void
    {
        $handler = new ServeHandler('/srv/app');

        self::assertSame('frankenphp', $handler->frankenphpBinary([]));
        self::assertSame(
            '/opt/frankenphp',
            $handler->frankenphpBinary([ServeHandler::FRANKENPHP_BIN_ENV => '/opt/frankenphp']),
        );
    }

    #[Test]
    public function frankenphp_ini_defaults_into_the_app_and_honors_override(): void
    {
        $handler = new ServeHandler('/srv/app');

        self::assertSame('/srv/app/config/frankenphp/php.ini', $handler->frankenphpIniPath([]));
        self::assertSame(
            '/custom/php.ini',
            $handler->frankenphpIniPath([ServeHandler::FRANKENPHP_INI_ENV => '/custom/php.ini']),
        );
    }

    #[Test]
    public function frankenphp_env_points_phprc_at_the_ini_dir_and_omits_php_s_workers(): void
    {
        $handler = new ServeHandler('/srv/app');

        $env = $handler->resolveFrankenphpEnv([], '/srv/app/config/frankenphp/php.ini');

        // PHPRC is the DIRECTORY PHP scans for php.ini (which enables pdo_sqlite).
        self::assertSame('/srv/app/config/frankenphp', $env['PHPRC']);
        self::assertSame('development', $env['APP_ENV']);
        // PHP_CLI_SERVER_WORKERS is a php -S knob; FrankenPHP manages its own pool.
        self::assertArrayNotHasKey('PHP_CLI_SERVER_WORKERS', $env);
    }

    #[Test]
    public function frankenphp_env_respects_caller_phprc(): void
    {
        $handler = new ServeHandler('/srv/app');

        $env = $handler->resolveFrankenphpEnv(['PHPRC' => '/etc/php'], '/srv/app/config/frankenphp/php.ini');

        self::assertSame('/etc/php', $env['PHPRC']);
    }
}
