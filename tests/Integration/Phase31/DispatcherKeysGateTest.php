<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase31;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for bin/check-dispatcher-keys (WP4 framework-wide
 * dead-subscriber sweep, audit-remediation batch 2026-07-01/02).
 *
 * Verifies the gate rejects a synthetic provider that resolves an unserved
 * dispatcher FQCN (foundation or Symfony-Component) and registers a listener
 * with it, passes a provider that resolves the served Symfony-contracts
 * FQCN, and respects the baseline/exemption mechanism — proving the gate is
 * not a silent no-op (same self-test discipline as
 * tests/Integration/Phase24/GetQueryBindingsGateTest.php for
 * check-getquery-bindings).
 */
#[CoversNothing]
final class DispatcherKeysGateTest extends TestCase
{
    private string $tempDir = '';
    private string $tempBaseline = '';
    private string $scriptPath = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_dispkeys_' . uniqid('', true);
        mkdir($this->tempDir . '/src', 0o755, true);

        $this->tempBaseline = sys_get_temp_dir() . '/waaseyaa_dispkeys_baseline_' . uniqid('', true) . '.txt';

        $this->scriptPath = dirname(__DIR__, 3) . '/bin/check-dispatcher-keys';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        if (file_exists($this->tempBaseline)) {
            unlink($this->tempBaseline);
        }
    }

    #[Test]
    public function foundationFqcnResolutionFollowedByAddListenerFailsGate(): void
    {
        file_put_contents($this->tempDir . '/src/OffenderProvider.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\Fixture;
            use Waaseyaa\Foundation\Event\EventDispatcherInterface;
            final class OffenderProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);
                    if (!$dispatcher instanceof EventDispatcherInterface) {
                        return;
                    }
                    $dispatcher->addListener('some.event', new SomeListener());
                }
            }
            PHP);

        file_put_contents($this->tempBaseline, "# empty baseline\n");

        [$output, $exitCode] = $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--verify']);

        self::assertNotSame(0, $exitCode, 'Expected non-zero exit for unserved-FQCN registration. Output: ' . $output);
        self::assertStringContainsString('NEW unserved-dispatcher-key registration', $output);
        self::assertStringContainsString('Waaseyaa\\Foundation\\Event\\EventDispatcherInterface', $output);
    }

    #[Test]
    public function symfonyComponentFqcnResolutionFollowedByAddSubscriberFailsGate(): void
    {
        file_put_contents($this->tempDir . '/src/OffenderProvider.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\Fixture;
            use Symfony\Component\EventDispatcher\EventDispatcherInterface;
            final class OffenderProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->resolve(EventDispatcherInterface::class);
                    $dispatcher->addSubscriber(new SomeSubscriber());
                }
            }
            PHP);

        file_put_contents($this->tempBaseline, "# empty baseline\n");

        [$output, $exitCode] = $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--verify']);

        self::assertNotSame(0, $exitCode, 'Expected non-zero exit for Component-FQCN registration. Output: ' . $output);
        self::assertStringContainsString('Symfony\\Component\\EventDispatcher\\EventDispatcherInterface', $output);
    }

    #[Test]
    public function servedContractsFqcnResolutionPassesGate(): void
    {
        file_put_contents($this->tempDir . '/src/GoodProvider.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\Fixture;
            use Waaseyaa\Foundation\Event\EventDispatcherInterface;
            final class GoodProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
                    if (!$dispatcher instanceof EventDispatcherInterface) {
                        return;
                    }
                    $dispatcher->addListener('some.event', new SomeListener());
                }
            }
            PHP);

        file_put_contents($this->tempBaseline, "# empty baseline\n");

        [$output, $exitCode] = $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--verify']);

        self::assertSame(0, $exitCode, 'Expected zero exit for served-FQCN registration. Output: ' . $output);
    }

    #[Test]
    public function resolutionWithoutAddListenerOrAddSubscriberPassesGate(): void
    {
        // Resolving an unserved FQCN in isolation (no registration call) is not
        // this gate's concern — it only fires when the dead dispatcher is
        // actually used to register a listener.
        file_put_contents($this->tempDir . '/src/HarmlessProvider.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\Fixture;
            use Waaseyaa\Foundation\Event\EventDispatcherInterface;
            final class HarmlessProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);
                    $dispatcher?->dispatch(new \stdClass());
                }
            }
            PHP);

        file_put_contents($this->tempBaseline, "# empty baseline\n");

        [$output, $exitCode] = $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--verify']);

        self::assertSame(0, $exitCode, 'Expected zero exit when no addListener/addSubscriber is present. Output: ' . $output);
    }

    #[Test]
    public function offenderInBaselinePassesGate(): void
    {
        file_put_contents($this->tempDir . '/src/ExemptProvider.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\Fixture;
            use Waaseyaa\Foundation\Event\EventDispatcherInterface;
            final class ExemptProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);
                    if (!$dispatcher instanceof EventDispatcherInterface) {
                        return;
                    }
                    $dispatcher->addListener('some.event', new SomeListener());
                }
            }
            PHP);

        [, $exitCode] = $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--generate-baseline']);
        self::assertSame(0, $exitCode);

        $baselineContent = file_get_contents($this->tempBaseline);
        assert(is_string($baselineContent));
        $baselineContent = str_replace('  # TODO: add exemption reason', '  # test exemption', $baselineContent);
        file_put_contents($this->tempBaseline, $baselineContent);

        [$output, $exitCode] = $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--verify']);

        self::assertSame(0, $exitCode, 'Expected zero exit when offender is baselined. Output: ' . $output);
    }

    #[Test]
    public function missingInlineCommentFailsGate(): void
    {
        file_put_contents($this->tempDir . '/src/NoCommentProvider.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\Fixture;
            use Waaseyaa\Foundation\Event\EventDispatcherInterface;
            final class NoCommentProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);
                    if (!$dispatcher instanceof EventDispatcherInterface) {
                        return;
                    }
                    $dispatcher->addListener('some.event', new SomeListener());
                }
            }
            PHP);

        $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--generate-baseline']);

        $baselineContent = file_get_contents($this->tempBaseline);
        assert(is_string($baselineContent));
        $baselineContent = preg_replace('/^([^#\s][^#\n]+)  # TODO: add exemption reason$/m', '$1', $baselineContent);
        assert(is_string($baselineContent));
        file_put_contents($this->tempBaseline, $baselineContent);

        [$output, $exitCode] = $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--verify']);

        self::assertNotSame(0, $exitCode, 'Expected non-zero exit for missing inline comment. Output: ' . $output);
        self::assertStringContainsString('Incomplete baseline entries', $output);
    }

    /**
     * @param list<string> $extraArgs
     * @return array{0: string, 1: int}
     */
    private function runGate(string $scanDir, string $baseline, array $extraArgs): array
    {
        $output = [];
        $exitCode = 0;
        exec(
            sprintf(
                'php %s --scan-dir %s --baseline %s %s 2>&1',
                escapeshellarg($this->scriptPath),
                escapeshellarg($scanDir),
                escapeshellarg($baseline),
                implode(' ', array_map('escapeshellarg', $extraArgs)),
            ),
            $output,
            $exitCode,
        );

        return [implode("\n", $output), $exitCode];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
