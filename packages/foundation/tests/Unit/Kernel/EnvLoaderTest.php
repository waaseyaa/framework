<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\EnvLoader;

#[CoversClass(EnvLoader::class)]
final class EnvLoaderTest extends TestCase
{
    private string $tempDir;

    /** @var array<string, string> */
    private array $processEnvironment;

    /** @var array<string, mixed> */
    private array $env;

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->processEnvironment = getenv();
        $this->env = $_ENV;
        $this->server = $_SERVER;
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_env_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (array_diff_key(getenv(), $this->processEnvironment) as $name => $_) {
            putenv($name);
        }
        foreach ($this->processEnvironment as $name => $value) {
            putenv("{$name}={$value}");
        }
        $_ENV = $this->env;
        $_SERVER = $this->server;

        foreach (glob($this->tempDir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function missing_file_is_silently_ignored(): void
    {
        EnvLoader::load($this->tempDir . '/.env.nonexistent');

        // No exception thrown — test passes by reaching this line.
        $this->assertTrue(true);
    }

    #[Test]
    public function empty_file_is_silently_ignored(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, '');

        EnvLoader::load($path);

        $this->assertTrue(true);
    }

    #[Test]
    public function loads_simple_key_value_pair(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_SIMPLE=hello');

        EnvLoader::load($path);

        $this->assertSame('hello', getenv('WAASEYAA_TEST_SIMPLE'));

        putenv('WAASEYAA_TEST_SIMPLE');
    }

    #[Test]
    public function skips_comment_lines(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, "# This is a comment\nWAASEYAA_TEST_AFTER_COMMENT=value");

        EnvLoader::load($path);

        $this->assertSame('value', getenv('WAASEYAA_TEST_AFTER_COMMENT'));

        putenv('WAASEYAA_TEST_AFTER_COMMENT');
    }

    #[Test]
    public function skips_blank_lines(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, "\n\nWAASEYAA_TEST_AFTER_BLANK=set\n\n");

        EnvLoader::load($path);

        $this->assertSame('set', getenv('WAASEYAA_TEST_AFTER_BLANK'));

