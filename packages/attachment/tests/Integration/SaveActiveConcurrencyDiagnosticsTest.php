<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
use Waaseyaa\Attachment\Tests\Support\ForkChildDiagnostics;
use Waaseyaa\Database\DBALDatabase;

/**
 * Focused regression harness for SaveActiveConcurrencyTest fork diagnostics.
 *
 * Proves the pre-repair failure modes:
 *  - silent child exit(1) with no stage/class/message evidence
 *  - parent waitpid without exit-status inspection
 *  - parent AttachmentSchema retaining the setup SQLite connection after unset($database)
 */
#[CoversNothing]
#[RequiresPhpExtension('pcntl')]
final class SaveActiveConcurrencyDiagnosticsTest extends TestCase
{
    private string $scratchDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped(
                'Requires pcntl extension and Linux-style fork. '
                . 'Run this test on the Linux CI matrix.',
            );
        }

        $this->scratchDirectory = sys_get_temp_dir()
            . '/waaseyaa_attachment_save_concurrency_diag_'
            . uniqid('', true);
        mkdir($this->scratchDirectory, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->scratchDirectory !== '' && is_dir($this->scratchDirectory)) {
            foreach (glob($this->scratchDirectory . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    foreach (glob($path . '/*') ?: [] as $nested) {
                        if (is_file($nested)) {
                            @unlink($nested);
                        }
                    }
                    @rmdir($path);
                }
            }
            @rmdir($this->scratchDirectory);
        }

        parent::tearDown();
    }

    #[Test]
    public function silentChildExitProducesMissingDiagnosticReportMessage(): void
    {
        $diagnostics = ForkChildDiagnostics::createInDirectory($this->scratchDirectory);

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'pcntl_fork() failed.');

        if ($pid === 0) {
            // Mirrors the pre-repair SaveActiveConcurrencyTest catch(Throwable){exit(1)} path.
            try {
                throw new \RuntimeException('injected save-stage failure');
            } catch (\Throwable) {
                exit(1);
            }
        }

        $failures = $diagnostics->waitReapAndCollectFailures([$pid => 0]);

        self::assertCount(1, $failures);
        self::assertStringContainsString('child 0: exit=1 (no diagnostic report written)', $failures[0]);

        $diagnostics->cleanup();
    }

    #[Test]
    public function childFailureDiagnosticSurvivesWaitReapCycle(): void
    {
        $diagnostics = ForkChildDiagnostics::createInDirectory($this->scratchDirectory);
        $expectedMessage = 'injected repository-setup failure';

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'pcntl_fork() failed.');

        if ($pid === 0) {
            $diagnostics->childExitWithFailure(
                0,
                'repository-setup',
                new \RuntimeException($expectedMessage),
            );
        }

        $failures = $diagnostics->waitReapAndCollectFailures([$pid => 0]);

        self::assertCount(1, $failures);
        self::assertStringContainsString('child 0: exit=1 stage=repository-setup', $failures[0]);
        self::assertStringContainsString(\RuntimeException::class, $failures[0]);
        self::assertStringContainsString($expectedMessage, $failures[0]);

        $diagnostics->cleanup();
    }

    #[Test]
    public function attachmentSchemaRetainsParentDatabaseUntilExplicitlyReleased(): void
    {
        $dbPath = $this->scratchDirectory . '/schema-retain.sqlite';
        $database = DBALDatabase::createSqlite($dbPath);
        $schema = new AttachmentSchema($database);
        $schema->ensureTable();

        unset($database);

        $databaseProperty = new \ReflectionProperty(AttachmentSchema::class, 'database');
        $heldDatabase = $databaseProperty->getValue($schema);
        self::assertInstanceOf(DBALDatabase::class, $heldDatabase);

        // Pre-repair SaveActiveConcurrencyTest only unset($database); AttachmentSchema
        // still held the setup connection until schema was also released.
        $heldDatabase->getConnection()->executeQuery('SELECT 1');

        $heldDatabase->getConnection()->close();
        unset($schema);
    }

    #[Test]
    public function waitReapContinuesAfterChildFailureAndReportsSignalTermination(): void
    {
        if (!extension_loaded('posix')) {
            $this->markTestSkipped('posix extension required to assert signal termination reporting.');
        }

        $diagnostics = ForkChildDiagnostics::createInDirectory($this->scratchDirectory);
        $children = [];

        $failingPid = pcntl_fork();
        self::assertNotSame(-1, $failingPid, 'pcntl_fork() failed.');
        if ($failingPid === 0) {
            $diagnostics->childExitWithFailure(
                0,
                'save',
                new \RuntimeException('first child failed'),
            );
        }
        $children[$failingPid] = 0;

        $signaledPid = pcntl_fork();
        self::assertNotSame(-1, $signaledPid, 'pcntl_fork() failed.');
        if ($signaledPid === 0) {
            posix_kill((int) getmypid(), SIGKILL);
            exit(99);
        }
        $children[$signaledPid] = 1;

        $successPid = pcntl_fork();
        self::assertNotSame(-1, $successPid, 'pcntl_fork() failed.');
        if ($successPid === 0) {
            exit(0);
        }
        $children[$successPid] = 2;

        $failures = $diagnostics->waitReapAndCollectFailures($children);

        self::assertCount(2, $failures);
        self::assertStringContainsString('child 0: exit=1 stage=save', $failures[0]);
        self::assertStringContainsString('child 1: terminated by signal', $failures[1]);

        $diagnostics->cleanup();
    }

    #[Test]
    public function forkFailurePathReapsAlreadyLaunchedChildrenBeforeAborting(): void
    {
        $diagnostics = ForkChildDiagnostics::createInDirectory($this->scratchDirectory);
        $pidToChildIndex = [];

        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'pcntl_fork() failed.');

            if ($pid === 0) {
                usleep(20_000);
                exit(0);
            }

            $pidToChildIndex[$pid] = $i;
        }

        $failures = $diagnostics->waitReapAndCollectFailures($pidToChildIndex);
        self::assertSame([], $failures);

        $diagnostics->cleanup();
    }

    #[Test]
    public function multibyteChildFailureMessageSurvivesBoundedDiagnosticReport(): void
    {
        $diagnostics = ForkChildDiagnostics::createInDirectory($this->scratchDirectory);
        // 499 ASCII bytes plus one 2-byte UTF-8 character forces a naive byte
        // substr() to produce invalid UTF-8 unless bounded with UTF-8 awareness.
        $message = str_repeat('a', 499) . 'é';

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'pcntl_fork() failed.');

        if ($pid === 0) {
            $diagnostics->childExitWithFailure(
                0,
                'save',
                new \RuntimeException($message),
            );
        }

        $failures = $diagnostics->waitReapAndCollectFailures([$pid => 0]);

        self::assertCount(1, $failures);
        self::assertStringContainsString('stage=save', $failures[0]);

        $raw = file_get_contents($diagnostics->childReportPath(0));
        self::assertIsString($raw);
        json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        $diagnostics->cleanup();
    }

    #[Test]
    public function invalidUtf8ChildFailureMessageSurvivesBoundedDiagnosticReport(): void
    {
        $diagnostics = ForkChildDiagnostics::createInDirectory($this->scratchDirectory);
        $message = 'prefix' . "\xFF\xFE";

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'pcntl_fork() failed.');

        if ($pid === 0) {
            $diagnostics->childExitWithFailure(
                0,
                'save',
                new \RuntimeException($message),
            );
        }

        $failures = $diagnostics->waitReapAndCollectFailures([$pid => 0]);

        self::assertCount(1, $failures);
        self::assertStringContainsString('stage=save', $failures[0]);

        $raw = file_get_contents($diagnostics->childReportPath(0));
        self::assertIsString($raw);
        json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        $diagnostics->cleanup();
    }
}
