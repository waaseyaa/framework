<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\BootFailureMessageFormatter;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Verifies that HttpKernel handles boot() failures gracefully.
 *
 * Boot-failure formatting is exercised through the
 * `BootFailureMessageFormatter` public seam (see #845). The remaining
 * reflection in this file is targeted: `boot()` itself is intentionally
 * `protected` on `AbstractKernel`, and `handle()` exits the process so
 * cannot be invoked directly under PHPUnit. Both reflection sites here
 * verify failure-mode preconditions of the kernel rather than internal
 * state.
 */
#[CoversClass(HttpKernel::class)]
#[CoversClass(BootFailureMessageFormatter::class)]
final class HttpKernelBootFailureTest extends TestCase
{
    #[Test]
    public function boot_throws_when_database_path_is_inaccessible(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_boot_fail_' . uniqid();
        mkdir($root . '/config', 0755, true);
        // Point to a non-existent directory so PDO cannot create the file.
        file_put_contents(
            $root . '/config/waaseyaa.php',
            "<?php return ['environment' => 'local', 'database' => '/nonexistent/deep/path/db.sqlite'];",
        );

        $kernel = new HttpKernel($root);
        $boot = new \ReflectionMethod(AbstractKernel::class, 'boot');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to create the database directory');
            $boot->invoke($kernel);
        } finally {
            @unlink($root . '/config/waaseyaa.php');
            @rmdir($root . '/config');
            @rmdir($root);
        }
    }

    #[Test]
    public function handle_method_wraps_boot_in_try_catch(): void
    {
        // Structural guard: verify the handle() method body contains a
        // try-catch around the boot() call, so boot failures cannot
        // propagate as uncaught exceptions. handle() exits the process,
        // so it cannot be invoked under PHPUnit.
        $method = new \ReflectionMethod(HttpKernel::class, 'handle');
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();

        $lines = file($file) ?: [];
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        $this->assertMatchesRegularExpression(
            '/try\s*\{[^}]*\$this->boot\(\)/s',
            $body,
            'HttpKernel::handle() must wrap $this->boot() in a try-catch to handle boot failures gracefully.',
        );
    }

    #[Test]
    public function client_safe_boot_failure_detail_hides_database_path(): void
    {
        $formatter = new BootFailureMessageFormatter();

        $e = new \RuntimeException(
            'Database not found at /secret/deploy/path/waaseyaa.sqlite. In production, the database must already exist. '
            . 'Run "bin/waaseyaa db:init" to create the database file and apply migrations. '
            . 'The command is idempotent and safe to run on every deploy.',
        );
        $detail = $formatter->format($e);

        self::assertStringNotContainsString('/secret/', $detail);
        self::assertStringContainsString('SQLite database file is missing', $detail);
    }

    #[Test]
    public function client_safe_boot_failure_detail_names_schema_sync_without_table_names(): void
    {
        $formatter = new BootFailureMessageFormatter();

        $detail = $formatter->format(new \RuntimeException(
            '[S1-DB106] Required runtime schema is unavailable for table "attachment"; missing: table. Apply migration "waaseyaa schema:sync" through the schema coordinator.',
        ));

        self::assertStringContainsString('schema:sync', $detail);
        self::assertStringContainsString('install:init', $detail);
        self::assertStringNotContainsString('attachment', $detail);
    }

    #[Test]
    public function client_safe_boot_failure_detail_passes_through_app_debug_guard_message(): void
    {
        $formatter = new BootFailureMessageFormatter();

        $expected = 'APP_DEBUG must not be enabled in production (APP_ENV=production). Aborting boot.';
        $detail = $formatter->format(new \RuntimeException($expected));

        self::assertSame($expected, $detail);
    }

    #[Test]
    public function client_safe_boot_failure_detail_describes_phpunit_autoload_mistake(): void
    {
        $formatter = new BootFailureMessageFormatter();

        $detail = $formatter->format(new \Error('Class "PHPUnit\\Framework\\TestCase" not found'));

        self::assertStringContainsString('PHPUnit-only class', $detail);
    }

    #[Test]
    public function client_safe_boot_failure_detail_falls_back_to_generic_for_unknown_failures(): void
    {
        // Regression: every unclassified failure must map to the generic
        // "Application failed to boot." line — never the raw exception
        // message (which can contain filesystem paths or credentials).
        $formatter = new BootFailureMessageFormatter();

        $detail = $formatter->format(new \LogicException('Sensitive: token=abc123 path=/etc/secrets'));

        self::assertSame('Application failed to boot.', $detail);
        self::assertStringNotContainsString('token=', $detail);
        self::assertStringNotContainsString('/etc/secrets', $detail);
    }
}
