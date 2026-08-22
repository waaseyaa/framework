<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Preflight;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\FieldAccessPreflightHandler;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Kernel\Preflight\LiveEntitySchemaFingerprint;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

/**
 * #2478: production HTTP must not create entity-storage tables after the
 * field-access preflight has fingerprinted the schema.
 */
#[CoversNothing]
final class ProductionHttpEntitySchemaMutationTest extends TestCase
{
    private string $repoRoot;

    private string $projectRoot;

    private string $secret;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa-2478-' . bin2hex(random_bytes(6));
        $this->secret = 'base64:' . base64_encode(random_bytes(32));
        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_APP_SECRET', 'WAASEYAA_DB'] as $name) {
            $this->originalEnv[$name] = getenv($name);
        }
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        mkdir($this->projectRoot . '/.waaseyaa', 0o755, true);
        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        copy($this->repoRoot . '/VERSION', $this->projectRoot . '/VERSION');
        copy($this->repoRoot . '/composer.lock', $this->projectRoot . '/composer.lock');
        copy($this->repoRoot . '/composer.json', $this->projectRoot . '/composer.json');
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', sprintf(
            "<?php\ndeclare(strict_types=1);\nreturn [\n    'database' => %s,\n    'environment' => getenv('APP_ENV') ?: 'production',\n    'debug' => false,\n    'app' => ['url' => 'http://localhost', 'name' => '2478'],\n];\n",
            var_export($databasePath, true),
        ));
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn [];\n");
        $this->putEnv('APP_DEBUG', '0');
        $this->putEnv('WAASEYAA_APP_SECRET', $this->secret);
        $this->putEnv('WAASEYAA_DB', $databasePath);
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
        EntityReadRuntime::installFieldRegistry(null);
        EntityReadRuntime::installGuard(null);
        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name]);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
        if (is_dir($this->projectRoot)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->projectRoot);
        }
    }

    #[Test]
    public function complete_install_produces_canonical_attachment_schema_and_stable_production_fingerprint(): void
    {
        $this->install();
        $schema = $this->sqlite();
        self::assertTrue($this->tableExists($schema, 'attachment'));
        self::assertTrue($this->columnExists($schema, 'attachment', 'is_active'));
        self::assertTrue($this->columnExists($schema, 'attachment', 'parent_entity_type'));

        $first = $this->fingerprintAndTables();
        $this->writeReadyPreflight();
        $artifact = (string) file_get_contents($this->projectRoot . '/.waaseyaa/field-access-preflight.json');

        $this->putEnv('APP_ENV', 'production');
        $this->bootProduction();
        $this->bootProduction();

        $second = $this->fingerprintAndTables();
        self::assertSame($first['fingerprint'], $second['fingerprint']);
        self::assertSame($first['tables'], $second['tables']);
        self::assertSame($artifact, (string) file_get_contents($this->projectRoot . '/.waaseyaa/field-access-preflight.json'));
    }

    #[Test]
    public function incomplete_schema_production_boot_refuses_before_ddl_and_keeps_artifact_byte_stable(): void
    {
        $this->install();
        $this->sqlite()->exec('DROP TABLE IF EXISTS attachment');
        $before = $this->fingerprintAndTables();
        self::assertFalse(in_array('attachment', $before['tables'], true));
        $this->writeReadyPreflight();
        $artifact = (string) file_get_contents($this->projectRoot . '/.waaseyaa/field-access-preflight.json');

        $this->putEnv('APP_ENV', 'production');
        $threw = false;
        try {
            $this->bootProduction();
        } catch (\RuntimeException $exception) {
            $threw = true;
            self::assertStringContainsString('S1-DB106', $exception->getMessage());
            self::assertStringContainsString('schema:sync', $exception->getMessage());
        }
        self::assertTrue($threw, 'Production boot must refuse a missing entity-storage table.');

        $after = $this->fingerprintAndTables();
        self::assertSame($before['fingerprint'], $after['fingerprint']);
        self::assertSame($before['tables'], $after['tables']);
        self::assertFalse(in_array('attachment', $after['tables'], true));
        self::assertSame($artifact, (string) file_get_contents($this->projectRoot . '/.waaseyaa/field-access-preflight.json'));
    }

    #[Test]
    public function production_boot_does_not_heal_a_base_only_attachment_table(): void
    {
        $this->install();
        $this->replaceAttachmentWithBaseOnlyTable();
        self::assertFalse($this->columnExists($this->sqlite(), 'attachment', 'is_active'));
        $this->writeReadyPreflight();
        $artifact = (string) file_get_contents($this->projectRoot . '/.waaseyaa/field-access-preflight.json');
        $before = $this->fingerprintAndTables();
        $sqlBefore = $this->attachmentTableSql();

        $this->putEnv('APP_ENV', 'production');
        $this->bootProduction();

        $after = $this->fingerprintAndTables();
        self::assertFalse($this->columnExists($this->sqlite(), 'attachment', 'is_active'));
        self::assertSame($sqlBefore, $this->attachmentTableSql());
        self::assertSame($before['fingerprint'], $after['fingerprint']);
        self::assertSame($before['tables'], $after['tables']);
        self::assertSame($artifact, (string) file_get_contents($this->projectRoot . '/.waaseyaa/field-access-preflight.json'));
    }

    #[Test]
    public function production_http_redacts_missing_schema_without_creating_tables(): void
    {
        $this->install();
        $this->sqlite()->exec('DROP TABLE IF EXISTS attachment');
        $this->writeReadyPreflight();
        $this->putEnv('APP_ENV', 'production');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $response = new HttpKernel($this->projectRoot)->handle();
        self::assertSame(500, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $detail = $payload['errors'][0]['detail'] ?? '';
        self::assertIsString($detail);
        self::assertStringContainsString('schema:sync', $detail);
        self::assertStringContainsString('install:init', $detail);
        self::assertStringNotContainsString($this->projectRoot, $detail);
        self::assertStringNotContainsString('attachment', $detail);
        self::assertFalse(in_array('attachment', $this->fingerprintAndTables()['tables'], true));
    }

    #[Test]
    public function concurrent_production_boots_do_not_create_missing_entity_tables(): void
    {
        $this->install();
        $this->sqlite()->exec('DROP TABLE IF EXISTS attachment');
        $this->writeReadyPreflight();
        $probe = __DIR__ . '/Fixtures/production_http_entity_schema_probe.php';
        $command = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($probe),
            escapeshellarg($this->projectRoot),
            escapeshellarg($this->secret),
        );
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $one = proc_open($command, $descriptors, $pipesOne);
        $two = proc_open($command, $descriptors, $pipesTwo);
        self::assertIsResource($one);
        self::assertIsResource($two);
        $outOne = stream_get_contents($pipesOne[1]);
        $errOne = stream_get_contents($pipesOne[2]);
        $outTwo = stream_get_contents($pipesTwo[1]);
        $errTwo = stream_get_contents($pipesTwo[2]);
        fclose($pipesOne[1]);
        fclose($pipesOne[2]);
        fclose($pipesTwo[1]);
        fclose($pipesTwo[2]);
        $statusOne = proc_close($one);
        $statusTwo = proc_close($two);
        self::assertSame(0, $statusOne, (string) $outOne . (string) $errOne);
        self::assertSame(0, $statusTwo, (string) $outTwo . (string) $errTwo);
        $resultOne = json_decode((string) $outOne, true, flags: JSON_THROW_ON_ERROR);
        $resultTwo = json_decode((string) $outTwo, true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($resultOne['booted']);
        self::assertFalse($resultTwo['booted']);
        self::assertStringContainsString('S1-DB106', (string) $resultOne['error']);
        self::assertStringContainsString('S1-DB106', (string) $resultTwo['error']);
        self::assertFalse(in_array('attachment', $this->fingerprintAndTables()['tables'], true));
    }

    private function install(): void
    {
        $this->putEnv('APP_ENV', 'local');
        $argv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['waaseyaa', 'install:init'];
        try {
            ob_start();
            $exit = new ConsoleKernel($this->projectRoot)->handle();
            $output = (string) ob_get_clean();
        } finally {
            $_SERVER['argv'] = $argv;
        }
        self::assertSame(0, $exit, $output);
    }

    private function writeReadyPreflight(): void
    {
        $this->putEnv('APP_ENV', 'local');
        $kernel = new ConsoleKernel($this->projectRoot);
        $kernel->bootForFieldAccessPreflight();
        $definition = new InputDefinition([
            new InputOption('format', mode: InputOption::VALUE_OPTIONAL, default: 'json'),
            new InputOption('write-artifact', mode: InputOption::VALUE_NONE),
        ]);
        $handler = new FieldAccessPreflightHandler(
            new DatabaseFieldAccessInventoryScanner($kernel->getDatabase(), $kernel->getEntityTypeManager()),
            $kernel->getEntityTypeManager(),
            projectRoot: $this->projectRoot,
        );
        $output = new BufferedOutput();
        $handler->execute(new SymfonyCommandIO(new ArrayInput(['--write-artifact' => true], $definition), $output));
        $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        if (!($decoded['ready'] ?? false)) {
            $fields = [];
            foreach ($decoded['unclassified_entries'] ?? [] as $key) {
                $fields[$key] = 'public';
            }
            file_put_contents(
                $this->projectRoot . '/.waaseyaa/field-access-classification.json',
                json_encode(['fields' => $fields], JSON_THROW_ON_ERROR),
            );
            if ($kernel->getDatabase() instanceof DBALDatabase) {
                $kernel->getDatabase()->getConnection()->close();
            }
            $kernel = new ConsoleKernel($this->projectRoot);
            $kernel->bootForFieldAccessPreflight();
            $handler = new FieldAccessPreflightHandler(
                new DatabaseFieldAccessInventoryScanner($kernel->getDatabase(), $kernel->getEntityTypeManager()),
                $kernel->getEntityTypeManager(),
                projectRoot: $this->projectRoot,
            );
            $output = new BufferedOutput();
            $code = $handler->execute(new SymfonyCommandIO(new ArrayInput(['--write-artifact' => true], $definition), $output));
            $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $code, json_encode($decoded));
            self::assertTrue($decoded['ready']);
        }
        if ($kernel->getDatabase() instanceof DBALDatabase) {
            $kernel->getDatabase()->getConnection()->close();
        }
    }

    private function bootProduction(): void
    {
        $kernel = new HttpKernel($this->projectRoot);
        new \ReflectionMethod(AbstractKernel::class, 'boot')->invoke($kernel);
        $database = $kernel->getDatabase();
        if ($database instanceof DBALDatabase) {
            $database->getConnection()->close();
        }
    }

    /** @return array{fingerprint: string, tables: list<string>} */
    private function fingerprintAndTables(): array
    {
        $this->putEnv('APP_ENV', 'local');
        $kernel = new ConsoleKernel($this->projectRoot);
        $kernel->bootForFieldAccessPreflight();
        $database = $kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $fingerprint = LiveEntitySchemaFingerprint::compute(
            $database,
            array_keys($kernel->getEntityTypeManager()->getDefinitions()),
        );
        $tables = $this->tableNames();
        $database->getConnection()->close();

        return ['fingerprint' => $fingerprint, 'tables' => $tables];
    }

    private function sqlite(): \PDO
    {
        return new \PDO('sqlite:' . $this->projectRoot . '/storage/waaseyaa.sqlite');
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $names = $this->sqlite()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        sort($names);

        return $names;
    }

    private function replaceAttachmentWithBaseOnlyTable(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE IF EXISTS attachment');
        $pdo->exec(
            'CREATE TABLE attachment ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . " uuid VARCHAR(128) NOT NULL DEFAULT '',"
            . " bundle VARCHAR(128) NOT NULL DEFAULT '',"
            . " filename VARCHAR(255) NOT NULL DEFAULT '',"
            . " langcode VARCHAR(12) NOT NULL DEFAULT 'en',"
            . " _data TEXT NOT NULL DEFAULT '{}'"
            . ')',
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    private function attachmentTableSql(): string
    {
        $sql = $this->sqlite()->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='attachment'")->fetchColumn();

        return is_string($sql) ? $sql : '';
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private function putEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
