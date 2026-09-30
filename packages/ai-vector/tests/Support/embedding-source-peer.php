<?php

declare(strict_types=1);

// Synthetic entity-source writer on an independent process/connection.
// Its database lock timeout and parent Process timeout bound every wait.
require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Waaseyaa\AI\Vector\DatabaseEmbeddingExecutionGuard;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\Database\DBALDatabase;

$params = json_decode((string) getenv('WAASEYAA_AIV_SOURCE_PEER_PARAMS'), true, flags: JSON_THROW_ON_ERROR);
$connection = DriverManager::getConnection($params);
$isSqlite = $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
$connection->executeStatement($isSqlite ? 'PRAGMA busy_timeout = 300' : "SET lock_timeout = '300ms'");
$database = new DBALDatabase($connection);
$storage = new DatabaseEmbeddingStorage($database);
$guard = new DatabaseEmbeddingExecutionGuard($database);
$transaction = $database->transaction();
echo "STARTED\n";
flush();
try {
    $action = $argv[1];
    if ($action === 'delete') {
        $database->query('DELETE FROM embedding_concurrency_source WHERE id = ?', ['01']);
    } elseif ($action !== 'exclude') {
        $database->query('UPDATE embedding_concurrency_source SET title = ? WHERE id = ?', [$action === 'unpublish' ? '' : 'new', '01']);
    }
    $guard->sourceChanged('concurrent_note', '01', $database, $storage);
    $transaction->commit();
    if (($argv[2] ?? '') === 'publish') {
        $token = $guard->begin('concurrent_note', '01');
        $guard->runIfCurrent('concurrent_note', '01', $token, fn() => $storage->store('concurrent_note', '01', [0, 1]));
    }
    echo "COMMITTED\n";
} catch (DriverException $error) {
    $transaction->rollBack();
    if ($error->getSQLState() === '55P03' || ($isSqlite && str_contains($error->getMessage(), 'locked'))) {
        echo "BLOCKED\n";
    } else {
        // Connection parameters and exception text must never enter output.
        echo 'ERROR ' . $error::class . "\n";
        exit(2);
    }
} catch (\Throwable $error) {
    if ($connection->isTransactionActive()) {
        $transaction->rollBack();
    }
    echo 'ERROR ' . $error::class . "\n";
    exit(2);
} finally {
    $connection->close();
}
