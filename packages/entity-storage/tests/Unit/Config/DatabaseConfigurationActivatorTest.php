<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Activation\ConfigurationActivationAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationActivationConflictException;
use Waaseyaa\Config\Activation\ConfigurationActivationContentionException;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivationRequestReuseException;
use Waaseyaa\Config\Activation\ConfigurationCandidateSweepAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationCandidateSweepRequest;
use Waaseyaa\Config\Activation\ConfigurationRollbackRequest;
use Waaseyaa\Config\Activation\ConfigurationRollbackValidatorInterface;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Tests\Fixtures\VerifiedConfigBundleFixture;
use Waaseyaa\Config\Drift\ConfigDriftVerifier;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Config\DatabaseConfigurationActivator;
use Waaseyaa\EntityStorage\Config\DatabaseConfigReplayStateReader;
use Waaseyaa\EntityStorage\Config\DatabaseConfigDriftSnapshotReader;
use Waaseyaa\EntityStorage\Config\DatabaseActiveConfigurationBridge;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class DatabaseConfigurationActivatorTest extends TestCase
{
    private int $bundleSequence = 0;
    private DBALDatabase $database;
    private ConfigurationAuthorityContext $context;
    private ConfigurationActivationAuthorizerInterface $authorizer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:', 'testing');
        $this->applyMigrations($this->database);
        $this->context = new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: 'database:v1:activation-test',
            syncPath: '/tmp/config-sync',
            selectorProvenance: ['testing'],
        );
        $this->authorizer = new class implements ConfigurationActivationAuthorizerInterface {
            public function authorize(ConfigurationActivationRequest $request, bool $deletes): void {}
        };
    }

    private function applyMigrations(DBALDatabase $database): void
    {
        foreach ([
            '2026_08_12_000002_configuration_authority.php',
            '2026_08_12_000003_configuration_activation.php',
            '2026_08_15_000004_configuration_manifest_replay.php',
            '2026_08_19_000005_configuration_genesis_marker.php',
        ] as $migrationFile) {
            $migration = require dirname(__DIR__, 3) . '/migrations/' . $migrationFile;
            $migration->up(new SchemaBuilder($database->getConnection()));
        }
    }

    #[Test]
    public function replayReaderFailsClosedWithAStableDiagnosticBeforeItsMigrationExists(): void
    {
        $database = DBALDatabase::createSqlite(':memory:', 'testing');
        $reader = new DatabaseConfigReplayStateReader($database, $this->context);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CFG-03 manifest replay state is unavailable');
        $reader->lastCommittedSequence('test:configuration-activation', 'cfg04:test-key');
    }

    #[Test]
    public function firstActivationPublishesOneContentBoundOrderedHead(): void
    {
        $activator = $this->activator();
        $result = $activator->activate($this->request('request-1', null, [
            $this->file('system', 'site', ['name' => 'Waaseyaa']),
        ]));

        self::assertSame('committed', $result->status);
        self::assertSame(1, $result->token->activationSequence);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result->token->generationId);
        self::assertEquals($result->token, $activator->currentToken());
        self::assertSame(['system.site'], array_map(
            static fn(ConfigSyncFile $file): string => $file->ref(),
            iterator_to_array($activator->readGeneration($result->token)),
        ));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
    }

    #[Test]
    public function replayEvidenceCannotReferenceAnAbsentActivation(): void
    {
        $result = $this->activator()->activate($this->request('trigger-guard-base', null, [
            $this->file('system', 'site', ['name' => 'Waaseyaa']),
        ]));

        try {
            $this->database->getConnection()->executeStatement(
                'UPDATE waaseyaa_config_manifest_replay SET activation_sequence = 999 WHERE authority_id = ?',
                [$this->context->authorityId],
            );
            self::fail('Replay state accepted an activation sequence with no committed activation.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('requires a committed activation', $exception->getMessage());
        }
        self::assertSame(
            $result->token->activationSequence,
            $this->scalar('SELECT activation_sequence FROM waaseyaa_config_manifest_replay'),
        );
    }

    #[Test]
    public function replayHighWaterCannotMoveBackward(): void
    {
        $first = $this->activator()->activate($this->request('replay-monotonic-first', null, [
            $this->file('system', 'site', ['name' => 'A']),
        ]));
        $second = $this->activator()->activate($this->request('replay-monotonic-second', $first->token, [
            $this->file('system', 'site', ['name' => 'B']),
        ]));

        $failure = null;
        try {
            $this->database->getConnection()->executeStatement(
                'UPDATE waaseyaa_config_manifest_replay SET last_sequence = 1, activation_sequence = 1 WHERE authority_id = ?',
                [$this->context->authorityId],
            );
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'Replay high-water moved backward.');
        self::assertStringContainsString('must advance monotonically', $failure->getMessage());
        self::assertSame(
            $second->token->activationSequence,
            $this->scalar('SELECT last_sequence FROM waaseyaa_config_manifest_replay'),
        );
    }

    #[Test]
    public function manifestProvenanceAndEntryContractsAreAppendOnly(): void
    {
        $result = $this->activator()->activate($this->request('append-only-base', null, [
            $this->file('system', 'site', ['name' => 'Waaseyaa']),
        ]));

        foreach ([
            [
                'UPDATE waaseyaa_config_activation_manifest SET manifest_hash = ? WHERE activation_sequence = ?',
                ['sha256:' . str_repeat('0', 64), $result->token->activationSequence],
                'manifest provenance is append-only',
            ],
            [
                'DELETE FROM waaseyaa_config_activation_manifest WHERE activation_sequence = ?',
                [$result->token->activationSequence],
                'manifest provenance is append-only',
            ],
            [
                'UPDATE waaseyaa_config_entry_contract SET effective_entry_hash = ? WHERE generation_id = ?',
                ['sha256:' . str_repeat('0', 64), $result->token->generationId],
                'entry contract evidence is immutable',
            ],
        ] as [$sql, $arguments, $message]) {
            $failure = null;
            try {
                $this->database->getConnection()->executeStatement($sql, $arguments);
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
            self::assertNotNull($failure, 'Direct evidence mutation unexpectedly succeeded.');
            self::assertStringContainsString($message, $failure->getMessage());
        }
    }

    #[Test]
    public function staleAndAbaExpectedTokensCannotCommit(): void
    {
        $activator = $this->activator();
        $first = $activator->activate($this->request('request-a', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $second = $activator->activate($this->request('request-b', $first->token, [$this->file('system', 'site', ['name' => 'B'])]));
        $third = $activator->activate($this->request('request-a-again', $second->token, [$this->file('system', 'site', ['name' => 'A'])]));

        self::assertSame($first->token->generationId, $third->token->generationId);
        self::assertSame(3, $third->token->activationSequence);

        $this->expectException(ConfigurationActivationConflictException::class);
        $activator->activate($this->request('request-stale', $first->token, [$this->file('system', 'site', ['name' => 'C'])]));
    }

    #[Test]
    public function committedRetryIsIdempotentAndRequestReuseWithDifferentInputFails(): void
    {
        $activator = $this->activator();
        $request = $this->request('request-retry', null, [$this->file('system', 'site', ['name' => 'A'])]);
        $first = $activator->activate($request);
        $retry = $activator->activate($request);

        self::assertSame('already-committed', $retry->status);
        self::assertEquals($first->token, $retry->token);
        self::assertSame($request->inputHash(), $retry->inputHash);
        self::assertSame('request-retry', $retry->candidateId);
        self::assertSame($first->evidenceHash(), $retry->evidenceHash());
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
        self::assertEquals($retry, $activator->committedResult('request-retry'));
        self::assertNull($activator->committedResult('request-never-committed'));

        $this->expectException(ConfigurationActivationRequestReuseException::class);
        $activator->activate($this->request('request-retry', null, [$this->file('system', 'site', ['name' => 'different'])]));
    }

    #[Test]
    public function retryingAStagedRequestAfterAnInterveningActivationIsAStaleConflict(): void
    {
        $activator = $this->activator();
        $first = $activator->activate($this->request('staged-retry-base', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $staged = $this->request('staged-retry-request', $first->token, [$this->file('system', 'site', ['name' => 'B'])]);
        try {
            $this->database->query(<<<'SQL'
                CREATE TRIGGER stage_retry_failure
                BEFORE INSERT ON waaseyaa_config_activation_v2
                WHEN NEW.activation_request_id = 'staged-retry-request'
                BEGIN
                    SELECT RAISE(ABORT, 'leave candidate staged');
                END
                SQL);
            $activator->activate($staged);
            self::fail('Injected activation failure was reported as success.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('leave candidate staged', $exception->getMessage());
        }
        $this->database->query('DROP TRIGGER stage_retry_failure');
        $intervening = $activator->activate($this->request(
            'staged-retry-intervening',
            $first->token,
            [$this->file('system', 'site', ['name' => 'C'])],
        ));

        try {
            $activator->activate($staged);
            self::fail('Staged request ignored an intervening activation.');
        } catch (ConfigurationActivationConflictException $exception) {
            self::assertStringContainsString('before planning', $exception->getMessage());
        }
        self::assertEquals($intervening->token, $activator->currentToken());
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
    }

    #[Test]
    public function completeBundleRetainsExplicitEntriesWhileAHashBoundTombstoneDeletes(): void
    {
        $activator = $this->activator();
        $site = $this->file('system', 'site', ['name' => 'Waaseyaa']);
        $role = $this->file('role', 'editor', ['label' => 'Editor']);
        $first = $activator->activate($this->request('request-full', null, [$site, $role]));

        $retained = $activator->activate($this->request('request-complete-update', $first->token, [
            $role,
            $this->file('system', 'site', ['name' => 'Waaseyaa 2']),
        ]));
        self::assertSame(['role.editor', 'system.site'], $this->refs($activator, $retained->token));

        $deleted = $activator->activate($this->request(
            'request-delete',
            $retained->token,
            [$this->file('system', 'site', ['name' => 'Waaseyaa 2'])],
            ['role.editor' => $role->contentHash()],
        ));
        self::assertSame(['system.site'], $this->refs($activator, $deleted->token));

        $this->expectException(ConfigurationActivationConflictException::class);
        $activator->activate($this->request(
            'request-stale-delete',
            $deleted->token,
            [],
            ['system.site' => str_repeat('0', 64)],
        ));
    }

    #[Test]
    public function completeReplacementRequiresExpectedTokenAndEveryOmittedEntryTombstone(): void
    {
        $activator = $this->activator();
        $site = $this->file('system', 'site', ['name' => 'Waaseyaa']);
        $role = $this->file('role', 'editor', ['label' => 'Editor']);
        $first = $activator->activate($this->request('request-replacement-base', null, [$site, $role]));

        try {
            new ConfigurationActivationRequest('request-replacement-unverified', null, [$site]);
            self::fail('Ordinary activation accepted raw unverified files.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('verified CFG-03 bundle', $exception->getMessage());
        }

        $incompleteBundle = VerifiedConfigBundleFixture::fromFiles([$site], ++$this->bundleSequence);
        try {
            $activator->activate(ConfigurationActivationRequest::activateVerified(
                'request-replacement-incomplete-plan', $first->token, $incompleteBundle,
            ));
            self::fail('Complete replacement accepted an incomplete deletion plan.');
        } catch (ConfigurationActivationConflictException $exception) {
            self::assertStringContainsString('every omitted active entry', $exception->getMessage());
        }

        $replacement = $activator->activate(ConfigurationActivationRequest::activateVerified(
            'request-replacement-valid',
            $first->token,
            VerifiedConfigBundleFixture::fromFiles([$site], ++$this->bundleSequence),
            ['role.editor' => $role->contentHash()],
        ));
        self::assertSame(['system.site'], $this->refs($activator, $replacement->token));
    }

    #[Test]
    public function verifiedManifestIdentityIsBoundIntoRequestIdentity(): void
    {
        $token = new ConfigurationActiveToken(str_repeat('a', 64), 1);
        $file = $this->file('system', 'site', ['name' => 'Waaseyaa']);
        $first = ConfigurationActivationRequest::activateVerified(
            'request-mode-a', $token, VerifiedConfigBundleFixture::fromFiles([$file], 1),
        );
        $second = ConfigurationActivationRequest::activateVerified(
            'request-mode-b', $token, VerifiedConfigBundleFixture::fromFiles([$file], 2),
        );

        self::assertNotSame($first->inputHash(), $second->inputHash());
    }

    #[Test]
    public function missingAuthorizationRefusesBeforeCandidateStaging(): void
    {
        $authorizer = new class implements ConfigurationActivationAuthorizerInterface {
            public function authorize(ConfigurationActivationRequest $request, bool $deletes): void
            {
                throw new \DomainException('not authorized');
            }
        };
        $activator = new DatabaseConfigurationActivator($this->database, $this->context, $authorizer);

        try {
            $activator->activate($this->request('request-denied', null, [$this->file('system', 'site', ['name' => 'A'])]));
            self::fail('Unauthorized activation was accepted.');
        } catch (\DomainException $exception) {
            self::assertSame('not authorized', $exception->getMessage());
        }

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_candidate'));
        self::assertNull($activator->currentToken());
    }

    /**
     * The one branch that maps a unique violation to a typed conflict had no
     * coverage at all, and its message named neither the authority nor the
     * violated key — so #2545 was diagnosed from a string that could not say
     * which database committed. Reaching it needs a genuine racing duplicate:
     * the activator's own idempotency check clears before the INSERT, so the
     * conflicting row is injected between them by a trigger.
     */
    #[Test]
    public function aRacingDuplicateRequestNamesTheAuthorityAndTheViolatedKey(): void
    {
        $activator = $this->activator();
        $first = $activator->activate($this->request('request-good', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $this->database->query(<<<'SQL'
            CREATE TRIGGER race_duplicate_activation_request
            BEFORE INSERT ON waaseyaa_config_activation_v2
            BEGIN
                INSERT INTO waaseyaa_config_activation_v2 (
                    authority_id, activation_sequence, activation_request_id, generation_id,
                    plan_hash, operation, activated_at
                ) VALUES (
                    NEW.authority_id, NEW.activation_sequence + 1000, NEW.activation_request_id,
                    NEW.generation_id, NEW.plan_hash, NEW.operation, NEW.activated_at
                );
            END
            SQL);

        try {
            $activator->activate($this->request('request-races', $first->token, [$this->file('system', 'site', ['name' => 'B'])]));
            self::fail('A duplicate activation request id was reported as success.');
        } catch (ConfigurationActivationConflictException $exception) {
            self::assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
            self::assertStringContainsString('request-races', $exception->getMessage());
            self::assertStringContainsString($this->context->authorityId, $exception->getMessage());
        }

        self::assertEquals($first->token, $activator->currentToken());
    }

    #[Test]
    public function failedLedgerAppendRollsBackCounterAndLeavesPriorHeadServing(): void
    {
        $activator = $this->activator();
        $first = $activator->activate($this->request('request-good', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $this->database->query(<<<'SQL'
            CREATE TRIGGER reject_config_activation
            BEFORE INSERT ON waaseyaa_config_activation_v2
            BEGIN
                SELECT RAISE(ABORT, 'injected activation failure');
            END
            SQL);

        try {
            $activator->activate($this->request('request-fails', $first->token, [$this->file('system', 'site', ['name' => 'B'])]));
            self::fail('Injected activation failure was reported as success.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('injected activation failure', $exception->getMessage());
        }

        self::assertEquals($first->token, $activator->currentToken());
        self::assertSame(1, $this->scalar('SELECT last_sequence FROM waaseyaa_config_activation_counter'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
    }

    #[Test]
    public function sqliteBusyIsMappedToTypedContentionAndCannotPublish(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa_cfg02_contention_' . bin2hex(random_bytes(8)) . '.sqlite';
        $firstDatabase = DBALDatabase::createSqlite($path, 'testing');
        try {
            foreach ([
                '2026_08_12_000002_configuration_authority.php',
                '2026_08_12_000003_configuration_activation.php',
                '2026_08_15_000004_configuration_manifest_replay.php',
                '2026_08_19_000005_configuration_genesis_marker.php',
            ] as $migrationFile) {
                $migration = require dirname(__DIR__, 3) . '/migrations/' . $migrationFile;
                $migration->up(new SchemaBuilder($firstDatabase->getConnection()));
            }
            $firstActivator = new DatabaseConfigurationActivator($firstDatabase, $this->context, $this->authorizer);
            $head = $firstActivator->activate($this->request(
                'request-contention-base',
                null,
                [$this->file('system', 'site', ['name' => 'A'])],
            ));

            $secondDatabase = DBALDatabase::createSqlite($path, 'testing');
            $secondDatabase->getConnection()->executeStatement('PRAGMA busy_timeout = 1');
            $firstDatabase->getConnection()->executeStatement('BEGIN IMMEDIATE');
            try {
                $secondActivator = new DatabaseConfigurationActivator($secondDatabase, $this->context, $this->authorizer);
                $secondActivator->activate($this->request(
                    'request-contention-loser',
                    $head->token,
                    [$this->file('system', 'site', ['name' => 'B'])],
                ));
                self::fail('A contending activation was reported as success.');
            } catch (ConfigurationActivationContentionException $exception) {
                self::assertStringContainsString('writer authority', $exception->getMessage());
            } finally {
                $firstDatabase->getConnection()->executeStatement('ROLLBACK');
            }

            self::assertEquals($head->token, $firstActivator->currentToken());
            self::assertSame(1, $this->scalarFrom($firstDatabase, 'SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    #[Test]
    public function firstV2ActivationContinuesTheLegacySequenceAndCopiesTheCompleteGeneration(): void
    {
        $legacy = $this->file('system', 'site', ['name' => 'Legacy']);
        $legacyGeneration = str_repeat('c', 64);
        $this->database->query(
            'INSERT INTO waaseyaa_config_generation '
            . '(authority_id, generation_id, activation_sequence, schema_version, manifest_hash, lifecycle_state, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->context->authorityId, $legacyGeneration, 7, 'config-schema.v1', str_repeat('d', 64), 'active', gmdate('c')],
        );
        $this->database->query(
            'INSERT INTO waaseyaa_config_entry '
            . '(authority_id, generation_id, config_name, entity_type, entity_id, uuid, dependencies_json, langcode, fields_json, content_hash) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->context->authorityId,
                $legacyGeneration,
                $legacy->ref(),
                $legacy->entityType,
                $legacy->entityId,
                $legacy->uuid,
                '[]',
                $legacy->langcode,
                json_encode($legacy->fields, JSON_THROW_ON_ERROR),
                $legacy->contentHash(),
            ],
        );
        $this->database->query(
            'INSERT INTO waaseyaa_config_activation (authority_id, generation_id, activation_sequence, activated_at) '
            . 'VALUES (?, ?, ?, ?)',
            [$this->context->authorityId, $legacyGeneration, 7, gmdate('c')],
        );

        $activator = $this->activator();
        $legacyToken = new ConfigurationActiveToken($legacyGeneration, 7);
        self::assertEquals($legacyToken, $activator->currentToken());
        $result = $activator->activate($this->request(
            'request-after-legacy',
            $legacyToken,
            [$legacy, $this->file('role', 'editor', ['label' => 'Editor'])],
        ));

        self::assertSame(8, $result->token->activationSequence);
        self::assertSame(['role.editor', 'system.site'], $this->refs($activator, $result->token));
        self::assertSame(8, $this->scalar('SELECT last_sequence FROM waaseyaa_config_activation_counter'));
    }

    #[Test]
    public function rollbackReactivatesRetainedContentWithANewSequenceAfterCompatibilityValidation(): void
    {
        $validator = new class implements ConfigurationRollbackValidatorInterface {
            public int $calls = 0;

            public function validate(ConfigurationRollbackRequest $request, array $targetFiles): void
            {
                ++$this->calls;
                TestCase::assertNotEmpty($targetFiles);
            }
        };
        $activator = new DatabaseConfigurationActivator(
            $this->database,
            $this->context,
            $this->authorizer,
            $validator,
        );
        $first = $activator->activate($this->request('rollback-base-a', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $second = $activator->activate($this->request('rollback-base-b', $first->token, [$this->file('system', 'site', ['name' => 'B'])]));

        $rollback = $activator->rollback(new ConfigurationRollbackRequest(
            'rollback-to-a',
            $second->token,
            $first->token,
        ));

        self::assertSame(1, $validator->calls);
        self::assertSame($first->token->generationId, $rollback->token->generationId);
        self::assertSame(3, $rollback->token->activationSequence);
        self::assertSame('rollback', $this->stringScalar(
            'SELECT operation FROM waaseyaa_config_activation_v2 WHERE activation_sequence = 3',
        ));
        self::assertSame($first->token->generationId, $this->stringScalar(
            'SELECT target_generation_id FROM waaseyaa_config_activation_v2 WHERE activation_sequence = 3',
        ));
    }

    #[Test]
    public function staleRollbackChangesNeitherHeadNorLedger(): void
    {
        $validator = new class implements ConfigurationRollbackValidatorInterface {
            public function validate(ConfigurationRollbackRequest $request, array $targetFiles): void {}
        };
        $activator = new DatabaseConfigurationActivator(
            $this->database,
            $this->context,
            $this->authorizer,
            $validator,
        );
        $first = $activator->activate($this->request('stale-rollback-a', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $second = $activator->activate($this->request('stale-rollback-b', $first->token, [$this->file('system', 'site', ['name' => 'B'])]));

        try {
            $activator->rollback(new ConfigurationRollbackRequest(
                'stale-rollback-attempt',
                $first->token,
                $first->token,
            ));
            self::fail('Stale rollback was reported as success.');
        } catch (ConfigurationActivationConflictException) {
        }

        self::assertEquals($second->token, $activator->currentToken());
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
        self::assertSame(2, $this->scalar('SELECT last_sequence FROM waaseyaa_config_activation_counter'));
    }

    #[Test]
    public function rollbackRefusesBeforeStagingWhenCompatibilityValidatorIsMissing(): void
    {
        $activator = $this->activator();
        $first = $activator->activate($this->request('refused-rollback-a', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $second = $activator->activate($this->request('refused-rollback-b', $first->token, [$this->file('system', 'site', ['name' => 'B'])]));
        $candidateCount = $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_candidate');

        try {
            $activator->rollback(new ConfigurationRollbackRequest('refused-rollback', $second->token, $first->token));
            self::fail('Rollback bypassed its compatibility validator.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('compatibility validator', $exception->getMessage());
        }

        self::assertSame($candidateCount, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_candidate'));
        self::assertEquals($second->token, $activator->currentToken());
    }

    #[Test]
    public function leaseAuthorizedSweepOnlySupersedesOldUncommittedCandidates(): void
    {
        $sweepAuthorizer = new class implements ConfigurationCandidateSweepAuthorizerInterface {
            public int $calls = 0;
            public function authorize(ConfigurationCandidateSweepRequest $request): void
            {
                ++$this->calls;
            }
        };
        $activator = new DatabaseConfigurationActivator(
            $this->database,
            $this->context,
            $this->authorizer,
            sweepAuthorizer: $sweepAuthorizer,
        );
        $head = $activator->activate($this->request('sweep-committed', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $this->database->query(<<<'SQL'
            CREATE TRIGGER sweep_stage_failure
            BEFORE INSERT ON waaseyaa_config_activation_v2
            WHEN NEW.activation_request_id = 'sweep-staged'
            BEGIN
                SELECT RAISE(ABORT, 'leave sweep candidate staged');
            END
            SQL);
        $stagedRequest = $this->request('sweep-staged', $head->token, [$this->file('system', 'site', ['name' => 'B'])]);
        try {
            $activator->activate($stagedRequest);
            self::fail('Injected sweep staging failure was reported as success.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('leave sweep candidate staged', $exception->getMessage());
        }
        $this->database->query('DROP TRIGGER sweep_stage_failure');
        $this->database->query(
            'UPDATE waaseyaa_config_candidate SET created_at = ? WHERE activation_request_id = ?',
            ['2020-01-01T00:00:00+00:00', 'sweep-staged'],
        );

        $affected = $activator->supersedeStagedCandidates(new ConfigurationCandidateSweepRequest(
            'sweep-maintenance-1',
            'configuration-candidate-maintenance',
            9,
            new \DateTimeImmutable('2021-01-01T00:00:00+00:00'),
        ));

        self::assertSame(1, $affected);
        self::assertSame(1, $sweepAuthorizer->calls);
        self::assertSame('committed', $this->stringScalar(
            "SELECT lifecycle_state FROM waaseyaa_config_candidate WHERE activation_request_id = 'sweep-committed'",
        ));
        self::assertSame('superseded', $this->stringScalar(
            "SELECT lifecycle_state FROM waaseyaa_config_candidate WHERE activation_request_id = 'sweep-staged'",
        ));
        self::assertEquals($head->token, $activator->currentToken());
        $retried = $activator->activate($stagedRequest);
        self::assertSame(2, $retried->token->activationSequence);
        self::assertSame('committed', $this->stringScalar(
            "SELECT lifecycle_state FROM waaseyaa_config_candidate WHERE activation_request_id = 'sweep-staged'",
        ));
    }

    #[Test]
    public function candidateSweepRefusesWithoutLeaseAuthorizationBeforeMutation(): void
    {
        $activator = $this->activator();
        $head = $activator->activate($this->request('sweep-refusal-base', null, [$this->file('system', 'site', ['name' => 'A'])]));

        try {
            $activator->supersedeStagedCandidates(new ConfigurationCandidateSweepRequest(
                'sweep-refusal',
                'configuration-candidate-maintenance',
                1,
                new \DateTimeImmutable('tomorrow'),
            ));
            self::fail('Candidate sweep bypassed lease authorization.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('lease and fence', $exception->getMessage());
        }
        self::assertSame('committed', $this->stringScalar(
            "SELECT lifecycle_state FROM waaseyaa_config_candidate WHERE activation_request_id = 'sweep-refusal-base'",
        ));
        self::assertEquals($head->token, $activator->currentToken());
    }

    #[Test]
    public function candidateSweepRejectsAStaleOrReplayedFenceWithinItsLeaseDomain(): void
    {
        $sweepAuthorizer = new class implements ConfigurationCandidateSweepAuthorizerInterface {
            public function authorize(ConfigurationCandidateSweepRequest $request): void {}
        };
        $activator = new DatabaseConfigurationActivator(
            $this->database,
            $this->context,
            $this->authorizer,
            sweepAuthorizer: $sweepAuthorizer,
        );
        $first = new ConfigurationCandidateSweepRequest(
            'sweep-fence-10',
            'configuration-candidate-maintenance',
            10,
            new \DateTimeImmutable('tomorrow'),
        );
        self::assertSame(0, $activator->supersedeStagedCandidates($first));

        foreach ([10, 9] as $staleFence) {
            try {
                $activator->supersedeStagedCandidates(new ConfigurationCandidateSweepRequest(
                    'sweep-fence-' . $staleFence,
                    'configuration-candidate-maintenance',
                    $staleFence,
                    new \DateTimeImmutable('tomorrow'),
                ));
                self::fail('A stale or replayed candidate-sweep fence was accepted.');
            } catch (ConfigurationActivationConflictException $exception) {
                self::assertStringContainsString('stale or replayed', $exception->getMessage());
            }
        }
        self::assertSame(10, $this->scalar(
            "SELECT last_fence FROM waaseyaa_config_candidate_sweep_fence WHERE lease_domain = 'configuration-candidate-maintenance'",
        ));
    }

    #[Test]
    public function missingCounterFailsClosedAfterActivationHistoryExists(): void
    {
        $activator = $this->activator();
        $head = $activator->activate($this->request('counter-history-a', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $this->database->delete('waaseyaa_config_activation_counter')
            ->condition('authority_id', $this->context->authorityId)
            ->execute();

        try {
            $activator->activate($this->request('counter-history-b', $head->token, [$this->file('system', 'site', ['name' => 'B'])]));
            self::fail('A deleted activation counter was recreated after durable history existed.');
        } catch (ConfigurationActivationConflictException $exception) {
            self::assertStringContainsString('counter is missing', $exception->getMessage());
        }
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
    }

    #[Test]
    public function manifestReplaySequenceIsClaimedAtomicallyAndAuthoredEvidenceIsAppendOnly(): void
    {
        $activator = $this->activator();
        $file = $this->file('system', 'site', ['name' => 'A']);
        $firstBundle = VerifiedConfigBundleFixture::fromFiles([$file], 1);
        $first = $activator->activate(ConfigurationActivationRequest::activateVerified(
            'manifest-sequence-1', null, $firstBundle,
        ));
        $secondBundle = VerifiedConfigBundleFixture::fromFiles([$file], 2);
        $second = $activator->activate(ConfigurationActivationRequest::activateVerified(
            'manifest-sequence-2', $first->token, $secondBundle,
        ));

        self::assertSame($first->token->generationId, $second->token->generationId);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_generation_v2'));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_manifest'));
        self::assertSame(2, new DatabaseConfigReplayStateReader($this->database, $this->context)
            ->lastCommittedSequence('test:configuration-activation', 'unsigned-sealed-local:authority:test'));
        $retained = array_values(iterator_to_array($activator->readGeneration($second->token)));
        self::assertCount(1, $retained);
        self::assertTrue($retained[0]->isWritableV1());
        self::assertSame($secondBundle->files()[0]->schemaHash, $retained[0]->schemaHash);
        self::assertSame(
            $secondBundle->verification->manifest->canonicalBytes,
            $this->stringScalar('SELECT manifest_bytes FROM waaseyaa_config_activation_manifest WHERE activation_sequence = 2'),
        );

        $replayed = ConfigurationActivationRequest::activateVerified(
            'manifest-sequence-replay',
            $second->token,
            VerifiedConfigBundleFixture::fromFiles([$this->file('system', 'site', ['name' => 'B'])], 2),
        );
        try {
            $activator->activate($replayed);
            self::fail('Replayed manifest sequence was committed.');
        } catch (ConfigurationActivationConflictException $exception) {
            self::assertStringContainsString('not newer than committed sequence', $exception->getMessage());
        }
        self::assertEquals($second->token, $activator->currentToken());
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_manifest'));
    }

    #[Test]
    public function driftVerificationBindsOneActiveSnapshotAndNeverMutatesArtifacts(): void
    {
        $directory = sys_get_temp_dir() . '/waaseyaa_cfg03_drift_' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);
        $compiled = $directory . '.compiled';
        $sqlite = $directory . '.sqlite';
        file_put_contents($compiled, 'compiled-evidence');
        try {
            $database = DBALDatabase::createSqlite($sqlite, 'testing');
            $this->applyMigrations($database);
            [$registry, $compatibility, $bundle] = VerifiedConfigBundleFixture::withAuthorities([
                $this->file('system', 'site', ['name' => 'A']),
            ], 1);
            foreach ($bundle->entries() as $entry) {
                file_put_contents($directory . '/' . $entry->file->filename(), $entry->exactBytes);
            }
            $baseContext = new ConfigurationAuthorityContext(
                authorityId: $this->context->authorityId,
                databaseIdentity: 'database:v1:file-backed-drift-test',
                syncPath: $directory,
                selectorProvenance: ['test'],
            );
            $activation = (new DatabaseConfigurationActivator($database, $baseContext, $this->authorizer))->activate(ConfigurationActivationRequest::activateVerified(
                'drift-snapshot-base', null, $bundle,
            ));
            $context = new ConfigurationAuthorityContext(
                authorityId: $baseContext->authorityId,
                databaseIdentity: $baseContext->databaseIdentity,
                syncPath: $directory,
                selectorProvenance: ['test'],
                activeGenerationId: $activation->token->generationId,
                activationSequence: $activation->token->activationSequence,
            );
            $bridge = new DatabaseActiveConfigurationBridge($database, $context);
            $verifier = new ConfigDriftVerifier(
                $directory,
                new ConfigSyncBundleValidator($registry),
                $registry,
                $compatibility,
                new DatabaseConfigDriftSnapshotReader($database, $context, $bridge),
                ['compiled' => $compiled, 'sqlite' => $sqlite, 'sync' => $directory],
            );

            $valid = $verifier->verify();
            self::assertTrue($valid->isValid(), implode("\n", array_map(
                static fn($diagnostic): string => $diagnostic->message,
                $valid->diagnostics,
            )));
            self::assertStringStartsWith('sha256:', $valid->artifactsBefore['sqlite']);
            self::assertSame($valid->artifactsBefore, $valid->artifactsAfter);
            $activationRows = $this->scalarFrom($database, 'SELECT COUNT(*) FROM waaseyaa_config_activation_v2');

            $database->getConnection()->executeStatement('DROP TRIGGER waaseyaa_config_activation_manifest_update_guard');
            $database->getConnection()->executeStatement(
                'UPDATE waaseyaa_config_activation_manifest SET manifest_hash = ? WHERE activation_sequence = ?',
                ['sha256:' . str_repeat('0', 64), $activation->token->activationSequence],
            );
            $manifestHashDrift = $verifier->verify();
            self::assertFalse($manifestHashDrift->isValid());
            self::assertStringContainsString('does not match its retained canonical bytes', implode("\n", array_map(
                static fn($diagnostic): string => $diagnostic->message,
                $manifestHashDrift->diagnostics,
            )));
            self::assertSame($manifestHashDrift->artifactsBefore, $manifestHashDrift->artifactsAfter);
            $database->getConnection()->executeStatement(
                'UPDATE waaseyaa_config_activation_manifest SET manifest_hash = ? WHERE activation_sequence = ?',
                [$bundle->verification->manifestHash, $activation->token->activationSequence],
            );

            $database->getConnection()->executeStatement('DROP TRIGGER waaseyaa_config_entry_contract_update_guard');
            $database->getConnection()->executeStatement(
                'UPDATE waaseyaa_config_entry_contract SET effective_entry_hash = ? WHERE generation_id = ?',
                ['sha256:' . str_repeat('0', 64), $activation->token->generationId],
            );
            $entryContractDrift = $verifier->verify();
            self::assertFalse($entryContractDrift->isValid());
            self::assertStringContainsString('retained effective-entry identity', implode("\n", array_map(
                static fn($diagnostic): string => $diagnostic->message,
                $entryContractDrift->diagnostics,
            )));
            self::assertSame($entryContractDrift->artifactsBefore, $entryContractDrift->artifactsAfter);
            $database->getConnection()->executeStatement(
                'UPDATE waaseyaa_config_entry_contract SET effective_entry_hash = ? WHERE generation_id = ?',
                [$bundle->entries()[0]->hashes->effectiveEntryHash, $activation->token->generationId],
            );

            $database->getConnection()->executeStatement('DROP TRIGGER waaseyaa_config_manifest_replay_monotonic_guard');
            $database->getConnection()->executeStatement(
                'UPDATE waaseyaa_config_manifest_replay SET last_sequence = 999 WHERE authority_id = ?',
                [$context->authorityId],
            );
            $replayEvidenceDrift = $verifier->verify();
            self::assertFalse($replayEvidenceDrift->isValid());
            self::assertStringContainsString('does not match retained activation evidence', implode("\n", array_map(
                static fn($diagnostic): string => $diagnostic->message,
                $replayEvidenceDrift->diagnostics,
            )));
            self::assertSame($replayEvidenceDrift->artifactsBefore, $replayEvidenceDrift->artifactsAfter);
            $database->getConnection()->executeStatement(
                'UPDATE waaseyaa_config_manifest_replay SET last_sequence = ? WHERE authority_id = ?',
                [$bundle->verification->bundleSequence, $context->authorityId],
            );

            file_put_contents($directory . '/system.site.yml', "# unauthenticated drift\n", \FILE_APPEND);
            $drift = $verifier->verify();
            self::assertFalse($drift->isValid());
            self::assertStringContainsString('do not match the activated authored manifest', implode("\n", array_map(
                static fn($diagnostic): string => $diagnostic->message,
                $drift->diagnostics,
            )));
            self::assertSame($drift->artifactsBefore, $drift->artifactsAfter);
            self::assertSame($activationRows, $this->scalarFrom($database, 'SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
            @unlink($compiled);
            @unlink($sqlite);
            @unlink($sqlite . '-journal');
            @unlink($sqlite . '-shm');
            @unlink($sqlite . '-wal');
        }
    }

    private function activator(): DatabaseConfigurationActivator
    {
        return new DatabaseConfigurationActivator($this->database, $this->context, $this->authorizer);
    }

    /**
     * @param list<ConfigSyncFile> $files
     * @param array<string, string> $tombstones
     */
    private function request(
        string $requestId,
        ?ConfigurationActiveToken $expected,
        array $files,
        array $tombstones = [],
    ): ConfigurationActivationRequest {
        $bundle = VerifiedConfigBundleFixture::fromFiles($files, ++$this->bundleSequence);

        return ConfigurationActivationRequest::activateVerified(
            $requestId,
            $expected,
            $bundle,
            $tombstones,
        );
    }

    /** @param array<string, mixed> $fields */
    private function file(string $entityType, string $entityId, array $fields): ConfigSyncFile
    {
        ksort($fields, SORT_STRING);

        return ConfigSyncFile::writable(
            entityType: $entityType,
            entityId: $entityId,
            uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
            dependencies: [],
            langcode: 'en',
            fields: $fields,
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    /** @return list<string> */
    private function refs(DatabaseConfigurationActivator $activator, ConfigurationActiveToken $token): array
    {
        return array_map(
            static fn(ConfigSyncFile $file): string => $file->ref(),
            iterator_to_array($activator->readGeneration($token)),
        );
    }

    private function scalar(string $sql): int
    {
        foreach ($this->database->query($sql) as $row) {
            return (int) array_values($row)[0];
        }

        self::fail('Scalar query returned no row.');
    }

    private function scalarFrom(DBALDatabase $database, string $sql): int
    {
        foreach ($database->query($sql) as $row) {
            return (int) array_values($row)[0];
        }

        self::fail('Scalar query returned no row.');
    }


    /**
     * Genesis (#2428): the one activation that claims no CFG-03 verification.
     * It exists because a site that has never been installed has no generation,
     * and every verified path to creating one needs one to already exist.
     */
    #[Test]
    public function genesisActivatesTheCanonicalEmptyGenerationOnAnUninstalledSite(): void
    {
        $activator = new DatabaseConfigurationActivator($this->database, $this->context, $this->authorizer);
        self::assertNull($activator->currentToken(), 'Fixture must start with no active generation.');

        $result = $activator->activateGenesis('install-init-fixture');

        self::assertSame(
            DatabaseConfigurationActivator::genesisGenerationId($this->context),
            $result->token->generationId,
            'The genesis generation identity is derived from the authority alone.',
        );
        self::assertSame(1, $result->token->activationSequence);
        self::assertSame($result->token->generationId, $activator->currentToken()?->generationId);
        self::assertSame([], iterator_to_array($activator->readGeneration($result->token)), 'Genesis carries no entries.');
    }

    #[Test]
    public function genesisIsRecordedAsAnActivationAndMarkedInTheLedger(): void
    {
        new DatabaseConfigurationActivator($this->database, $this->context, $this->authorizer)
            ->activateGenesis('install-init-fixture');

        $rows = iterator_to_array($this->database->query(
            'SELECT operation, is_genesis, activation_sequence FROM waaseyaa_config_activation_v2 WHERE authority_id = ?',
            [$this->context->authorityId],
        ));

        self::assertCount(1, $rows);
        // Genesis truthfully IS an activation, so the verb is unchanged; the
        // additive marker carries the fact that needed recording.
        self::assertSame('activate', (string) $rows[0]['operation']);
        self::assertSame(1, (int) $rows[0]['is_genesis']);
        self::assertSame(1, (int) $rows[0]['activation_sequence']);
    }

    #[Test]
    public function genesisReplaysItsCommittedResultAndRefusesACompetingGeneration(): void
    {
        $activator = new DatabaseConfigurationActivator($this->database, $this->context, $this->authorizer);
        $first = $activator->activateGenesis('install-init-fixture');

        // Same installation request: an interrupted install is safe to retry.
        $replayed = $activator->activateGenesis('install-init-fixture');
        self::assertSame($first->token->generationId, $replayed->token->generationId);
        self::assertSame($first->requestId, $replayed->requestId);

        // A different request against an installed site must refuse rather than
        // mint a second generation.
        try {
            $activator->activateGenesis('install-init-other');
            self::fail('Genesis accepted a competing generation.');
        } catch (ConfigurationActivationConflictException $expected) {
            self::assertStringContainsString('already active', $expected->getMessage());
        }
    }


    private function stringScalar(string $sql): string
    {
        foreach ($this->database->query($sql) as $row) {
            return (string) array_values($row)[0];
        }

        self::fail('Scalar query returned no row.');
    }
}
