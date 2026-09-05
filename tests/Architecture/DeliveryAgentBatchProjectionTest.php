<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class DeliveryAgentBatchProjectionTest extends TestCase
{
    #[Test]
    public function sqlite_projection_replays_and_binds_batch_identity_atomically(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root . '/bin/project-delivery-agent-events', '--self-test'], $root, null, null, 120);

        self::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('delivery agent projection self-test: PASS', $process->getOutput());
    }

    #[Test]
    public function self_test_is_history_independent_while_durable_projection_refuses_a_shallow_source(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir() . '/waaseyaa_batch_projection_shallow_' . uniqid('', true);
        $source = $fixture . '/source';
        $shallow = $fixture . '/shallow';
        $sourceOnly = $fixture . '/source-only';
        $fs = new Filesystem();
        try {
            $fs->mkdir([
                $source . '/bin/lib',
                $source . '/ops/observability/delivery-agent-batches-v1',
                $source . '/vendor',
            ]);
            foreach ([
                'bin/git',
                'bin/check-delivery-agent-events',
                'bin/project-delivery-agent-events',
                'bin/lib/delivery-agent-event-set.php',
                'ops/observability/delivery-agent-event-v1.schema.json',
                'ops/observability/delivery-agent-events-v1.jsonl',
                'ops/observability/delivery-agent-batch-v1.schema.json',
                'ops/observability/delivery-agent-v1-freeze.json',
            ] as $relativePath) {
                $fs->copy($root . '/' . $relativePath, $source . '/' . $relativePath);
            }
            chmod($source . '/bin/git', 0o755);
            $fs->dumpFile(
                $source . '/vendor/autoload.php',
                '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ";\n",
            );

            $this->git($source, ['init', '--initial-branch=main']);
            $this->git($source, ['config', 'user.name', 'Projection Shallow Fixture']);
            $this->git($source, ['config', 'user.email', 'projection-shallow@example.invalid']);
            $this->git($source, ['add', '--all']);
            $this->git($source, ['commit', '-m', 'fixture: projection source baseline']);
            $this->git($source, ['commit', '--allow-empty', '-m', 'fixture: shallow tip']);

            $clone = new Process([
                $root . '/bin/git',
                'clone',
                '--depth=1',
                '--no-local',
                'file://' . $source,
                $shallow,
            ]);
            self::assertSame(0, $clone->run(), $clone->getErrorOutput());
            self::assertSame('true', trim($this->git($shallow, ['rev-parse', '--is-shallow-repository'])));

            $fs->mirror($shallow, $sourceOnly);
            $fs->remove($sourceOnly . '/.git');
            self::assertDirectoryDoesNotExist($sourceOnly . '/.git');

            foreach ([$shallow, $sourceOnly] as $selfTestRoot) {
                $selfTest = new Process(
                    [PHP_BINARY, $selfTestRoot . '/bin/project-delivery-agent-events', '--self-test'],
                    $selfTestRoot,
                    null,
                    null,
                    120,
                );
                self::assertSame(0, $selfTest->run(), $selfTest->getErrorOutput() . $selfTest->getOutput());
                self::assertStringContainsString('delivery agent projection self-test: PASS', $selfTest->getOutput());
            }

            $environment = ['WAASEYAA_DELIVERY_TELEMETRY_DSN' => 'sqlite:' . $fixture . '/projection.sqlite'];
            self::assertSame(0, $this->project($shallow, $environment, ['install'])->getExitCode());
            foreach (['plan', 'apply', 'verify'] as $operation) {
                $durable = $this->project($shallow, $environment, [$operation, '--source-ref=HEAD']);
                self::assertSame(1, $durable->getExitCode(), $operation);
                self::assertStringContainsString(
                    'batch source commit must have an immutable first parent for complete-set validation',
                    $durable->getErrorOutput(),
                    $operation,
                );
            }
        } finally {
            $fs->remove($fixture);
        }
    }

    #[Test]
    public function cli_projects_a_committed_batch_and_repairs_projection_drift_from_immutable_source(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir() . '/waaseyaa_batch_projection_' . uniqid('', true);
        $fs = new Filesystem();
        try {
            $clone = new Process([$root . '/bin/git', 'clone', '--shared', $root, $fixture]);
            self::assertSame(0, $clone->run(), $clone->getErrorOutput());
            $fs->remove($fixture . '/.git');
            $this->git($fixture, ['init']);
            $this->git($fixture, ['config', 'user.name', 'Projection Fixture']);
            $this->git($fixture, ['config', 'user.email', 'projection@example.invalid']);
            $this->git($fixture, ['add', '--all']);
            $this->git($fixture, ['commit', '-m', 'fixture: projection baseline']);
            self::assertSame('false', trim($this->git($fixture, ['rev-parse', '--is-shallow-repository'])));
            $fs->copy($root . '/bin/project-delivery-agent-events', $fixture . '/bin/project-delivery-agent-events', true);
            // The real vendor/ (not an autoload.php shim): the clone carries the
            // repository's composer.json/composer.lock, and the ledger gate the
            // projector shells out to runs the vendor-freshness precondition
            // (#2926) against vendor/composer/installed.json and the dumped
            // PSR-4 map before it touches the autoloader. On a machine whose
            // vendor/ is stale this test therefore fails with that gate's exit-3
            // "run composer install" message — not a fixture bug.
            self::assertTrue(symlink($root . '/vendor', $fixture . '/vendor'));

            $ledgerLines = file($fixture . '/ops/observability/delivery-agent-events-v1.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($ledgerLines);
            $event = null;
            foreach ($ledgerLines as $line) {
                $candidate = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($candidate['event_type'] === 'substantive_review_issued' && $candidate['causation_event_id'] === null) {
                    $event = $candidate;
                    break;
                }
            }
            self::assertIsArray($event);
            $event['event_id'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            $event['recorded_at'] = '2099-01-01T00:00:00+00:00';
            $event['occurred_at'] = null;
            $batchId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
            $batch = [
                'schema_version' => 'delivery-agent-batch/v1',
                'batch_id' => $batchId,
                'created_at' => '2099-01-01T00:00:01+00:00',
                'producer' => ['kind' => 'test', 'name' => 'projection fixture', 'model' => null],
                'events' => [$event],
            ];
            $batchPath = $fixture . '/ops/observability/delivery-agent-batches-v1/' . $batchId . '.json';
            $batchBytes = json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $fs->dumpFile($batchPath, $batchBytes);
            $this->git($fixture, ['add', 'ops/observability/delivery-agent-batches-v1']);
            $this->git($fixture, ['commit', '-m', 'fixture: accepted batch']);
            $source = trim($this->git($fixture, ['rev-parse', 'HEAD']));

            $database = $fixture . '/projection.sqlite';
            $environment = ['WAASEYAA_DELIVERY_TELEMETRY_DSN' => 'sqlite:' . $database];
            self::assertSame(0, $this->project($fixture, $environment, ['install'])->getExitCode());
            $plan = $this->project($fixture, $environment, ['plan', '--source-ref=' . $source]);
            self::assertSame('drift', json_decode($plan->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome']);
            $fs->dumpFile($batchPath, "{\"poisoned\":true}\n");
            $applied = $this->project($fixture, $environment, ['apply', '--source-ref=' . $source]);
            self::assertSame(0, $applied->getExitCode(), $applied->getErrorOutput());
            $receipt = json_decode($applied->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('applied', $receipt['outcome']);

            $pdo = new \PDO('sqlite:' . $database, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM waaseyaa_delivery_agent_events_v1 WHERE event_id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'")->fetchColumn());
            $identity = $pdo->query('SELECT * FROM waaseyaa_delivery_projection_identity_v2')->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($identity);
            $expectedManifest = json_encode([['batch_id' => $batchId, 'sha256' => hash('sha256', $batchBytes)]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $replayRows = $pdo->query('SELECT raw_event_json FROM waaseyaa_delivery_agent_events_v1 ORDER BY source_ordinal')->fetchAll(\PDO::FETCH_COLUMN);
            self::assertSame(hash('sha256', $expectedManifest), $receipt['batch_manifest_sha256']);
            self::assertSame(hash('sha256', (string) file_get_contents($fixture . '/ops/observability/delivery-agent-batch-v1.schema.json')), $receipt['batch_schema_sha256']);
            self::assertSame(hash('sha256', (string) file_get_contents($fixture . '/ops/observability/delivery-agent-v1-freeze.json')), $receipt['freeze_sha256']);
            self::assertSame(hash('sha256', implode("\n", $replayRows) . "\n"), $receipt['replay_sha256']);
            self::assertSame(2, $receipt['projector_version']);
            foreach (['batch_manifest_sha256', 'batch_schema_sha256', 'freeze_sha256', 'replay_sha256'] as $field) {
                self::assertSame($identity[$field], $receipt[$field]);
            }
            $pdo->exec("UPDATE waaseyaa_delivery_projection_identity_v2 SET batch_manifest = '[]'");
            $pdo->exec("UPDATE waaseyaa_delivery_agent_events_v1 SET raw_event_json = '{}' WHERE source_ordinal = (SELECT MAX(source_ordinal) FROM waaseyaa_delivery_agent_events_v1)");
            self::assertSame(1, $this->project($fixture, $environment, ['verify', '--source-ref=' . $source])->getExitCode());
            self::assertSame(0, $this->project($fixture, $environment, ['apply', '--source-ref=' . $source])->getExitCode());
            self::assertSame(0, $this->project($fixture, $environment, ['verify', '--source-ref=' . $source])->getExitCode());
        } finally {
            $fs->remove($fixture);
        }
    }

    #[Test]
    public function explicit_install_upgrades_a_populated_v1_projection_without_losing_atomicity(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir() . '/waaseyaa_batch_projection_upgrade_' . uniqid('', true);
        $fs = new Filesystem();
        try {
            $clone = new Process([$root . '/bin/git', 'clone', '--shared', $root, $fixture]);
            self::assertSame(0, $clone->run(), $clone->getErrorOutput());
            $fs->remove($fixture . '/.git');
            $this->git($fixture, ['init']);
            $this->git($fixture, ['config', 'user.name', 'Projection Upgrade Fixture']);
            $this->git($fixture, ['config', 'user.email', 'projection-upgrade@example.invalid']);
            $this->git($fixture, ['add', '--all']);
            $this->git($fixture, ['commit', '-m', 'fixture: projection source baseline']);
            $this->git($fixture, ['commit', '--allow-empty', '-m', 'fixture: legacy accepted source']);
            self::assertSame('false', trim($this->git($fixture, ['rev-parse', '--is-shallow-repository'])));
            $fs->copy($root . '/bin/project-delivery-agent-events', $fixture . '/bin/project-delivery-agent-events', true);
            // The real vendor/ (not an autoload.php shim): the clone carries the
            // repository's composer.json/composer.lock, and the ledger gate the
            // projector shells out to runs the vendor-freshness precondition
            // (#2926) against vendor/composer/installed.json and the dumped
            // PSR-4 map before it touches the autoloader. On a machine whose
            // vendor/ is stale this test therefore fails with that gate's exit-3
            // "run composer install" message — not a fixture bug.
            self::assertTrue(symlink($root . '/vendor', $fixture . '/vendor'));

            $legacySource = trim($this->git($fixture, ['rev-parse', 'HEAD']));
            $database = $fixture . '/projection.sqlite';
            $environment = ['WAASEYAA_DELIVERY_TELEMETRY_DSN' => 'sqlite:' . $database];
            self::assertSame(0, $this->project($fixture, $environment, ['install'])->getExitCode());
            $legacyApply = $this->project($fixture, $environment, ['apply', '--source-ref=' . $legacySource]);
            self::assertSame(0, $legacyApply->getExitCode(), $legacyApply->getErrorOutput());

            $pdo = new \PDO('sqlite:' . $database, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('DROP TABLE waaseyaa_delivery_projection_identity_v2');
            $pdo->exec('UPDATE waaseyaa_delivery_projection_state SET projector_version = 1');
            $legacyRows = $pdo->query('SELECT * FROM waaseyaa_delivery_agent_events_v1 ORDER BY source_ordinal')->fetchAll(\PDO::FETCH_ASSOC);
            $legacyState = $pdo->query('SELECT * FROM waaseyaa_delivery_projection_state')->fetch(\PDO::FETCH_ASSOC);
            self::assertNotEmpty($legacyRows);
            self::assertIsArray($legacyState);
            self::assertSame(1, (int) $legacyState['projector_version']);

            $ledgerLines = file($fixture . '/ops/observability/delivery-agent-events-v1.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($ledgerLines);
            $event = json_decode($ledgerLines[0], true, flags: JSON_THROW_ON_ERROR);
            $event['event_id'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
            $event['causation_event_id'] = null;
            $event['recorded_at'] = '2099-02-01T00:00:00+00:00';
            $event['occurred_at'] = null;
            $batchId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
            $batch = [
                'schema_version' => 'delivery-agent-batch/v1',
                'batch_id' => $batchId,
                'created_at' => '2099-02-01T00:00:01+00:00',
                'producer' => ['kind' => 'test', 'name' => 'projection upgrade fixture', 'model' => null],
                'events' => [$event],
            ];
            $batchPath = $fixture . '/ops/observability/delivery-agent-batches-v1/' . $batchId . '.json';
            $fs->dumpFile($batchPath, json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $this->git($fixture, ['add', 'ops/observability/delivery-agent-batches-v1']);
            $this->git($fixture, ['commit', '-m', 'fixture: accepted post-v1 batch']);
            $source = trim($this->git($fixture, ['rev-parse', 'HEAD']));

            $verifyBeforeInstall = $this->project($fixture, $environment, ['verify', '--source-ref=' . $source]);
            self::assertSame(1, $verifyBeforeInstall->getExitCode());
            self::assertStringContainsString('projection schema is absent or incompatible; run install explicitly', $verifyBeforeInstall->getErrorOutput());
            self::assertSame($legacyRows, $pdo->query('SELECT * FROM waaseyaa_delivery_agent_events_v1 ORDER BY source_ordinal')->fetchAll(\PDO::FETCH_ASSOC));
            self::assertSame($legacyState, $pdo->query('SELECT * FROM waaseyaa_delivery_projection_state')->fetch(\PDO::FETCH_ASSOC));

            self::assertSame(0, $this->project($fixture, $environment, ['install'])->getExitCode());
            self::assertSame($legacyRows, $pdo->query('SELECT * FROM waaseyaa_delivery_agent_events_v1 ORDER BY source_ordinal')->fetchAll(\PDO::FETCH_ASSOC));
            self::assertSame($legacyState, $pdo->query('SELECT * FROM waaseyaa_delivery_projection_state')->fetch(\PDO::FETCH_ASSOC));
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM waaseyaa_delivery_projection_identity_v2')->fetchColumn());

            $pdo->exec("CREATE TRIGGER interrupt_upgrade BEFORE INSERT ON waaseyaa_delivery_agent_events_v1 WHEN NEW.event_id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' BEGIN SELECT RAISE(ABORT, 'injected upgrade interruption'); END");
            $interrupted = $this->project($fixture, $environment, ['apply', '--source-ref=' . $source]);
            self::assertSame(1, $interrupted->getExitCode());
            self::assertStringContainsString('injected upgrade interruption', $interrupted->getErrorOutput());
            self::assertSame($legacyRows, $pdo->query('SELECT * FROM waaseyaa_delivery_agent_events_v1 ORDER BY source_ordinal')->fetchAll(\PDO::FETCH_ASSOC));
            self::assertSame($legacyState, $pdo->query('SELECT * FROM waaseyaa_delivery_projection_state')->fetch(\PDO::FETCH_ASSOC));
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM waaseyaa_delivery_projection_identity_v2')->fetchColumn());
            $pdo->exec('DROP TRIGGER interrupt_upgrade');

            $applied = $this->project($fixture, $environment, ['apply', '--source-ref=' . $source]);
            self::assertSame(0, $applied->getExitCode(), $applied->getErrorOutput());
            $receipt = json_decode($applied->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('applied', $receipt['outcome']);
            self::assertSame(2, $receipt['projector_version']);
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM waaseyaa_delivery_agent_events_v1 WHERE event_id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'")->fetchColumn());
            $identity = $pdo->query('SELECT * FROM waaseyaa_delivery_projection_identity_v2')->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($identity);
            foreach (['batch_manifest_sha256', 'batch_schema_sha256', 'freeze_sha256', 'replay_sha256'] as $field) {
                self::assertSame($identity[$field], $receipt[$field]);
            }
            self::assertSame(0, $this->project($fixture, $environment, ['verify', '--source-ref=' . $source])->getExitCode());
            $upgradedRows = $pdo->query('SELECT * FROM waaseyaa_delivery_agent_events_v1 ORDER BY source_ordinal')->fetchAll(\PDO::FETCH_ASSOC);
            $upgradedState = $pdo->query('SELECT * FROM waaseyaa_delivery_projection_state')->fetch(\PDO::FETCH_ASSOC);
            $upgradedIdentity = $pdo->query('SELECT * FROM waaseyaa_delivery_projection_identity_v2')->fetch(\PDO::FETCH_ASSOC);
            $again = $this->project($fixture, $environment, ['apply', '--source-ref=' . $source]);
            self::assertSame(0, $again->getExitCode(), $again->getErrorOutput());
            self::assertSame('no_op', json_decode($again->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome']);
            self::assertSame($upgradedRows, $pdo->query('SELECT * FROM waaseyaa_delivery_agent_events_v1 ORDER BY source_ordinal')->fetchAll(\PDO::FETCH_ASSOC));
            self::assertSame($upgradedState, $pdo->query('SELECT * FROM waaseyaa_delivery_projection_state')->fetch(\PDO::FETCH_ASSOC));
            self::assertSame($upgradedIdentity, $pdo->query('SELECT * FROM waaseyaa_delivery_projection_identity_v2')->fetch(\PDO::FETCH_ASSOC));
        } finally {
            $fs->remove($fixture);
        }
    }

    /** @param list<string> $arguments */
    private function git(string $root, array $arguments): string
    {
        $process = new Process(array_merge([$root . '/bin/git'], $arguments), $root);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        return $process->getOutput();
    }

    /** @param array<string, string> $environment @param list<string> $arguments */
    private function project(string $root, array $environment, array $arguments): Process
    {
        $process = new Process(array_merge([PHP_BINARY, $root . '/bin/project-delivery-agent-events'], $arguments), $root, $environment, null, 120);
        $process->run();
        return $process;
    }
}
