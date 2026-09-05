<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class K1CutoverVerificationTest extends TestCase
{
    private const SCRIPT = 'bin/verify-k1-delivery-cutover';
    private const PROJECTION_ID = 'delivery-agent-events/v1';
    private const TOKEN = 'fixture-grafana-token-must-not-print';
    private const BASIC_USER = 'fixture-grafana-user';
    private const BASIC_PASSWORD = 'fixture-grafana-password-must-not-print';
    private const SOURCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SCHEMA = '3333333333333333333333333333333333333333333333333333333333333333';
    private const LEDGER = '1111111111111111111111111111111111111111111111111111111111111111';
    private const REPLAY = '2222222222222222222222222222222222222222222222222222222222222222';
    private const MANIFEST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const BATCH_SCHEMA = '4444444444444444444444444444444444444444444444444444444444444444';
    private const FREEZE = '5555555555555555555555555555555555555555555555555555555555555555';
    private string $root = '';
    private ?Filesystem $filesystem = null;

    /** @var list<string> */
    private array $directories = [];

    /** @var list<Process> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->filesystem = new Filesystem();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            if ($server->isRunning()) {
                $server->stop();
            }
        }
        foreach ($this->directories as $directory) {
            $this->filesystem()->remove($directory);
        }
    }

    #[Test]
    public function matching_live_grafana_row_projection_and_apply_receipt_pass(): void
    {
        $fixture = $this->fixture();
        $result = $this->execute($fixture);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertSame("k1 delivery cutover: PASS\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
        $this->assertNoSecrets($result, $fixture);
        $requests = $this->requests($fixture);
        self::assertCount(2, $requests);
        self::assertSame(['GET', 'POST'], array_column($requests, 'method'));
        self::assertSame(['/api/dashboards/uid/waaseyaa-k1-flow', '/api/ds/query'], array_column($requests, 'path'));
        self::assertSame([true, true], array_column($requests, 'authorization_matches'));
        $panel = $this->trackedPanel();
        $trackedSql = $panel['targets'][0]['rawSql'] ?? null;
        self::assertIsString($trackedSql);
        self::assertStringContainsString('i.replay_sha256', $trackedSql);
        self::assertStringContainsString('i.batch_manifest_sha256', $trackedSql);
        self::assertSame($trackedSql, $requests[1]['body']['queries'][0]['rawSql'] ?? null);
        self::assertSame('waaseyaa-devlake-mysql', $requests[1]['body']['queries'][0]['datasource']['uid'] ?? null);
    }

    #[Test]
    public function matching_verify_receipt_and_basic_auth_pass(): void
    {
        $fixture = $this->fixture(
            receipt: ['operation' => 'verify', 'outcome' => 'no_op'],
            basicAuth: true,
        );
        $result = $this->execute($fixture);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('PASS', $result['stdout']);
        self::assertSame([true, true], array_column($this->requests($fixture), 'authorization_matches'));
        $this->assertNoSecrets($result, $fixture);
    }

    #[Test]
    public function every_projection_identity_field_is_bound_to_the_receipt_independently(): void
    {
        $fixture = $this->fixture();
        $cases = [
            'source_commit_sha' => 'Source commit',
            'projector_version' => 'Projector version',
            'generation' => 'Generation',
            'event_count' => 'Events',
            'schema_sha256' => 'Schema SHA-256',
            'ledger_sha256' => 'Frozen v1 ledger SHA-256',
            'event_schema_sha256' => 'Event schema SHA-256',
            'batch_schema_sha256' => 'Batch schema SHA-256',
            'freeze_sha256' => 'Freeze SHA-256',
            'replay_sha256' => 'Complete replay SHA-256',
            'batch_manifest_sha256' => 'Batch manifest SHA-256',
        ];
        foreach ($cases as $field => $label) {
            $identity = $this->identity();
            $identity[$field] = in_array($field, ['projector_version', 'generation', 'event_count'], true)
                ? 99
                : str_repeat('f', $field === 'source_commit_sha' ? 40 : 64);
            $this->seedIdentity($fixture['pdo'], $identity);
            $result = $this->execute($fixture);

            self::assertSame(1, $result['exit'], $field . "\n" . $result['stderr'] . $result['stdout']);
            self::assertStringContainsString('mismatch: projection ' . $label, $result['stdout'], $field);
            $this->assertNoSecrets($result, $fixture);
        }
    }

    #[Test]
    public function every_grafana_field_is_bound_to_the_receipt_and_unknown_is_refused(): void
    {
        $fixture = $this->fixture();
        $fields = $this->grafanaRow();
        unset($fields['Projected (UTC)']);
        foreach (array_keys($fields) as $label) {
            $row = $this->grafanaRow();
            $row[$label] = in_array($label, ['Projector version', 'Generation', 'Events'], true)
                ? 99
                : str_repeat('f', $label === 'Source commit' ? 40 : 64);
            $this->writeServerConfig($fixture, row: $row);
            $result = $this->execute($fixture);

            self::assertSame(1, $result['exit'], $label . "\n" . $result['stderr'] . $result['stdout']);
            self::assertStringContainsString('mismatch: Grafana ' . $label, $result['stdout'], $label);
        }

        $row = $this->grafanaRow();
        $row['Complete replay SHA-256'] = 'unknown';
        $this->writeServerConfig($fixture, row: $row);
        $missing = $this->execute($fixture);
        self::assertSame(1, $missing['exit']);
        self::assertStringContainsString('missing provenance: Grafana Complete replay SHA-256', $missing['stdout']);
    }

    #[Test]
    public function deployed_panel_sql_and_datasource_must_match_the_tracked_panel_exactly(): void
    {
        $fixture = $this->fixture();
        $dashboard = $this->trackedDashboard();
        $panelIndex = $this->trackedPanelIndex($dashboard);
        $dashboard['panels'][$panelIndex]['targets'][0]['rawSql'] .= ' ';
        $this->writeServerConfig($fixture, dashboard: $dashboard);
        $sqlMismatch = $this->execute($fixture);
        self::assertSame(1, $sqlMismatch['exit']);
        self::assertStringContainsString('deployed panel 8 SQL does not match tracked SQL', $sqlMismatch['stderr']);

        $dashboard = $this->trackedDashboard();
        $panelIndex = $this->trackedPanelIndex($dashboard);
        $dashboard['panels'][$panelIndex]['datasource']['uid'] = 'other-datasource';
        $this->writeServerConfig($fixture, dashboard: $dashboard);
        $datasourceMismatch = $this->execute($fixture);
        self::assertSame(1, $datasourceMismatch['exit']);
        self::assertStringContainsString('deployed panel 8 datasource does not match tracked datasource', $datasourceMismatch['stderr']);
    }

    #[Test]
    public function stale_consistent_grafana_and_projection_are_refused_by_the_accepted_receipt(): void
    {
        $fixture = $this->fixture(receipt: [
            'source_commit_sha' => str_repeat('f', 40),
            'generation' => 5,
        ]);
        $result = $this->execute($fixture);

        self::assertSame(1, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('mismatch: projection Source commit', $result['stdout']);
        self::assertStringContainsString('mismatch: projection Generation', $result['stdout']);
        self::assertStringContainsString('mismatch: Grafana Source commit', $result['stdout']);
        self::assertStringContainsString('mismatch: Grafana Generation', $result['stdout']);
    }

    #[Test]
    public function receipt_and_connection_inputs_fail_closed_before_external_access(): void
    {
        $fixture = $this->fixture(receipt: ['operation' => 'plan']);
        $invalidReceipt = $this->execute($fixture);
        self::assertSame(2, $invalidReceipt['exit']);
        self::assertStringContainsString('accepted apply or verify receipt', $invalidReceipt['stderr']);

        $fixture = $this->fixture();
        $nonLoopback = $this->execute($fixture, ['WAASEYAA_GRAFANA_URL' => 'http://example.invalid']);
        self::assertSame(2, $nonLoopback['exit']);
        self::assertStringContainsString('Grafana URL must use http or https on a loopback host', $nonLoopback['stderr']);

        unlink($fixture['database']);
        $missingDatabase = $this->execute($fixture);
        self::assertSame(2, $missingDatabase['exit']);
        self::assertStringContainsString('SQLite projection database must already exist', $missingDatabase['stderr']);
        self::assertFileDoesNotExist($fixture['database']);

        self::assertSame([], $this->requests($fixture));
    }

    #[Test]
    public function public_dashboard_override_is_rejected(): void
    {
        $fixture = $this->fixture();
        $result = $this->runProcess(
            [PHP_BINARY, $fixture['application'] . '/' . self::SCRIPT, '--dashboard=/tmp/foreign.json'],
            $fixture['application'],
            $this->environment($fixture),
        );

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('normal verification requires exactly --receipt=', $result['stderr']);
        self::assertSame([], $this->requests($fixture));
    }

    #[Test]
    public function redirects_are_not_followed_and_query_results_require_exactly_one_row(): void
    {
        $fixture = $this->fixture();
        $this->writeServerConfig($fixture, overrides: [
            'dashboard_status' => 302,
            'dashboard_location' => 'http://example.invalid/credential-target',
        ]);
        $redirect = $this->execute($fixture);
        self::assertSame(1, $redirect['exit']);
        self::assertStringContainsString('Grafana dashboard request failed', $redirect['stderr']);
        $this->assertNoSecrets($redirect, $fixture);
        self::assertCount(1, $this->requests($fixture));

        $this->filesystem()->dumpFile($fixture['log'], '');
        foreach ([0, 2] as $rowCount) {
            $this->writeServerConfig($fixture, overrides: ['row_count' => $rowCount]);
            $rows = $this->execute($fixture);
            self::assertSame(1, $rows['exit'], (string) $rowCount);
            self::assertStringContainsString('exactly one unambiguous row', $rows['stderr']);
        }
    }

    #[Test]
    public function credential_free_self_test_still_passes(): void
    {
        $result = $this->runProcess([PHP_BINARY, $this->root . '/' . self::SCRIPT, '--self-test'], $this->root);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('k1 delivery cutover self-test: PASS', $result['stdout']);
        self::assertStringNotContainsString('sqlite:', $result['stdout'] . $result['stderr']);
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function fixture(array $receipt = [], bool $basicAuth = false): array
    {
        $directory = sys_get_temp_dir() . '/waaseyaa-k1-cutover-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700));
        $this->directories[] = $directory;
        $application = $directory . '/application';
        $this->filesystem()->mkdir([
            $application . '/bin',
            $application . '/vendor',
            $application . '/tests/Architecture/Fixtures',
            $application . '/ops/observability/grafana',
        ]);
        $this->filesystem()->copy($this->root . '/' . self::SCRIPT, $application . '/' . self::SCRIPT);
        chmod($application . '/' . self::SCRIPT, 0o755);
        $this->filesystem()->copy(
            $this->root . '/tests/Architecture/Fixtures/k1-cutover-self-test.php',
            $application . '/tests/Architecture/Fixtures/k1-cutover-self-test.php',
        );
        $this->filesystem()->dumpFile(
            $application . '/vendor/autoload.php',
            '<?php require ' . var_export($this->root . '/vendor/autoload.php', true) . ";\n",
        );
        $this->filesystem()->copy(
            $this->root . '/ops/observability/grafana/waaseyaa-k1-delivery-flow.json',
            $application . '/ops/observability/grafana/waaseyaa-k1-delivery-flow.json',
        );

        $database = $directory . '/projection.sqlite';
        $pdo = new PDO('sqlite:' . $database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->installSchema($pdo);
        $this->seedIdentity($pdo, $this->identity());

        $receiptPath = $directory . '/accepted-receipt.json';
        $this->filesystem()->dumpFile(
            $receiptPath,
            json_encode(array_replace($this->receipt(), $receipt), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        $config = $directory . '/grafana-config.json';
        $log = $directory . '/grafana-requests.jsonl';
        $this->filesystem()->dumpFile($log, '');
        $port = $this->freePort();
        $url = 'http://127.0.0.1:' . $port;
        $authorization = $basicAuth
            ? 'Basic ' . base64_encode(self::BASIC_USER . ':' . self::BASIC_PASSWORD)
            : 'Bearer ' . self::TOKEN;
        $fixture = [
            'directory' => $directory,
            'application' => $application,
            'database' => $database,
            'dsn' => 'sqlite:' . $database,
            'pdo' => $pdo,
            'receipt' => $receiptPath,
            'config' => $config,
            'log' => $log,
            'url' => $url,
            'authorization' => $authorization,
            'basic_auth' => $basicAuth,
        ];
        $this->writeServerConfig($fixture);
        $server = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->root . '/tests/Architecture/Fixtures/k1-grafana-server.php'],
            $this->root,
            self::replacingEnvironment([
                'K1_GRAFANA_FIXTURE_CONFIG' => $config,
                'K1_GRAFANA_FIXTURE_LOG' => $log,
                'PATH' => (($path = getenv('PATH')) !== false && $path !== '') ? $path : '/usr/bin:/bin',
            ]),
            null,
            null,
        );
        $server->start();
        $this->servers[] = $server;
        $this->waitUntilReady($port);
        $this->filesystem()->dumpFile($log, '');

        return $fixture;
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, mixed>|null $dashboard
     * @param array<string, mixed>|null $row
     * @param array<string, mixed> $overrides
     */
    private function writeServerConfig(array $fixture, ?array $dashboard = null, ?array $row = null, array $overrides = []): void
    {
        $config = array_replace([
            'authorization' => $fixture['authorization'],
            'dashboard' => $dashboard ?? $this->trackedDashboard(),
            'row' => $row ?? $this->grafanaRow(),
        ], $overrides);
        $this->filesystem()->dumpFile(
            $fixture['config'],
            json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $fixture @param array<string, string> $overrides */
    private function execute(array $fixture, array $overrides = []): array
    {
        return $this->runProcess(
            [PHP_BINARY, $fixture['application'] . '/' . self::SCRIPT, '--receipt=' . $fixture['receipt']],
            $fixture['application'],
            array_replace($this->environment($fixture), $overrides),
        );
    }

    /** @param array<string, mixed> $fixture @return array<string, string> */
    private function environment(array $fixture): array
    {
        $environment = [
            'WAASEYAA_DELIVERY_TELEMETRY_DSN' => $fixture['dsn'],
            'WAASEYAA_GRAFANA_URL' => $fixture['url'],
        ];
        if (($fixture['basic_auth'] ?? false) === true) {
            $environment['WAASEYAA_GRAFANA_USER'] = self::BASIC_USER;
            $environment['WAASEYAA_GRAFANA_PASSWORD'] = self::BASIC_PASSWORD;
        } else {
            $environment['WAASEYAA_GRAFANA_TOKEN'] = self::TOKEN;
        }

        return $environment;
    }

    /** @param array<string, mixed> $fixture @return list<array<string, mixed>> */
    private function requests(array $fixture): array
    {
        $lines = file($fixture['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : array_map(
            static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    /** @param array{exit: int, stdout: string, stderr: string} $result @param array<string, mixed> $fixture */
    private function assertNoSecrets(array $result, array $fixture): void
    {
        $output = $result['stdout'] . $result['stderr'];
        foreach ([self::TOKEN, self::BASIC_USER, self::BASIC_PASSWORD, $fixture['dsn']] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        return [
            'operation' => 'apply',
            'outcome' => 'applied',
            'source_commit_sha' => self::SOURCE,
            'schema_sha256' => self::SCHEMA,
            'ledger_sha256' => self::LEDGER,
            'batch_manifest_sha256' => self::MANIFEST,
            'batch_schema_sha256' => self::BATCH_SCHEMA,
            'freeze_sha256' => self::FREEZE,
            'replay_sha256' => self::REPLAY,
            'event_count' => 15,
            'generation' => 4,
            'projector_version' => 2,
            'added' => 1,
            'replaced' => 0,
            'removed' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return [
            'source_commit_sha' => self::SOURCE,
            'projector_version' => 2,
            'generation' => 4,
            'event_count' => 15,
            'schema_sha256' => self::SCHEMA,
            'ledger_sha256' => self::LEDGER,
            'event_schema_sha256' => self::SCHEMA,
            'batch_schema_sha256' => self::BATCH_SCHEMA,
            'freeze_sha256' => self::FREEZE,
            'replay_sha256' => self::REPLAY,
            'batch_manifest_sha256' => self::MANIFEST,
        ];
    }

    /** @return array<string, int|string> */
    private function grafanaRow(): array
    {
        return [
            'Source commit' => self::SOURCE,
            'Projector version' => 2,
            'Generation' => 4,
            'Events' => 15,
            'Projected (UTC)' => '2026-09-05T14:00:00Z',
            'Frozen v1 ledger SHA-256' => self::LEDGER,
            'Complete replay SHA-256' => self::REPLAY,
            'Batch manifest SHA-256' => self::MANIFEST,
        ];
    }

    /** @return array<string, mixed> */
    private function trackedDashboard(): array
    {
        $bytes = file_get_contents($this->root . '/ops/observability/grafana/waaseyaa-k1-delivery-flow.json');
        self::assertIsString($bytes);
        $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $dashboard = $document['dashboard'] ?? null;
        self::assertIsArray($dashboard);

        return $dashboard;
    }

    /** @return array<string, mixed> */
    private function trackedPanel(): array
    {
        $dashboard = $this->trackedDashboard();

        return $dashboard['panels'][$this->trackedPanelIndex($dashboard)];
    }

    /** @param array<string, mixed> $dashboard */
    private function trackedPanelIndex(array $dashboard): int
    {
        foreach ($dashboard['panels'] ?? [] as $index => $panel) {
            if (is_array($panel) && ($panel['id'] ?? null) === 8) {
                return $index;
            }
        }

        self::fail('Tracked default dashboard does not contain Panel 8.');
    }

    /** @param array<string, mixed> $identity */
    private function seedIdentity(PDO $pdo, array $identity): void
    {
        $pdo->exec('DELETE FROM waaseyaa_delivery_projection_state');
        $pdo->exec('DELETE FROM waaseyaa_delivery_projection_identity_v2');
        $state = $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_state (
            projection_id, contract_version, source_commit_sha, schema_sha256, ledger_sha256,
            ledger_bytes, event_count, first_event_id, last_event_id, generation, projector_version, projected_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if ($state === false) {
            self::fail('could not prepare projection-state fixture');
        }
        $state->execute([
            self::PROJECTION_ID,
            'delivery-agent-event/v1',
            $identity['source_commit_sha'],
            $identity['schema_sha256'],
            $identity['ledger_sha256'],
            128,
            $identity['event_count'],
            null,
            null,
            $identity['generation'],
            $identity['projector_version'],
            '2026-09-05T14:00:00Z',
        ]);
        $identityStatement = $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_identity_v2 (
            projection_id, batch_manifest, batch_manifest_sha256, event_schema_sha256,
            batch_schema_sha256, freeze_sha256, replay_sha256
        ) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if ($identityStatement === false) {
            self::fail('could not prepare projection-identity fixture');
        }
        $identityStatement->execute([
            self::PROJECTION_ID,
            '[]',
            $identity['batch_manifest_sha256'],
            $identity['event_schema_sha256'],
            $identity['batch_schema_sha256'],
            $identity['freeze_sha256'],
            $identity['replay_sha256'],
        ]);
    }

    private function installSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE waaseyaa_delivery_projection_state (
            projection_id TEXT PRIMARY KEY, contract_version TEXT NOT NULL,
            source_commit_sha TEXT NOT NULL, schema_sha256 TEXT NOT NULL,
            ledger_sha256 TEXT NOT NULL, ledger_bytes INTEGER NOT NULL,
            event_count INTEGER NOT NULL, first_event_id TEXT NULL,
            last_event_id TEXT NULL, generation INTEGER NOT NULL,
            projector_version INTEGER NOT NULL, projected_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE waaseyaa_delivery_projection_identity_v2 (
            projection_id TEXT PRIMARY KEY, batch_manifest TEXT NOT NULL,
            batch_manifest_sha256 TEXT NOT NULL, event_schema_sha256 TEXT NOT NULL,
            batch_schema_sha256 TEXT NULL, freeze_sha256 TEXT NULL,
            replay_sha256 TEXT NOT NULL
        )');
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitUntilReady(int $port): void
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::fail('Grafana fixture server did not start');
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $workingDirectory, array $environment = []): array
    {
        $process = new Process($command, $workingDirectory, self::replacingEnvironment($environment), null, 30);
        $process->run();

        return [
            'exit' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function filesystem(): Filesystem
    {
        return $this->filesystem ?? throw new \LogicException('filesystem fixture is not initialized');
    }

    /** @param array<string, string> $explicit @return array<string, string|false> */
    private static function replacingEnvironment(array $explicit): array
    {
        $environment = $explicit;
        foreach (array_keys($_ENV + getenv()) as $name) {
            if (!array_key_exists((string) $name, $environment)) {
                $environment[(string) $name] = false;
            }
        }

        return $environment;
    }
}
