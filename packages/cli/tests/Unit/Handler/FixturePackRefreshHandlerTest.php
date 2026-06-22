<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\FixturePackRefreshHandler;
use Waaseyaa\CLI\Provider\BundleFixtureServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(FixturePackRefreshHandler::class)]
final class FixturePackRefreshHandlerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_fixture_pack_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.json') ?: [] as $file) {
            unlink((string) $file);
        }
        rmdir($this->tmpDir);
    }

    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new BundleFixtureServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'fixture:pack:refresh') {
                return $cmd;
            }
        }

        throw new \RuntimeException('fixture:pack:refresh command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === FixturePackRefreshHandler::class) {
                    return new FixturePackRefreshHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === FixturePackRefreshHandler::class;
            }
        };
    }

    private function writeScenario(string $name, mixed $data): void
    {
        file_put_contents(
            $this->tmpDir . '/' . $name . '.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function itAggregatesMultipleScenariosIntoFixturePack(): void
    {
        $this->writeScenario('scenario_a', [
            'nodes' => ['node_a' => ['title' => 'A', 'type' => 'article', 'uid' => 1, 'created' => 100, 'changed' => 100, 'status' => 1, 'workflow_state' => 'published']],
            'relationships' => [],
        ]);
        $this->writeScenario('scenario_b', [
            'nodes' => ['node_b' => ['title' => 'B', 'type' => 'article', 'uid' => 1, 'created' => 200, 'changed' => 200, 'status' => 0, 'workflow_state' => 'draft']],
            'relationships' => [],
        ]);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute(['--input-dir=' . $this->tmpDir, '--json']);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $decoded['scenario_count']);
        self::assertSame(2, $decoded['node_count']);
        self::assertArrayHasKey('scenario_a', $decoded['scenarios']);
        self::assertArrayHasKey('scenario_b', $decoded['scenarios']);
        self::assertNotEmpty($decoded['hash']);
    }

    #[Test]
    public function itReturnsErrorForNonExistentInputDir(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute(['--input-dir=/does/not/exist']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('does not exist', $tester->getStderr());
    }

    #[Test]
    public function itReturnsErrorForEmptyDirWhenFailOnEmptySet(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute(['--input-dir=' . $this->tmpDir, '--fail-on-empty']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('No fixture scenario files found', $tester->getStderr());
    }

    #[Test]
    public function itSucceedsForEmptyDirWithoutFailOnEmpty(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute(['--input-dir=' . $this->tmpDir]);

        self::assertSame(0, $tester->getExitCode());
    }
}
