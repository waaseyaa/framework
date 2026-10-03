<?php

declare(strict_types=1);

// Run from the repository: php tests/Fixtures/Audits/DatabaseLegacy/FW-SCHEMA-INSPECTION-01-guard-cost.php
// Expected exit: 0. Synthetic unchanged 31-table schema; not kernel boot timing.
require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\SchemaRequirement;

$logger = new class extends AbstractLogger {
    /** @var list<string> */
    public array $sql = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
};

// Preserve the framework's SQLite driver middleware and topology while adding
// Doctrine's maintained SQL logging middleware before opening this connection.
$seed = DBALDatabase::createSqlite();
$source = $seed->getConnection();
$database = new DBALDatabase(new Connection(
    $source->getParams(),
    new Middleware($logger)->wrap($source->getDriver()),
    $source->getConfiguration(),
));
$connection = $database->getConnection();
$fields = ['id', 'uuid', 'langcode', '_data', 'key', 'status', 'created', 'changed'];
for ($table = 0; $table < 31; ++$table) {
    $connection->executeStatement(sprintf(
        'CREATE TABLE fixture_%d (id INTEGER PRIMARY KEY, uuid TEXT, langcode TEXT, _data TEXT, "key" TEXT, status INTEGER, created INTEGER, changed INTEGER)',
        $table,
    ));
}
$schemaBefore = $connection->fetchFirstColumn('SELECT sql FROM sqlite_master ORDER BY name');

$results = [];
foreach (['baseline', 'candidate'] as $mode) {
    $samples = [];
    $queryCounts = [];
    for ($sample = 0; $sample < 7; ++$sample) {
        $logger->sql = [];
        $start = hrtime(true);
        for ($table = 0; $table < 31; ++$table) {
            $name = 'fixture_' . $table;
            if ($mode === 'baseline') {
                // Exact pre-repair guard algorithm, against the same live DB.
                $schema = $database->schema();
                if (!$schema->tableExists($name)) {
                    throw new RuntimeException('Fixture table absent.');
                }
                foreach ($fields as $field) {
                    if (!$schema->fieldExists($name, $field)) {
                        throw new RuntimeException('Fixture field absent.');
                    }
                }
            } else {
                SchemaRequirement::assertAvailable($database, $name, $fields, 'fixture:0001');
            }
        }
        $samples[] = round((hrtime(true) - $start) / 1_000_000, 3);
        $queryCounts[] = count($logger->sql);
    }
    $results[$mode] = ['sql_queries' => $queryCounts, 'elapsed_ms' => $samples];
}
if ($schemaBefore !== $connection->fetchFirstColumn('SELECT sql FROM sqlite_master ORDER BY name')) {
    throw new RuntimeException('Validation changed fixture DDL.');
}
if (max($results['candidate']['sql_queries']) >= min($results['baseline']['sql_queries'])) {
    throw new RuntimeException('Column batching did not reduce SQL queries.');
}
echo json_encode(['php' => PHP_VERSION, 'tables' => 31, 'fields_per_table' => count($fields), 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
