<?php

declare(strict_types=1);

// Disposable core-composition fixture using real kernel hooks and migrations.
// Usage: php THIS_SCRIPT ARTIFACT_ROOT PROJECT_ROOT prepare|boot|missing-table|missing-column|drift
// PROJECT_ROOT must be a caller-owned disposable fixture. prepare writes it;
// refusal controls deliberately damage fixture DDL. Exit 0 means verified success.
if ($argc !== 4 || !in_array($argv[3], ['prepare', 'boot', 'missing-table', 'missing-column', 'drift'], true)) {
    throw new InvalidArgumentException('Expected ARTIFACT_ROOT PROJECT_ROOT and supported operation.');
}
$artifactRoot = str_replace('\\', '/', $argv[1]);
$projectRoot = str_replace('\\', '/', $argv[2]);
$operation = $argv[3];
require $artifactRoot . '/vendor/autoload.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Path;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\FieldAccessPreflightHandler;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

class ProfileEntity extends ContentEntityBase {}

final class ProfileLogger extends AbstractLogger
{
    public array $sql = [];
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

function profileKernel(string $projectRoot): AbstractKernel
{
    return new class ($projectRoot) extends AbstractKernel {
        public ProfileLogger $sqlLog;
        public array $phases = [];
        private float $phaseStarted;
        private int $phaseCount;

        public function bootProbe(): void
        {
            $this->boot();
        }

        protected function bootDatabase(): void
        {
            parent::bootDatabase();
            $source = $this->database->getConnection();
            $this->sqlLog = new ProfileLogger();
            $this->database = new DBALDatabase(new Connection(
                $source->getParams(),
                new Middleware($this->sqlLog)->wrap($source->getDriver()),
                $source->getConfiguration(),
            ));
            $source->close();
        }

        protected function validateContentTypes(): void
        {
            parent::validateContentTypes();
            $this->phaseStarted = hrtime(true);
            $this->phaseCount = count($this->sqlLog->sql);
        }

        protected function bootProviders(): void
        {
            $this->finishPhase('production_guards_and_composition');
            parent::bootProviders();
        }

        protected function validateQueryDefinitions(): void
        {
            $this->phaseStarted = hrtime(true);
            $this->phaseCount = count($this->sqlLog->sql);
            parent::validateQueryDefinitions();
            $this->finishPhase('query_validation');
        }

        private function finishPhase(string $name): void
        {
            $statements = array_slice($this->sqlLog->sql, $this->phaseCount);
            $this->phases[$name] = [
                'ms' => round((hrtime(true) - $this->phaseStarted) / 1e6, 3),
                'queries' => count($statements),
                'writes' => count(array_filter($statements, static fn(string $sql): bool => preg_match('/^(INSERT|UPDATE|DELETE)/i', $sql) === 1)),
            ];
        }
    };
}

putenv('APP_DEBUG=0');
// Synthetic fixture custody only, no live credentials.
putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(str_repeat('S', 32)));
putenv('WAASEYAA_DB=' . $projectRoot . '/storage/waaseyaa.sqlite');
putenv('APP_ENV=' . ($operation === 'prepare' ? 'local' : 'production'));

function scanPreflight(AbstractKernel $kernel, string $projectRoot): array
{
    $definition = new InputDefinition([new InputOption('format', mode: InputOption::VALUE_OPTIONAL, default: 'json'), new InputOption('write-artifact', mode: InputOption::VALUE_NONE)]);
    $handler = new FieldAccessPreflightHandler(new DatabaseFieldAccessInventoryScanner($kernel->getDatabase(), $kernel->getEntityTypeManager()), $kernel->getEntityTypeManager(), projectRoot: $projectRoot);
    $output = new BufferedOutput();
    $handler->execute(new SymfonyCommandIO(new ArrayInput(['--write-artifact' => true], $definition), $output));
    return json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
}

