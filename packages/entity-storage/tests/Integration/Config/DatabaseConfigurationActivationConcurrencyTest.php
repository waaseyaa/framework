<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Activation\ConfigurationActivationAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationActivationConflictException;
use Waaseyaa\Config\Activation\ConfigurationActivationContentionException;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Tests\Fixtures\VerifiedConfigBundleFixture;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Config\DatabaseConfigurationActivator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class DatabaseConfigurationActivationConcurrencyTest extends TestCase
{
    private string $directory;
    private string $databasePath;
    private ConfigurationAuthorityContext $context;
    private ConfigurationActiveToken $baseToken;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork() is required for the real two-writer activation proof.');
        }

        $this->directory = sys_get_temp_dir() . '/waaseyaa_cfg02_race_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700, true);
        $this->databasePath = $this->directory . '/config.sqlite';
        $this->context = new ConfigurationAuthorityContext(
            authorityId: str_repeat('b', 64),
            databaseIdentity: 'database:v1:activation-race',
            syncPath: $this->directory,
            selectorProvenance: ['testing'],
        );

        $database = DBALDatabase::createSqlite($this->databasePath, 'testing');
        foreach ([
            '2026_08_12_000002_configuration_authority.php',
            '2026_08_12_000003_configuration_activation.php',
            '2026_08_15_000004_configuration_manifest_replay.php',
        ] as $migrationFile) {
            $migration = require dirname(__DIR__, 3) . '/migrations/' . $migrationFile;
            $migration->up(new SchemaBuilder($database->getConnection()));
        }
        $activator = new DatabaseConfigurationActivator($database, $this->context, $this->authorizer());
        $this->baseToken = $activator->activate(ConfigurationActivationRequest::activateVerified(
            'race-base',
            null,
            VerifiedConfigBundleFixture::fromFiles([$this->file('Base')], 1),
        ))->token;
        $database->getConnection()->close();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    #[Test]
    public function twoWritersFromOneTokenProduceOneWinnerAndOneTypedLoser(): void
    {
        $children = [];
        foreach (['A', 'B'] as $label) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('pcntl_fork() failed.');
            }
            if ($pid === 0) {
                $this->runChild($label);
            }
            $children[] = $pid;
        }

        $deadline = microtime(true) + 10.0;
        while ((!is_file($this->directory . '/ready-A') || !is_file($this->directory . '/ready-B')) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        self::assertFileExists($this->directory . '/ready-A');
        self::assertFileExists($this->directory . '/ready-B');
        file_put_contents($this->directory . '/go', 'go', LOCK_EX);

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $outcomes = [
            trim((string) file_get_contents($this->directory . '/result-A')),
            trim((string) file_get_contents($this->directory . '/result-B')),
        ];
        sort($outcomes, SORT_STRING);
        self::assertSame(['committed', 'typed-loser'], $outcomes);

        $database = DBALDatabase::createSqlite($this->databasePath, 'testing');
        $activator = new DatabaseConfigurationActivator($database, $this->context, $this->authorizer());
        self::assertSame(2, $activator->currentToken()?->activationSequence);
        self::assertSame(2, $this->scalar($database, 'SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
    }

    private function runChild(string $label): never
    {
        $database = DBALDatabase::createSqlite($this->databasePath, 'testing');
        $database->getConnection()->executeStatement('PRAGMA busy_timeout = 5000');
        $activator = new DatabaseConfigurationActivator($database, $this->context, $this->authorizer());
        file_put_contents($this->directory . '/ready-' . $label, 'ready', LOCK_EX);
        $deadline = microtime(true) + 10.0;
        while (!is_file($this->directory . '/go') && microtime(true) < $deadline) {
            usleep(1_000);
        }

        try {
            $activator->activate(ConfigurationActivationRequest::activateVerified(
                'race-' . strtolower($label),
                $this->baseToken,
                VerifiedConfigBundleFixture::fromFiles([$this->file($label)], 2),
            ));
            $outcome = 'committed';
        } catch (ConfigurationActivationConflictException|ConfigurationActivationContentionException) {
            $outcome = 'typed-loser';
        } catch (\Throwable $exception) {
            $outcome = 'unexpected:' . $exception::class . ':' . $exception->getMessage();
        }
        file_put_contents($this->directory . '/result-' . $label, $outcome, LOCK_EX);
        exit(0);
    }

    private function authorizer(): ConfigurationActivationAuthorizerInterface
    {
        return new class implements ConfigurationActivationAuthorizerInterface {
            public function authorize(ConfigurationActivationRequest $request, bool $deletes): void {}
        };
    }

    private function file(string $name): ConfigSyncFile
    {
        return ConfigSyncFile::writable(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: ['name' => $name],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    private function scalar(DBALDatabase $database, string $sql): int
    {
        foreach ($database->query($sql) as $row) {
            return (int) array_values($row)[0];
        }
        self::fail('Scalar query returned no row.');
    }
}
