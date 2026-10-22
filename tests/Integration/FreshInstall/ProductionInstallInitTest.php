<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FreshInstall;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

#[CoversNothing]
final class ProductionInstallInitTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 3);
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa-production-install-' . bin2hex(random_bytes(8));
        new Filesystem()->mkdir($this->projectRoot . '/config');
        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        new Filesystem()->mirror($this->repoRoot . '/skeleton/src', $this->projectRoot . '/src');
        new Filesystem()->mkdir($this->projectRoot . '/public');
        copy($this->repoRoot . '/skeleton/public/index.php', $this->projectRoot . '/public/index.php');
        file_put_contents($this->projectRoot . '/vendor/autoload.php', '<?php $loader = require '
            . var_export($this->repoRoot . '/vendor/autoload.php', true)
            . '; $loader->addPsr4("App\\\\", __DIR__ . "/../src"); return $loader;');
        file_put_contents($this->projectRoot . '/composer.json', "{\"name\":\"test/production-install\"}\n");
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php return [];\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    #[DataProvider('databasePaths')]
    public function production_installation_creates_the_configured_database_and_preserves_existing_data(
        ?string $configured,
        ?string $environment,
        string $expected,
    ): void {
        $configured = $configured === null ? null : str_replace('{root}', $this->projectRoot, $configured);
        $environment = $environment === null ? null : str_replace('{root}', $this->projectRoot, $environment);
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', '<?php return '
            . var_export($configured === null ? [] : ['database' => $configured], true) . ';');
        $databasePath = $this->projectRoot . '/' . $expected;
        self::assertFileDoesNotExist($databasePath);

        $install = $this->console('install:init', $environment);

        self::assertSame(0, $install->getExitCode(), $install->getOutput() . $install->getErrorOutput());
        self::assertStringContainsString('Installation is complete.', $install->getOutput());
        self::assertFileExists($databasePath);
        $connection = new \PDO('sqlite:' . $databasePath);
        $connection->exec("INSERT INTO node (type, title) VALUES ('page', 'preserve-me')");
        $before = $connection->query('SELECT * FROM waaseyaa_config_activation_v2 ORDER BY authority_id')->fetchAll(\PDO::FETCH_ASSOC);
        $connection = null;

        $repeat = $this->console('install:init', $environment);

        self::assertSame(0, $repeat->getExitCode(), $repeat->getOutput() . $repeat->getErrorOutput());
        self::assertStringContainsString('Configuration already initialized', $repeat->getOutput());
        $connection = new \PDO('sqlite:' . $databasePath);
        self::assertSame('preserve-me', $connection->query("SELECT title FROM node WHERE title = 'preserve-me'")->fetchColumn());
        self::assertSame($before, $connection->query('SELECT * FROM waaseyaa_config_activation_v2 ORDER BY authority_id')->fetchAll(\PDO::FETCH_ASSOC));
        self::assertFileDoesNotExist($this->projectRoot . '/ignored/database.sqlite');
    }

    public static function databasePaths(): iterable
    {
        yield 'default' => [null, null, 'storage/waaseyaa.sqlite'];
        yield 'configured relative path beats environment' => ['state/nested/app.sqlite', 'ignored/database.sqlite', 'state/nested/app.sqlite'];
        yield 'environment relative path' => [null, 'state/from-env.sqlite', 'state/from-env.sqlite'];
        yield 'configured absolute path' => ['{root}/state/absolute.sqlite', null, 'state/absolute.sqlite'];
        yield 'environment absolute path' => [null, '{root}/state/env-absolute.sqlite', 'state/env-absolute.sqlite'];
    }

    #[Test]
    #[DataProvider('nonExecutingInstallationInputs')]
    public function help_and_invalid_installation_input_do_not_create_storage(array $arguments, int $exitCode): void
    {
        $database = $this->projectRoot . '/selected/nested/app.sqlite';

        $process = $this->console('install:init', $database, $arguments);

        self::assertSame($exitCode, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertFileDoesNotExist($database);
        self::assertFileDoesNotExist($database . '-wal');
        self::assertFileDoesNotExist($database . '-shm');
        self::assertDirectoryDoesNotExist($this->projectRoot . '/selected');
        self::assertDirectoryDoesNotExist($this->projectRoot . '/storage');
    }

    public static function nonExecutingInstallationInputs(): iterable
    {
        yield 'help' => [['--help'], 0];
        yield 'short help' => [['-h'], 0];
        yield 'invalid option' => [['--invalid-install-option'], 2];
        yield 'unexpected argument' => [['unexpected-argument'], 2];
    }

    #[Test]
    public function ordinary_production_console_boot_still_refuses_an_absent_database(): void
    {
        $process = $this->console('about');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Database not found', $process->getOutput() . $process->getErrorOutput());
        self::assertFileDoesNotExist($this->projectRoot . '/storage/waaseyaa.sqlite');
    }

    #[Test]
    #[DataProvider('restrictedCommands')]
    public function other_restricted_commands_do_not_receive_installation_authority(string $command): void
    {
        $process = $this->console($command);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Database not found', $process->getOutput() . $process->getErrorOutput());
        self::assertFileDoesNotExist($this->projectRoot . '/storage/waaseyaa.sqlite');
    }

    public static function restrictedCommands(): iterable
    {
        yield ['migrate:status'];
        yield ['schema:sync'];
        yield ['field-access:preflight'];
    }

    #[Test]
    public function installation_does_not_create_field_access_activation_evidence(): void
    {
        $install = $this->console('install:init');
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput());

        $ordinary = $this->console('about');

        self::assertSame(1, $ordinary->getExitCode());
        self::assertStringContainsString('field-access-preflight.json', $ordinary->getOutput() . $ordinary->getErrorOutput());
        self::assertFileDoesNotExist($this->projectRoot . '/.waaseyaa/field-access-preflight.json');
    }

    #[Test]
    public function installation_requires_a_valid_production_secret_before_creating_storage(): void
    {
        $process = new Process(
            [PHP_BINARY, $this->repoRoot . '/packages/cli/bin/waaseyaa', 'install:init'],
            $this->projectRoot,
            ['WAASEYAA_APP_SECRET' => 'invalid'] + $this->environment(),
            timeout: 60,
        );
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('WAASEYAA_APP_SECRET', $process->getOutput() . $process->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->projectRoot . '/storage');
    }

    #[Test]
    public function installation_still_refuses_existing_schema_drift_without_losing_data(): void
    {
        self::assertSame(0, $this->console('install:init')->getExitCode());
        $connection = new \PDO('sqlite:' . $this->projectRoot . '/storage/waaseyaa.sqlite');
        $connection->exec('CREATE TABLE j1_foreign_table (value TEXT)');
        $connection->exec("INSERT INTO j1_foreign_table VALUES ('preserve-me')");
        $before = $connection->query('SELECT * FROM waaseyaa_config_activation_v2')->fetchAll(\PDO::FETCH_ASSOC);
        $connection = null;

        $repeat = $this->console('install:init');

        self::assertSame(1, $repeat->getExitCode());
        self::assertStringContainsString('S1-DB109', $repeat->getOutput() . $repeat->getErrorOutput());
        $connection = new \PDO('sqlite:' . $this->projectRoot . '/storage/waaseyaa.sqlite');
        self::assertSame('preserve-me', $connection->query('SELECT value FROM j1_foreign_table')->fetchColumn());
        self::assertSame($before, $connection->query('SELECT * FROM waaseyaa_config_activation_v2')->fetchAll(\PDO::FETCH_ASSOC));
    }

    #[Test]
    public function ordinary_production_http_boot_still_refuses_an_absent_database(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);
        $server = new Process([PHP_BINARY, '-S', $address, '-t', $this->projectRoot . '/public',
            $this->projectRoot . '/public/index.php'], $this->projectRoot, $this->environment(), timeout: 15);

        try {
            $server->start();
            self::assertTrue($server->waitUntil(static fn(string $type, string $output): bool => str_contains($output, 'Development Server')));
            $body = file_get_contents(
                'http://' . $address . '/',
                false,
                stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]),
            );
            $headers = http_get_last_response_headers();

            self::assertIsArray($headers);
            self::assertStringContainsString('500', $headers[0]);
            self::assertIsString($body);
            self::assertStringNotContainsString($this->projectRoot, $body);
            self::assertFileDoesNotExist($this->projectRoot . '/storage/waaseyaa.sqlite');
        } finally {
            $server->stop(1);
        }
    }

    /** @return array<string, string|false> */
    private function environment(?string $database = null): array
    {
        return ['APP_ENV' => 'production', 'APP_DEBUG' => 'false',
            'WAASEYAA_APP_SECRET' => 'base64:' . base64_encode(str_repeat('J', 32)),
            'WAASEYAA_DB' => $database ?? false] + getenv();
    }

    private function console(string $command, ?string $database = null, array $arguments = []): Process
    {
        $process = new Process(
            [PHP_BINARY, $this->repoRoot . '/packages/cli/bin/waaseyaa', $command, '--no-interaction', '--no-ansi', ...$arguments],
            $this->projectRoot,
            $this->environment($database),
            timeout: 60,
        );
        $process->run();

        return $process;
    }
}
