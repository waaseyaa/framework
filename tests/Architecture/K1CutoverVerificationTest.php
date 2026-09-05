<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Disposable proofs that the K1 cutover verifier compares Panel 8 results
 * with projection identity and fails closed on missing or mismatched provenance.
 */
#[CoversNothing]
final class K1CutoverVerificationTest extends TestCase
{
    private const SCRIPT = 'bin/verify-k1-delivery-cutover';
    private const PROJECTION_ID = 'delivery-agent-events/v1';
    private const SECRET = 'fixture-password-must-not-print';
    private const SOURCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const LEDGER = '1111111111111111111111111111111111111111111111111111111111111111';
    private const REPLAY = '2222222222222222222222222222222222222222222222222222222222222222';
    private const MANIFEST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function matching_panel_and_identity_pass_without_printing_credentials(): void
    {
        $fixture = $this->matchingFixture();
        $result = $this->execute($fixture);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('PASS', $result['stdout']);
        $this->assertNoSecrets($result, $fixture['dsn']);
    }

    #[Test]
    public function missing_provenance_fails_verification(): void
    {
        $fixture = $this->emptyFixture();
        $result = $this->execute($fixture);

        self::assertSame(1, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('missing', strtolower($result['stderr'] . $result['stdout']));
        $this->assertNoSecrets($result, $fixture['dsn']);
    }

    #[Test]
    public function mismatched_source_sha_version_generation_count_and_hashes_are_reported(): void
    {
        $fixture = $this->mismatchedFixture();
        $result = $this->execute($fixture);
        $output = strtolower($result['stderr'] . $result['stdout']);

        self::assertSame(1, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('source', $output);
        self::assertStringContainsString('projector', $output);
        self::assertStringContainsString('generation', $output);
        self::assertStringContainsString('event', $output);
        self::assertTrue(
            str_contains($output, 'ledger') || str_contains($output, 'replay') || str_contains($output, 'manifest'),
            $result['stderr'] . $result['stdout'],
        );
        $this->assertNoSecrets($result, $fixture['dsn']);
    }

    #[Test]
    public function credential_free_self_test_covers_matching_missing_and_mismatched_fixtures(): void
    {
        $result = $this->runProcess([PHP_BINARY, $this->root . '/' . self::SCRIPT, '--self-test']);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('PASS', $result['stdout']);
        self::assertStringContainsString('matching', strtolower($result['stdout']));
        self::assertStringContainsString('missing', strtolower($result['stdout']));
        self::assertStringContainsString('mismatched', strtolower($result['stdout']));
        $this->assertNoSecrets($result, 'sqlite:');
    }

    /** @param array{dsn: string, dashboard: string} $fixture */
    private function execute(array $fixture): array
    {
        return $this->runProcess(
            [PHP_BINARY, $this->root . '/' . self::SCRIPT, '--dashboard=' . $fixture['dashboard']],
            [
                'WAASEYAA_DELIVERY_TELEMETRY_DSN' => $fixture['dsn'],
                'WAASEYAA_DELIVERY_TELEMETRY_DB_USER' => 'fixture-user',
                'WAASEYAA_DELIVERY_TELEMETRY_DB_PASSWORD' => self::SECRET,
            ],
        );
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, array $env = []): array
    {
        $process = new Process($command, $this->root, $env);
        $process->run();

        return ['exit' => $process->getExitCode() ?? 1, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }

    /** @param array{exit: int, stdout: string, stderr: string} $result */
    private function assertNoSecrets(array $result, string $dsn): void
    {
        $output = $result['stdout'] . $result['stderr'];
        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertStringNotContainsString('fixture-user', $output);
        self::assertStringNotContainsString($dsn, $output);
        self::assertStringNotContainsString('WAASEYAA_DELIVERY_TELEMETRY_DB_PASSWORD', $output);
    }

    /** @return array{dsn: string, dashboard: string} */
    private function matchingFixture(): array
    {
        return $this->writeFixture($this->identityPanelSql(), populated: true);
    }

    /** @return array{dsn: string, dashboard: string} */
    private function emptyFixture(): array
    {
        return $this->writeFixture($this->identityPanelSql(), populated: false);
    }

    /** @return array{dsn: string, dashboard: string} */
    private function mismatchedFixture(): array
    {
        $sql = str_replace(
            [
                'COALESCE(s.source_commit_sha, \'unknown\')',
                'COALESCE(s.projector_version, \'unknown\')',
                'COALESCE(s.generation, \'unknown\')',
                'COALESCE(s.event_count, \'unknown\')',
                'COALESCE(s.ledger_sha256, \'unknown\')',
                'COALESCE(i.replay_sha256, \'unknown\')',
                'COALESCE(i.batch_manifest_sha256, \'unknown\')',
            ],
            [
                '\'ffffffffffffffffffffffffffffffffffffffff\'',
                '\'9\'',
                '\'99\'',
                '\'1\'',
                '\'' . str_repeat('c', 64) . '\'',
                '\'' . str_repeat('d', 64) . '\'',
                '\'' . str_repeat('e', 64) . '\'',
            ],
            $this->identityPanelSql(),
        );

        return $this->writeFixture($sql, populated: true);
    }

    /** @return array{dsn: string, dashboard: string} */
    private function writeFixture(string $sql, bool $populated): array
    {
        $directory = sys_get_temp_dir() . '/waaseyaa-k1-cutover-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700));
        $database = $directory . '/projection.sqlite';
        $dashboard = $directory . '/dashboard.json';
        $pdo = new PDO('sqlite:' . $database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->installSchema($pdo);
        if ($populated) {
            $this->insertIdentity($pdo);
        }
        file_put_contents($dashboard, json_encode([
            'dashboard' => [
                'panels' => [
                    [
                        'id' => 8,
                        'title' => 'Governed projection identity',
                        'targets' => [['rawSql' => $sql]],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return ['dsn' => 'sqlite:' . $database, 'dashboard' => $dashboard];
    }

    private function installSchema(PDO $pdo): void
    {
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
    }

    private function insertIdentity(PDO $pdo): void
    {
        $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_state (
            projection_id, contract_version, source_commit_sha, schema_sha256, ledger_sha256,
            ledger_bytes, event_count, first_event_id, last_event_id, generation, projector_version, projected_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            self::PROJECTION_ID,
            'delivery-agent-event/v1',
            self::SOURCE,
            str_repeat('a', 64),
            self::LEDGER,
            128,
            15,
            null,
            null,
            4,
            2,
            '2026-09-05T14:00:00Z',
        ]);
        $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_identity_v2 (
            projection_id, batch_manifest, batch_manifest_sha256, event_schema_sha256,
            batch_schema_sha256, freeze_sha256, replay_sha256
        ) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            self::PROJECTION_ID,
            '[]',
            self::MANIFEST,
            str_repeat('a', 64),
            null,
            null,
            self::REPLAY,
        ]);
    }

    private function identityPanelSql(): string
    {
        return 'SELECT COALESCE(s.source_commit_sha, \'unknown\') AS `Source commit`, COALESCE(s.projector_version, \'unknown\') AS `Projector version`, COALESCE(s.generation, \'unknown\') AS `Generation`, COALESCE(s.event_count, \'unknown\') AS `Events`, COALESCE(s.projected_at, \'unknown\') AS `Projected (UTC)`, COALESCE(s.ledger_sha256, \'unknown\') AS `Frozen v1 ledger SHA-256`, COALESCE(i.replay_sha256, \'unknown\') AS `Complete replay SHA-256`, COALESCE(i.batch_manifest_sha256, \'unknown\') AS `Batch manifest SHA-256` FROM (SELECT \'delivery-agent-events/v1\' AS projection_id) AS p LEFT JOIN waaseyaa_delivery_projection_state AS s ON s.projection_id = p.projection_id LEFT JOIN waaseyaa_delivery_projection_identity_v2 AS i ON i.projection_id = p.projection_id';
    }
}
