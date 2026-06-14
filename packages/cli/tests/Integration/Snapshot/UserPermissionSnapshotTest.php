<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration\Snapshot;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\CliKernel;
use Waaseyaa\CLI\CommandRegistry;
use Waaseyaa\CLI\Help\HelpRenderer;
use Waaseyaa\CLI\Io\BufferedCliOutput;
use Waaseyaa\CLI\Io\EmptyStdinSource;
use Waaseyaa\CLI\Provider\UserPermissionServiceProvider;

/**
 * Snapshot tests: verify user/permission --help output matches the WP01 fixtures byte-for-byte.
 *
 * Covers: user:create, user:role, user:assign-role, permission:list
 */
#[CoversNothing]
final class UserPermissionSnapshotTest extends TestCase
{
    private CommandRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CommandRegistry();
        $provider = new UserPermissionServiceProvider();
        foreach ($provider->nativeCommands() as $cmd) {
            $this->registry->register($cmd);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function helpFixtures(): array
    {
        return [
            'user:create'       => ['user:create',       'user__create.help.stdout'],
            'user:role'         => ['user:role',          'user__role.help.stdout'],
            'user:assign-role'  => ['user:assign-role',   'user__assign-role.help.stdout'],
            'permission:list'   => ['permission:list',    'permission__list.help.stdout'],
        ];
    }

    #[Test]
    #[DataProvider('helpFixtures')]
    public function helpOutputMatchesFixtureByteForByte(string $commandName, string $fixtureFile): void
    {
        $fixturePath = __DIR__ . '/../../Fixtures/snapshots/' . $fixtureFile;
        $fixture = file_get_contents($fixturePath);
        self::assertNotFalse($fixture, sprintf('Fixture file must exist: %s', $fixturePath));

        [$stdout, $exitCode] = $this->runHelp($commandName);

        self::assertSame(0, $exitCode, sprintf('%s --help must exit 0', $commandName));
        self::assertSame($fixture, $stdout, sprintf('%s --help output must match fixture byte-for-byte', $commandName));
    }

    /**
     * @return array{string, int}
     */
    private function runHelp(string $commandName): array
    {
        $stdout = new BufferedCliOutput();
        $stderr = new BufferedCliOutput();

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException(sprintf('Container::get(%s) called in snapshot test', $id));
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $kernel = new CliKernel(
            registry: $this->registry,
            container: $container,
            help: new HelpRenderer(),
            stdout: $stdout,
            stderr: $stderr,
            stdin: new EmptyStdinSource(),
        );

        $exitCode = $kernel->run([$commandName, '--help']);

        return [$stdout->getContents(), $exitCode];
    }
}
