<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Verifies that HttpKernel handles boot() failures gracefully.
 *
 * The handle() method returns `never` (exits) so it cannot be called directly
 * in unit tests. These tests verify the precondition (boot can throw) and the
 * structural guards added to handle().
 */
#[CoversClass(HttpKernel::class)]
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
            "<?php return ['database' => '/nonexistent/deep/path/db.sqlite'];",
        );

        $kernel = new HttpKernel($root);
        $boot = new \ReflectionMethod(AbstractKernel::class, 'boot');
        $boot->setAccessible(true);

        try {
            $this->expectException(\Throwable::class);
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
        // Structural guard: verify the handle() method body contains a try-catch
        // around the boot() call, so boot failures cannot propagate as uncaught exceptions.
        $method = new \ReflectionMethod(HttpKernel::class, 'handle');
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();

        $lines = file($file) ?: [];
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        // The boot() call must be inside a try block.
        $this->assertMatchesRegularExpression(
            '/try\s*\{[^}]*\$this->boot\(\)/s',
            $body,
            'HttpKernel::handle() must wrap $this->boot() in a try-catch to handle boot failures gracefully.',
        );
    }
}
