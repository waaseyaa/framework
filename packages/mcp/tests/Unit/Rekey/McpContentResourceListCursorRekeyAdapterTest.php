<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Rekey;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRecord;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyState;
use Waaseyaa\Mcp\Rekey\McpContentResourceListCursorRekeyAdapter;
use Waaseyaa\Mcp\Tests\Support\McpContentResourceListCursorKeyring;

#[CoversClass(McpContentResourceListCursorRekeyAdapter::class)]
final class McpContentResourceListCursorRekeyAdapterTest extends TestCase
{
    #[Test]
    public function ephemeral_cursor_owner_produces_request_bound_zero_row_evidence_in_both_directions(): void
    {
        $database = DBALDatabase::createSqlite();
        $adapter = new McpContentResourceListCursorRekeyAdapter($database);
        $forward = $this->context($database, 2);
        $rollback = $this->context($database, 1, ApplicationMasterRekeyState::RollingBack);

        self::assertSame($database, $adapter->databaseAuthority());
        self::assertSame(McpContentResourceListCursorRekeyAdapter::ID, $adapter->id());
        self::assertSame([ApplicationSecret::PURPOSE_MCP_CONTENT_RESOURCE_LIST_CURSOR], $adapter->purposeIds());

        $forwardSnapshot = $adapter->snapshot($forward);
        $forwardVerification = $adapter->verify($forward, $forwardSnapshot);
        $rollbackSnapshot = $adapter->rollbackSnapshot($rollback);
        $rollbackVerification = $adapter->verifyRollback($rollback, $rollbackSnapshot);

        self::assertSame(0, $forwardSnapshot->totalRecords);
        self::assertSame(0, $rollbackSnapshot->totalRecords);
        self::assertNotSame($forwardSnapshot->token, $rollbackSnapshot->token);
        self::assertSame(0, $forwardVerification[ApplicationSecret::PURPOSE_MCP_CONTENT_RESOURCE_LIST_CURSOR]->verifiedRecords);
        self::assertSame(0, $rollbackVerification[ApplicationSecret::PURPOSE_MCP_CONTENT_RESOURCE_LIST_CURSOR]->verifiedRecords);
        self::assertNotSame(
            $forwardVerification[ApplicationSecret::PURPOSE_MCP_CONTENT_RESOURCE_LIST_CURSOR]->verificationHash,
            $rollbackVerification[ApplicationSecret::PURPOSE_MCP_CONTENT_RESOURCE_LIST_CURSOR]->verificationHash,
        );

        $this->assertConflict(static fn() => $adapter->transitionBatch($forward, $forwardSnapshot, null, 10));
        $this->assertConflict(static fn() => $adapter->rollbackBatch($rollback, $rollbackSnapshot, null, 10));
    }

    #[Test]
    public function snapshots_refuse_wrong_database_writer_version_and_tampered_evidence(): void
    {
        $database = DBALDatabase::createSqlite();
        $adapter = new McpContentResourceListCursorRekeyAdapter($database);
        $forward = $this->context($database, 2);
        $snapshot = $adapter->snapshot($forward);

        $this->assertConflict(fn() => $adapter->snapshot($this->context(DBALDatabase::createSqlite(), 2)));
        $this->assertConflict(fn() => $adapter->snapshot($this->context($database, 1)));
        $this->assertConflict(fn() => $adapter->verify(
            $forward,
            new ApplicationMasterInventorySnapshot(hash('sha256', 'tampered'), 0),
        ));
        $this->assertConflict(fn() => $adapter->verify(
            $forward,
            new ApplicationMasterInventorySnapshot($snapshot->token, 1),
        ));
    }

    private function context(
        DBALDatabase $database,
        int $activeVersion,
        ApplicationMasterRekeyState $state = ApplicationMasterRekeyState::EnumerateSnapshot,
    ): ApplicationMasterRekeyContext {
        return new ApplicationMasterRekeyContext(
            new ApplicationMasterRekeyRecord(
                'mcp-cursor-rekey-1',
                hash('sha256', 'mcp-cursor-request'),
                1,
                2,
                hash('sha256', 'mcp-cursor-registry'),
                hash('sha256', 'mcp-cursor-authorization'),
                'test-operator',
                1_000_100,
                1_002_000,
                $state,
                0,
                0,
                1_000_000,
                1_000_000,
            ),
            McpContentResourceListCursorKeyring::create($activeVersion),
            $database,
        );
    }

    private function assertConflict(\Closure $case): void
    {
        try {
            $case();
            self::fail('Expected exact rekey-boundary refusal.');
        } catch (ApplicationMasterRekeyConflictException) {
            self::addToAssertionCount(1);
        }
    }
}
