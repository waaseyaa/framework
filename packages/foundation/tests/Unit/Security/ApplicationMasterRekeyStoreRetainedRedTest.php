<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyGate;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRequest;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRevocationBlockedException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyState;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyStore;

/** Retained-red DB-02 and resumable-ledger proof for the CFG-04 rekey store. */
final class ApplicationMasterRekeyStoreRetainedRedTest extends TestCase
{
    private const string REQUEST_ID = 'synthetic-rekey-20260815-0001';
    private const string ADAPTER_ID = 'synthetic-oidc-row-v1';

    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/waaseyaa-app-master-rekey-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if ($this->dbPath !== '' && is_file($this->dbPath . $suffix)) {
                @unlink($this->dbPath . $suffix);
            }
        }
    }

    #[Test]
    public function runtime_store_performs_zero_lazy_ddl_and_names_the_required_migration(): void
    {
        $database = DBALDatabase::createSqlite($this->dbPath);
        $before = $this->tableNames($database);
        $store = new ApplicationMasterRekeyStore($database);

        try {
            $store->require(self::REQUEST_ID);
            self::fail('An unmigrated application-master rekey store was readable.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB106]', $exception->getMessage());
            self::assertStringContainsString(
                'waaseyaa/foundation:2026_08_15_000002_application_master_rekey',
                $exception->getMessage(),
            );
        }

        self::assertSame($before, $this->tableNames($database));
    }

    #[Test]
    public function migration_installs_the_closed_store_and_event_rows_are_database_append_only(): void
    {
        $database = $this->migratedDatabase();
        self::assertSame(
            [
                'waaseyaa_application_master_rekey',
                'waaseyaa_application_master_rekey_adapter',
                'waaseyaa_application_master_rekey_event',
                'waaseyaa_application_master_rekey_gate',
                'waaseyaa_application_master_rekey_purpose',
                'waaseyaa_application_master_rekey_verification',
                'waaseyaa_application_master_version',
            ],
            array_values(array_intersect(
                $this->tableNames($database),
                [
                    'waaseyaa_application_master_rekey',
                    'waaseyaa_application_master_rekey_adapter',
                    'waaseyaa_application_master_rekey_event',
                    'waaseyaa_application_master_rekey_gate',
                    'waaseyaa_application_master_rekey_purpose',
                    'waaseyaa_application_master_rekey_verification',
                    'waaseyaa_application_master_version',
                ],
            )),
        );

        $store = new ApplicationMasterRekeyStore($database);
        $store->prepare($this->request(), $this->purposes());
        $event = $database->getConnection()->fetchAssociative(
            'SELECT event_id, event_hash FROM waaseyaa_application_master_rekey_event WHERE request_id = ?',
            [self::REQUEST_ID],
        );
        self::assertIsArray($event);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $event['event_hash']);

        foreach (['UPDATE waaseyaa_application_master_rekey_event SET event_hash = ? WHERE event_id = ?', 'DELETE FROM waaseyaa_application_master_rekey_event WHERE event_id = ?'] as $index => $sql) {
            try {
                $arguments = $index === 0 ? [str_repeat('0', 64), $event['event_id']] : [$event['event_id']];
                $database->getConnection()->executeStatement($sql, $arguments);
                self::fail('The immutable application-master rekey ledger accepted mutation.');
            } catch (\Throwable) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function request_and_frozen_purpose_snapshot_are_immutable_idempotent_and_restart_readable(): void
    {
        $database = $this->migratedDatabase();
        $store = new ApplicationMasterRekeyStore($database);
        $request = $this->request();
        $registry = $this->purposes();

        $prepared = $store->prepare($request, $registry);
        self::assertSame(ApplicationMasterRekeyState::PrepareAndAuthorize, $prepared->state);
        self::assertSame(1, $prepared->revision);
        self::assertSame($request->digest(), $prepared->requestDigest);
        self::assertSame($registry->checksum(), $prepared->registryChecksum);
        self::assertSame(3, (int) $database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM waaseyaa_application_master_rekey_purpose WHERE request_id = ?',
            [self::REQUEST_ID],
        ));

        $same = $store->prepare($request, $registry);
        self::assertSame($prepared, $same);
        self::assertSame(1, (int) $database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM waaseyaa_application_master_rekey_event WHERE request_id = ?',
            [self::REQUEST_ID],
        ));

        $restarted = new ApplicationMasterRekeyStore(DBALDatabase::createSqlite($this->dbPath));
        self::assertEquals($prepared, $restarted->require(self::REQUEST_ID));

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $store->prepare(new ApplicationMasterRekeyRequest(
            requestId: self::REQUEST_ID,
            fromVersion: 1,
            toVersion: 2,
            registryChecksum: $registry->checksum(),
            authorizationDigest: hash('sha256', 'different-synthetic-authorization'),
            actor: 'synthetic-operator-1',
            rollbackDeadline: 2_000,
            retentionDeadline: 2_500,
            createdAt: 1_000,
        ), $registry);
    }

    #[Test]
    public function active_install_and_adapter_batches_use_durable_compare_and_swap_cursors(): void
    {
        $database = $this->migratedDatabase();
        $store = new ApplicationMasterRekeyStore($database);
        $store->prepare($this->request(), $this->purposes());

        $installed = $store->installActive(
            self::REQUEST_ID,
            expectedRequestRevision: 1,
            fromReferenceFingerprint: hash('sha256', 'synthetic-master-v1-reference'),
            toReferenceFingerprint: hash('sha256', 'synthetic-master-v2-reference'),
            activatedAt: 1_100,
        );
        self::assertSame(ApplicationMasterRekeyState::InstallNewActiveWithOldLegacyReadVerify, $installed->state);
        self::assertSame(2, $installed->revision);
        self::assertSame(
            ['1:legacy-read-verify', '2:active-write'],
            $database->getConnection()->fetchFirstColumn(
                "SELECT CAST(master_version AS TEXT) || ':' || state FROM waaseyaa_application_master_version ORDER BY master_version",
            ),
        );

        $purposes = [
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
        ];
        $snapshot = $store->recordAdapterSnapshot(
            self::REQUEST_ID,
            expectedRequestRevision: 2,
            adapterId: self::ADAPTER_ID,
            purposeIds: $purposes,
            snapshotToken: hash('sha256', 'synthetic-snapshot'),
            totalRecords: 2,
        );
        self::assertNull($snapshot->cursor);
        self::assertSame(1, $snapshot->revision);

        $first = $store->commitAdapterBatch(
            self::REQUEST_ID,
            expectedRequestRevision: 3,
            adapterId: self::ADAPTER_ID,
            expectedAdapterRevision: 1,
            expectedCursor: null,
            nextCursor: 'row:1',
            transitionedRecords: 1,
            purposeCountDeltas: array_fill_keys($purposes, 1),
            batchCommitment: hash('sha256', 'synthetic-batch-1'),
        );
        self::assertSame('row:1', $first->cursor);
        self::assertSame(1, $first->transitionedRecords);
        self::assertSame(array_fill_keys($purposes, 1), $first->purposeCounts);

        $restarted = new ApplicationMasterRekeyStore(DBALDatabase::createSqlite($this->dbPath));
        self::assertEquals($first, $restarted->requireAdapter(self::REQUEST_ID, self::ADAPTER_ID));

        try {
            $store->commitAdapterBatch(
                self::REQUEST_ID,
                expectedRequestRevision: 3,
                adapterId: self::ADAPTER_ID,
                expectedAdapterRevision: 1,
                expectedCursor: null,
                nextCursor: 'stale-overwrite',
                transitionedRecords: 1,
                purposeCountDeltas: array_fill_keys($purposes, 1),
                batchCommitment: hash('sha256', 'stale-batch'),
            );
            self::fail('A stale application-master batch overwrote its durable cursor.');
        } catch (ApplicationMasterRekeyConflictException) {
            self::assertEquals($first, $store->requireAdapter(self::REQUEST_ID, self::ADAPTER_ID));
        }
    }

    #[Test]
    public function revocation_requires_complete_adapter_verification_and_every_closed_gate(): void
    {
        $store = new ApplicationMasterRekeyStore($this->migratedDatabase());
        $registry = $this->purposes();
        $store->prepare($this->request(), $registry);
        $store->installActive(
            self::REQUEST_ID,
            1,
            hash('sha256', 'synthetic-master-v1-reference'),
            hash('sha256', 'synthetic-master-v2-reference'),
            1_100,
        );
        $purposes = $registry->purposeIds();
        $store->recordAdapterSnapshot(
            self::REQUEST_ID,
            2,
            self::ADAPTER_ID,
            $purposes,
            hash('sha256', 'empty-synthetic-snapshot'),
            0,
        );
        $store->completeAdapter(self::REQUEST_ID, 3, self::ADAPTER_ID, 1, null);

        try {
            $store->revokePredecessor(self::REQUEST_ID, 4, 3_000);
            self::fail('Application-master predecessor revocation ignored missing verification and gates.');
        } catch (ApplicationMasterRekeyRevocationBlockedException $exception) {
            self::assertStringContainsString('verification', $exception->getMessage());
        }

        $revision = 4;
        foreach ($purposes as $purpose) {
            $record = $store->recordPurposeVerification(
                self::REQUEST_ID,
                $revision,
                $purpose,
                verifiedRecords: 0,
                verificationHash: hash('sha256', 'verified:' . $purpose),
            );
            $revision = $record->revision;
        }
        foreach (ApplicationMasterRekeyGate::cases() as $gate) {
            $record = $store->recordRevocationGate(
                self::REQUEST_ID,
                $revision,
                $gate,
                hash('sha256', 'gate:' . $gate->value),
                2_500,
            );
            $revision = $record->revision;
        }

        try {
            $store->revokePredecessor(self::REQUEST_ID, $revision, 2_400);
            self::fail('Application-master predecessor revocation ignored the retained-backup horizon.');
        } catch (ApplicationMasterRekeyRevocationBlockedException $exception) {
            self::assertStringContainsString('retention deadline', $exception->getMessage());
        }

        $revoked = $store->revokePredecessor(self::REQUEST_ID, $revision, 3_000);
        self::assertSame(ApplicationMasterRekeyState::RevokeOldInLedger, $revoked->state);
        self::assertSame('revoked', $store->masterVersionState(1));
        self::assertSame('active-write', $store->masterVersionState(2));

        $events = $store->eventChain(self::REQUEST_ID);
        self::assertNotEmpty($events);
        $previousHash = str_repeat('0', 64);
        foreach ($events as $sequence => $event) {
            self::assertSame($sequence + 1, $event->sequence);
            self::assertSame($previousHash, $event->previousHash);
            self::assertSame($event->recomputeHash(), $event->eventHash);
            $previousHash = $event->eventHash;
        }

        $nextRequestId = 'synthetic-rekey-20260815-0002';
        $store->prepare(new ApplicationMasterRekeyRequest(
            requestId: $nextRequestId,
            fromVersion: 2,
            toVersion: 3,
            registryChecksum: $registry->checksum(),
            authorizationDigest: hash('sha256', 'next-synthetic-authorization'),
            actor: 'synthetic-operator-2',
            rollbackDeadline: 4_000,
            retentionDeadline: 4_500,
            createdAt: 3_500,
        ), $registry);
        $store->installActive(
            $nextRequestId,
            1,
            hash('sha256', 'synthetic-master-v2-reference'),
            hash('sha256', 'synthetic-master-v3-reference'),
            3_600,
        );
        self::assertSame('revoked', $store->masterVersionState(1));
        self::assertSame('legacy-read-verify', $store->masterVersionState(2));
        self::assertSame('active-write', $store->masterVersionState(3));
    }

    #[Test]
    public function one_completed_adapter_cannot_skip_an_uninventoried_registry_owner(): void
    {
        $registry = $this->purposes();
        $expanded = new ApplicationMasterPurposeRegistry();
        foreach ($registry->purposeIds() as $purpose) {
            $expanded->register($registry->policy($purpose));
        }
        $expanded->register(new ApplicationMasterPurposePolicy(
            id: ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC,
            ownerPackage: 'waaseyaa/cache',
            strategy: ApplicationMasterPurposeStrategy::InvalidateRebuildable,
            maximumLifetimeSeconds: 86_400,
            retentionSeconds: 86_400,
            adapterId: 'synthetic-cache-v1',
            rollbackBehavior: 'rebuild-generation',
        ));
        $expanded->freeze();

        $store = new ApplicationMasterRekeyStore($this->migratedDatabase());
        $store->prepare(new ApplicationMasterRekeyRequest(
            requestId: self::REQUEST_ID,
            fromVersion: 1,
            toVersion: 2,
            registryChecksum: $expanded->checksum(),
            authorizationDigest: hash('sha256', 'synthetic-two-person-authorization'),
            actor: 'synthetic-operator-1',
            rollbackDeadline: 2_000,
            retentionDeadline: 2_500,
            createdAt: 1_000,
        ), $expanded);
        $store->installActive(
            self::REQUEST_ID,
            1,
            hash('sha256', 'synthetic-master-v1-reference'),
            hash('sha256', 'synthetic-master-v2-reference'),
            1_100,
        );
        $oidcPurposes = array_values(array_filter(
            $expanded->purposeIds(),
            static fn(string $purpose): bool => $purpose !== ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC,
        ));
        $store->recordAdapterSnapshot(
            self::REQUEST_ID,
            2,
            self::ADAPTER_ID,
            $oidcPurposes,
            hash('sha256', 'empty-oidc-snapshot'),
            0,
        );
        $afterOidc = $store->completeAdapter(self::REQUEST_ID, 3, self::ADAPTER_ID, 1, null);
        self::assertSame(ApplicationMasterRekeyState::TransitionBoundedBatches, $afterOidc->state);

        $store->recordAdapterSnapshot(
            self::REQUEST_ID,
            4,
            'synthetic-cache-v1',
            [ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC],
            hash('sha256', 'empty-cache-snapshot'),
            0,
        );
        $afterCache = $store->completeAdapter(
            self::REQUEST_ID,
            5,
            'synthetic-cache-v1',
            1,
            null,
        );
        self::assertSame(ApplicationMasterRekeyState::VerifyEveryPurpose, $afterCache->state);
    }

    private function request(): ApplicationMasterRekeyRequest
    {
        return new ApplicationMasterRekeyRequest(
            requestId: self::REQUEST_ID,
            fromVersion: 1,
            toVersion: 2,
            registryChecksum: $this->purposes()->checksum(),
            authorizationDigest: hash('sha256', 'synthetic-two-person-authorization'),
            actor: 'synthetic-operator-1',
            rollbackDeadline: 2_000,
            retentionDeadline: 2_500,
            createdAt: 1_000,
        );
    }

    private function purposes(): ApplicationMasterPurposeRegistry
    {
        $registry = new ApplicationMasterPurposeRegistry();
        $registry->register($this->policy(
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
            ApplicationMasterPurposeStrategy::ReencryptCiphertext,
        ));
        $registry->register($this->policy(
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ApplicationMasterPurposeStrategy::ReencryptCiphertext,
        ));
        $registry->register($this->policy(
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
            ApplicationMasterPurposeStrategy::RecomputeLookupIndex,
        ));
        $registry->freeze();

        return $registry;
    }

    private function policy(
        string $purpose,
        ApplicationMasterPurposeStrategy $strategy,
    ): ApplicationMasterPurposePolicy {
        return new ApplicationMasterPurposePolicy(
            id: $purpose,
            ownerPackage: 'waaseyaa/oidc',
            strategy: $strategy,
            maximumLifetimeSeconds: 3_600,
            retentionSeconds: 86_400,
            adapterId: self::ADAPTER_ID,
            rollbackBehavior: 'reverse-cas',
        );
    }

    private function migratedDatabase(): DBALDatabase
    {
        $database = DBALDatabase::createSqlite($this->dbPath);
        $migration = require dirname(__DIR__, 3) . '/migrations/2026_08_15_000002_application_master_rekey.php';
        if (!$migration instanceof Migration) {
            throw new \LogicException('The Foundation application-master rekey migration is invalid.');
        }
        $migration->up(new SchemaBuilder($database->getConnection()));

        return $database;
    }

    /** @return list<string> */
    private function tableNames(DBALDatabase $database): array
    {
        $names = array_map(
            static fn($table): string => $table->getName(),
            $database->getConnection()->createSchemaManager()->listTables(),
        );
        sort($names, SORT_STRING);

        return $names;
    }
}
