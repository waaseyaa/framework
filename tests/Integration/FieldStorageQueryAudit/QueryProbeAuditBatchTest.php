<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FieldStorageQueryAudit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Backend\BatchStrictFieldStorageGatewayAuditInterface;
use Waaseyaa\EntityStorage\Backend\DatabaseStrictFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAttempt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayInput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOperation;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOutput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayRole;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

final class QueryProbeAuditBatchTest extends TestCase
{
    private DBALDatabase $database;
    private DBALDatabase $observer;
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'query_audit_');
        $this->database = DBALDatabase::createSqlite($this->path, 'testing');
        RuntimeSchemaMigrations::audit($this->database);
        $this->observer = DBALDatabase::createSqlite($this->path, 'testing');
    }

    protected function tearDown(): void
    {
        $this->observer->getConnection()->close();
        $this->database->getConnection()->close();
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public static function audits(): iterable
    {
        yield 'core fallback' => [DatabaseStrictFieldStorageGatewayAudit::class];
        yield 'audit provider' => [\Waaseyaa\Audit\Writer\DatabaseStrictFieldStorageGatewayAudit::class];
    }

    #[DataProvider('audits')]
    public function test_reservations_are_committed_before_callbacks_and_outcomes_before_return(string $class): void
    {
        $backend = new ProbeBatchBackend(function (): bool {
            self::assertSame(3, $this->countEvents('reserved'));
            self::assertSame(0, $this->countEvents('finalized'));
            self::assertFalse($this->database->getConnection()->isTransactionActive());
            return true;
        });
        $gateway = $this->gateway($backend, new $class($this->database));
        self::assertSame([true, true, true], $gateway->supportsQueries($this->fields(), $this->query()));
        self::assertSame(3, $this->countEvents('finalized'));
        self::assertSame(['succeeded', 'succeeded', 'succeeded'], $this->outcomes());
    }

    #[DataProvider('audits')]
    public function test_unsupported_probe_stops_invocation_and_closes_skipped_attempts(string $class): void
    {
        $backend = new ProbeBatchBackend(fn(): bool => false);
        self::assertSame([false], $this->gateway($backend, new $class($this->database))->supportsQueries($this->fields(), $this->query()));
        self::assertSame(1, $backend->calls);
        self::assertSame(['succeeded', 'failed_closed', 'failed_closed'], $this->outcomes());
    }

    #[DataProvider('audits')]
    public function test_fingerprint_drift_records_closed_failure_without_invocation(string $class): void
    {
        $backend = new ProbeBatchBackend(fn(): bool => true);
        $gateway = $this->gateway($backend, new $class($this->database));
        $backend->hash = str_repeat('b', 64);
        try {
            $gateway->supportsQueries($this->fields(), $this->query());
            self::fail('Fingerprint drift must refuse.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('fingerprint changed', $error->getMessage());
        }
        self::assertSame(0, $backend->calls);
        self::assertSame(['failed_closed', 'failed_closed', 'failed_closed'], $this->outcomes());
    }

    #[DataProvider('audits')]
    public function test_backend_exception_closes_started_and_skipped_attempts(string $class): void
    {
        $backend = new ProbeBatchBackend(fn(): bool => throw new \RuntimeException('probe failed'));
        try {
            $this->gateway($backend, new $class($this->database))->supportsQueries($this->fields(), $this->query());
            self::fail('Backend exception must refuse.');
        } catch (\RuntimeException $error) {
            self::assertSame('probe failed', $error->getMessage());
        }
        self::assertSame(1, $backend->calls);
        self::assertSame(['failed_started', 'failed_closed', 'failed_closed'], $this->outcomes());
    }

    #[DataProvider('audits')]
    public function test_partial_reservation_failure_rolls_back_and_never_invokes_backend(string $class): void
    {
        $this->database->getConnection()->executeStatement("CREATE TRIGGER reject_reservation BEFORE INSERT ON privileged_read_ledger WHEN NEW.event_type = 'reserved' AND (SELECT COUNT(*) FROM privileged_read_ledger) = 1 BEGIN SELECT RAISE(ABORT, 'reservation rejected'); END");
        $backend = new ProbeBatchBackend(fn(): bool => true);
        try {
            $this->gateway($backend, new $class($this->database))->supportsQueries($this->fields(), $this->query());
            self::fail('Reservation failure must refuse.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('reservation rejected', $error->getMessage());
        }
        self::assertSame(0, $backend->calls);
        self::assertSame(0, $this->countEvents('reserved'));
        self::assertSame(0, $this->database->getConnection()->getTransactionNestingLevel());
    }

    #[DataProvider('audits')]
    public function test_partial_finalization_failure_rolls_back_and_preserves_reservations(string $class): void
    {
        $this->database->getConnection()->executeStatement("CREATE TRIGGER reject_finalization BEFORE INSERT ON privileged_read_ledger WHEN NEW.event_type = 'finalized' AND (SELECT COUNT(*) FROM privileged_read_ledger WHERE event_type = 'finalized') = 1 BEGIN SELECT RAISE(ABORT, 'finalization rejected'); END");
        $backend = new ProbeBatchBackend(fn(): bool => true);
        try {
            $this->gateway($backend, new $class($this->database))->supportsQueries($this->fields(), $this->query());
            self::fail('Finalization failure must prevent successful validation.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('finalization rejected', $error->getMessage());
        }
        self::assertSame(3, $backend->calls);
        self::assertSame(3, $this->countEvents('reserved'));
        self::assertSame(0, $this->countEvents('finalized'));
        self::assertSame(0, $this->database->getConnection()->getTransactionNestingLevel());
    }

    #[DataProvider('audits')]
    public function test_outer_transaction_is_refused_without_touching_caller_work(string $class): void
    {
        $audit = new $class($this->database);
        $transaction = $this->database->transaction();
        try {
            $audit->reserveMany([$this->attempt()]);
            self::fail('A savepoint cannot supply durable reservation.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('outside a caller transaction', $error->getMessage());
        } finally {
            self::assertSame(1, $this->database->getConnection()->getTransactionNestingLevel());
            $transaction->rollBack();
        }
        self::assertSame(0, $this->countEvents('reserved'));
    }

    #[DataProvider('audits')]
    public function test_duplicate_and_consumed_receipts_are_refused(string $class): void
    {
        $audit = new $class($this->database);
        $receipt = $audit->reserveMany([$this->attempt()])[0];
        $outcome = ['receipt' => $receipt, 'failure' => null];
        try {
            $audit->finalizeMany([$outcome, $outcome]);
            self::fail('Duplicate receipt must refuse before writes.');
        } catch (\LogicException) {
            self::assertSame(0, $this->countEvents('finalized'));
        }
        $audit->finalizeMany([$outcome]);
        try {
            $audit->finalizeMany([$outcome]);
            self::fail('Consumed receipt must refuse.');
        } catch (\LogicException) {
            self::assertSame(1, $this->countEvents('finalized'));
        }
    }

    #[DataProvider('audits')]
    public function test_failed_batch_can_finalize_once_after_storage_recovers(string $class): void
    {
        $audit = new $class($this->database);
        $receipts = $audit->reserveMany([$this->attempt(), $this->attempt()]);
        $outcomes = array_map(fn($receipt) => ['receipt' => $receipt, 'failure' => null], $receipts);
        $this->database->getConnection()->executeStatement("CREATE TRIGGER reject_second BEFORE INSERT ON privileged_read_ledger WHEN NEW.event_type = 'finalized' AND (SELECT COUNT(*) FROM privileged_read_ledger WHERE event_type = 'finalized') = 1 BEGIN SELECT RAISE(ABORT, 'reject'); END");
        try {
            $audit->finalizeMany($outcomes);
            self::fail('Partial finalization must fail.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame(0, $this->countEvents('finalized'));
        }
        $this->database->getConnection()->executeStatement('DROP TRIGGER reject_second');
        $audit->finalizeMany($outcomes);
        self::assertSame(2, $this->countEvents('finalized'));
    }

    #[DataProvider('audits')]
    public function test_process_exit_during_probe_preserves_unfinished_reservations(string $class): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $db = \Waaseyaa\Database\DBALDatabase::createSqlite($argv[2], 'testing');
            $audit = new $argv[3]($db);
            $backend = new class implements \Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface {
                public function id(): string { return 'interrupt'; }
                public function fingerprint(): string { return str_repeat('a', 64); }
                public function invoke(\Waaseyaa\EntityStorage\Backend\FieldStorageGatewayRole $role, \Waaseyaa\EntityStorage\Backend\FieldStorageGatewayInput $input): \Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOutput {
                    $role->unwrap($input, $this);
                    exit(73);
                }
            };
            $gateway = new \Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway('interrupt', $backend, $backend->fingerprint(), $audit);
            $fields = [new \Waaseyaa\Field\FieldDefinition(name:'one',type:'string'),new \Waaseyaa\Field\FieldDefinition(name:'two',type:'string')];
            $gateway->supportsQueries($fields, new class implements \Waaseyaa\EntityStorage\Query\EntityQuery {});
            PHP;
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $code, dirname(__DIR__, 3) . '/vendor/autoload.php', $this->path, $class]);
        $process->setTimeout(20);
        try {
            $process->run();
            self::assertSame(73, $process->getExitCode(), $process->getErrorOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        self::assertSame(2, $this->countEvents('reserved'));
        self::assertSame(0, $this->countEvents('finalized'));
        // A fresh boot neither consumes nor rewrites interrupted receipts.
        $this->gateway(new ProbeBatchBackend(fn(): bool => true), new $class($this->database))->supportsQueries($this->fields(), $this->query());
        self::assertSame(5, $this->countEvents('reserved'));
        self::assertSame(3, $this->countEvents('finalized'));
    }

    private function gateway(ProbeBatchBackend $backend, BatchStrictFieldStorageGatewayAuditInterface $audit): FieldStorageBackendGateway
    {
        return new FieldStorageBackendGateway($backend->id(), $backend, $backend->fingerprint(), $audit);
    }

    private function fields(): array
    {
        return array_map(fn(int $id) => new FieldDefinition(name: 'field_' . $id, type: 'string'), [1, 2, 3]);
    }

    private function query(): EntityQuery
    {
        return new class implements EntityQuery {};
    }

    private function attempt(): FieldStorageGatewayAttempt
    {
        return new FieldStorageGatewayAttempt('probe', str_repeat('a', 64), FieldStorageGatewayOperation::SupportsQuery, 'synthetic', null, 'field');
    }

    private function countEvents(string $event): int
    {
        return (int) $this->observer->getConnection()->fetchOne('SELECT COUNT(*) FROM privileged_read_ledger WHERE event_type = ?', [$event]);
    }

    private function outcomes(): array
    {
        return $this->observer->getConnection()->fetchFirstColumn("SELECT outcome FROM privileged_read_ledger WHERE event_type = 'finalized' ORDER BY id");
    }
}

final class ProbeBatchBackend implements FieldStorageBackendV2Interface
{
    public int $calls = 0;
    public string $hash;
    public function __construct(private readonly \Closure $callback)
    {
        $this->hash = str_repeat('a', 64);
    }
    public function id(): string
    {
        return 'probe';
    }
    public function fingerprint(): string
    {
        return $this->hash;
    }
    public function invoke(FieldStorageGatewayRole $gateway, FieldStorageGatewayInput $input): FieldStorageGatewayOutput
    {
        $gateway->unwrap($input, $this);
        ++$this->calls;
        return $gateway->complete($input, $this, ($this->callback)());
    }
}
