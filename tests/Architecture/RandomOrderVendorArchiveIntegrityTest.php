<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises bin/verify-random-order-vendor-archive against real fixture
 * archives instead of grepping ci.yml for string presence. Before this
 * script existed, the workflow's inline integrity gate always discarded the
 * archive (the digest check ran from the wrong directory — see the ci.yml
 * fix in the same change), and CiSingleExecutionProofTest::shardsVerifyTheRunScopedDependencyArtifact
 * passed the whole time because it only grepped for `sha256sum --check`.
 * docs/specs/ci-test-selection.md §8 promises "vendor artifact: missing,
 * corrupt digest, and wrong-checkout symlink each fall back to a locked
 * install" — this test is what actually proves that.
 */
#[CoversNothing]
final class RandomOrderVendorArchiveIntegrityTest extends TestCase
{
    private string $repoRoot;

    /** @var list<string> */
    private array $fixtureRoots = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isLink() || $entry->isFile() ? unlink($entry->getPathname()) : rmdir($entry->getPathname());
            }
            rmdir($root);
        }
        $this->fixtureRoots = [];
    }

    #[Test]
    public function a_good_archive_is_verified_and_extracted(): void
    {
        $root = $this->buildFixture();

        $result = $this->runVerify($root, 'archive', '.');

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('vendor archive verified', $result['output']);
        self::assertFileExists($root . '/vendor/composer/installed.php');
        self::assertTrue(is_link($root . '/vendor/waaseyaa/pkg'));
    }

    #[Test]
    public function a_missing_archive_falls_back(): void
    {
        $root = $this->buildFixture();
        unlink($root . '/archive/vendor.tar');

        $result = $this->runVerify($root, 'archive', '.');

        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertStringContainsString('archive missing', $result['output']);
    }

    #[Test]
    public function a_missing_digest_file_falls_back(): void
    {
        $root = $this->buildFixture();
        unlink($root . '/archive/vendor.tar.sha256');

        $result = $this->runVerify($root, 'archive', '.');

        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertStringContainsString('digest file missing', $result['output']);
    }

    #[Test]
    public function a_corrupt_digest_falls_back(): void
    {
        $root = $this->buildFixture();
        $digestFile = $root . '/archive/vendor.tar.sha256';
        $contents = (string) file_get_contents($digestFile);
        // Flip the first hex character of the recorded digest, leaving the
        // recorded filename (and therefore path resolution) untouched — this
        // isolates "digest is wrong" from "digest file names the wrong path".
        $corrupted = (($contents[0] === '0') ? '1' : '0') . substr($contents, 1);
        file_put_contents($digestFile, $corrupted);

        $result = $this->runVerify($root, 'archive', '.');

        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertStringContainsString('digest mismatch', $result['output']);
    }

    #[Test]
    public function a_dangling_wrong_checkout_symlink_falls_back(): void
    {
        // Simulates a stale archive built against a different checkout: the
        // extracted vendor/waaseyaa/pkg symlink survives, but the target
        // package directory this checkout would resolve it against is gone.
        $root = $this->buildFixture();
        $this->removeTree($root . '/packages/pkg');

        $result = $this->runVerify($root, 'archive', '.');

        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertStringContainsString('dangling symlink', $result['output']);
    }

    /** @return array{exit_code: int, output: string} */
    private function runVerify(string $root, string $archiveDir, string $workDir): array
    {
        $command = [$this->repoRoot . '/bin/verify-random-order-vendor-archive', $archiveDir, $workDir];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'output' => $stdout . $stderr];
    }

    /**
     * Builds a fixture root containing archive/{vendor.tar,vendor.tar.sha256,installed.sha256}
     * and a packages/pkg/composer.json that vendor/waaseyaa/pkg symlinks to
     * once extracted, matching the real prepare-random-order-plan/ci-random-order-shard shape.
     */
    private function buildFixture(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('rosvendor', true);
        $this->fixtureRoots[] = $root;

        mkdir($root . '/archive', 0o777, true);
        mkdir($root . '/packages/pkg', 0o777, true);
        file_put_contents($root . '/packages/pkg/composer.json', json_encode(['name' => 'waaseyaa/pkg'], JSON_THROW_ON_ERROR));

        mkdir($root . '/vendor/composer', 0o777, true);
        mkdir($root . '/vendor/waaseyaa', 0o777, true);
        file_put_contents($root . '/vendor/composer/installed.php', "<?php\nreturn [];\n");
        symlink('../../packages/pkg', $root . '/vendor/waaseyaa/pkg');

        $this->runShell($root, 'tar --create --file archive/vendor.tar vendor');
        $this->runShell($root, 'sha256sum archive/vendor.tar > archive/vendor.tar.sha256');
        $this->runShell(
            $root,
            'php -r \'echo hash_file("sha256", "vendor/composer/installed.php");\' > archive/installed.sha256',
        );

        $this->removeTree($root . '/vendor');

        return $root;
    }

    private function runShell(string $cwd, string $command): void
    {
        exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isLink() || $entry->isFile() ? unlink($entry->getPathname()) : rmdir($entry->getPathname());
        }
        rmdir($path);
    }
}
