<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * FW-REHYDRATION-DRIFT-01 / GitHub #3056.
 *
 * Reproduces, without Docker, the real defect Studio's materialization
 * protocol hit at PROTOCOL304_REHYDRATION_DRIFT: running the generated
 * `bin/maintenance/site-verify` command's PHPUnit invocation twice against
 * an otherwise-unchanged project must leave the project tree byte-identical
 * OUTSIDE the directories every artifact-bundle consumer already treats as
 * ephemeral runtime state (`storage/`, `vendor/` — see the skeleton's own
 * `.gitignore` and Studio's `MaterializationProtocol::treeManifest()`).
 * Before the fix, PHPUnit's own `cacheDirectory` default wrote real
 * wall-clock timings to `.phpunit.cache/test-run-history` at the PROJECT
 * ROOT on every run, so two verification passes of the same reviewed plan
 * produced two different "identical" bundles.
 *
 * This test checks the generated override and builds an equivalent invocation
 * (it does not execute the full generated wrapper) against a throwaway
 * project skeleton, runs it twice, and diffs the tree excluding storage/ —
 * exactly the exclusion Studio's own bundle logic already applies.
 */
#[CoversNothing]
final class SiteVerifyPhpunitCacheIsolationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_verify_cache_' . bin2hex(random_bytes(8));
        mkdir($this->root . '/tests', 0o755, true);
        mkdir($this->root . '/storage', 0o755, true);
        mkdir($this->root . '/vendor/bin', 0o755, true);
        symlink(
            dirname(__DIR__, 5) . '/vendor/bin/phpunit',
            $this->root . '/vendor/bin/phpunit',
        );
        file_put_contents($this->root . '/phpunit.xml.dist', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="{$this->frameworkAutoload()}" colors="false" cacheDirectory=".phpunit.cache">
                <testsuites>
                    <testsuite name="Unit"><directory>tests</directory></testsuite>
                </testsuites>
            </phpunit>
            XML);
        file_put_contents($this->root . '/tests/SmokeTest.php', <<<'PHP'
            <?php
            use PHPUnit\Framework\TestCase;
            final class SmokeTest extends TestCase {
                public function testOk(): void { self::assertTrue(true); }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function theGeneratedVerificationCommandLeavesTheTreeByteIdenticalAcrossTwoRuns(): void
    {
        $command = $this->extractGeneratedPhpunitCommand();
        self::assertStringContainsString('--cache-directory=', $command, 'sanity: the generator must still emit the override this test protects');

        [$exitA] = $this->runShellCommand($command);
        self::assertSame(0, $exitA);
        $snapshotA = $this->snapshotExcludingStorageAndVendor();

        self::assertSame(
            [],
            $this->pathsUnderPhpunitCache($snapshotA),
            'the PHPUnit result cache must never land at the project root',
        );
        // The exact filename PHPUnit writes there ("test-results" pre-13.3,
        // "test-run-history" from 13.3 on, per its own CHANGELOG) is a
        // PHPUnit-version detail this test must not pin to; only the
        // directory this override controls matters here.
        self::assertNotSame([], glob($this->root . '/storage/.phpunit.cache/*') ?: [], 'the PHPUnit result cache must land under storage/, which every bundle consumer already excludes');

        [$exitB] = $this->runShellCommand($command);
        self::assertSame(0, $exitB);
        $snapshotB = $this->snapshotExcludingStorageAndVendor();

        self::assertSame(
            $snapshotA,
            $snapshotB,
            'two verification runs of the same unchanged project must produce a byte-identical tree outside storage/ and vendor/ — this is the exact invariant PROTOCOL304_REHYDRATION_DRIFT depends on',
        );
    }

    #[Test]
    public function withoutTheCacheDirectoryOverrideVerificationPollutesThePortableTree(): void
    {
        // Mutation control: removing the override must create real cache files
        // outside storage/. Cache creation is deterministic; differences between
        // two wall-clock durations are not.
        $command = $this->extractGeneratedPhpunitCommand();
        $vulnerable = preg_replace('/ --cache-directory=\S+/', '', $command, 1);
        self::assertNotNull($vulnerable);
        self::assertStringNotContainsString('--cache-directory=', $vulnerable);

        $before = $this->snapshotExcludingStorageAndVendor();
        self::assertSame([], $this->pathsUnderPhpunitCache($before));

        [$exit] = $this->runShellCommand($vulnerable);
        self::assertSame(0, $exit);
        $after = $this->snapshotExcludingStorageAndVendor();
        $cachePaths = $this->pathsUnderPhpunitCache($after);
        self::assertNotSame([], $cachePaths, 'removing the override must write real cache files at the project root');
        self::assertNotSame($before, $after, 'verification must expose the unscoped cache in the portable tree');

        foreach ($cachePaths as $path) {
            unset($after[$path]);
        }
        self::assertSame($before, $after, 'the cache alone must account for the portable-tree change');
    }

    /**
     * Proves the real generated source still contains the exact
     * `--cache-directory=` clause this test relies on (so a regression that
     * deletes the override, rather than merely changing its value, is caught
     * here too — not only in SiteArtifactRendererTest), then builds the
     * equivalent invocation against THIS test's own throwaway project so the
     * behavioural proof below never has to `eval()` extracted source.
     */
    private function extractGeneratedPhpunitCommand(): string
    {
        $manifest = new SiteManifestParser()->parse($this->manifest());
        $content = new SiteArtifactRenderer()->render($manifest)->artifacts['bin/maintenance/site-verify']->content;
        self::assertMatchesRegularExpression(
            "/--no-coverage --cache-directory=' \. escapeshellarg\(\\\$root \. '\/storage\/\.phpunit\.cache'\)/",
            $content,
            'the generator must emit exactly one storage/-scoped --cache-directory override per phpunit invocation',
        );

        $runner = $this->root . '/vendor/bin/phpunit';
        $test = $this->root . '/tests/SmokeTest.php';

        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($test)
            . ' --no-coverage --cache-directory=' . escapeshellarg($this->root . '/storage/.phpunit.cache');
    }

    /** @return array{int, string, string} */
    private function runShellCommand(string $command): array
    {
        $process = Process::fromShellCommandline($command, $this->root, timeout: 30.0);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    /** @param array<string, string> $snapshot @return list<string> */
    private function pathsUnderPhpunitCache(array $snapshot): array
    {
        return array_values(array_filter(
            array_keys($snapshot),
            static fn(string $path): bool => str_starts_with($path, '.phpunit.cache/'),
        ));
    }

    /** @return array<string, string> path (relative, forward-slash) => sha256 */
    private function snapshotExcludingStorageAndVendor(): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root) + 1));
            $top = explode('/', $relative, 2)[0];
            if (in_array($top, ['storage', 'vendor'], true)) {
                continue;
            }
            $snapshot[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    private function frameworkAutoload(): string
    {
        return dirname(__DIR__, 5) . '/vendor/autoload.php';
    }

    private function manifest(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              name: Example Nation
              id: example-nation
              canonical_origin:
                config_key: APP_ORIGIN
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - id: page
                canonical_route: /{slug}
            capabilities:
              - id: governed_authoring
                state: active
                package: waaseyaa/page-builder
                provider: site.page_builder
                configuration_authority: .waaseyaa/site.yaml#/capabilities/governed_authoring
                public_routes: []
                data_classification: public
                lifecycle: [create, revise, publish, archive]
                verification: [tests/Acceptance/SiteGoldenPathTest.php]
            personal_data_stores: []
            recipes: []
            verification:
              command: bin/maintenance/site-verify
            YAML;
    }
}
