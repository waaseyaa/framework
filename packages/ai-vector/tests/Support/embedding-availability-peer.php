<?php

declare(strict_types=1);

// Independent connection with bounded lock refusal, owned by the shared contract.
require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Waaseyaa\AI\Vector\DatabaseEmbeddingExecutionGuard;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\Database\DBALDatabase;

$params = json_decode((string) getenv('WAASEYAA_AIV_AVAILABILITY_PEER_PARAMS'), true, flags: JSON_THROW_ON_ERROR);
$connection = DriverManager::getConnection($params);
$sqlite = $connection->getDatabasePlatform() instanceof SQLitePlatform;
$connection->executeStatement($sqlite ? 'PRAGMA busy_timeout = 300' : "SET lock_timeout = '300ms'");
$database = new DBALDatabase($connection);
$storage = new DatabaseEmbeddingStorage($database);
$guard = new DatabaseEmbeddingExecutionGuard($database, EmbeddingIndexPolicy::fromArray([]));
$transaction = null;
echo "STARTED\n";
flush();
try {
    $action = $argv[1];
    $id = $argv[2];
    if ($action === 'begin') {
        $guard->begin('unrelated', $id);
    } elseif ($action === 'store') {
        $storage->store('unrelated', $id, [1, 0]);
    } elseif ($action === 'source') {
        $transaction = $database->transaction();
        $database->query('INSERT INTO embedding_availability_source (id, title) VALUES (?, ?) ON CONFLICT (id) DO UPDATE SET title = excluded.title', [$id, 'peer']);
        $guard->sourceChanged('unrelated', $id, $database, $storage);
        $transaction->commit();
    } else {
        throw new \LogicException('Unknown peer action.');
    }
    echo "COMMITTED\n";
} catch (DriverException $error) {
    if ($connection->isTransactionActive() && $transaction !== null) {
        $transaction->rollBack();
    }
    if ($error->getSQLState() === '55P03' || ($sqlite && str_contains($error->getMessage(), 'locked'))) {
        echo "BLOCKED\n";
    } else {
        // Never publish connection parameters or exception diagnostics.
        echo 'ERROR ' . $error::class . "\n";
        exit(2);
    }
} catch (\Throwable $error) {
    if ($connection->isTransactionActive() && $transaction !== null) {
        $transaction->rollBack();
    }
    echo 'ERROR ' . $error::class . "\n";
    exit(2);
} finally {
    $connection->close();
}
