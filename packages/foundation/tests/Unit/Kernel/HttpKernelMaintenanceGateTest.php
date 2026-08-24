<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;
use Waaseyaa\Foundation\Middleware\MaintenanceModeMiddleware;

/**
 * Proves the maintenance gate is a PRE-BOOT short-circuit: a maintenance 503 is
 * served even when the database is unreachable (the SFN live-SQLite-swap case,
 * #2122), which is only possible because the check runs before boot() — a
 * post-boot middleware would 500 on migrations/schema validation instead.
 *
 * Each case drives HttpKernel::handle() the way the FrankenPHP worker loop and
 * the classic php-server / FPM front controllers do (fresh kernel per request).
 */
#[CoversClass(HttpKernel::class)]
final class HttpKernelMaintenanceGateTest extends TestCase
{
    private string $root;

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(str_repeat('m', 32)));
        foreach (['WAASEYAA_MAINTENANCE_FLAG', 'WAASEYAA_MAINTENANCE_HEALTH_PATH', 'WAASEYAA_MAINTENANCE_TRUST_LOCALHOST'] as $key) {
            putenv($key);
        }

        $this->root = sys_get_temp_dir() . '/waaseyaa-maint-kernel-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0o755, true);
        // A deliberately unreachable database: if the gate did NOT short-circuit
        // before boot, handle() would return a 500 boot-failure, not a 503.
        file_put_contents(
            $this->root . '/config/waaseyaa.php',
            "<?php return ['environment' => 'production', 'database' => '/nonexistent/deep/path/db.sqlite'];\n",
        );

        // A non-loopback client so the localhost exemption does not apply.
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        putenv('WAASEYAA_APP_SECRET');
        (new Filesystem())->remove($this->root);
    }

    #[Test]
    public function serves_503_before_boot_when_flag_is_set_even_with_unreachable_database(): void
    {
        new MaintenanceState($this->root . '/storage/maintenance.flag')->activate(120, 'Swapping the database.');

        $response = new HttpKernel($this->root)->handle();

        self::assertSame(503, $response->getStatusCode(), 'Maintenance must win over an unreachable-DB boot failure.');
        self::assertSame('120', $response->headers->get('Retry-After'));
        self::assertStringContainsString('Swapping the database.', (string) $response->getContent());
    }

    #[Test]
    public function boots_normally_when_no_flag_is_set(): void
    {
        // No flag → gate is off → boot runs → unreachable DB → 500 (NOT 503).
        // Proves the gate is off by default and does not swallow real requests.
        $response = new HttpKernel($this->root)->handle();

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function middleware_is_not_discoverable_into_any_pipeline(): void
    {
        // Single invocation path: the class carries NO #[AsMiddleware] attribute,
        // so PackageManifestCompiler cannot discover it into the post-boot HTTP
        // pipeline. The kernel gate is therefore the only place it runs.
        $attributes = new \ReflectionClass(MaintenanceModeMiddleware::class)->getAttributes(AsMiddleware::class);

        self::assertSame([], $attributes, 'MaintenanceModeMiddleware must not be auto-discovered — it runs exactly once, pre-boot.');
    }

}
