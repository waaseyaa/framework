<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Disposable SQLite proofs that the tracked K1 identity panel binds Codex's
 * v2 projection-identity schema without reinterpreting missing hashes.
 */
#[CoversNothing]
final class K1BatchProvenanceDashboardTest extends TestCase
{
    private const DASHBOARD = 'ops/observability/grafana/waaseyaa-k1-delivery-flow.json';
    private const PROJECTION_ID = 'delivery-agent-events/v1';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function identity_panel_sql_targets_v2_identity_and_keeps_ledger_and_replay_distinct(): void
    {
        $sql = $this->identityPanelSql();
        self::assertStringContainsString('waaseyaa_delivery_projection_state', $sql);
        self::assertStringContainsString('waaseyaa_delivery_projection_identity_v2', $sql);
        self::assertStringContainsString('LEFT JOIN', $sql);
        self::assertStringContainsString('`Frozen v1 ledger SHA-256`', $sql);
        self::assertStringContainsString('`Complete replay SHA-256`', $sql);
        self::assertStringContainsString('`Batch manifest SHA-256`', $sql);
        self::assertStringContainsString('`Projector version`', $sql);
        self::assertStringContainsString("'unknown'", $sql);
        self::assertStringNotContainsString('waaseyaa_agent_events', $sql);
    }

    #[Test]
    public function populated_identity_returns_source_sha_version_generation_counts_and_distinct_hashes(): void
    {
        $pdo = $this->fixtureDatabase();
        $this->insertState($pdo, [
            'source_commit_sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'ledger_sha256' => str_repeat('1', 64),
            'event_count' => 15,
            'generation' => 4,
            'projector_version' => 2,
            'projected_at' => '2026-09-05T14:00:00Z',
        ]);
        $this->insertIdentity($pdo, [
            'batch_manifest_sha256' => str_repeat('b', 64),
            'replay_sha256' => str_repeat('2', 64),
        ]);

        $row = $this->queryIdentityPanel($pdo);
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $row['Source commit']);
        self::assertSame('2', (string) $row['Projector version']);
        self::assertSame('4', (string) $row['Generation']);
        self::assertSame('15', (string) $row['Events']);
        self::assertSame('2026-09-05T14:00:00Z', $row['Projected (UTC)']);
        self::assertSame(str_repeat('1', 64), $row['Frozen v1 ledger SHA-256']);
        self::assertSame(str_repeat('2', 64), $row['Complete replay SHA-256']);
        self::assertSame(str_repeat('b', 64), $row['Batch manifest SHA-256']);
        self::assertNotSame($row['Frozen v1 ledger SHA-256'], $row['Complete replay SHA-256']);
    }

    #[Test]
    public function missing_identity_row_keeps_state_and_shows_unknown_batch_and_replay_hashes(): void
    {
        $pdo = $this->fixtureDatabase();
        $this->insertState($pdo, [
            'source_commit_sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'ledger_sha256' => str_repeat('c', 64),
            'event_count' => 9,
            'generation' => 1,
            'projector_version' => 1,
            'projected_at' => '2026-09-04T00:00:00Z',
        ]);

        $row = $this->queryIdentityPanel($pdo);
        self::assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $row['Source commit']);
        self::assertSame('1', (string) $row['Projector version']);
        self::assertSame(str_repeat('c', 64), $row['Frozen v1 ledger SHA-256']);
        self::assertSame('unknown', $row['Complete replay SHA-256']);
        self::assertSame('unknown', $row['Batch manifest SHA-256']);
    }

    #[Test]
    public function absent_projection_tables_fail_instead_of_rendering_unknown(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('no such table: waaseyaa_delivery_projection_state');
        $this->queryIdentityPanel($pdo);
    }

    #[Test]
    public function missing_state_and_identity_remain_visibly_unknown(): void
    {
        $pdo = $this->fixtureDatabase();
        $row = $this->queryIdentityPanel($pdo);

        self::assertSame('unknown', $row['Source commit']);
        self::assertSame('unknown', (string) $row['Projector version']);
        self::assertSame('unknown', (string) $row['Generation']);
        self::assertSame('unknown', (string) $row['Events']);
        self::assertSame('unknown', $row['Projected (UTC)']);
        self::assertSame('unknown', $row['Frozen v1 ledger SHA-256']);
        self::assertSame('unknown', $row['Complete replay SHA-256']);
        self::assertSame('unknown', $row['Batch manifest SHA-256']);
    }

    private function identityPanelSql(): string
    {
        $path = $this->root . '/' . self::DASHBOARD;
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        foreach ($decoded['dashboard']['panels'] ?? [] as $panel) {
            if (($panel['id'] ?? null) === 8) {
                $sql = $panel['targets'][0]['rawSql'] ?? null;
                self::assertIsString($sql);

                return $sql;
            }
        }
        self::fail('Governed projection identity panel id 8 is missing.');
    }

    private function fixtureDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE waaseyaa_delivery_projection_state (
            projection_id TEXT PRIMARY KEY,
            contract_version TEXT NOT NULL,
            source_commit_sha TEXT NOT NULL,
            schema_sha256 TEXT NOT NULL,
            ledger_sha256 TEXT NOT NULL,
            ledger_bytes INTEGER NOT NULL,
            event_count INTEGER NOT NULL,
            first_event_id TEXT NULL,
            last_event_id TEXT NULL,
            generation INTEGER NOT NULL,
            projector_version INTEGER NOT NULL,
            projected_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE waaseyaa_delivery_projection_identity_v2 (
            projection_id TEXT PRIMARY KEY,
            batch_manifest TEXT NOT NULL,
            batch_manifest_sha256 TEXT NOT NULL,
            event_schema_sha256 TEXT NOT NULL,
            batch_schema_sha256 TEXT NULL,
            freeze_sha256 TEXT NULL,
            replay_sha256 TEXT NOT NULL
        )');

        return $pdo;
    }

    /** @param array{source_commit_sha: string, ledger_sha256: string, event_count: int, generation: int, projector_version: int, projected_at: string} $values */
    private function insertState(PDO $pdo, array $values): void
    {
        $statement = $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_state (
            projection_id, contract_version, source_commit_sha, schema_sha256, ledger_sha256,
            ledger_bytes, event_count, first_event_id, last_event_id, generation, projector_version, projected_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            self::PROJECTION_ID,
            'delivery-agent-event/v1',
            $values['source_commit_sha'],
            str_repeat('a', 64),
            $values['ledger_sha256'],
            128,
            $values['event_count'],
            null,
            null,
            $values['generation'],
            $values['projector_version'],
            $values['projected_at'],
        ]);
    }

    /** @param array{batch_manifest_sha256: string, replay_sha256: string} $values */
    private function insertIdentity(PDO $pdo, array $values): void
    {
        $statement = $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_identity_v2 (
            projection_id, batch_manifest, batch_manifest_sha256, event_schema_sha256,
            batch_schema_sha256, freeze_sha256, replay_sha256
        ) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            self::PROJECTION_ID,
            '[]',
            $values['batch_manifest_sha256'],
            str_repeat('a', 64),
            null,
            null,
            $values['replay_sha256'],
        ]);
    }

    /** @return array<string, mixed> */
    private function queryIdentityPanel(PDO $pdo): array
    {
        $row = $pdo->query($this->identityPanelSql())->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
