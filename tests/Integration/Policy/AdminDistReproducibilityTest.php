<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Policy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AdminDistReproducibilityTest extends TestCase
{
    private const BIN = __DIR__ . '/../../../bin/normalize-admin-dist';
    private const SIGNATURE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const BUILD_ID = 'waaseyaa-0123456789abcdef0123456789abcdef';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
    }

    #[Test]
    public function volatile_nuxt_metadata_normalizes_to_byte_identical_trees(): void
    {
        $first = $this->makeFixture(1_786_020_000_001);
        $second = $this->makeFixture(1_786_020_999_999);

        self::assertSame(0, $this->normalize($first)[0]);
        self::assertSame(0, $this->normalize($second)[0]);
        self::assertSame($this->treeHashes($first), $this->treeHashes($second));

        $latest = json_decode((string) file_get_contents($first . '/_nuxt/builds/latest.json'), true);
        self::assertSame(self::BUILD_ID, $latest['id']);
        self::assertSame(1_600_019_088_743, $latest['timestamp']);
    }

    #[Test]
    public function real_asset_drift_remains_visible_after_normalization(): void
    {
        $first = $this->makeFixture(1_786_020_000_001, 'compiled asset A');
        $second = $this->makeFixture(1_786_020_999_999, 'compiled asset B');

        self::assertSame(0, $this->normalize($first)[0]);
        self::assertSame(0, $this->normalize($second)[0]);
        self::assertNotSame($this->treeHashes($first), $this->treeHashes($second));
    }

    #[Test]
    public function an_unexpected_build_identifier_fails_closed(): void
    {
        $fixture = $this->makeFixture(1_786_020_000_001);
        $index = $fixture . '/index.html';
        file_put_contents($index, str_replace(self::BUILD_ID, 'unexpected-id', (string) file_get_contents($index)));

        [$exit, , $stderr] = $this->normalize($fixture);

        self::assertSame(1, $exit);
        self::assertStringContainsString('expected deterministic build ID', $stderr);
    }

    #[Test]
    public function an_unexpected_prerender_payload_shape_fails_closed(): void
    {
        $fixture = $this->makeFixture(1_786_020_000_001);
        $index = $fixture . '/index.html';
        file_put_contents($index, str_replace('"prerenderedAt":1', '"generatedAt":1', (string) file_get_contents($index)));

        [$exit, , $stderr] = $this->normalize($fixture);

        self::assertSame(1, $exit);
        self::assertStringContainsString('unexpected prerender timestamp reference', $stderr);
    }

    #[Test]
    public function build_entrypoint_derives_and_threads_the_source_signature(): void
    {
        $root = dirname(__DIR__, 3);
        $build = (string) file_get_contents($root . '/bin/build-admin-dist');
        $freshness = (string) file_get_contents($root . '/bin/check-admin-dist-fresh');
        $config = (string) file_get_contents($root . '/packages/admin/nuxt.config.ts');

        self::assertStringContainsString('check-admin-dist-fresh" --print', $build);
        self::assertStringContainsString('WAASEYAA_ADMIN_BUILD_ID', $build);
        self::assertStringContainsString('normalize-admin-dist', $build);
        self::assertStringContainsString('buildId: process.env.WAASEYAA_ADMIN_BUILD_ID', $config);
        self::assertStringContainsString("'bin/normalize-admin-dist'", $freshness);
        self::assertStringContainsString("'.nvmrc'", $freshness);
    }

    private function makeFixture(int $timestamp, string $asset = 'compiled asset'): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_admin_dist_' . uniqid('', true);
        mkdir($root . '/_nuxt/builds/meta', 0o755, true);
        file_put_contents($root . '/_nuxt/app.js', $asset);
        file_put_contents(
            $root . '/_nuxt/builds/latest.json',
            json_encode(['id' => self::BUILD_ID, 'timestamp' => $timestamp], JSON_UNESCAPED_SLASHES),
        );
        file_put_contents(
            $root . '/_nuxt/builds/meta/' . self::BUILD_ID . '.json',
            json_encode(['id' => self::BUILD_ID, 'timestamp' => $timestamp, 'prerendered' => []], JSON_UNESCAPED_SLASHES),
        );
        file_put_contents(
            $root . '/index.html',
            '<!DOCTYPE html><script>window.__NUXT__={config:{app:{buildId:"' . self::BUILD_ID . '"}}}</script>'
            . '<script type="application/json" data-nuxt-data="nuxt-app" data-ssr="false" id="__NUXT_DATA__">'
            . '[{"prerenderedAt":1,"serverRendered":2},' . $timestamp . ',false]</script>',
        );
        $this->tempDirs[] = $root;

        return $root;
    }

    /** @return array{int, string, string} */
    private function normalize(string $dist): array
    {
        $process = proc_open(
            [PHP_BINARY, self::BIN, $dist, self::SIGNATURE],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    /** @return array<string, string> */
    private function treeHashes(string $root): array
    {
        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $path = $file->getPathname();
                $hashes[substr($path, strlen($root) + 1)] = hash_file('sha256', $path);
            }
        }
        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
