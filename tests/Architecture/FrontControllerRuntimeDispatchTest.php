<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Locks the runtime-awareness contract of the front controller (public/index.php).
 *
 * Two invariants are enforced here:
 *
 *  1. EXPLICIT RUNTIME SELECTION. `frankenphp_handle_request()` is defined under
 *     BOTH FrankenPHP worker mode and classic FrankenPHP (php-server / FPM).
 *     Classic builds may throw or return false when it is called, so function
 *     existence is not a runtime-mode signal. The shipped worker block sets an
 *     exact process marker; every other runtime serves one synchronous request.
 *
 *  2. FOUR-COPY SYNC. The runtime adapter is duplicated across the repo front
 *     controller, the skeleton front controller, the `make:public` template stub,
 *     and the golden drift reference that `waaseyaa-audit-site` byte-compares in
 *     consumer apps. The skeleton copy, the stub, and the golden must stay
 *     byte-identical (line-ending–normalized) or convergence audits break.
 */
#[CoversNothing]
final class FrontControllerRuntimeDispatchTest extends TestCase
{
    private const REPO_PUBLIC = 'public/index.php';
    private const SKELETON_PUBLIC = 'skeleton/public/index.php';
    private const GOLDEN = 'skeleton/bin/maintenance/golden-public-index.php';
    private const STUB = 'packages/cli/templates/public/index.php.stub';

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $path = self::root() . '/' . $relative;
        $contents = file_get_contents($path);
        self::assertIsString($contents, "Unable to read {$relative}");

        // Normalize line endings so Windows (autocrlf) checkouts compare equal to
        // the LF form git stores and CI runs.
        return str_replace(["\r\n", "\r"], "\n", $contents);
    }

    /**
     * @return list<string>
     */
    private static function allFrontControllers(): array
    {
        return [self::REPO_PUBLIC, self::SKELETON_PUBLIC, self::GOLDEN, self::STUB];
    }

    #[Test]
    public function everyFrontControllerSelectsWorkerModeExplicitly(): void
    {
        foreach (self::allFrontControllers() as $relative) {
            $source = self::read($relative);

            $this->assertStringContainsString(
                "\$_SERVER['WAASEYAA_FRANKENPHP_WORKER'] ?? getenv('WAASEYAA_FRANKENPHP_WORKER')",
                $source,
                "{$relative} must require the exact worker-process marker.",
            );
            $this->assertStringContainsString(
                "if (!function_exists('frankenphp_handle_request'))",
                $source,
                "{$relative} must fail closed when an explicitly selected worker lacks its API.",
            );
            $this->assertStringNotContainsString(
                "if (function_exists('frankenphp_handle_request'))",
                $source,
                "{$relative} must not infer worker mode from function existence.",
            );
            $this->assertStringContainsString(
                '$handler();',
                $source,
                "{$relative} must retain the single-request fallback path.",
            );
        }
    }

    #[Test]
    public function everyFrontController_uses_the_canonical_environment_loader_before_dispatch(): void
    {
        foreach (self::allFrontControllers() as $relative) {
            $source = self::read($relative);
            $load = strpos($source, "EnvLoader::load(\$projectRoot . '/.env');");
            $handler = strpos($source, '$handler =');

            self::assertIsInt($load, "{$relative} must use the canonical environment loader.");
            self::assertIsInt($handler, "{$relative} must retain its request handler.");
            self::assertLessThan($handler, $load, "{$relative} must load bootstrap values before dispatch.");
            self::assertStringNotContainsString('new \\Symfony\\Component\\Dotenv\\Dotenv', $source);
        }

        $foundation = json_decode(
            self::read('packages/foundation/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('^8.0', $foundation['require']['symfony/dotenv'] ?? null);
    }

    #[Test]
    public function the_canonical_loader_is_the_only_production_dotenv_parser(): void
    {
        $process = new Process([
            'git',
            'grep',
            '-F',
            '-l',
            'Symfony\\Component\\Dotenv\\Dotenv',
            '--',
            'public',
            'skeleton',
            'packages',
        ], self::root());
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $matches = preg_split('/\R/', trim($process->getOutput()));
        self::assertIsArray($matches);
        $productionMatches = array_values(array_filter(
            $matches,
            static fn(string $path): bool => !str_contains($path, '/tests/'),
        ));

        self::assertSame(['packages/foundation/src/Kernel/EnvLoader.php'], $productionMatches);

        foreach ([
            'packages/foundation/src/Kernel/AbstractKernel.php',
            'packages/foundation/src/Kernel/ConsoleKernel.php',
            'packages/cli/src/Handler/DbInitHandler.php',
            'packages/cli/src/Provider/MigrateServiceProvider.php',
        ] as $relative) {
            self::assertStringContainsString(
                'EnvLoader::load(',
                self::read($relative),
                "{$relative} must use the canonical loader for its CLI boot path.",
            );
        }
    }

    #[Test]
    public function shipped_and_acceptance_worker_blocks_set_the_explicit_marker(): void
    {
        $caddy = self::read('skeleton/config/frankenphp/Caddyfile');
        $harness = self::read('scripts/acceptance-frankenphp-worker.sh');

        $this->assertStringContainsString('env WAASEYAA_FRANKENPHP_WORKER "1"', $caddy);
        $this->assertStringContainsString('env WAASEYAA_FRANKENPHP_WORKER "1"', $harness);
        $this->assertStringContainsString('classic FrankenPHP returned an empty response body', $harness);
    }

    #[Test]
    public function noFrontControllerLeaksTheRawBootFailureMessage(): void
    {
        // A catastrophic boot failure (around new HttpKernel() / handle()) must
        // NOT return the raw exception message to the client unconditionally — it
        // can carry a DB DSN, file paths, or other internals. Every copy must gate
        // the detail behind APP_DEBUG: golden/skeleton/stub via
        // App\Http\BootFailureResponder::detail(...); the framework-repo dev copy
        // via an inline filter_var(APP_DEBUG, FILTER_VALIDATE_BOOLEAN) ternary.
        // Pre-fix, the make:public stub and the repo front controller emitted
        // `'detail' => $e->getMessage()` directly (audit Medium; the stub leaked
        // into every freshly scaffolded app).
        foreach (self::allFrontControllers() as $relative) {
            $source = self::read($relative);

            $this->assertStringNotContainsString(
                "'detail' => \$e->getMessage()",
                $source,
                "{$relative} leaks the raw boot-failure exception message to the client (must be debug-gated).",
            );
            $this->assertStringContainsString(
                'APP_DEBUG',
                $source,
                "{$relative} must gate the boot-failure detail behind APP_DEBUG.",
            );
        }
    }

    #[Test]
    public function skeletonStubAndGoldenStayByteIdentical(): void
    {
        $golden = self::read(self::GOLDEN);

        $this->assertSame(
            $golden,
            self::read(self::SKELETON_PUBLIC),
            'skeleton/public/index.php must match the golden drift reference byte-for-byte (line-ending normalized).',
        );
        $this->assertSame(
            $golden,
            self::read(self::STUB),
            'The make:public stub must match the golden drift reference byte-for-byte (line-ending normalized).',
        );
    }
}
