<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MigrateHandler;
use Waaseyaa\CLI\Handler\MigrateStatusHandler;
use Waaseyaa\CLI\Provider\MigrateServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

final class RootApplicationV2MigrationRuntimeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/waaseyaa_root_v2_runtime_'.uniqid();
        mkdir($this->root.'/vendor/composer', 0o755, true);
        file_put_contents($this->root.'/vendor/composer/installed.json', '{"packages":[]}');
        file_put_contents(
            $this->root.'/composer.json',
            json_encode([
                'name' => 'acme/application',
                'extra' => ['waaseyaa' => [
                    'migrations' => ['Waaseyaa\\CLI\\Tests\\Fixtures'],
                ]],
            ], JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        try {
            (new Filesystem())->remove($this->root);
        } catch (IOException) {
            // The provider retains its SQLite connection for the process lifetime;
            // Windows keeps the WAL handle locked until PHPUnit releases it.
        }
    }

    #[Test]
    public function stock_runtime_discovers_plans_applies_reports_and_verifies_root_v2_migration(): void
    {
        $databasePath = $this->root.'/application.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
        $connection->close();

        $provider = new MigrateServiceProvider();
        $provider->setKernelContext($this->root, [
            'environment' => 'testing',
            'database' => $databasePath,
        ], []);
        $provider->register();
        $migrateHandler = $provider->resolve(MigrateHandler::class);
        $statusHandler = $provider->resolve(MigrateStatusHandler::class);
        self::assertInstanceOf(MigrateHandler::class, $migrateHandler);
        self::assertInstanceOf(MigrateStatusHandler::class, $statusHandler);

        $dryRun = self::migrateTester($migrateHandler);
        $dryRun->execute(['--dry-run', '--json']);
        self::assertSame(0, $dryRun->getExitCode());
        self::assertStringContainsString('acme/application:v2:add-widget-profile', $dryRun->getStdout());
        $readConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
        self::assertNotContains('profile', self::columnNames($readConnection));

        $apply = self::migrateTester($migrateHandler);
        $apply->execute([]);
        self::assertSame(0, $apply->getExitCode());
        self::assertContains('profile', self::columnNames($readConnection));

        $status = self::statusTester($statusHandler);
        $status->execute([]);
        self::assertSame(0, $status->getExitCode());
        self::assertStringContainsString('acme/application:v2:add-widget-profile', $status->getStdout());
        self::assertStringContainsString('Ran', $status->getStdout());

        $verify = self::migrateTester($migrateHandler);
        $verify->execute(['--verify', '--json']);
        self::assertSame(0, $verify->getExitCode());
        $verification = json_decode($verify->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($verification);
        self::assertSame('match', $verification['results'][0]['status']);
        self::assertSame(0, $verification['summary']['authority_mismatch']);
        $readConnection->close();
    }

    private static function migrateTester(MigrateHandler $handler): CliTester
    {
        return self::tester(new HandlerCommand(
            name: 'migrate',
            description: 'Migrate',
            options: [
                new HandlerOption('dry-run', mode: HandlerOptionMode::None),
                new HandlerOption('verify', mode: HandlerOptionMode::None),
                new HandlerOption('json', mode: HandlerOptionMode::None),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        ));
    }

    private static function statusTester(MigrateStatusHandler $handler): CliTester
    {
        return self::tester(new HandlerCommand(
            name: 'migrate:status',
            description: 'Migration status',
            handler: \Closure::fromCallable([$handler, 'execute']),
        ));
    }

    private static function tester(HandlerCommand $command): CliTester
    {
        return CliTester::for($command, new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Not found: '.$id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        });
    }

    /** @return list<string> */
    private static function columnNames(\Doctrine\DBAL\Connection $connection): array
    {
        return array_column(
            $connection->executeQuery('PRAGMA table_info(widgets)')->fetchAllAssociative(),
            'name',
        );
    }
}
