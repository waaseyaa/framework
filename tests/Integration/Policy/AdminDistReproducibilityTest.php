<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Policy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

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
            new Filesystem()->remove($dir);
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
        self::assertStringContainsString('check-admin-dist-fresh" --print-build-id', $build);
        self::assertStringContainsString('WAASEYAA_ADMIN_BUILD_ID', $build);
        self::assertStringContainsString('run-hermetic-admin-build', $build);
        self::assertStringContainsString('normalize-admin-dist', $build);
        self::assertStringNotContainsString(' npm ci', $build);
        self::assertStringNotContainsString(' npm run generate', $build);
        self::assertStringContainsString('buildId: process.env.WAASEYAA_ADMIN_BUILD_ID', $config);
        self::assertStringContainsString("'bin/normalize-admin-dist'", $freshness);
        self::assertStringContainsString("'bin/run-hermetic-admin-build'", $freshness);
        self::assertStringContainsString("'/packages/cli/src/AdminBuild'", $freshness);
        self::assertStringContainsString("'.nvmrc'", $freshness);
        self::assertStringContainsString("in_array('--print-build-id'", $freshness);
    }

    #[Test]
    public function procedure_drift_stales_freshness_without_changing_the_content_build_identity(): void
    {
        $root = $this->makeSourceSignatureFixture();
        $beforeFull = $this->signature($root, '--print');
        $beforeBuild = $this->signature($root, '--print-build-id');
        file_put_contents($root . '/bin/run-hermetic-admin-build', "procedure revision two\n");

        self::assertNotSame($beforeFull, $this->signature($root, '--print'));
        self::assertSame($beforeBuild, $this->signature($root, '--print-build-id'));
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

    private function makeSourceSignatureFixture(): string
    {
        $sourceRoot = dirname(__DIR__, 3);
        $root = sys_get_temp_dir() . '/waaseyaa_admin_signature_' . bin2hex(random_bytes(6));
        foreach ([
            'bin',
            'packages/admin/app',
            'packages/admin-surface',
            'packages/cli/src/AdminBuild',
            'packages/foundation/src/Log/Processor',
        ] as $directory) {
            mkdir($root . '/' . $directory, 0o755, true);
        }
        copy($sourceRoot . '/bin/check-admin-dist-fresh', $root . '/bin/check-admin-dist-fresh');
        foreach ([
            '.nvmrc',
            'bin/build-admin-dist',
            'bin/normalize-admin-dist',
            'bin/run-hermetic-admin-build',
            'packages/admin/nuxt.config.ts',
            'packages/admin/app.config.ts',
            'packages/admin/package.json',
            'packages/admin/package-lock.json',
            'packages/foundation/src/Log/Processor/RedactorProcessor.php',
        ] as $relative) {
            $destination = $root . '/' . $relative;
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0o755, true);
            }
            file_put_contents($destination, $relative . " fixture\n");
        }
        file_put_contents($root . '/packages/admin/app/input.ts', 'export const fixture = true;');
        file_put_contents($root . '/packages/cli/src/AdminBuild/Policy.php', '<?php // fixture');
        file_put_contents($root . '/packages/admin-surface/dist.signature', "placeholder\n");
        $this->tempDirs[] = $root;

        return $root;
    }

    private function signature(string $root, string $mode): string
    {
        // #2491 names this file's runners directly: stdout was drained to EOF
        // before stderr was touched, so a child filling the ~64KB stderr buffer
        // wedged both sides. proc_open got neither a cwd nor an env argument,
        // so the child inherited both: null for each, never []. timeout null
        // because this was never time-bounded and the freshness check can
        // legitimately outrun Symfony's 60-second constructor default.
        $process = new Process(
            [PHP_BINARY, $root . '/bin/check-admin-dist-fresh', $mode],
            null,
            null,
            null,
            null,
        );
        $exit = $process->run();
        self::assertSame(0, $exit, $process->getErrorOutput());

        return trim($process->getOutput());
    }

    /** @return array{int, string, string} */
    private function normalize(string $dist): array
    {
        // Same sequential-drain defect as signature() above (#2491). The stdin
        // pipe was opened and closed without a byte being written, so Symfony's
        // $input of null is equivalent. cwd and env were both omitted and
        // therefore inherited: null for each, never []. timeout null because
        // normalisation walks a whole dist tree and was never time-bounded.
        $process = new Process(
            [PHP_BINARY, self::BIN, $dist, self::SIGNATURE],
            null,
            null,
            null,
            null,
        );
        $exit = $process->run();

        return [$exit, $process->getOutput(), $process->getErrorOutput()];
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

}
