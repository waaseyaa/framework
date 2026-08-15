<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\AdminBuild\AdminBuildArtifactScanException;
use Waaseyaa\CLI\AdminBuild\AdminBuildArtifactScanner;

#[CoversClass(AdminBuildArtifactScanner::class)]
final class AdminBuildArtifactScannerTest extends TestCase
{
    private string $projectRoot;
    private string $adminPath;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_admin_scan_' . bin2hex(random_bytes(6));
        $this->adminPath = $this->projectRoot . '/packages/admin';
        mkdir($this->adminPath . '/app', 0700, true);
        mkdir($this->adminPath . '/.output/public/_nuxt', 0700, true);
        mkdir($this->adminPath . '/.nuxt/cache', 0700, true);
        mkdir($this->adminPath . '/coverage', 0700, true);
        mkdir($this->adminPath . '/node_modules/dependency', 0700, true);
        mkdir($this->adminPath . '/node_modules/.cache', 0700, true);
        mkdir($this->projectRoot . '/packages/admin-surface/dist', 0700, true);
        file_put_contents($this->adminPath . '/app/input.ts', 'export const synthetic = true');
        file_put_contents($this->adminPath . '/.output/public/index.html', '<main>synthetic</main>');
        file_put_contents($this->adminPath . '/.output/public/_nuxt/app.js.map', '{"version":3}');
        file_put_contents($this->adminPath . '/.nuxt/cache/build.json', '{}');
        file_put_contents($this->adminPath . '/coverage/coverage.json', '{}');
        file_put_contents($this->adminPath . '/node_modules/dependency/fixture.txt', 'dependency fixture is not a build artifact');
        file_put_contents($this->adminPath . '/node_modules/.cache/bundle.cache', 'synthetic dependency cache');
        file_put_contents($this->projectRoot . '/packages/admin-surface/dist/index.html', '<main>publishable</main>');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function inventory_includes_publishable_ignored_source_map_and_cache_outputs_deterministically(): void
    {
        $scanner = new AdminBuildArtifactScanner();

        $first = $scanner->scan($this->projectRoot, $this->adminPath);
        $second = $scanner->scan($this->projectRoot, $this->adminPath);

        self::assertSame($first->inventoryHash, $second->inventoryHash);
        self::assertSame($first->files, $second->files);
        self::assertContains('packages/admin/.output/public/index.html', $first->files);
        self::assertContains('packages/admin/.output/public/_nuxt/app.js.map', $first->files);
        self::assertContains('packages/admin/.nuxt/cache/build.json', $first->files);
        self::assertContains('packages/admin/coverage/coverage.json', $first->files);
        self::assertContains('packages/admin-surface/dist/index.html', $first->files);
        self::assertContains('packages/admin/node_modules/.cache/bundle.cache', $first->files);
        self::assertNotContains('packages/admin/node_modules/dependency/fixture.txt', $first->files);
        self::assertNotContains('packages/admin/app/input.ts', $first->files);
        self::assertTrue($first->clean);
    }

    #[Test]
    public function a_supported_external_admin_package_is_scanned_under_a_stable_logical_prefix(): void
    {
        $external = $this->projectRoot . '-external-admin';
        mkdir($external . '/.output/public', 0700, true);
        file_put_contents($external . '/.output/public/index.html', '<main>external synthetic</main>');

        try {
            $report = new AdminBuildArtifactScanner()->scan($this->projectRoot, $external);

            self::assertContains('admin-package/.output/public/index.html', $report->files);
            self::assertTrue($report->clean);
        } finally {
            unlink($external . '/.output/public/index.html');
            rmdir($external . '/.output/public');
            rmdir($external . '/.output');
            rmdir($external);
        }
    }

    #[Test]
    public function raw_and_encoded_synthetic_canaries_fail_without_entering_evidence(): void
    {
        $canary = 'cfg04-' . 'artifact-' . 'credential-' . bin2hex(random_bytes(10));
        $scanner = new AdminBuildArtifactScanner(syntheticCanaries: [$canary]);

        foreach ([$canary, base64_encode($canary), rawurlencode($canary), json_encode($canary, JSON_THROW_ON_ERROR)] as $index => $form) {
            file_put_contents($this->adminPath . '/.output/public/leak-' . $index . '.txt', $form);
            try {
                $scanner->scan($this->projectRoot, $this->adminPath);
                self::fail('Encoded synthetic canary must fail the artifact scan.');
            } catch (AdminBuildArtifactScanException $e) {
                self::assertSame('artifact-secret-detected', $e->errorCode);
                self::assertStringNotContainsString($canary, $e->getMessage());
                self::assertStringNotContainsString($form, $e->getMessage());
            }
            unlink($this->adminPath . '/.output/public/leak-' . $index . '.txt');
        }
    }

    #[Test]
    public function private_material_and_credential_labeled_high_entropy_values_fail(): void
    {
        $pem = implode("\n", ['-----BEGIN ' . 'PRIVATE KEY-----', str_repeat('Q', 80), '-----END ' . 'PRIVATE KEY-----']);
        file_put_contents($this->adminPath . '/.output/public/key.txt', $pem);
        $this->assertScanFailure('artifact-secret-detected');
        unlink($this->adminPath . '/.output/public/key.txt');

        $entropy = 'apiCredential="' . substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 3)), 0, 80) . '"';
        file_put_contents($this->adminPath . '/.output/public/config.js', $entropy);
        $this->assertScanFailure('artifact-secret-detected');
    }

    #[Test]
    public function a_symlinked_output_refuses_instead_of_scanning_outside_the_project(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symlink support unavailable.');
        }
        $outside = sys_get_temp_dir() . '/waaseyaa_admin_outside_' . bin2hex(random_bytes(5));
        file_put_contents($outside, 'synthetic outside file');
        $link = $this->adminPath . '/.output/public/outside-link';
        if (!@symlink($outside, $link)) {
            unlink($outside);
            self::markTestSkipped('Symlink creation unavailable.');
        }

        try {
            $this->assertScanFailure('artifact-symlink-refused');
        } finally {
            unlink($link);
            unlink($outside);
        }
    }

    private function assertScanFailure(string $expectedCode): void
    {
        try {
            new AdminBuildArtifactScanner()->scan($this->projectRoot, $this->adminPath);
            self::fail('Expected artifact scan refusal.');
        } catch (AdminBuildArtifactScanException $e) {
            self::assertSame($expectedCode, $e->errorCode);
        }
    }
}
