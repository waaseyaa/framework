<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Scaffold\AuthUiScaffoldManager;

#[CoversClass(AuthUiScaffoldManager::class)]
final class AuthUiScaffoldManifestModeTest extends TestCase
{
    private const int PORTABLE_MODE_644 = 0o644;
    private const int PRIVATE_MODE_600 = 0o600;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_auth_ui_scaffold_mode_' . uniqid();
        $this->seedFrameworkAuthUiSources($this->tempDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function publishWritesScaffoldManifestAtThePortableContractMode(): void
    {
        $manager = new AuthUiScaffoldManager($this->tempDir);
        $result = $manager->publish(force: true, dryRun: false);

        self::assertSame(5, $result['copied']);
        $manifestPath = $this->tempDir . '/app/.waaseyaa/scaffold-manifest.json';
        self::assertFileExists($manifestPath);
        self::assertSame([], glob($this->tempDir . '/app/.waaseyaa/.scaffold-manifest.*') ?: []);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(AuthUiScaffoldManager::MANIFEST_SCHEMA, $manifest['schema']);
        self::assertArrayHasKey('pages/login.vue', $manifest['scaffolds']['auth-ui']['files']);

        $this->assertPortableMode($manifestPath, 'the auth UI scaffold manifest after publish()');
        self::assertNotSame(
            self::PRIVATE_MODE_600,
            fileperms($manifestPath) & 0o777,
            'publish() must not leave scaffold-manifest.json at tempnam()\'s private default (0600)',
        );
    }

    #[Test]
    public function acceptCurrentWritesScaffoldManifestAtThePortableContractMode(): void
    {
        $manager = new AuthUiScaffoldManager($this->tempDir);
        self::assertSame(5, $manager->publish(force: true, dryRun: false)['copied']);

        file_put_contents($this->tempDir . '/app/pages/login.vue', '<template>custom merged login</template>');
        self::assertSame(5, $manager->acceptCurrent());

        $manifestPath = $this->tempDir . '/app/.waaseyaa/scaffold-manifest.json';
        self::assertFileExists($manifestPath);
        self::assertSame([], glob($this->tempDir . '/app/.waaseyaa/.scaffold-manifest.*') ?: []);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            hash('sha256', '<template>custom merged login</template>'),
            $manifest['scaffolds']['auth-ui']['files']['pages/login.vue']['consumer_digest'],
        );

        $this->assertPortableMode($manifestPath, 'the auth UI scaffold manifest after acceptCurrent()');
        self::assertNotSame(
            self::PRIVATE_MODE_600,
            fileperms($manifestPath) & 0o777,
            'acceptCurrent() must not leave scaffold-manifest.json at tempnam()\'s private default (0600)',
        );
    }

    #[Test]
    public function thePortableModeAssertionGenuinelyDiscriminatesOnTheRealInodeMode(): void
    {
        $private = $this->tempDir . '/private-manifest.json';
        $portable = $this->tempDir . '/portable-manifest.json';
        file_put_contents($private, '{}');
        chmod($private, self::PRIVATE_MODE_600);
        file_put_contents($portable, '{}');
        chmod($portable, self::PORTABLE_MODE_644);

        $this->assertPortableMode($portable, 'the 0644 fixture');

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->assertPortableMode($private, 'the 0600 fixture');
    }

    private function assertPortableMode(string $path, string $description): void
    {
        $mode = fileperms($path);
        self::assertNotFalse($mode, sprintf('unable to stat %s', $description));
        self::assertContains(
            $mode & 0o777,
            [self::PORTABLE_MODE_644, 0o755],
            sprintf(
                '%s must be portable (mode 0644 or 0755); found %04o — a portable artifact left at a private-by-default mode (e.g. tempnam()\'s 0600) is bundled unreadable by whatever consumes it in a different process or user context',
                $description,
                $mode & 0o777,
            ),
        );
    }

    private function seedFrameworkAuthUiSources(string $root): void
    {
        mkdir($root . '/packages/admin/app/pages', 0755, true);
        mkdir($root . '/packages/admin/app/components/auth', 0755, true);
        mkdir($root . '/packages/admin/app/composables', 0755, true);
        mkdir($root . '/packages/admin/app/assets', 0755, true);
        file_put_contents($root . '/packages/admin/app/pages/login.vue', '<template>login</template>');
        file_put_contents($root . '/packages/admin/app/components/auth/LoginForm.vue', '<template>form</template>');
        file_put_contents($root . '/packages/admin/app/components/auth/BrandPanel.vue', '<template>brand</template>');
        file_put_contents($root . '/packages/admin/app/composables/useAuth.ts', 'export function useAuth() {}');
        file_put_contents($root . '/packages/admin/app/assets/auth.css', ':root {}');
        file_put_contents($root . '/VERSION', "v0.1.0-alpha.299\n");
    }
}
