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

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_env_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
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
    public function skips_lines_without_equals_sign(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, "INVALID_LINE_NO_EQUALS\nWAASEYAA_TEST_VALID=ok");

        EnvLoader::load($path);

        $this->assertFalse(getenv('INVALID_LINE_NO_EQUALS'));
        $this->assertSame('ok', getenv('WAASEYAA_TEST_VALID'));

        putenv('WAASEYAA_TEST_VALID');
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
    public function does_not_strip_mismatched_quotes(): void
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, "WAASEYAA_TEST_MISMATCH=\"mismatched'");

        EnvLoader::load($path);

        $this->assertSame("\"mismatched'", getenv('WAASEYAA_TEST_MISMATCH'));

        putenv('WAASEYAA_TEST_MISMATCH');
    }

    #[Test]
    public function existing_env_var_is_not_overwritten(): void
    {
        putenv('WAASEYAA_TEST_EXISTING=original');

        $path = $this->tempDir . '/.env';
        file_put_contents($path, 'WAASEYAA_TEST_EXISTING=overwritten');

        EnvLoader::load($path);

        $this->assertSame('original', getenv('WAASEYAA_TEST_EXISTING'));

        putenv('WAASEYAA_TEST_EXISTING');
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
}
