<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakePublicHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderB;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakePublicHandler::class)]
final class MakePublicCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa-make-public-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function it_creates_public_index_php(): void
    {
        $tester = $this->createTester($this->tempDir);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        $target = $this->tempDir . '/public/index.php';
        self::assertFileExists($target);
        $contents = (string) file_get_contents($target);
        self::assertNotSame('', $contents);
        self::assertStringContainsString('HttpKernel', $contents);
        self::assertStringContainsString('handle()', $contents);
        self::assertStringContainsString("->loadEnv(\$projectRoot . '/.env', 'APP_ENV', 'production');", $contents);
    }

    #[Test]
    public function it_refuses_to_overwrite_existing_file(): void
    {
        mkdir($this->tempDir . '/public', 0755, true);
        $target = $this->tempDir . '/public/index.php';
        file_put_contents($target, '<?php // existing');

        $tester = $this->createTester($this->tempDir);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('already exists', $tester->getStdout());
        self::assertSame('<?php // existing', file_get_contents($target));
    }

    #[Test]
    public function it_overwrites_with_force_flag(): void
    {
        mkdir($this->tempDir . '/public', 0755, true);
        $target = $this->tempDir . '/public/index.php';
        file_put_contents($target, '<?php // old');

        $tester = $this->createTester($this->tempDir);
        $tester->execute(['--force']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('HttpKernel', (string) file_get_contents($target));
    }

    private function createTester(string $projectRoot): CliTester
    {
        $provider = new MakeServiceProviderB();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:public') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class ($projectRoot) implements ContainerInterface {
            public function __construct(private readonly string $projectRoot) {}

            public function get(string $id): mixed
            {
                if ($id === MakePublicHandler::class) {
                    return new MakePublicHandler($this->projectRoot);
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakePublicHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $item_path = $dir . '/' . $item;
            if (is_dir($item_path)) {
                $this->removeDir($item_path);
            } else {
                @unlink($item_path);
            }
        }
        @rmdir($dir);
    }
}
