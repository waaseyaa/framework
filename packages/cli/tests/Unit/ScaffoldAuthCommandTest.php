<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\ScaffoldAuthCommand;

#[CoversClass(ScaffoldAuthCommand::class)]
final class ScaffoldAuthCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_scaffold_test_' . uniqid();
        mkdir($this->tempDir . '/packages/admin/app/pages', 0755, true);
        mkdir($this->tempDir . '/packages/admin/app/components/auth', 0755, true);
        mkdir($this->tempDir . '/packages/admin/app/composables', 0755, true);
        mkdir($this->tempDir . '/packages/admin/app/assets', 0755, true);

        file_put_contents($this->tempDir . '/packages/admin/app/pages/login.vue', '<template>login</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/components/auth/LoginForm.vue', '<template>form</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/components/auth/BrandPanel.vue', '<template>brand</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/composables/useAuth.ts', 'export function useAuth() {}');
        file_put_contents($this->tempDir . '/packages/admin/app/assets/auth.css', ':root {}');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function it_copies_all_auth_files(): void
    {
        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($this->tempDir . '/app/pages/login.vue');
        self::assertFileExists($this->tempDir . '/app/components/auth/LoginForm.vue');
        self::assertFileExists($this->tempDir . '/app/components/auth/BrandPanel.vue');
        self::assertFileExists($this->tempDir . '/app/composables/useAuth.ts');
        self::assertFileExists($this->tempDir . '/app/assets/auth.css');
    }

    #[Test]
    public function it_skips_existing_files_without_force(): void
    {
        mkdir($this->tempDir . '/app/pages', 0755, true);
        file_put_contents($this->tempDir . '/app/pages/login.vue', 'custom');

        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('custom', file_get_contents($this->tempDir . '/app/pages/login.vue'));
        self::assertStringContainsString('SKIP', $tester->getDisplay());
    }

    #[Test]
    public function it_overwrites_with_force(): void
    {
        mkdir($this->tempDir . '/app/pages', 0755, true);
        file_put_contents($this->tempDir . '/app/pages/login.vue', 'custom');

        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        self::assertStringContainsString('<template>login</template>', file_get_contents($this->tempDir . '/app/pages/login.vue'));
    }

    #[Test]
    public function dry_run_does_not_write_files(): void
    {
        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        self::assertFileDoesNotExist($this->tempDir . '/app/pages/login.vue');
        self::assertStringContainsString('login.vue', $tester->getDisplay());
    }

    #[Test]
    public function it_writes_scaffold_manifest(): void
    {
        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $manifestPath = $this->tempDir . '/app/.waaseyaa/scaffold-manifest.json';
        self::assertFileExists($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true);
        self::assertArrayHasKey('pages/login.vue', $manifest);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
