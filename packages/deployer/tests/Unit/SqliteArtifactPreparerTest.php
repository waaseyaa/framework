<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Deployer\RuntimeState\FrameworkRuntimeTableCatalogue;
use Waaseyaa\Deployer\RuntimeState\RuntimeTablePolicy;
use Waaseyaa\Deployer\RuntimeState\SqliteArtifactPreparer;

#[CoversClass(FrameworkRuntimeTableCatalogue::class)]
#[CoversClass(SqliteArtifactPreparer::class)]
final class SqliteArtifactPreparerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/waaseyaa-artifact-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function catalogue_assigns_security_state_and_evidence_to_framework_policies(): void
    {
        $definitions = new FrameworkRuntimeTableCatalogue()->definitions();

        self::assertSame(RuntimeTablePolicy::Artifact, $definitions['cache_render']->policy);
        self::assertSame(RuntimeTablePolicy::Artifact, $definitions['embeddings']->policy);
        self::assertSame(RuntimeTablePolicy::Artifact, $definitions['search_index']->policy);
        self::assertSame(RuntimeTablePolicy::Preserve, $definitions['audit_retention_policy']->policy);
        self::assertSame(RuntimeTablePolicy::IdentityMerge, $definitions['user']->policy);
        self::assertSame(['blocker_id', 'blocked_id'], $definitions['user_block']->accountReferenceColumns);
        self::assertSame(RuntimeTablePolicy::Preserve, $definitions['auth_tokens']->policy);
        self::assertSame(RuntimeTablePolicy::Preserve, $definitions['oidc_signing_key']->policy);
        self::assertSame(['account_id'], $definitions['agent_run']->accountReferenceColumns);
        self::assertSame(RuntimeTablePolicy::AppendOnly, $definitions['audit_event']->policy);
        self::assertSame(RuntimeTablePolicy::AppendOnly, $definitions['audit_checkpoint_succession']->policy);
        self::assertSame(RuntimeTablePolicy::AppendOnly, $definitions['audit_checkpoint_succession_pruned']->policy);
        self::assertSame(RuntimeTablePolicy::AppendOnly, $definitions['privileged_read_ledger']->policy);
        self::assertSame(RuntimeTablePolicy::AppendOnly, $definitions['strict_audit_ledger']->policy);
        self::assertSame(RuntimeTablePolicy::AppendOnly, $definitions['mcp_approval_event']->policy);
    }

    #[Test]
    public function catalogue_claims_secret_custody_runtime_tables(): void
    {
        $definitions = new FrameworkRuntimeTableCatalogue()->definitions();

        $expected = [
            'cache_generation' => RuntimeTablePolicy::Preserve,
            'oidc_token_custody_sequence' => RuntimeTablePolicy::Preserve,
            'waaseyaa_application_master_rekey' => RuntimeTablePolicy::Preserve,
            'waaseyaa_application_master_rekey_adapter' => RuntimeTablePolicy::Preserve,
            'waaseyaa_application_master_rekey_event' => RuntimeTablePolicy::AppendOnly,
            'waaseyaa_application_master_rekey_failure' => RuntimeTablePolicy::Preserve,
            'waaseyaa_application_master_rekey_gate' => RuntimeTablePolicy::AppendOnly,
            'waaseyaa_application_master_rekey_purpose' => RuntimeTablePolicy::AppendOnly,
            'waaseyaa_application_master_rekey_rollback_verification' => RuntimeTablePolicy::AppendOnly,
            'waaseyaa_application_master_rekey_verification' => RuntimeTablePolicy::AppendOnly,
            'waaseyaa_application_master_version' => RuntimeTablePolicy::Preserve,
        ];
        foreach ($expected as $table => $policy) {
            self::assertArrayHasKey($table, $definitions, sprintf('Custody runtime table "%s" is not catalogued.', $table));
            self::assertSame($policy, $definitions[$table]->policy, $table);
            self::assertSame([], $definitions[$table]->accountReferenceColumns, $table);
        }
    }

    #[Test]
    public function populated_security_runtime_stores_survive_while_artifact_content_changes(): void
    {
        $schema = [
            'CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)',
            'CREATE TABLE user (uid INTEGER PRIMARY KEY, name TEXT NOT NULL, secret TEXT NOT NULL)',
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER, created_by INTEGER, secret_hash TEXT NOT NULL)',
            'CREATE TABLE oidc_access_token (jti TEXT PRIMARY KEY, account_id TEXT NOT NULL, token_hash TEXT NOT NULL)',
            'CREATE TABLE audit_retention_policy (id INTEGER PRIMARY KEY, kind_pattern TEXT NOT NULL)',
            'CREATE TABLE audit_event (id INTEGER PRIMARY KEY, actor_uid INTEGER, event TEXT NOT NULL)',
            'CREATE TABLE privileged_read_ledger (id INTEGER PRIMARY KEY, event TEXT NOT NULL)',
            'CREATE TABLE strict_audit_ledger (id INTEGER PRIMARY KEY, actor_uid INTEGER, event TEXT NOT NULL)',
            'CREATE TABLE mcp_approval_event (id INTEGER PRIMARY KEY, operator_uid INTEGER, event_type TEXT NOT NULL)',
        ];
        $current = $this->database('current.sqlite', $schema, [
            "INSERT INTO content VALUES (1, 'old content')",
            "INSERT INTO user VALUES (7, 'Operator', 'serving-user-secret')",
            "INSERT INTO auth_tokens VALUES ('auth-live', 7, 7, 'serving-auth-hash')",
            "INSERT INTO oidc_access_token VALUES ('oidc-live', '7', 'serving-oidc-hash')",
            "INSERT INTO audit_retention_policy VALUES (1, 'security.*')",
            "INSERT INTO audit_event VALUES (10, 7, 'serving-audit')",
            "INSERT INTO audit_event VALUES (11, 7, 'serving-audit-2')",
            "INSERT INTO privileged_read_ledger VALUES (20, 'serving-read')",
            "INSERT INTO strict_audit_ledger VALUES (30, 7, 'serving-strict')",
            "INSERT INTO mcp_approval_event VALUES (40, 7, 'serving-approval')",
        ]);
        $artifact = $this->database('artifact.sqlite', $schema, [
            "INSERT INTO content VALUES (1, 'new content')",
            "INSERT INTO user VALUES (1, 'Author', 'artifact-user-secret')",
            "INSERT INTO audit_retention_policy VALUES (2, 'artifact.*')",
            "INSERT INTO audit_event VALUES (1, 1, 'artifact-audit')",
            "INSERT INTO privileged_read_ledger VALUES (1, 'artifact-read')",
            "INSERT INTO strict_audit_ledger VALUES (1, 1, 'artifact-strict')",
            "INSERT INTO mcp_approval_event VALUES (1, 1, 'artifact-approval')",
        ]);
        $candidate = $this->directory . '/candidate.sqlite';

        $report = new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $candidate,
            ['content'],
        );

        $pdo = $this->open($candidate);
        self::assertSame('new content', $pdo->query('SELECT title FROM content')->fetchColumn());
        self::assertSame([[1, 'Author', 'artifact-user-secret'], [7, 'Operator', 'serving-user-secret']], $pdo->query('SELECT * FROM user ORDER BY uid')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([['auth-live', 7, 7, 'serving-auth-hash']], $pdo->query('SELECT * FROM auth_tokens')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([['oidc-live', '7', 'serving-oidc-hash']], $pdo->query('SELECT * FROM oidc_access_token')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([[1, 'security.*']], $pdo->query('SELECT * FROM audit_retention_policy')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([[10, 7, 'serving-audit'], [11, 7, 'serving-audit-2']], $pdo->query('SELECT * FROM audit_event ORDER BY id')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([[20, 'serving-read']], $pdo->query('SELECT * FROM privileged_read_ledger')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([[30, 7, 'serving-strict']], $pdo->query('SELECT * FROM strict_audit_ledger')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([[40, 7, 'serving-approval']], $pdo->query('SELECT * FROM mcp_approval_event')->fetchAll(\PDO::FETCH_NUM));
        foreach (['audit_event', 'privileged_read_ledger', 'strict_audit_ledger', 'mcp_approval_event'] as $table) {
            self::assertSame($report->tables[$table]->beforeRows, $report->tables[$table]->afterRows);
            self::assertSame($report->tables[$table]->beforeDigest, $report->tables[$table]->afterDigest);
        }
        $encodedReport = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['serving-user-secret', 'serving-auth-hash', 'serving-oidc-hash', 'serving-audit', 'serving-approval'] as $secret) {
            self::assertStringNotContainsString($secret, $encodedReport);
        }
    }

    #[Test]
    public function candidate_gets_artifact_content_serving_runtime_rows_and_destination_winning_identities(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)',
            'CREATE TABLE user (uid INTEGER PRIMARY KEY, name TEXT NOT NULL, secret TEXT NOT NULL)',
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER, created_by INTEGER, secret_hash TEXT NOT NULL)',
            'CREATE TABLE audit_event (id INTEGER PRIMARY KEY, actor_uid INTEGER, event TEXT NOT NULL)',
        ], [
            "INSERT INTO content VALUES (1, 'old')",
            "INSERT INTO user VALUES (1, 'Imported current', 'current-secret')",
            "INSERT INTO user VALUES (2, 'Operator', 'operator-secret')",
            "INSERT INTO auth_tokens VALUES ('token-live', 2, 2, 'hash-live')",
            "INSERT INTO audit_event VALUES (10, 2, 'live-evidence')",
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)',
            'CREATE TABLE user (uid INTEGER PRIMARY KEY, name TEXT NOT NULL, secret TEXT NOT NULL)',
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER, created_by INTEGER, secret_hash TEXT NOT NULL)',
            'CREATE TABLE audit_event (id INTEGER PRIMARY KEY, actor_uid INTEGER, event TEXT NOT NULL)',
        ], [
            "INSERT INTO content VALUES (1, 'new')",
            "INSERT INTO user VALUES (1, 'Imported artifact', 'artifact-secret')",
            "INSERT INTO user VALUES (3, 'New author', 'new-secret')",
            "INSERT INTO audit_event VALUES (1, 1, 'build-evidence')",
        ]);
        $candidate = $this->directory . '/candidate.sqlite';

        $report = new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            currentDatabase: $current,
            artifactDatabase: $artifact,
            candidateDatabase: $candidate,
            applicationArtifactTables: ['content'],
        );

        $pdo = $this->open($candidate);
        self::assertSame('new', $pdo->query('SELECT title FROM content WHERE id = 1')->fetchColumn());
        self::assertSame([
            [1, 'Imported current', 'current-secret'],
            [2, 'Operator', 'operator-secret'],
            [3, 'New author', 'new-secret'],
        ], $pdo->query('SELECT uid, name, secret FROM user ORDER BY uid')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([['token-live', 2, 2, 'hash-live']], $pdo->query('SELECT * FROM auth_tokens')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([[10, 2, 'live-evidence']], $pdo->query('SELECT * FROM audit_event')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame(FrameworkRuntimeTableCatalogue::VERSION, $report->catalogueVersion);
        self::assertSame(1, $report->tables['audit_event']->beforeRows);
        self::assertSame(1, $report->tables['audit_event']->afterRows);
        self::assertSame($report->tables['audit_event']->beforeDigest, $report->tables['audit_event']->afterDigest);
        self::assertStringNotContainsString('operator-secret', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('live-evidence', json_encode($report, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function serving_only_lazy_runtime_table_is_cloned_with_indexes_and_rows(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            'CREATE TABLE mcp_approval_event (id INTEGER PRIMARY KEY, operator_uid INTEGER, event_type TEXT NOT NULL)',
            'CREATE UNIQUE INDEX approval_once ON mcp_approval_event (id, event_type)',
        ], [
            "INSERT INTO user VALUES (7, 'Operator')",
            "INSERT INTO mcp_approval_event VALUES (11, 7, 'decided')",
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY, name TEXT NOT NULL)',
        ], [
            "INSERT INTO user VALUES (1, 'Author')",
        ]);
        $candidate = $this->directory . '/candidate.sqlite';

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $candidate,
            [],
        );

        $pdo = $this->open($candidate);
        self::assertSame('decided', $pdo->query('SELECT event_type FROM mcp_approval_event WHERE id = 11')->fetchColumn());
        self::assertSame('approval_once', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'approval_once'")->fetchColumn());
    }

    #[Test]
    public function incompatible_runtime_schemas_fail_before_candidate_commit(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER)',
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, account_uid INTEGER NOT NULL)',
        ]);
        $candidate = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incompatible runtime schema for auth_tokens');

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $candidate,
            [],
        );
    }

    #[Test]
    public function runtime_check_constraint_changes_are_incompatible_even_when_columns_match(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL CHECK (user_id > 0))',
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL CHECK (user_id >= 0))',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incompatible runtime schema for auth_tokens');

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $this->directory . '/candidate.sqlite',
            [],
        );
    }

    #[Test]
    public function partial_index_predicate_changes_are_incompatible(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, revoked INTEGER NOT NULL)',
            'CREATE INDEX active_tokens ON auth_tokens (user_id) WHERE revoked = 0',
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, revoked INTEGER NOT NULL)',
            'CREATE INDEX active_tokens ON auth_tokens (user_id) WHERE revoked = 1',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incompatible runtime schema for auth_tokens');

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $this->directory . '/candidate.sqlite',
            [],
        );
    }

    #[Test]
    public function a_database_with_live_sqlite_sidecars_fails_before_candidate_creation(): void
    {
        $current = $this->database('current.sqlite', ['CREATE TABLE user (uid INTEGER PRIMARY KEY)']);
        $artifact = $this->database('artifact.sqlite', ['CREATE TABLE user (uid INTEGER PRIMARY KEY)']);
        $writer = $this->open($current);
        self::assertSame('wal', $writer->query('PRAGMA journal_mode = WAL')->fetchColumn());
        $writer->exec('PRAGMA wal_autocheckpoint = 0');
        $writer->exec('INSERT INTO user VALUES (7)');
        self::assertFileExists($current . '-wal');

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $current,
                $artifact,
                $this->directory . '/candidate.sqlite',
                [],
            );
            self::fail('Preparation accepted an input whose committed frames still lived in WAL.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('committed frames in WAL', $error->getMessage());
            self::assertFileDoesNotExist($this->directory . '/candidate.sqlite');
        } finally {
            $writer = null;
        }
    }

    #[Test]
    public function zero_length_wal_and_shm_metadata_do_not_make_a_clean_artifact_unsafe(): void
    {
        $current = $this->database('current.sqlite', ['CREATE TABLE user (uid INTEGER PRIMARY KEY)']);
        $artifact = $this->database('artifact.sqlite', ['CREATE TABLE user (uid INTEGER PRIMARY KEY)']);
        file_put_contents($artifact . '-wal', '');
        file_put_contents($artifact . '-shm', str_repeat("\0", 32_768));
        $candidate = $this->directory . '/candidate.sqlite';

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $candidate,
            [],
        );

        self::assertFileExists($candidate);
        self::assertFileDoesNotExist($candidate . '-wal');
        self::assertFileDoesNotExist($candidate . '-shm');
    }

    #[Test]
    public function dangling_runtime_account_reference_fails_closed(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL)',
        ], [
            "INSERT INTO auth_tokens VALUES ('orphan', 99)",
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE auth_tokens (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL)',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('auth_tokens.user_id references missing user 99');

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $this->directory . '/candidate.sqlite',
            [],
        );
    }

    #[Test]
    public function every_declared_user_block_account_reference_is_validated(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE user_block (id INTEGER PRIMARY KEY, blocker_id INTEGER NOT NULL, blocked_id INTEGER NOT NULL)',
        ], [
            'INSERT INTO user VALUES (1)',
            'INSERT INTO user_block VALUES (1, 1, 99)',
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE user_block (id INTEGER PRIMARY KEY, blocker_id INTEGER NOT NULL, blocked_id INTEGER NOT NULL)',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('user_block.blocked_id references missing user 99');

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $this->directory . '/candidate.sqlite',
            [],
        );
    }

    #[Test]
    public function declared_audit_principal_sentinels_do_not_require_persisted_user_rows(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE audit_event (id INTEGER PRIMARY KEY, account_uid INTEGER NOT NULL, actor_uid INTEGER)',
        ], [
            'INSERT INTO audit_event VALUES (1, 0, NULL)',
            'INSERT INTO audit_event VALUES (2, ' . PHP_INT_MAX . ', ' . PHP_INT_MAX . ')',
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE audit_event (id INTEGER PRIMARY KEY, account_uid INTEGER NOT NULL, actor_uid INTEGER)',
        ]);
        $candidate = $this->directory . '/candidate.sqlite';

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $candidate,
            [],
        );

        self::assertSame(2, $this->open($candidate)->query('SELECT COUNT(*) FROM audit_event')->fetchColumn());
    }

    #[Test]
    public function undeclared_sentinel_in_an_authentication_owner_column_fails_closed(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE auth_bearer_token (id TEXT PRIMARY KEY, account_uid INTEGER NOT NULL)',
        ], [
            'INSERT INTO auth_bearer_token VALUES (\'bad-owner\', ' . PHP_INT_MAX . ')',
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE auth_bearer_token (id TEXT PRIMARY KEY, account_uid INTEGER NOT NULL)',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('auth_bearer_token.account_uid references missing user ' . PHP_INT_MAX);

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $this->directory . '/candidate.sqlite',
            [],
        );
    }

    #[Test]
    public function unknown_artifact_table_fails_closed(): void
    {
        $current = $this->database('current.sqlite', ['CREATE TABLE user (uid INTEGER PRIMARY KEY)']);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE user (uid INTEGER PRIMARY KEY)',
            'CREATE TABLE surprise (id INTEGER PRIMARY KEY)',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown artifact tables: surprise');

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $this->directory . '/candidate.sqlite',
            [],
        );
    }

    #[Test]
    public function explicitly_retired_empty_application_table_is_omitted_from_the_candidate(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE content (id INTEGER PRIMARY KEY)',
            'CREATE TABLE retired_pipeline (id TEXT PRIMARY KEY)',
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE content (id INTEGER PRIMARY KEY)',
        ]);
        $candidate = $this->directory . '/candidate.sqlite';

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $candidate,
            ['content'],
            ['retired_pipeline'],
        );

        self::assertFalse($this->open($candidate)->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'retired_pipeline'")->fetchColumn());
    }

    #[Test]
    public function populated_retired_application_table_fails_closed(): void
    {
        $current = $this->database('current.sqlite', [
            'CREATE TABLE content (id INTEGER PRIMARY KEY)',
            'CREATE TABLE retired_pipeline (id TEXT PRIMARY KEY)',
        ], [
            "INSERT INTO retired_pipeline VALUES ('must-survive')",
        ]);
        $artifact = $this->database('artifact.sqlite', [
            'CREATE TABLE content (id INTEGER PRIMARY KEY)',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retired application table is not empty: retired_pipeline');

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $current,
            $artifact,
            $this->directory . '/candidate.sqlite',
            ['content'],
            ['retired_pipeline'],
        );
    }

    /** @param list<string> $schema @param list<string> $rows */
    private function database(string $name, array $schema, array $rows = []): string
    {
        $path = $this->directory . '/' . $name;
        $pdo = $this->open($path);
        foreach ([...$schema, ...$rows] as $sql) {
            $pdo->exec($sql);
        }

        return $path;
    }

    private function open(string $path): \PDO
    {
        return new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }
}
