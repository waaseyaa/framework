<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Mcp\Stdio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Mcp\Stdio\StdioServerExecutableResolver;

/**
 * ADR-022 D-9.2 acceptance: "Executable resolution reuses `waaseyaa dev` and
 * never assumes `command: php`." Proven on both POSIX and Windows path
 * shapes without touching the filesystem or the actual OS — the resolver is a
 * pure function over its string inputs, exactly like
 * `Waaseyaa\Frankenphp\Binary\BinaryResolver`.
 */
#[CoversClass(StdioServerExecutableResolver::class)]
final class StdioServerExecutableResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_a_posix_shaped_project_root(): void
    {
        $result = StdioServerExecutableResolver::resolve('/home/dev/app', phpBinary: '/usr/bin/php8.5');

        self::assertSame('/usr/bin/php8.5', $result['command']);
        self::assertSame(
            ['/home/dev/app/vendor/bin/waaseyaa', 'mcp:serve', '--profile=developer'],
            $result['args'],
        );
    }

    #[Test]
    public function it_resolves_a_windows_shaped_project_root_by_normalizing_to_forward_slashes(): void
    {
        $result = StdioServerExecutableResolver::resolve('C:\\Users\\dev\\app', phpBinary: 'C:\\tools\\php85\\php.exe');

        // PHP's own file APIs accept '/' on Windows exactly as on POSIX
        // (documented precedent: OpenSslKeyFactory, BinaryResolver), so the
        // interpreter path is passed through untouched and only the project
        // root — which becomes part of an argv element, not a raw OS call —
        // is normalized.
        self::assertSame('C:\\tools\\php85\\php.exe', $result['command']);
        self::assertSame(
            ['C:/Users/dev/app/vendor/bin/waaseyaa', 'mcp:serve', '--profile=developer'],
            $result['args'],
        );
    }

    #[Test]
    public function it_strips_a_trailing_slash_from_the_project_root_on_either_shape(): void
    {
        $posix = StdioServerExecutableResolver::resolve('/home/dev/app/', phpBinary: '/usr/bin/php');
        $windows = StdioServerExecutableResolver::resolve('C:\\Users\\dev\\app\\', phpBinary: 'C:\\php\\php.exe');

        self::assertSame('/home/dev/app/vendor/bin/waaseyaa', $posix['args'][0]);
        self::assertSame('C:/Users/dev/app/vendor/bin/waaseyaa', $windows['args'][0]);
    }

    #[Test]
    public function the_command_is_never_the_bare_string_php(): void
    {
        $result = StdioServerExecutableResolver::resolve('/home/dev/app', phpBinary: '/usr/bin/php');

        self::assertNotSame('php', $result['command'], 'The interpreter must always be an absolute, resolved path — never a bare name for $PATH to search.');
    }

    #[Test]
    public function it_defaults_the_interpreter_to_the_running_php_binary(): void
    {
        $result = StdioServerExecutableResolver::resolve('/home/dev/app');

        self::assertSame(\PHP_BINARY, $result['command']);
    }

    #[Test]
    public function the_profile_flag_is_carried_through_to_the_argv_and_is_never_a_positional_argument(): void
    {
        $result = StdioServerExecutableResolver::resolve('/home/dev/app', phpBinary: '/usr/bin/php', profile: 'developer');

        self::assertSame('mcp:serve', $result['args'][1]);
        self::assertSame('--profile=developer', $result['args'][2]);
    }

    #[Test]
    public function it_refuses_an_empty_php_binary_override_rather_than_silently_falling_back_to_a_bare_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StdioServerExecutableResolver::resolve('/home/dev/app', phpBinary: '');
    }

    #[Test]
    public function it_refuses_an_empty_project_root(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StdioServerExecutableResolver::resolve('', phpBinary: '/usr/bin/php');
    }
}
