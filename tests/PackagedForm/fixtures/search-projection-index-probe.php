<?php

declare(strict_types=1);

/**
 * Reads what actually landed in the packaged consumer's search index (#2849).
 *
 * Deliberately does not boot the kernel: the boot proof is `search:reindex`
 * itself, which can only index a `story` if the generated provider was
 * discovered from literal root `composer.json` and its projector consulted
 * ahead of the built-in default. This probe answers the separate question of
 * what bytes are stored, which is the only honest place to check that a field
 * the application left restricted never entered the index file.
 *
 * Reads through `DBALDatabase`, the framework's own database layer, rather
 * than a raw PDO handle: the repository forbids bypassing it, and a proof
 * about packaged behaviour should use the packaged path.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Database\DBALDatabase;

$database = $argv[1] ?? '';
if ($database === '' || !is_file($database)) {
    fwrite(STDERR, "search-projection probe: missing database {$database}\n");
    exit(1);
}

$connection = DBALDatabase::createSqlite($database);

$tables = iterator_to_array($connection->query(
    "SELECT name FROM sqlite_master WHERE type IN ('table','view') AND name = 'search_index'",
));
if ($tables === []) {
    fwrite(STDERR, "search-projection probe: no search_index table\n");
    exit(1);
}

$rows = array_values(iterator_to_array(
    $connection->query('SELECT document_id, title, body FROM search_index'),
));

echo 'search-projection indexed rows: ' . count($rows) . "\n";
foreach ($rows as $row) {
    echo 'search-projection document: ' . (string) $row['document_id'] . "\n";
}
echo 'search-projection stored bytes: ' . json_encode($rows, JSON_THROW_ON_ERROR) . "\n";
