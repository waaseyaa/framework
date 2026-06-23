<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the runtime-awareness contract of the front controller (public/index.php).
 *
 * Two invariants are enforced here:
 *
 *  1. CLASSIC-FRANKENPHP FALLBACK. `frankenphp_handle_request()` is defined under
 *     BOTH FrankenPHP worker mode and classic FrankenPHP (php-server / FPM); in
 *     classic mode it throws "called while not in worker mode". So branching on
 *     `function_exists('frankenphp_handle_request')` alone is not enough — the
 *     loop must tolerate that first-call throw and fall through to a single
 *     synchronous request. Regression guard for the alpha.225 entry file, which
 *     500'd under `frankenphp php-server` until the worker loop learned to fall
 *     back (verified against a real FrankenPHP 1.12 binary).
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
    public function everyFrontControllerFallsBackToClassicWhenNotInWorkerMode(): void
    {
        foreach (self::allFrontControllers() as $relative) {
            $source = self::read($relative);

            // Still gated on the worker API existing...
            $this->assertStringContainsString(
                "function_exists('frankenphp_handle_request')",
                $source,
                "{$relative} must still detect the FrankenPHP worker API.",
            );

            // ...but the first-call throw must be caught and, when no request has
            // been handled yet, swallowed so we fall through to $handler().
            $this->assertStringContainsString(
                'catch (\Throwable $e)',
                $source,
                "{$relative} must guard the worker loop so classic FrankenPHP (which throws) falls back.",
            );
            $this->assertStringContainsString(
                'if ($handled > 0)',
                $source,
                "{$relative} must only re-throw worker errors AFTER the first request (classic mode throws on call #1).",
            );
            $this->assertStringContainsString(
                '$handler();',
                $source,
                "{$relative} must retain the single-request fallback path.",
            );

            // Guard against reverting to the buggy unconditional loop / comment.
            $this->assertStringNotContainsString(
                'Without this function we are',
                $source,
                "{$relative} carries the pre-fix comment — the classic-mode fallback was reverted.",
            );
        }
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