if ($operation === 'prepare') {
    foreach (['config', 'storage', '.waaseyaa', 'vendor/composer'] as $directory) {
        if (!is_dir($projectRoot . '/' . $directory)) {
            mkdir($projectRoot . '/' . $directory, 0o775, true);
        }
    }
    $installed = json_decode(file_get_contents($artifactRoot . '/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = [];
    foreach ($installed['packages'] ?? $installed as $package) {
        $packages[$package['name']] = $package;
    }
    $corePath = is_file($artifactRoot . '/packages/core/composer.json')
        ? $artifactRoot . '/packages/core/composer.json'
        : $artifactRoot . '/vendor/waaseyaa/framework/packages/core/composer.json';
    $core = json_decode(file_get_contents($corePath), true, flags: JSON_THROW_ON_ERROR);
    $queue = array_keys($core['require']);
    $selected = [];
    while ($queue !== []) {
        $name = array_pop($queue);
        if (!str_starts_with($name, 'waaseyaa/') || isset($selected[$name])) {
            continue;
        }
        if (!isset($packages[$name])) {
            throw new RuntimeException('Missing installed package ' . $name);
        }
        $package = $packages[$name];
        $actual = realpath($artifactRoot . '/vendor/composer/' . $package['install-path']);
        if ($actual === false) {
            throw new RuntimeException('Missing package directory ' . $name);
        }
        $package['install-path'] = Path::makeRelative(str_replace('\\', '/', $actual), $projectRoot . '/vendor/composer');
        $selected[$name] = $package;
        $queue = [...$queue, ...array_keys($package['require'] ?? [])];
    }
    ksort($selected);
    file_put_contents($projectRoot . '/vendor/composer/installed.json', json_encode(['packages' => array_values($selected)], JSON_THROW_ON_ERROR));
    $autoload = require $artifactRoot . '/vendor/composer/autoload_psr4.php';
    $prefixes = [];
    foreach ($selected as $package) {
        foreach (array_keys($package['autoload']['psr-4'] ?? []) as $prefix) {
            if (isset($autoload[$prefix])) {
                $prefixes[$prefix] = $autoload[$prefix];
            }
        }
    }
    file_put_contents($projectRoot . '/vendor/composer/autoload_psr4.php', '<?php return ' . var_export($prefixes, true) . ';');
    file_put_contents($projectRoot . '/vendor/composer/autoload_classmap.php', '<?php return [];');
    file_put_contents($projectRoot . '/composer.json', json_encode([
        'name' => 'waaseyaa/profile-fixture',
        'require' => ['waaseyaa/core' => '0.1.0-alpha.303', 'waaseyaa/cli' => '0.1.0-alpha.303'],
        'extra' => ['waaseyaa' => ['providers' => [\Waaseyaa\CLI\Provider\MigrateServiceProvider::class]]],
    ], JSON_THROW_ON_ERROR));
    copy($artifactRoot . '/composer.lock', $projectRoot . '/composer.lock');
    file_put_contents($projectRoot . '/VERSION', '0.1.0-alpha.303');
    file_put_contents($projectRoot . '/config/waaseyaa.php', "<?php return ['database' => " . var_export($projectRoot . '/storage/waaseyaa.sqlite', true) . ", 'environment' => getenv('APP_ENV'), 'debug' => false];");
    file_put_contents($projectRoot . '/config/entity-types.php', '<?php return [];');
    $kernel = profileKernel($projectRoot);
    $kernel->bootForSchemaSync();
    $existing = count($kernel->getEntityTypeManager()->getDefinitions());
    if ($existing > 31) {
        throw new RuntimeException('Core composition already exceeds 31 registered types: ' . $existing);
    }
    $definitions = [];
    $declarations = [];
    for ($i = $existing; $i < 31; ++$i) {
        $declarations[] = "if (!class_exists('ProfileFixture{$i}', false)) { #[\\Waaseyaa\\Entity\\Attribute\\ContentEntityType(id: 'fixture_{$i}', storageBackend: 'sql-column')] #[\\Waaseyaa\\Entity\\Attribute\\ContentEntityKeys(id:'id',uuid:'uuid',label:'label',langcode:'langcode')] class ProfileFixture{$i} extends \\ProfileEntity { #[\\Waaseyaa\\Entity\\Attribute\\Field(type:'string',indexed:true,read:\\Waaseyaa\\Entity\\FieldReadLevel::Public)] public string \$label; } }";
        $definitions[] = "\\Waaseyaa\\Entity\\EntityType::fromClass(\\ProfileFixture{$i}::class)";
    }
    file_put_contents($projectRoot . '/config/entity-types.php', '<?php ' . implode("\n", $declarations) . ' return [' . implode(',', $definitions) . '];');
    $kernel->getDatabase()->getConnection()->close();
    $kernel = profileKernel($projectRoot);
    $kernel->bootForSchemaSync();
    $loader = $kernel->getMigrationLoader();
    $kernel->getMigrator()->run($loader->loadAll(), $loader->loadAllV2());
    $manager = $kernel->getEntityTypeManager();
    new \Waaseyaa\EntityStorage\EntitySchemaSyncRunner($kernel->getDatabase(), $manager->getFieldRegistry())->run($manager->getDefinitions());
    $kernel->getDatabase()->getConnection()->close();

    // Establish configuration genesis through the real installation boundary,
    // rather than bypassing production's active-generation requirement.
    $_SERVER['argv'] = ['waaseyaa', 'install:init'];
    ob_start();
    $exit = new \Waaseyaa\Foundation\Kernel\ConsoleKernel($projectRoot)->handle();
    $installOutput = ob_get_clean();
    if ($exit !== 0) {
        throw new RuntimeException('install:init failed: ' . $installOutput);
    }

    $kernel = profileKernel($projectRoot);
    $kernel->bootForFieldAccessPreflight();
    $result = scanPreflight($kernel, $projectRoot);
    if (!($result['ready'] ?? false)) {
        $fields = [];
        foreach ($result['unclassified_entries'] ?? [] as $key) {
            $fields[$key] = 'public';
        }
        file_put_contents($projectRoot . '/.waaseyaa/field-access-classification.json', json_encode(['fields' => $fields], JSON_THROW_ON_ERROR));
        $kernel->getDatabase()->getConnection()->close();
        $kernel = profileKernel($projectRoot);
        $kernel->bootForFieldAccessPreflight();
        $result = scanPreflight($kernel, $projectRoot);
    }
    if (!($result['ready'] ?? false)) {
        throw new RuntimeException(json_encode($result));
    }
    new PackageManifestCompiler($projectRoot, $projectRoot . '/storage')->load();
    echo json_encode(['prepared' => true, 'entity_types' => count($kernel->getEntityTypeManager()->getDefinitions()), 'packages' => array_keys($selected)], JSON_PRETTY_PRINT), "\n";
} else {
    if ($operation !== 'boot') {
        putenv('APP_ENV=local');
        $controlKernel = profileKernel($projectRoot);
        $controlKernel->bootForFieldAccessPreflight();
        $ids = array_filter(array_keys($controlKernel->getEntityTypeManager()->getDefinitions()), static fn(string $id): bool => str_starts_with($id, 'fixture_'));
        $table = reset($ids);
        if (!is_string($table)) {
            throw new RuntimeException('No fixture type for refusal control.');
        }
        $connection = $controlKernel->getDatabase()->getConnection();
        $quoted = $connection->quoteIdentifier($table);
        if ($operation === 'missing-table') {
            $connection->executeStatement('DROP TABLE ' . $quoted);
        } elseif (in_array($operation, ['missing-column', 'drift'], true)) {
            $connection->executeStatement('ALTER TABLE ' . $quoted . ' DROP COLUMN langcode');
        } else {
            throw new RuntimeException('Unknown operation.');
        }
        $connection->close();
        if ($operation !== 'drift') {
            $controlKernel = profileKernel($projectRoot);
            $controlKernel->bootForFieldAccessPreflight();
            $result = scanPreflight($controlKernel, $projectRoot);
            if (!($result['ready'] ?? false)) {
                throw new RuntimeException('Control preflight failed: ' . json_encode($result));
            }
            $controlKernel->getDatabase()->getConnection()->close();
        }
        putenv('APP_ENV=production');
    }
    $control = DBALDatabase::createSqlite($projectRoot . '/storage/waaseyaa.sqlite', 'production');
    $beforeSchema = $control->getConnection()->fetchAllAssociative('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
    $beforeLedger = (int) $control->getConnection()->fetchOne('SELECT COUNT(*) FROM privileged_read_ledger');
    $control->getConnection()->close();
    $artifactBefore = hash_file('sha256', $projectRoot . '/.waaseyaa/field-access-preflight.json');
    $kernel = profileKernel($projectRoot);
    $start = hrtime(true);
    try {
        $kernel->bootProbe();
        if ($operation !== 'boot') {
            throw new LogicException('Production accepted damaged schema.');
        }
    } catch (Throwable $failure) {
        if ($operation === 'boot') {
            throw $failure;
        }
        $expected = $operation === 'drift'
            ? $failure instanceof \Waaseyaa\Entity\Exception\FieldAccessActivationBlocked && str_contains($failure->getMessage(), 'stale')
            : str_contains($failure->getMessage(), '[S1-DB106]') && str_contains($failure->getMessage(), $operation === 'missing-column' ? 'langcode' : $table);
        if (!$expected) {
            throw new RuntimeException('Unexpected refusal reason.', previous: $failure);
        }
        $afterSchema = $kernel->getDatabase()->getConnection()->fetchAllAssociative('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        if ($beforeSchema !== $afterSchema || $artifactBefore !== hash_file('sha256', $projectRoot . '/.waaseyaa/field-access-preflight.json')) {
            throw new RuntimeException('Refusal mutated schema or preflight.');
        }
        $afterLedger = (int) $kernel->getDatabase()->getConnection()->fetchOne('SELECT COUNT(*) FROM privileged_read_ledger');
        if ($beforeLedger !== $afterLedger) {
            throw new RuntimeException('Refusal unexpectedly wrote audit rows.');
        }
        echo json_encode(['audit_rows_appended' => 0, 'control' => $operation, 'refused' => true, 'exception' => $failure::class, 'message' => $failure->getMessage(), 'schema_stable' => true, 'artifact_stable' => true], JSON_PRETTY_PRINT), "\n";
        exit(0);
    }
    $elapsed = round((hrtime(true) - $start) / 1e6, 3);
    $sqlCount = count($kernel->sqlLog->sql);
    $afterSchema = $kernel->getDatabase()->getConnection()->fetchAllAssociative('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
    $afterLedger = (int) $kernel->getDatabase()->getConnection()->fetchOne('SELECT COUNT(*) FROM privileged_read_ledger');
    if ($beforeSchema !== $afterSchema || $artifactBefore !== hash_file('sha256', $projectRoot . '/.waaseyaa/field-access-preflight.json')) {
        throw new RuntimeException('Boot mutated schema or preflight.');
    }
    echo json_encode([
        'elapsed_ms' => $elapsed,
        'queries' => $sqlCount,
        'entity_types' => count($kernel->getEntityTypeManager()->getDefinitions()),
        'phases' => $kernel->phases,
        'database_source' => new ReflectionClass(DBALDatabase::class)->getFileName(),
        'schema_guard_source' => new ReflectionClass(\Waaseyaa\Database\Schema\SchemaRequirement::class)->getFileName(),
        'schema_stable' => true,
        'artifact_stable' => true,
        'audit_rows_appended' => $afterLedger - $beforeLedger,
    ], JSON_PRETTY_PRINT), "\n";
    $kernel->getDatabase()->getConnection()->close();
}
