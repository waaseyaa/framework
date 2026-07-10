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
 * dispatcher FQCN (Symfony-Component) and registers a listener with it,
 * passes a provider that resolves any of the served FQCNs (Symfony
 * contracts, PSR-14, or the Waaseyaa foundation contract — served since
 * #1942, G-025), and respects the baseline/exemption mechanism — proving the
 * gate is not a silent no-op (same self-test discipline as
 * tests/Integration/Phase24/GetQueryBindingsGateTest.php for
 * check-getquery-bindings).
 *
 * The Waaseyaa foundation FQCN
 * (`Waaseyaa\Foundation\Event\EventDispatcherInterface`) used to be the
 * canonical unserved-key fixture pre-#1942; it is now a served key, so the
 * "genuinely unserved" fixtures below use the Symfony Component FQCN
 * instead — the one dispatcher-interface FQCN the bus still does not serve.
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
    public function foundationFqcnResolutionFollowedByAddListenerPassesGate(): void
    {
        // Served since #1942 (G-025): the bus resolves the Waaseyaa
        // foundation contract directly (instanceof-guarded), so a provider
        // relying on it now reaches its registration in a real boot.
        file_put_contents($this->tempDir . '/src/FoundationFqcnProvider.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\Fixture;
            use Waaseyaa\Foundation\Event\EventDispatcherInterface;
            final class FoundationFqcnProvider
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

        self::assertSame(0, $exitCode, 'Expected zero exit for foundation-FQCN registration (served since #1942). Output: ' . $output);
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
    public function kernelServicesGetWithFoundationFqcnFollowedByAddListenerPassesGate(): void
    {
        // The direct bus-access shape (`$this->kernelServices?->get(...)`,
        // the style packages/listing uses) must be treated the same as
        // resolve()/resolveOptional() — the bus serves the identical key
        // set, and the foundation FQCN is now part of it (#1942, G-025).
        file_put_contents($this->tempDir . '/src/FoundationFqcnKernelServicesProvider.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\Fixture;
            use Waaseyaa\Foundation\Event\EventDispatcherInterface;
            final class FoundationFqcnKernelServicesProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->kernelServices?->get(EventDispatcherInterface::class);
                    if (!$dispatcher instanceof EventDispatcherInterface) {
                        return;
                    }
                    $dispatcher->addListener('some.event', new SomeListener());
                }
            }
            PHP);

        file_put_contents($this->tempBaseline, "# empty baseline\n");

        [$output, $exitCode] = $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--verify']);

        self::assertSame(0, $exitCode, 'Expected zero exit for kernelServices->get() foundation-FQCN registration (served since #1942). Output: ' . $output);
    }

    #[Test]
    public function kernelServicesGetMultiKeyFallbackIncludingServedKeyPassesGate(): void
    {
        // The listing-style defensive multi-key fallback: tries the Component
        // and foundation FQCNs but ALSO the served Contracts FQCN in the same
        // method — the served-key attempt makes the registration reachable in
        // a real boot, so the method must stay clean.
        file_put_contents($this->tempDir . '/src/ListingLikeProvider.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\Fixture;
            use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyComponentEventDispatcherInterface;
            final class ListingLikeProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->kernelServices?->get(SymfonyComponentEventDispatcherInterface::class);
                    if (!$dispatcher instanceof SymfonyComponentEventDispatcherInterface) {
                        $dispatcher = $this->kernelServices?->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
                    }
                    if (!$dispatcher instanceof SymfonyComponentEventDispatcherInterface) {
                        return;
                    }
                    $dispatcher->addListener('some.event', new SomeListener());
                }
            }
            PHP);

        file_put_contents($this->tempBaseline, "# empty baseline\n");

        [$output, $exitCode] = $this->runGate($this->tempDir . '/src', $this->tempBaseline, ['--verify']);

        self::assertSame(0, $exitCode, 'Expected zero exit for multi-key fallback that includes the served FQCN. Output: ' . $output);
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
            use Symfony\Component\EventDispatcher\EventDispatcherInterface;
            final class ExemptProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->resolve(EventDispatcherInterface::class);
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
            use Symfony\Component\EventDispatcher\EventDispatcherInterface;
            final class NoCommentProvider
            {
                public function boot(): void
                {
                    $dispatcher = $this->resolve(EventDispatcherInterface::class);
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
