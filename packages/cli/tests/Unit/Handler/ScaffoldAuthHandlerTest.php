<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\ScaffoldAuthHandler;
use Waaseyaa\CLI\Provider\OtherScaffoldsServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(ScaffoldAuthHandler::class)]
final class ScaffoldAuthHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_scaffold_auth_test_' . uniqid();
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

    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new OtherScaffoldsServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:auth') {
                return $cmd;
            }
        }

        throw new \RuntimeException('scaffold:auth command definition not found');
    }

    private function makeContainer(string $tempDir): \Psr\Container\ContainerInterface
    {
        return new class ($tempDir) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly string $tempDir) {}

            public function get(string $id): mixed
            {
                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function itCopiesAllAuthFiles(): void
    {
        $handler = new ScaffoldAuthHandler($this->tempDir);
        $provider = new OtherScaffoldsServiceProvider();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:auth') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        // Use the handler directly with a Closure-based definition
        $tester = CliTester::for(
            new \Waaseyaa\CLI\Command\HandlerCommand(
                name: 'scaffold:auth',
                description: 'Copy framework auth UI files into your app for customization',
                options: $definition->options,
                handler: \Closure::fromCallable([$handler, 'execute']),
            ),
            $this->makeContainer($this->tempDir),
        );

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertFileExists($this->tempDir . '/app/pages/login.vue');
        self::assertFileExists($this->tempDir . '/app/components/auth/LoginForm.vue');
        self::assertFileExists($this->tempDir . '/app/components/auth/BrandPanel.vue');
        self::assertFileExists($this->tempDir . '/app/composables/useAuth.ts');
        self::assertFileExists($this->tempDir . '/app/assets/auth.css');
    }

    #[Test]
    public function itSkipsExistingFilesWithoutForce(): void
    {
        mkdir($this->tempDir . '/app/pages', 0755, true);
        file_put_contents($this->tempDir . '/app/pages/login.vue', 'custom');

        $handler = new ScaffoldAuthHandler($this->tempDir);
        $provider = new OtherScaffoldsServiceProvider();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:auth') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $tester = CliTester::for(
            new \Waaseyaa\CLI\Command\HandlerCommand(
                name: 'scaffold:auth',
                description: 'Copy framework auth UI files into your app for customization',
                options: $definition->options,
                handler: \Closure::fromCallable([$handler, 'execute']),
            ),
            $this->makeContainer($this->tempDir),
        );

        $tester->execute([]);

        self::assertStringContainsString('custom', (string) file_get_contents($this->tempDir . '/app/pages/login.vue'));
        self::assertStringContainsString('SKIP', $tester->getStdout());
    }

    #[Test]
    public function dryRunDoesNotWriteFiles(): void
    {
        $handler = new ScaffoldAuthHandler($this->tempDir);
        $provider = new OtherScaffoldsServiceProvider();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:auth') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $tester = CliTester::for(
            new \Waaseyaa\CLI\Command\HandlerCommand(
                name: 'scaffold:auth',
                description: 'Copy framework auth UI files into your app for customization',
                options: $definition->options,
                handler: \Closure::fromCallable([$handler, 'execute']),
            ),
            $this->makeContainer($this->tempDir),
        );

        $tester->execute(['--dry-run']);

        self::assertFileDoesNotExist($this->tempDir . '/app/pages/login.vue');
        self::assertStringContainsString('login.vue', $tester->getStdout());
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
