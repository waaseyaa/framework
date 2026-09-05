<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\CLI\Provider\MiscBServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Resource selection follows the loaded owner, not CLI's physical siblings.
 * This isolated layout does not qualify a Composer provenance profile.
 */
#[CoversClass(MiscBServiceProvider::class)]
final class MiscBSyncRulesTopologyTest extends TestCase
{
    #[Test]
    public function rules_come_from_loaded_foundation_when_cli_has_a_decoy_sibling(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_rules_topology_' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();

        try {
            $providerDirectory = $root . '/detached/cli/src/Provider';
            $decoyDirectory = $root . '/detached/foundation/.claude/rules';
            $app = $root . '/app';
            $filesystem->mkdir([$providerDirectory, $decoyDirectory, $app], 0o700);
            $providerSource = (string) new \ReflectionClass(MiscBServiceProvider::class)->getFileName();
            $providerCopy = $providerDirectory . '/MiscBServiceProvider.php';
            $filesystem->copy($providerSource, $providerCopy);
            self::assertSame(hash_file('sha256', $providerSource), hash_file('sha256', $providerCopy));
            file_put_contents($decoyDirectory . '/waaseyaa-decoy.md', "Wrong package owner.\n");
            file_put_contents($app . '/composer.json', "{}\n");

            $probe = $root . '/probe.php';
            file_put_contents($probe, <<<'PHP'
                <?php
                declare(strict_types=1);

                require $argv[1];
                // Load unmodified provider bytes before its ordinary PSR-4 lookup.
                require $argv[2];

                $provider = new \Waaseyaa\CLI\Provider\MiscBServiceProvider();
                $provider->setKernelContext($argv[3], [], []);
                foreach ($provider->consoleCommands() as $command) {
                    if (!$command instanceof \Waaseyaa\CLI\Command\HandlerCommand || $command->name !== 'sync-rules') {
                        continue;
                    }
                    $container = new class implements \Psr\Container\ContainerInterface {
                        public function get(string $id): mixed
                        {
                            throw new \RuntimeException('Unexpected container resolution: ' . $id);
                        }

                        public function has(string $id): bool
                        {
                            return false;
                        }
                    };
                    $tester = \Waaseyaa\CLI\Testing\CliTester::for($command, $container);
                    $tester->executeMap([]);
                    echo json_encode([
                        'exit' => $tester->getExitCode(),
                        'stdout' => $tester->getStdout(),
                        'stderr' => $tester->getStderr(),
                        'provider' => (new \ReflectionClass($provider))->getFileName(),
                        'foundation' => (new \ReflectionClass(\Waaseyaa\Foundation\ServiceProvider\ServiceProvider::class))->getFileName(),
                    ], JSON_THROW_ON_ERROR);
                    exit($tester->getExitCode());
                }
                throw new \RuntimeException('sync-rules command missing');
                PHP);

            $process = new Process([PHP_BINARY, $probe, dirname(__DIR__, 5) . '/vendor/autoload.php', $providerCopy, $app]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $result['exit']);
            self::assertSame('', $result['stderr']);
            self::assertSame(realpath($providerCopy), realpath($result['provider']));
            $foundationFile = (string) new \ReflectionClass(ServiceProvider::class)->getFileName();
            self::assertSame(realpath($foundationFile), realpath($result['foundation']));
            self::assertNotSame(realpath($decoyDirectory), realpath(dirname($foundationFile, 3) . '/.claude/rules'));

            $expectedFiles = glob(dirname($foundationFile, 3) . '/.claude/rules/waaseyaa-*.md');
            self::assertNotFalse($expectedFiles);
            self::assertNotEmpty($expectedFiles);
            $actualFiles = glob($app . '/.claude/rules/waaseyaa-*.md');
            self::assertNotFalse($actualFiles);
            self::assertSame(array_map('basename', $expectedFiles), array_map('basename', $actualFiles));
            foreach ($expectedFiles as $expectedFile) {
                self::assertSame(file_get_contents($expectedFile), file_get_contents($app . '/.claude/rules/' . basename($expectedFile)));
            }
            self::assertFileDoesNotExist($app . '/.claude/rules/waaseyaa-decoy.md');
            self::assertDirectoryDoesNotExist($app . '/vendor/waaseyaa/framework');
        } finally {
            $filesystem->remove($root);
        }
    }
}