        putenv('WAASEYAA_TEST_AFTER_BLANK');
    }

    #[Test]
    public function malformed_lines_fail_closed(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, "WAASEYAA_TEST_PARTIAL=must-not-survive\nINVALID_LINE_NO_EQUALS");

        try {
            EnvLoader::load($path);
            self::fail('Malformed environment input must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Application environment file is malformed or unreadable.', $exception->getMessage());
            self::assertStringNotContainsString('INVALID_LINE_NO_EQUALS', $exception->getMessage());
        }
        self::assertFalse(getenv('WAASEYAA_TEST_PARTIAL'));
        self::assertArrayNotHasKey('WAASEYAA_TEST_PARTIAL', $_ENV);
        self::assertArrayNotHasKey('WAASEYAA_TEST_PARTIAL', $_SERVER);
    }

    #[Test]
    public function strips_double_quotes_from_value(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_DQUOTE="quoted value"');

        EnvLoader::load($path);

        $this->assertSame('quoted value', getenv('WAASEYAA_TEST_DQUOTE'));

        putenv('WAASEYAA_TEST_DQUOTE');
    }

    #[Test]
    public function strips_single_quotes_from_value(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, "WAASEYAA_TEST_SQUOTE='single quoted'");

        EnvLoader::load($path);

        $this->assertSame('single quoted', getenv('WAASEYAA_TEST_SQUOTE'));

        putenv('WAASEYAA_TEST_SQUOTE');
    }

    #[Test]
    public function mismatched_quotes_fail_closed(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, "WAASEYAA_TEST_MISMATCH=\"mismatched'");

        try {
            EnvLoader::load($path);
            self::fail('Malformed environment input must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Application environment file is malformed or unreadable.', $exception->getMessage());
            self::assertStringNotContainsString('mismatched', $exception->getMessage());
        }
    }

    #[Test]
    public function existing_env_var_is_not_overwritten(): void
    {
        putenv('WAASEYAA_TEST_EXISTING=original');

        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_EXISTING=overwritten');

        EnvLoader::load($path);

        $this->assertSame('original', getenv('WAASEYAA_TEST_EXISTING'));
        $this->assertSame('original', $_ENV['WAASEYAA_TEST_EXISTING'] ?? null);
        $this->assertSame('original', $_SERVER['WAASEYAA_TEST_EXISTING'] ?? null);

        putenv('WAASEYAA_TEST_EXISTING');
        unset($_ENV['WAASEYAA_TEST_EXISTING'], $_SERVER['WAASEYAA_TEST_EXISTING']);
    }

    #[Test]
    public function value_after_first_equals_is_preserved(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_URL=http://localhost:8080/path?a=1&b=2');

        EnvLoader::load($path);

        $this->assertSame('http://localhost:8080/path?a=1&b=2', getenv('WAASEYAA_TEST_URL'));

        putenv('WAASEYAA_TEST_URL');
    }

    #[Test]
    public function loads_multiple_vars_from_one_file(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, implode("\n", [
            '# Database',
            'WAASEYAA_TEST_DB=/tmp/test.sqlite',
            '',
            '# Server',
            'WAASEYAA_TEST_ENV=local',
        ]));

        EnvLoader::load($path);

        $this->assertSame('/tmp/test.sqlite', getenv('WAASEYAA_TEST_DB'));
        $this->assertSame('local', getenv('WAASEYAA_TEST_ENV'));

        putenv('WAASEYAA_TEST_DB');
        putenv('WAASEYAA_TEST_ENV');
    }

    #[Test]
    public function populates_env_superglobal(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_ENV_SUPER=env_value');

        EnvLoader::load($path);

        $this->assertSame('env_value', $_ENV['WAASEYAA_TEST_ENV_SUPER'] ?? null);

        putenv('WAASEYAA_TEST_ENV_SUPER');
        unset($_ENV['WAASEYAA_TEST_ENV_SUPER'], $_SERVER['WAASEYAA_TEST_ENV_SUPER']);
    }

    #[Test]
    public function populates_server_superglobal(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_SERVER_SUPER=server_value');

        EnvLoader::load($path);

        $this->assertSame('server_value', $_SERVER['WAASEYAA_TEST_SERVER_SUPER'] ?? null);

        putenv('WAASEYAA_TEST_SERVER_SUPER');
        unset($_ENV['WAASEYAA_TEST_SERVER_SUPER'], $_SERVER['WAASEYAA_TEST_SERVER_SUPER']);
    }

    #[Test]
    public function does_not_overwrite_preset_env_superglobal(): void
    {
        $_ENV['WAASEYAA_TEST_PRESET_ENV'] = 'preset_env';

        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_PRESET_ENV=from_file');

        EnvLoader::load($path);

        $this->assertSame('preset_env', $_ENV['WAASEYAA_TEST_PRESET_ENV']);

        putenv('WAASEYAA_TEST_PRESET_ENV');
        unset($_ENV['WAASEYAA_TEST_PRESET_ENV'], $_SERVER['WAASEYAA_TEST_PRESET_ENV']);
    }

    #[Test]
    public function does_not_overwrite_preset_server_superglobal(): void
    {
        $_SERVER['WAASEYAA_TEST_PRESET_SERVER'] = 'preset_server';

        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_PRESET_SERVER=from_file');

        EnvLoader::load($path);

        $this->assertSame('preset_server', $_SERVER['WAASEYAA_TEST_PRESET_SERVER']);

        putenv('WAASEYAA_TEST_PRESET_SERVER');
        unset($_ENV['WAASEYAA_TEST_PRESET_SERVER'], $_SERVER['WAASEYAA_TEST_PRESET_SERVER']);
    }

    #[Test]
    public function symfony_grammar_resolves_identically_in_every_environment_store(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, <<<'DOTENV'
            WAASEYAA_TEST_BASE=abc
            export WAASEYAA_TEST_INTERPOLATED=${WAASEYAA_TEST_BASE}-def
            WAASEYAA_TEST_COMMENT=bar # trailing comment
            WAASEYAA_TEST_MULTILINE="line one
            line two"
            DOTENV);

        EnvLoader::load($path);

        foreach ([
            'WAASEYAA_TEST_INTERPOLATED' => 'abc-def',
            'WAASEYAA_TEST_COMMENT' => 'bar',
            'WAASEYAA_TEST_MULTILINE' => "line one\nline two",
        ] as $key => $expected) {
            self::assertSame($expected, getenv($key));
            self::assertSame($expected, $_ENV[$key] ?? null);
            self::assertSame($expected, $_SERVER[$key] ?? null);
        }

        foreach (['WAASEYAA_TEST_BASE', 'WAASEYAA_TEST_INTERPOLATED', 'WAASEYAA_TEST_COMMENT', 'WAASEYAA_TEST_MULTILINE'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    #[Test]
    public function process_values_win_and_drive_file_interpolation(): void
    {
        putenv('WAASEYAA_TEST_EXTERNAL_BASE=outside');
        $path = $this->tempDir . '/.env';
        file_put_contents($path, <<<'DOTENV'
            WAASEYAA_TEST_EXTERNAL_BASE=inside
            WAASEYAA_TEST_EXTERNAL_DERIVED=${WAASEYAA_TEST_EXTERNAL_BASE}-value
            DOTENV);

        EnvLoader::load($path);

        foreach (['WAASEYAA_TEST_EXTERNAL_BASE' => 'outside', 'WAASEYAA_TEST_EXTERNAL_DERIVED' => 'outside-value'] as $key => $expected) {
            self::assertSame($expected, getenv($key));
            self::assertSame($expected, $_ENV[$key] ?? null);
            self::assertSame($expected, $_SERVER[$key] ?? null);
        }
    }

    #[Test]
    public function symfony_environment_cascade_uses_production_safe_environment_resolution(): void
    {
        putenv('APP_ENV');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        $path = $this->tempDir . '/.env';
        file_put_contents($path, "APP_ENV=staging\nWAASEYAA_TEST_CASCADE=base");
        file_put_contents($path . '.local', 'WAASEYAA_TEST_CASCADE=local');
        file_put_contents($path . '.staging', 'WAASEYAA_TEST_CASCADE=staging');
        file_put_contents($path . '.staging.local', 'WAASEYAA_TEST_CASCADE=staging-local');

        EnvLoader::load($path);

        self::assertSame('staging', getenv('APP_ENV'));
        self::assertSame('staging-local', getenv('WAASEYAA_TEST_CASCADE'));
        self::assertSame('staging-local', $_ENV['WAASEYAA_TEST_CASCADE'] ?? null);
        self::assertSame('staging-local', $_SERVER['WAASEYAA_TEST_CASCADE'] ?? null);
    }

    #[Test]
    public function an_existing_file_is_parsed_only_once_per_process(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_ONCE=first');
        EnvLoader::load($path);

        putenv('WAASEYAA_TEST_ONCE');
        unset($_ENV['WAASEYAA_TEST_ONCE'], $_SERVER['WAASEYAA_TEST_ONCE']);
        file_put_contents($path, 'WAASEYAA_TEST_ONCE=second');

        EnvLoader::load($path);

        self::assertFalse(getenv('WAASEYAA_TEST_ONCE'));
        self::assertArrayNotHasKey('WAASEYAA_TEST_ONCE', $_ENV);
        self::assertArrayNotHasKey('WAASEYAA_TEST_ONCE', $_SERVER);
    }
}
